<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WealthCharmLevel;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\WealthCharmLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** GFT-027 / docs/00 §7 — the wealth/charm level ladder and its wallet resolution. */
class LevelManagementTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class, WealthCharmLevelSeeder::class]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->superAdmin = AdminUser::create([
            'name' => 'Super', 'email' => 'super@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::SUPER_ADMIN)->value('id'), 'status' => 'active',
        ]);
    }

    protected function makeUser(): User
    {
        static $seq = 0;
        $seq++;

        return User::create([
            'guftagu_id' => 'GF'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            'phone'      => '+9198201'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'email'      => "level-test{$seq}@example.com",
            'status'     => User::STATUS_ACTIVE,
            'agora_uid'  => 800000 + $seq,
        ]);
    }

    #[Test]
    public function a_wallets_level_is_derived_from_lifetime_totals_with_no_job_involved(): void
    {
        $user = $this->makeUser();
        $wallet = Wallet::create(['user_id' => $user->id, 'lifetime_coins_spent' => 60_000, 'lifetime_diamonds_earned' => 6_000]);

        // Seeded ladder: wealth III starts at 50,000; charm III starts at 25,000 — 6,000
        // diamonds only clears charm II (5,000).
        $this->assertSame('Wealth III', $wallet->wealthLevel()?->name_en);
        $this->assertSame('Charm II', $wallet->charmLevel()?->name_en);
    }

    #[Test]
    public function a_wallet_below_every_threshold_but_zero_still_resolves_level_one(): void
    {
        $user = $this->makeUser();
        $wallet = Wallet::create(['user_id' => $user->id]);

        // The seeded ladder starts both tracks at threshold 0, so a brand-new wallet is
        // already "Wealth I" / "Charm I" rather than levelless.
        $this->assertSame(1, $wallet->wealthLevel()?->level);
        $this->assertSame(1, $wallet->charmLevel()?->level);
    }

    #[Test]
    public function creating_a_level_with_a_lower_threshold_than_the_level_below_it_is_refused(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/levels', [
                // Wealth V already exists at threshold 1,000,000 (level 5); a level 6
                // asking for less than that would rank users out of order.
                'type' => 'wealth', 'level' => 6, 'name_en' => 'Wealth Broken', 'threshold' => 500_000,
            ])
            ->assertStatus(422);

        $this->assertSame('LEVEL_THRESHOLD_ORDER_INVALID', $response->json('error.code'));
    }

    #[Test]
    public function a_valid_level_can_be_appended_to_the_ladder(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/levels', [
                'type' => 'wealth', 'level' => 6, 'name_en' => 'Wealth VI', 'threshold' => 5_000_000,
            ])
            ->assertStatus(201);

        $this->assertTrue(WealthCharmLevel::where('type', 'wealth')->where('level', 6)->exists());
        $this->assertTrue(AuditLog::where('action', 'level.create')->exists());
    }

    #[Test]
    public function two_levels_of_the_same_type_cannot_share_a_level_number(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/levels', [
                'type' => 'wealth', 'level' => 3, 'name_en' => 'Duplicate', 'threshold' => 9_000_000,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function a_levels_type_cannot_be_changed_after_creation(): void
    {
        $level = WealthCharmLevel::where('type', 'wealth')->where('level', 2)->first();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base."/levels/{$level->id}", ['type' => 'charm'])
            ->assertStatus(422);
    }

    #[Test]
    public function a_badge_upload_returns_a_usable_url(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/levels/badge', ['file' => UploadedFile::fake()->image('medal.png')])
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'path', 'size']]);

        Storage::disk('public')->assertExists($response->json('data.path'));
    }

    #[Test]
    public function uploading_a_badge_with_an_id_saves_it_onto_that_level_immediately(): void
    {
        Storage::fake('public');

        $level = WealthCharmLevel::where('type', 'wealth')->where('level', 1)->first();
        $this->assertNull($level->badge_url);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/levels/badge', [
                'file' => UploadedFile::fake()->image('medal.png'),
                'id'   => $level->id,
            ])
            ->assertOk();

        $url = $response->json('data.url');
        $this->assertSame($url, $response->json('data.level.badge_url'));
        $this->assertSame($url, $level->fresh()->badge_url);
        $this->assertTrue(AuditLog::where('action', 'level.update')->where('entity_id', $level->id)->exists());
    }

    // ------------------------------------------------------------- GFT-027 override

    #[Test]
    public function an_override_wins_over_the_derived_level_and_can_be_cleared(): void
    {
        $user = $this->makeUser();
        // Only 100 coins spent — derives to Wealth I on the seeded ladder.
        Wallet::create(['user_id' => $user->id, 'lifetime_coins_spent' => 100]);

        $target = WealthCharmLevel::where('type', 'wealth')->where('level', 5)->first();

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/level-override", [
                'type' => 'wealth', 'level_id' => $target->id,
            ])
            ->assertOk();

        $this->assertSame(5, $response->json('data.level'));
        $this->assertTrue($response->json('data.is_override'));
        $this->assertTrue(AuditLog::where('action', 'wallet.level_override')->exists());

        // The user detail endpoint must reflect the override too, not just this response.
        $detail = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base."/users/{$user->id}")
            ->assertOk();

        $this->assertSame(5, $detail->json('data.wallet.wealth_level.level'));
        $this->assertTrue($detail->json('data.wallet.wealth_level.is_override'));

        // Clearing it returns to the derived value.
        $cleared = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/level-override", [
                'type' => 'wealth', 'level_id' => null,
            ])
            ->assertOk();

        $this->assertSame(1, $cleared->json('data.level'));
        $this->assertFalse($cleared->json('data.is_override'));
    }

    #[Test]
    public function an_override_pointed_at_the_wrong_type_is_rejected(): void
    {
        $user = $this->makeUser();
        Wallet::create(['user_id' => $user->id]);

        $charmLevel = WealthCharmLevel::where('type', 'charm')->where('level', 2)->first();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/level-override", [
                'type' => 'wealth', 'level_id' => $charmLevel->id,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function overriding_a_level_needs_its_own_permission(): void
    {
        $admin = AdminUser::create([
            'name' => 'Plain Admin', 'email' => 'plain@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::MODERATOR)->value('id'), 'status' => 'active',
        ]);
        $user = $this->makeUser();
        Wallet::create(['user_id' => $user->id]);

        $this->actingAs($admin, 'sanctum-admin')
            ->postJson($this->base."/users/{$user->id}/level-override", ['type' => 'wealth', 'level_id' => null])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    #[Test]
    public function the_seeder_is_idempotent(): void
    {
        $count = WealthCharmLevel::count();

        $this->seed(WealthCharmLevelSeeder::class);

        $this->assertSame($count, WealthCharmLevel::count());
    }
}
