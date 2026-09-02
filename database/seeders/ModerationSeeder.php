<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use App\Models\BannedWord;
use App\Models\ContentFlag;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Fixtures for epic A.5 — a starter word list plus a queue with something in every lane.
 *
 *     php artisan db:seed --class=ModerationSeeder
 *
 * The word list is deliberately tame: scam/spam/contact-sharing patterns and a couple of
 * mild profanities. It exists so the filter and the queue can be exercised end to end, not
 * as the real policy list — that comes from the client's trust & safety team.
 */
class ModerationSeeder extends Seeder
{
    /** [word, language, severity, replacement, scope, is_regex] */
    public const WORDS = [
        // Fraud and off-platform payment — the highest-value things to block outright.
        ['sendmoney', 'en', 'block', null, [], false],
        ['paytm me', 'en', 'block', null, ['chat', 'dm'], false],
        ['free coins', 'en', 'block', null, [], false],
        ['telegram', 'en', 'flag', null, ['chat', 'dm', 'bio'], false],

        // Contact sharing — a 10-digit Indian mobile number typed into chat or a bio.
        ['(?<!\d)[6-9]\d{9}(?!\d)', 'en', 'block', null, ['chat', 'dm', 'bio'], true],

        // Mild profanity: delivered, but cleaned up and flagged.
        ['idiot', 'en', 'replace', '****', ['chat'], false],
        ['stupid', 'en', 'replace', '****', ['chat'], false],
        ['bakwas', 'hi', 'flag', null, ['chat'], false],

        // Room names get a stricter hand — they are public and persistent.
        ['xxx', 'en', 'block', null, ['room_name'], false],
        ['18+', 'en', 'block', null, ['room_name'], false],
    ];

    /** [category, priority, status, description, days ago] */
    public const REPORTS = [
        ['harassment', 'critical', Report::OPEN, 'Kept abusing other speakers after being asked to stop. Audio clip attached.', 0],
        ['nudity', 'critical', Report::OPEN, 'Profile photo is explicit.', 1],
        ['fraud', 'high', Report::OPEN, 'Asking people in the room to send money on UPI for "double coins".', 0],
        ['spam', 'high', Report::ASSIGNED, 'Pasting the same Telegram link in every room.', 2],
        ['abuse', 'medium', Report::OPEN, 'Verbal abuse in Hindi during a music room.', 3],
        ['underage', 'critical', Report::ESCALATED, 'User says in their bio they are 15.', 4],
        ['other', 'low', Report::OPEN, 'Room name is misleading.', 5],
        ['spam', 'medium', Report::ACTIONED, 'Bot-like account spamming gift requests.', 8],
        ['harassment', 'low', Report::DISMISSED, 'Reported for "being annoying" — no policy breach.', 9],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->error('ModerationSeeder refuses to run outside local/testing.');

            return;
        }

        $admin = AdminUser::query()->orderBy('id')->first();

        foreach (self::WORDS as [$word, $language, $severity, $replacement, $scope, $isRegex]) {
            BannedWord::query()->firstOrCreate(
                ['word' => $word, 'language' => $language],
                [
                    'severity'    => $severity,
                    'replacement' => $replacement,
                    'scope'       => $scope,
                    'is_regex'    => $isRegex,
                    'is_active'   => true,
                    'created_by'  => $admin?->id,
                ],
            );
        }

        $this->command->info(count(self::WORDS).' banned words in place.');

        $users = User::query()->orderBy('id')->get();

        if ($users->isEmpty()) {
            $this->command->warn('No app users — run DemoUsersSeeder first for reports to point at real people.');

            return;
        }

        if (Report::query()->exists()) {
            $this->command->warn('Reports already present — left alone.');

            return;
        }

        foreach (self::REPORTS as $index => [$category, $priority, $status, $description, $daysAgo]) {
            // Reporter and target are different people, or the queue makes no sense.
            $target = $users[$index % $users->count()];
            $reporter = $users[($index + 3) % $users->count()];

            $resolved = in_array($status, [Report::ACTIONED, Report::DISMISSED], true);

            Report::create([
                'reporter_id'  => $reporter->id === $target->id ? null : $reporter->id,
                'target_type'  => 'user',
                'target_id'    => (string) $target->id,
                'category'     => $category,
                'description'  => $description,
                'evidence_urls' => $index % 3 === 0 ? ['https://cdn.example.com/evidence/'.($index + 1).'.png'] : null,
                'priority'     => $priority,
                'status'       => $status,
                'assigned_to'  => $status === Report::OPEN ? null : $admin?->id,
                'assigned_at'  => $status === Report::OPEN ? null : now()->subDays($daysAgo)->addHours(2),
                'resolved_by'  => $resolved ? $admin?->id : null,
                'resolved_at'  => $resolved ? now()->subDays($daysAgo)->addHours(5) : null,
                'resolution_note' => $resolved ? 'Handled per policy.' : null,
                'created_at'   => now()->subDays($daysAgo)->subHours(random_int(1, 20)),
            ]);
        }

        ContentFlag::create([
            'content_type' => 'chat',
            'content_id'   => 'demo-message-1',
            'user_id'      => $users->first()->id,
            'flagged_by'   => 'system',
            'rule_matched' => 'idiot',
            'confidence'   => 100,
            'excerpt'      => 'stop being an idiot yaar',
            'status'       => 'open',
        ]);

        $this->command->info(count(self::REPORTS).' reports seeded across every lane.');
    }
}
