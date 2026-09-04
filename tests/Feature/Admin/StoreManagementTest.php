<?php

namespace Tests\Feature\Admin;

use App\Domain\Store\GiftCatalogue;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Gift;
use App\Models\GiftCategory;
use App\Models\Role;
use App\Models\VipTier;
use Database\Seeders\GiftCatalogueSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\VipTierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Epic A.6 acceptance criteria. */
class StoreManagementTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class,
            VipTierSeeder::class, GiftCatalogueSeeder::class,
        ]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->superAdmin = $this->makeAdmin(Role::SUPER_ADMIN);
        Cache::flush();
    }

    protected function makeAdmin(string $roleKey): AdminUser
    {
        return AdminUser::create([
            'name'     => ucfirst($roleKey),
            'email'    => $roleKey.'@test.local',
            'password' => 'Password12345',
            'role_id'  => Role::where('key', $roleKey)->value('id'),
            'status'   => 'active',
        ]);
    }

    // -------------------------------------------------------------------- A.6a

    #[Test]
    public function creating_a_gift_puts_it_in_the_app_catalogue_immediately(): void
    {
        $catalogue = app(GiftCatalogue::class);

        // Warm the cache so we are proving invalidation, not an empty first read.
        $before = collect($catalogue->forApp())->pluck('code')->all();
        $this->assertNotContains('fireworks', $before);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/gifts', [
                'code' => 'fireworks', 'name_en' => 'Fireworks',
                'coin_price' => 999, 'diamond_value' => 500,
            ])
            ->assertStatus(201);

        // A.6a allows the app to lag by the TTL, but a panel write should not need to.
        $after = collect($catalogue->forApp())->pluck('code')->all();

        $this->assertContains('fireworks', $after, 'A catalogue write must invalidate the cache.');

        $gift = Gift::where('code', 'fireworks')->first();
        $this->assertSame(999, $gift->coin_price, 'The price must be stored exactly.');
        $this->assertTrue(AuditLog::where('action', 'gift.create')->exists());
    }

    #[Test]
    public function a_fractional_price_is_rejected(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/gifts', [
                'code' => 'half_coin', 'name_en' => 'Half', 'coin_price' => 99.5, 'diamond_value' => 50,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function an_oversized_animation_is_rejected_with_a_size_error(): void
    {
        Storage::fake('public');

        // A.6a names 60 MB explicitly.
        $huge = UploadedFile::fake()->create('epic.svga', 60 * 1024);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/gifts/animation', ['file' => $huge, 'type' => 'svga'])
            ->assertStatus(422);

        $this->assertStringContainsString(
            'larger than',
            $response->json('error.details.file.0'),
            'The error must say the file is too big, not just "invalid".',
        );
    }

    #[Test]
    public function an_animation_within_the_cap_is_accepted(): void
    {
        Storage::fake('public');

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/gifts/animation', [
                'file' => UploadedFile::fake()->create('sparkle.json', 512),
                'type' => 'lottie',
            ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'path', 'size']]);
    }

    #[Test]
    public function a_thumbnail_upload_returns_a_usable_url(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/gifts/thumbnail', [
                'file' => UploadedFile::fake()->image('rose.png', 200, 200),
            ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['url', 'path', 'size']]);

        Storage::disk('public')->assertExists($response->json('data.path'));
    }

    #[Test]
    public function an_oversized_thumbnail_is_rejected(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/gifts/thumbnail', [
                'file' => UploadedFile::fake()->create('huge.png', 6000, 'image/png'),
            ])
            ->assertStatus(422);

        $this->assertStringContainsString('larger than', $response->json('error.details.file.0'));
    }

    #[Test]
    public function uploading_a_thumbnail_with_an_id_saves_it_onto_that_gift_immediately(): void
    {
        Storage::fake('public');

        $gift = Gift::where('code', 'rose')->first();
        $this->assertNull($gift->thumbnail_url);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/gifts/thumbnail', [
                'file' => UploadedFile::fake()->image('rose.png'),
                'id'   => $gift->id,
            ])
            ->assertOk();

        $url = $response->json('data.url');
        $this->assertSame($url, $response->json('data.gift.thumbnail_url'));
        $this->assertSame($url, $gift->fresh()->thumbnail_url);
        $this->assertTrue(AuditLog::where('action', 'gift.update')->where('entity_id', $gift->id)->exists());
    }

    #[Test]
    public function uploading_a_thumbnail_without_an_id_only_returns_the_url(): void
    {
        Storage::fake('public');

        $gift = Gift::where('code', 'rose')->first();
        $before = $gift->thumbnail_url;

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/gifts/thumbnail', ['file' => UploadedFile::fake()->image('rose.png')])
            ->assertOk()
            ->assertJsonMissingPath('data.gift');

        $this->assertSame($before, $gift->fresh()->thumbnail_url, 'No id means nothing to save onto yet.');
    }

    #[Test]
    public function uploading_a_thumbnail_with_an_unknown_id_is_a_validation_error(): void
    {
        Storage::fake('public');

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/gifts/thumbnail', [
                'file' => UploadedFile::fake()->image('rose.png'),
                'id'   => 999999,
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function a_category_can_be_created_and_edited_with_an_icon(): void
    {
        Storage::fake('public');

        $icon = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/gift-categories/icon', [
                'file' => UploadedFile::fake()->image('sparkle.png'),
            ])
            ->assertOk()
            ->json('data.url');

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/gift-categories', [
                'key' => 'seasonal', 'name_en' => 'Seasonal', 'icon_url' => $icon,
            ])
            ->assertStatus(201);

        $category = GiftCategory::where('key', 'seasonal')->first();
        $this->assertSame($icon, $category->icon_url);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base."/gift-categories/{$category->id}", ['is_active' => false])
            ->assertOk();

        $this->assertFalse($category->fresh()->is_active);
    }

    // -------------------------------------------------------------------- A.6b

    #[Test]
    public function a_limited_drop_sells_out_and_cannot_oversell(): void
    {
        $gift = Gift::create([
            'code' => 'ltd_lamp', 'name_en' => 'Limited Lamp',
            'coin_price' => 500, 'diamond_value' => 250,
            'is_limited' => true, 'stock' => 100,
        ]);

        $catalogue = app(GiftCatalogue::class);

        // A.6b — 100 units, and not one more, however many attempts are made.
        $claimed = 0;

        for ($attempt = 0; $attempt < 150; $attempt++) {
            if ($catalogue->claimStock($gift->fresh(), 1)) {
                $claimed++;
            }
        }

        $this->assertSame(100, $claimed, 'Exactly the stock may be claimed — never more.');
        $this->assertSame(0, $gift->fresh()->stock);
        $this->assertGreaterThanOrEqual(0, $gift->fresh()->stock, 'Stock must never go negative.');
    }

    #[Test]
    public function a_sold_out_gift_leaves_the_app_catalogue(): void
    {
        $gift = Gift::create([
            'code' => 'ltd_rose', 'name_en' => 'Limited Rose',
            'coin_price' => 100, 'diamond_value' => 50,
            'is_limited' => true, 'stock' => 1,
        ]);

        $catalogue = app(GiftCatalogue::class);

        $this->assertContains('ltd_rose', collect($catalogue->forApp())->pluck('code')->all());

        $this->assertTrue($catalogue->claimStock($gift->fresh()));
        $this->assertFalse($catalogue->claimStock($gift->fresh()), 'The second claim must fail.');

        $this->assertNotContains(
            'ltd_rose',
            collect($catalogue->forApp())->pluck('code')->all(),
            'A sold-out gift must drop out of the catalogue.',
        );
        $this->assertTrue($gift->fresh()->isSoldOut());
    }

    #[Test]
    public function an_unlimited_gift_never_runs_out(): void
    {
        $gift = Gift::where('code', 'rose')->first();

        $this->assertNull($gift->stock, 'NULL stock means unlimited.');

        $catalogue = app(GiftCatalogue::class);

        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($catalogue->claimStock($gift, 5));
        }

        $this->assertNull($gift->fresh()->stock, 'Unlimited stock must not be decremented.');
    }

    #[Test]
    public function a_scheduled_drop_is_hidden_until_its_window_opens(): void
    {
        Gift::create([
            'code' => 'diwali_2027', 'name_en' => 'Diwali Special',
            'coin_price' => 2000, 'diamond_value' => 1000,
            'available_from' => now()->addWeek(), 'available_to' => now()->addWeeks(2),
        ]);

        $codes = collect(app(GiftCatalogue::class)->forApp())->pluck('code')->all();

        $this->assertNotContains('diwali_2027', $codes, 'A future drop must not be sellable yet.');
    }

    #[Test]
    public function an_expired_drop_leaves_the_catalogue_without_a_job(): void
    {
        $gift = Gift::create([
            'code' => 'holi_past', 'name_en' => 'Holi Special',
            'coin_price' => 500, 'diamond_value' => 250,
            'available_from' => now()->subWeeks(2), 'available_to' => now()->subDay(),
        ]);

        // Availability is decided in the query, so nothing had to run for this to lapse.
        $this->assertFalse($gift->isWithinWindow());
        $this->assertNotContains('holi_past', collect(app(GiftCatalogue::class)->forApp())->pluck('code')->all());
    }

    #[Test]
    public function marking_a_gift_unlimited_clears_its_stock(): void
    {
        $gift = Gift::create([
            'code' => 'was_limited', 'name_en' => 'Was Limited',
            'coin_price' => 100, 'diamond_value' => 50, 'is_limited' => true, 'stock' => 3,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base."/gifts/{$gift->id}", ['is_limited' => false])
            ->assertOk();

        // Leaving a stock number on an unlimited gift would make it sell out anyway.
        $this->assertNull($gift->fresh()->stock);
    }

    #[Test]
    public function restocking_needs_its_own_permission_and_refuses_unlimited_gifts(): void
    {
        $limited = Gift::create([
            'code' => 'ltd_x', 'name_en' => 'Ltd', 'coin_price' => 10, 'diamond_value' => 5,
            'is_limited' => true, 'stock' => 0,
        ]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/gifts/{$limited->id}/restock", ['stock' => 250])
            ->assertOk();

        $this->assertSame(250, $limited->fresh()->stock);

        // An unlimited gift has no stock to set.
        $unlimited = Gift::where('code', 'rose')->first();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/gifts/{$unlimited->id}/restock", ['stock' => 10])
            ->assertStatus(400);
    }

    #[Test]
    public function a_gift_is_deactivated_rather_than_deleted(): void
    {
        $gift = Gift::where('code', 'rose')->first();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->deleteJson($this->base."/gifts/{$gift->id}")
            ->assertOk();

        // Past sends reference this row; deleting it would break the history.
        $this->assertDatabaseHas('gifts', ['id' => $gift->id, 'is_active' => false]);
    }

    // -------------------------------------------------------------------- A.6c

    #[Test]
    public function a_vip_price_is_stored_in_paise_and_reads_back_exactly(): void
    {
        $tier = VipTier::where('level', 3)->first();

        // A.6c — "set VIP 3 monthly to ₹999 and the app shows ₹999".
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base."/vip-tiers/{$tier->id}", ['monthly_price_paise' => 99900])
            ->assertOk()
            ->assertJsonPath('data.monthly_price_paise', 99900)
            ->assertJsonPath('data.monthly_rupees', 999);

        $this->assertSame(99900, $tier->fresh()->monthly_price_paise);
    }

    #[Test]
    public function a_fractional_paise_price_is_rejected(): void
    {
        $tier = VipTier::where('level', 1)->first();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base."/vip-tiers/{$tier->id}", ['monthly_price_paise' => 19999.5])
            ->assertStatus(422);
    }

    #[Test]
    public function the_privileges_matrix_only_accepts_keys_the_app_understands(): void
    {
        $tier = VipTier::where('level', 1)->first();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base."/vip-tiers/{$tier->id}", [
                'privileges' => ['ad_free', 'teleportation'],
            ])
            ->assertStatus(422);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->patchJson($this->base."/vip-tiers/{$tier->id}", [
                'privileges' => ['ad_free', 'anti_kick'],
            ])
            ->assertOk();

        $this->assertTrue($tier->fresh()->grants('anti_kick'));
    }

    #[Test]
    public function the_tier_list_ships_the_privilege_catalogue_for_the_matrix(): void
    {
        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base.'/vip-tiers')->assertOk();

        // The panel builds its matrix from this, so it cannot drift from the backend.
        $keys = collect($response->json('data.privilege_catalogue'))->pluck('key')->all();

        $this->assertEqualsCanonicalizing(array_keys(VipTier::PRIVILEGES), $keys);
    }

    #[Test]
    public function two_tiers_cannot_share_a_level(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/vip-tiers', ['level' => 3, 'name_en' => 'Duplicate'])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------- A.6d

    #[Test]
    public function a_frame_can_be_gated_to_a_vip_tier(): void
    {
        $tier = VipTier::where('level', 2)->first();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/store-items', [
                'type' => 'frame', 'name' => 'Silver Halo', 'source' => 'vip', 'required_vip_tier_id' => $tier->id,
            ])
            ->assertStatus(201);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson($this->base.'/store-items?type=frame')->assertOk();

        $frame = collect($response->json('data.items'))->firstWhere('name', 'Silver Halo');

        $this->assertSame(2, $frame['vip_level'], 'The gate is reported as a level, not a raw id.');
    }

    #[Test]
    public function a_gate_pointing_at_a_tier_that_does_not_exist_is_rejected(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base.'/store-items', [
                'type' => 'frame', 'name' => 'Ghost Frame', 'required_vip_tier_id' => 9999,
            ])
            ->assertStatus(422);
    }

    // ---------------------------------------------------- catalogue integrity

    #[Test]
    public function room_themes_now_resolve_their_vip_gate_through_the_tier_table(): void
    {
        // The A.4 migration deliberately left this FK off because vip_tiers did not exist.
        $this->seed(\Database\Seeders\RoomCatalogueSeeder::class);

        $theme = \App\Models\RoomTheme::where('name', 'Royal Durbar')->first();

        $this->assertNotNull($theme->required_vip_tier_id);

        $tier = VipTier::find($theme->required_vip_tier_id);

        $this->assertNotNull($tier, 'The gate must point at a real tier now the FK exists.');
        $this->assertSame(3, $tier->level, 'Royal Durbar is gated to VIP 3.');
    }

    #[Test]
    public function the_seeders_are_idempotent(): void
    {
        $gifts = Gift::count();
        $tiers = VipTier::count();
        $categories = GiftCategory::count();

        $this->seed([VipTierSeeder::class, GiftCatalogueSeeder::class]);

        $this->assertSame($gifts, Gift::count());
        $this->assertSame($tiers, VipTier::count());
        $this->assertSame($categories, GiftCategory::count());
    }

    // ------------------------------------------------------------ route gating

    #[Test]
    public function a_moderator_cannot_reach_the_store(): void
    {
        $moderator = $this->makeAdmin(Role::MODERATOR);

        foreach (['/gifts', '/gift-categories', '/vip-tiers', '/cosmetics', '/store-items', '/levels'] as $path) {
            $this->actingAs($moderator, 'sanctum-admin')
                ->getJson($this->base.$path)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'PERMISSION_DENIED');
        }
    }

    #[Test]
    public function pricing_a_gift_and_stocking_a_drop_are_separate_permissions(): void
    {
        $admin = $this->makeAdmin(Role::ADMIN);
        $gift = Gift::create([
            'code' => 'ltd_y', 'name_en' => 'Ltd Y', 'coin_price' => 10, 'diamond_value' => 5,
            'is_limited' => true, 'stock' => 5,
        ]);

        app(\App\Domain\Access\Services\MfaReauthGate::class)->markSatisfied($this->superAdmin);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson($this->base."/admins/{$admin->id}/permissions/deny", [
                'permissions' => ['gifts.drop_manage'],
            ])->assertOk();

        // Still allowed to edit the gift ...
        $this->actingAs($admin->fresh(), 'sanctum-admin')
            ->patchJson($this->base."/gifts/{$gift->id}", ['coin_price' => 20])
            ->assertOk();

        // ... but not to change how many exist.
        $this->actingAs($admin->fresh(), 'sanctum-admin')
            ->postJson($this->base."/gifts/{$gift->id}/restock", ['stock' => 999])
            ->assertStatus(403);
    }
}
