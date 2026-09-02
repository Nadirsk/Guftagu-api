<?php

namespace Tests\Feature\Admin;

use App\Domain\Moderation\ContentFilter;
use App\Models\AdminUser;
use App\Models\BannedWord;
use App\Models\ContentFlag;
use App\Models\ModerationLog;
use App\Models\Report;
use App\Models\ReportAction;
use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserSanction;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Epic A.5 acceptance criteria — content filter, reports queue, oversight, sanctions. */
class ModerationTest extends TestCase
{
    use RefreshDatabase;

    protected string $base = '/api/v1/admin';

    protected AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, SettingsSeeder::class]);
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->superAdmin = AdminUser::create([
            'name' => 'Super', 'email' => 'super@test.local', 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::SUPER_ADMIN)->value('id'), 'status' => 'active',
        ]);

        app(ContentFilter::class)->flush();
    }

    protected function makeUser(string $status = User::STATUS_ACTIVE): User
    {
        static $seq = 0;
        $seq++;

        $user = User::create([
            'guftagu_id' => 'GF'.str_pad((string) $seq, 7, '0', STR_PAD_LEFT),
            'phone'      => '+9198205'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT),
            'status'     => $status,
            'agora_uid'  => 500000 + $seq,
        ]);

        UserProfile::create(['user_id' => $user->id, 'display_name' => "Member {$seq}"]);

        return $user;
    }

    protected function makeReport(array $attributes = []): Report
    {
        // `created_at` is not fillable, so it has to be forced on afterwards — passing it
        // to create() silently gives every report the same timestamp, which makes any
        // ordering or elapsed-time assertion meaningless.
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $report = Report::create(array_merge([
            'target_type' => 'user',
            'target_id'   => (string) $this->makeUser()->id,
            'category'    => 'abuse',
            'description' => 'Shouting over everyone.',
            'priority'    => 'medium',
            'status'      => Report::OPEN,
        ], $attributes));

        if ($createdAt !== null) {
            $report->forceFill(['created_at' => $createdAt])->save();
        }

        return $report;
    }

    protected function makeModerator(array $extraPermissions = []): AdminUser
    {
        static $seq = 0;
        $seq++;

        $moderator = AdminUser::create([
            'name' => "Mod {$seq}", 'email' => "mod{$seq}@test.local", 'password' => 'Password12345',
            'role_id' => Role::where('key', Role::MODERATOR)->value('id'), 'status' => 'active',
        ]);

        foreach ($extraPermissions as $key) {
            DB::table('admin_user_permission')->insert([
                'admin_user_id' => $moderator->id,
                'permission_id' => DB::table('permissions')->where('key', $key)->value('id'),
                'effect'        => 'allow',
                'granted_by'    => $this->superAdmin->id,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        return $moderator;
    }

    protected function word(array $attributes = []): BannedWord
    {
        return BannedWord::create(array_merge([
            'word' => 'scamword', 'language' => 'en', 'severity' => 'block',
            'is_regex' => false, 'is_active' => true,
        ], $attributes));
    }

    // -------------------------------------------------------------------- A.5a

    #[Test]
    public function a_blocked_word_is_reported_as_refused(): void
    {
        $this->word(['word' => 'freecoins']);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/filter-test", ['text' => 'get freecoins now'])
            ->assertOk()
            ->assertJsonPath('data.severity', 'block')
            ->assertJsonPath('data.outcome', 'The message would be refused.');
    }

    #[Test]
    public function a_replace_rule_returns_the_cleaned_text(): void
    {
        $this->word(['word' => 'idiot', 'severity' => 'replace', 'replacement' => '****']);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/filter-test", ['text' => 'you idiot'])
            ->assertOk()
            ->assertJsonPath('data.severity', 'replace')
            ->assertJsonPath('data.filtered', 'you ****');
    }

    #[Test]
    public function a_ban_does_not_trip_on_a_substring_of_a_longer_word(): void
    {
        $this->word(['word' => 'ass']);

        $result = app(ContentFilter::class)->check('a classic assumption');

        $this->assertFalse($result->matched(), 'A word-boundary ban must not fire inside "classic assumption".');
    }

    #[Test]
    public function a_term_ending_in_punctuation_still_matches(): void
    {
        // `\b` cannot match after a `+`, so this rule would sit in the list looking
        // enforced and never fire once.
        $this->word(['word' => '18+', 'scope' => ['room_name']]);

        $result = app(ContentFilter::class)->check('18+ party room', 'room_name');

        $this->assertSame('block', $result->severity);
    }

    #[Test]
    public function a_scoped_rule_only_fires_in_that_scope(): void
    {
        $this->word(['word' => 'bakwas', 'severity' => 'flag', 'scope' => ['chat']]);

        $filter = app(ContentFilter::class);

        $this->assertSame('flag', $filter->check('kya bakwas hai', 'chat')->severity);
        $this->assertNull($filter->check('kya bakwas hai', 'bio')->severity, 'A chat-only rule must not police bios.');
    }

    #[Test]
    public function an_unscoped_rule_applies_everywhere(): void
    {
        $this->word(['word' => 'sendmoney', 'scope' => []]);

        $filter = app(ContentFilter::class);

        foreach (ContentFilter::SCOPES as $scope) {
            $this->assertSame('block', $filter->check('sendmoney please', $scope)->severity, "Unscoped rule missed {$scope}.");
        }
    }

    #[Test]
    public function the_most_severe_match_decides_the_outcome(): void
    {
        $this->word(['word' => 'meh', 'severity' => 'flag']);
        $this->word(['word' => 'scamlink', 'severity' => 'block']);

        $result = app(ContentFilter::class)->check('meh, scamlink here');

        $this->assertSame('block', $result->severity);
        $this->assertCount(2, $result->matches);
    }

    #[Test]
    public function a_blocked_message_is_not_silently_rewritten(): void
    {
        $this->word(['word' => 'nope', 'severity' => 'replace', 'replacement' => '--']);
        $this->word(['word' => 'blocked', 'severity' => 'block']);

        $result = app(ContentFilter::class)->check('nope blocked');

        // Nothing is delivered, so there is nothing to clean up — handing back a
        // half-scrubbed string would invite a caller to send it anyway.
        $this->assertSame('nope blocked', $result->filtered);
    }

    #[Test]
    public function an_invalid_regex_degrades_to_no_match_rather_than_fataling(): void
    {
        // Written straight to the table, bypassing the controller's validation, to model a
        // rule that got in before that validation existed.
        BannedWord::create([
            'word' => '([unclosed', 'language' => 'en', 'severity' => 'block',
            'is_regex' => true, 'is_active' => true,
        ]);

        $result = app(ContentFilter::class)->check('any ordinary message');

        $this->assertFalse($result->matched());
    }

    #[Test]
    public function the_controller_refuses_an_uncompilable_pattern(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/banned-words", [
                'word' => '([unclosed', 'severity' => 'block', 'is_regex' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    #[Test]
    public function a_new_word_takes_effect_immediately_rather_than_after_the_cache_ttl(): void
    {
        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/filter-test", ['text' => 'brandnewban'])
            ->assertJsonPath('data.severity', null);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/banned-words", ['word' => 'brandnewban', 'severity' => 'block'])
            ->assertCreated();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/filter-test", ['text' => 'brandnewban'])
            ->assertJsonPath('data.severity', 'block');
    }

    #[Test]
    public function a_duplicate_word_for_the_same_language_is_a_422_not_a_500(): void
    {
        $this->word(['word' => 'dupe']);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/banned-words", ['word' => 'dupe', 'severity' => 'block'])
            ->assertStatus(422);
    }

    #[Test]
    public function the_same_word_may_exist_in_two_languages(): void
    {
        $this->word(['word' => 'chutki', 'language' => 'hi']);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/banned-words", [
                'word' => 'chutki', 'language' => 'en', 'severity' => 'flag',
            ])
            ->assertCreated();
    }

    #[Test]
    public function import_adds_what_is_new_and_names_what_it_skipped(): void
    {
        $this->word(['word' => 'already']);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/banned-words/import", [
                'words' => ['already', 'fresh one', 'another'],
            ])
            ->assertOk()
            ->assertJsonPath('data.added', 2)
            ->assertJsonPath('data.skipped', ['already']);
    }

    #[Test]
    public function testing_a_phrase_does_not_write_a_flag(): void
    {
        $this->word(['word' => 'flagme', 'severity' => 'flag']);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/filter-test", ['text' => 'flagme'])
            ->assertJsonPath('data.flag_recorded', false);

        $this->assertSame(0, ContentFlag::count(), 'A dry run must not pollute the review queue.');
    }

    #[Test]
    public function check_and_flag_records_what_it_let_through(): void
    {
        $this->word(['word' => 'flagme', 'severity' => 'flag']);
        $user = $this->makeUser();

        app(ContentFilter::class)->checkAndFlag('flagme please', 'chat', $user->id, 'msg-1');

        $this->assertDatabaseHas('content_flags', [
            'user_id' => $user->id, 'content_id' => 'msg-1', 'rule_matched' => 'flagme',
        ]);
    }

    // -------------------------------------------------------------------- A.5b

    #[Test]
    public function the_queue_orders_critical_before_high(): void
    {
        // Alphabetically "critical" sorts after "high"; the FIELD() ordering exists so it
        // does not.
        $this->makeReport(['priority' => 'low']);
        $this->makeReport(['priority' => 'high']);
        $this->makeReport(['priority' => 'critical']);
        $this->makeReport(['priority' => 'medium']);

        $response = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/reports")
            ->assertOk();

        $this->assertSame(
            ['critical', 'high', 'medium', 'low'],
            array_column($response->json('data'), 'priority'),
        );
    }

    #[Test]
    public function inside_a_priority_the_oldest_report_comes_first(): void
    {
        $newer = $this->makeReport(['priority' => 'high', 'created_at' => now()->subHour()]);
        $older = $this->makeReport(['priority' => 'high', 'created_at' => now()->subDay()]);

        $ids = array_column(
            $this->actingAs($this->superAdmin, 'sanctum-admin')->getJson("{$this->base}/reports")->json('data'),
            'id'
        );

        $this->assertSame([$older->id, $newer->id], $ids);
    }

    #[Test]
    public function the_queue_defaults_to_open_reports(): void
    {
        $this->makeReport(['status' => Report::OPEN]);
        $this->makeReport(['status' => Report::ACTIONED]);
        $this->makeReport(['status' => Report::DISMISSED]);
        $this->makeReport(['status' => Report::ESCALATED]);

        $data = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/reports")->json('data');

        $this->assertCount(2, $data, 'Only open and escalated reports belong in the working queue.');
    }

    #[Test]
    public function the_summary_counts_each_lane(): void
    {
        $this->makeReport(['priority' => 'critical']);
        $this->makeReport(['priority' => 'critical']);
        $this->makeReport(['priority' => 'low']);
        $this->makeReport(['priority' => 'high', 'status' => Report::ACTIONED]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/reports/summary")
            ->assertOk()
            ->assertJsonPath('data.open', 3)
            ->assertJsonPath('data.critical', 2)
            ->assertJsonPath('data.unassigned', 3);
    }

    #[Test]
    public function a_report_detail_shows_the_targets_history(): void
    {
        $target = $this->makeUser();
        UserSanction::create([
            'user_id' => $target->id, 'type' => UserSanction::WARNING,
            'reason' => 'Earlier warning.', 'starts_at' => now()->subMonth(), 'is_active' => false,
        ]);

        $report = $this->makeReport(['target_id' => (string) $target->id]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('data.target.prior_sanctions', 1)
            ->assertJsonPath('data.target.guftagu_id', $target->guftagu_id);
    }

    #[Test]
    public function a_non_user_target_says_so_instead_of_returning_an_empty_object(): void
    {
        $report = $this->makeReport(['target_type' => 'room', 'target_id' => '42']);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('data.target', null)
            ->assertJsonPath('data.target_note', 'Room, message and post targets resolve once those modules land.');
    }

    #[Test]
    public function an_already_resolved_report_cannot_be_actioned_again(): void
    {
        $report = $this->makeReport(['status' => Report::ACTIONED]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", [
                'action' => 'warn', 'note' => 'Second bite.',
            ])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'BAD_REQUEST');
    }

    #[Test]
    public function actioning_without_a_note_is_refused(): void
    {
        $report = $this->makeReport();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", ['action' => 'warn'])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------- A.5c

    #[Test]
    public function a_temp_ban_from_the_queue_suspends_the_account_and_resolves_the_report(): void
    {
        $target = $this->makeUser();
        $report = $this->makeReport(['target_id' => (string) $target->id]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", [
                'action' => 'ban_temp', 'duration_minutes' => 1440, 'note' => 'Harassment, third strike.',
            ])
            ->assertOk();

        $this->assertSame(User::STATUS_SUSPENDED, $target->fresh()->status);
        $this->assertSame(Report::ACTIONED, $report->fresh()->status);
        $this->assertDatabaseHas('user_sanctions', ['user_id' => $target->id, 'type' => UserSanction::TEMP_BAN]);
    }

    #[Test]
    public function a_warning_records_the_sanction_without_locking_the_account(): void
    {
        $target = $this->makeUser();
        $report = $this->makeReport(['target_id' => (string) $target->id]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", [
                'action' => 'warn', 'note' => 'First warning.',
            ])
            ->assertOk();

        $this->assertSame(User::STATUS_ACTIVE, $target->fresh()->status);
        $this->assertDatabaseHas('user_sanctions', ['user_id' => $target->id, 'type' => UserSanction::WARNING]);
    }

    #[Test]
    public function every_action_lands_in_the_moderation_log(): void
    {
        $report = $this->makeReport();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", [
                'action' => 'warn', 'note' => 'Told them once.',
            ])->assertOk();

        $this->assertDatabaseHas('moderation_logs', [
            'admin_user_id' => $this->superAdmin->id, 'action' => 'warn',
        ]);
    }

    #[Test]
    public function reversing_a_ban_actually_lets_the_person_back_in(): void
    {
        $target = $this->makeUser();
        $report = $this->makeReport(['target_id' => (string) $target->id]);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", [
                'action' => 'ban_permanent', 'note' => 'Repeated abuse.',
            ])->assertOk();

        $this->assertSame(User::STATUS_BANNED, $target->fresh()->status);

        $action = ReportAction::where('report_id', $report->id)->firstOrFail();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/actions/{$action->id}/reverse", [
                'reason' => 'Clip shows the other party started it.',
            ])->assertOk();

        // A reversal that leaves the account locked is paperwork.
        $this->assertSame(User::STATUS_ACTIVE, $target->fresh()->status);
        $this->assertNotNull($action->fresh()->reversed_at);
    }

    #[Test]
    public function an_action_cannot_be_reversed_twice(): void
    {
        $report = $this->makeReport();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", ['action' => 'warn', 'note' => 'Warned.'])
            ->assertOk();

        $action = ReportAction::where('report_id', $report->id)->firstOrFail();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/actions/{$action->id}/reverse", ['reason' => 'Mistake.'])
            ->assertOk();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/actions/{$action->id}/reverse", ['reason' => 'Again.'])
            ->assertStatus(400);
    }

    #[Test]
    public function a_moderator_cannot_reverse_an_action(): void
    {
        // Undoing a colleague's decision is oversight, not moderation — it is deliberately
        // outside the Moderator baseline.
        $moderator = $this->makeModerator(['reports.action']);
        $report = $this->makeReport();

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", ['action' => 'warn', 'note' => 'Warned.'])
            ->assertOk();

        $action = ReportAction::where('report_id', $report->id)->firstOrFail();

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/actions/{$action->id}/reverse", ['reason' => 'Undo my own.'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    #[Test]
    public function moderator_stats_report_the_reversal_rate(): void
    {
        $moderator = $this->makeModerator(['reports.action']);

        foreach (range(1, 4) as $i) {
            $report = $this->makeReport();

            $this->actingAs($moderator, 'sanctum-admin')
                ->postJson("{$this->base}/reports/{$report->id}/action", ['action' => 'warn', 'note' => "Warned {$i}."])
                ->assertOk();
        }

        $first = ReportAction::orderBy('id')->firstOrFail();

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->postJson("{$this->base}/moderation/actions/{$first->id}/reverse", ['reason' => 'Overreach.'])
            ->assertOk();

        $row = collect(
            $this->actingAs($this->superAdmin, 'sanctum-admin')
                ->getJson("{$this->base}/moderation/stats")->json('data.moderators')
        )->firstWhere('admin_user_id', $moderator->id);

        $this->assertSame(4, $row['actions']);
        $this->assertSame(1, $row['reversed']);
        $this->assertSame(0.25, $row['reversal_rate']);
    }

    #[Test]
    public function response_time_is_measured_from_when_the_report_arrived(): void
    {
        // Measuring from assignment would hide the exact failure this view exists to
        // catch: a report nobody picked up for a day.
        $moderator = $this->makeModerator(['reports.action']);
        $report = $this->makeReport(['created_at' => now()->subMinutes(600)]);

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", ['action' => 'warn', 'note' => 'Late.'])
            ->assertOk();

        $row = collect(
            $this->actingAs($this->superAdmin, 'sanctum-admin')
                ->getJson("{$this->base}/moderation/stats")->json('data.moderators')
        )->firstWhere('admin_user_id', $moderator->id);

        $this->assertGreaterThanOrEqual(595, $row['avg_response_minutes']);
    }

    #[Test]
    public function the_alerts_endpoint_returns_only_open_criticals(): void
    {
        $this->makeReport(['priority' => 'critical']);
        $this->makeReport(['priority' => 'critical', 'status' => Report::DISMISSED]);
        $this->makeReport(['priority' => 'high']);

        $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/moderation/alerts")
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    // -------------------------------------------------------------------- A.5d

    #[Test]
    public function a_temp_ban_stops_biting_the_moment_it_lapses_even_with_no_job_run(): void
    {
        $target = $this->makeUser();

        UserSanction::create([
            'user_id' => $target->id, 'type' => UserSanction::TEMP_BAN, 'reason' => 'Spam.',
            'starts_at' => now()->subDay(), 'expires_at' => now()->subMinute(), 'is_active' => true,
        ]);
        $target->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        // The column still says suspended, and nothing has run to change it.
        $this->assertSame(User::STATUS_SUSPENDED, $target->fresh()->status);
        $this->assertTrue($target->fresh()->isActive(), 'A lapsed ban must release the account without waiting for a job.');
        $this->assertSame(User::STATUS_ACTIVE, $target->fresh()->effectiveStatus());
    }

    #[Test]
    public function an_unexpired_ban_still_bites(): void
    {
        $target = $this->makeUser();

        UserSanction::create([
            'user_id' => $target->id, 'type' => UserSanction::TEMP_BAN, 'reason' => 'Spam.',
            'starts_at' => now(), 'expires_at' => now()->addDay(), 'is_active' => true,
        ]);
        $target->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->assertFalse($target->fresh()->isActive());
        $this->assertSame(User::STATUS_SUSPENDED, $target->fresh()->effectiveStatus());
    }

    #[Test]
    public function a_permanent_ban_never_lapses(): void
    {
        $target = $this->makeUser();

        UserSanction::create([
            'user_id' => $target->id, 'type' => UserSanction::PERMANENT_BAN, 'reason' => 'Fraud.',
            'starts_at' => now()->subYear(), 'expires_at' => null, 'is_active' => true,
        ]);
        $target->forceFill(['status' => User::STATUS_BANNED])->save();

        $this->assertTrue($target->fresh()->isBanned());
    }

    #[Test]
    public function a_deleted_account_is_never_revived_by_a_sanction_lapsing(): void
    {
        $target = $this->makeUser(User::STATUS_DELETED);

        $this->assertSame(User::STATUS_DELETED, $target->fresh()->effectiveStatus());
        $this->assertFalse($target->fresh()->isActive());
    }

    #[Test]
    public function the_expiry_job_reconciles_the_column_and_logs_the_expiry(): void
    {
        $target = $this->makeUser();

        UserSanction::create([
            'user_id' => $target->id, 'type' => UserSanction::TEMP_BAN, 'reason' => 'Spam.',
            'starts_at' => now()->subDay(), 'expires_at' => now()->subMinute(), 'is_active' => true,
        ]);
        $target->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        Artisan::call('moderation:expire-sanctions');

        $this->assertSame(User::STATUS_ACTIVE, $target->fresh()->status);
        $this->assertDatabaseHas('user_sanctions', ['user_id' => $target->id, 'is_active' => false]);

        // A.5d wants the expiry itself logged, with no actor — time did it, not a person.
        $log = ModerationLog::where('action', 'sanction_expired')->firstOrFail();
        $this->assertNull($log->admin_user_id);
    }

    #[Test]
    public function the_expiry_job_leaves_an_account_locked_when_another_sanction_still_holds_it(): void
    {
        $target = $this->makeUser();

        UserSanction::create([
            'user_id' => $target->id, 'type' => UserSanction::TEMP_BAN, 'reason' => 'Spam.',
            'starts_at' => now()->subDay(), 'expires_at' => now()->subMinute(), 'is_active' => true,
        ]);
        UserSanction::create([
            'user_id' => $target->id, 'type' => UserSanction::PERMANENT_BAN, 'reason' => 'Fraud.',
            'starts_at' => now()->subHour(), 'expires_at' => null, 'is_active' => true,
        ]);
        $target->forceFill(['status' => User::STATUS_BANNED])->save();

        Artisan::call('moderation:expire-sanctions');

        $this->assertSame(User::STATUS_BANNED, $target->fresh()->status);
    }

    #[Test]
    public function the_sanctions_list_separates_the_stored_flag_from_whether_it_still_bites(): void
    {
        $target = $this->makeUser();

        UserSanction::create([
            'user_id' => $target->id, 'type' => UserSanction::TEMP_BAN, 'reason' => 'Spam.',
            'starts_at' => now()->subDay(), 'expires_at' => now()->subMinute(), 'is_active' => true,
        ]);

        $row = $this->actingAs($this->superAdmin, 'sanctum-admin')
            ->getJson("{$this->base}/moderation/sanctions?user_id={$target->id}")
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($row['is_active'], 'The stored flag has not been reconciled yet.');
        $this->assertFalse($row['in_force'], 'But the window has passed, so it no longer bites.');
    }

    #[Test]
    public function the_user_list_reports_the_effective_status_alongside_the_column(): void
    {
        $target = $this->makeUser();

        UserSanction::create([
            'user_id' => $target->id, 'type' => UserSanction::TEMP_BAN, 'reason' => 'Spam.',
            'starts_at' => now()->subDay(), 'expires_at' => now()->subMinute(), 'is_active' => true,
        ]);
        $target->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $row = collect(
            $this->actingAs($this->superAdmin, 'sanctum-admin')
                ->getJson("{$this->base}/users")->json('data')
        )->firstWhere('id', $target->id);

        $this->assertSame('suspended', $row['status']);
        $this->assertSame('active', $row['effective_status']);
    }

    // ------------------------------------------------------------- permissions

    #[Test]
    public function a_moderator_baseline_cannot_manage_the_word_list(): void
    {
        $this->actingAs($this->makeModerator(), 'sanctum-admin')
            ->postJson("{$this->base}/moderation/banned-words", ['word' => 'nope', 'severity' => 'block'])
            ->assertStatus(403);
    }

    #[Test]
    public function a_moderator_baseline_can_read_the_queue_but_not_action_it(): void
    {
        $moderator = $this->makeModerator();
        $report = $this->makeReport();

        $this->actingAs($moderator, 'sanctum-admin')->getJson("{$this->base}/reports")->assertOk();

        $this->actingAs($moderator, 'sanctum-admin')
            ->postJson("{$this->base}/reports/{$report->id}/action", ['action' => 'warn', 'note' => 'Nope.'])
            ->assertStatus(403);
    }
}
