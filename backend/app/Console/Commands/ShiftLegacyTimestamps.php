<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * One-off: moves rows written before the timezone change onto East
 * African Time.
 *
 * WHY THIS IS NEEDED
 *
 * Laravel stores what you give it. While APP_TIMEZONE was UTC, every
 * timestamp was written as the UTC wall clock - a report created at
 * 23:08 in Nairobi was saved as "20:08". Now that the app runs on
 * Africa/Nairobi, those same values are read back as EAT, so they render
 * three hours earlier than they actually happened. Rows written after
 * the cutover are already correct.
 *
 * The fix is to add three hours to the legacy values, which turns
 * "20:08 interpreted as EAT" back into 23:08 - the real moment.
 *
 * WHY A COMMAND AND NOT A MIGRATION
 *
 * A migration would run on every fresh database forever, including the
 * test database, where shifting nothing is a no-op today but becomes a
 * confusing moving part later. This is data repair for one specific
 * database, run once, with a dry run and a written marker file so it
 * cannot silently run twice.
 *
 *   php artisan app:shift-legacy-timestamps            # report only
 *   php artisan app:shift-legacy-timestamps --apply    # do it
 *
 * Kenya has never observed daylight saving, so a flat +3 hours is exact
 * rather than an approximation.
 */
class ShiftLegacyTimestamps extends Command
{
    protected $signature = 'app:shift-legacy-timestamps
                            {--apply : Write the changes. Without this the command only reports what it would do}
                            {--before=2026-09-19 09:03:00 : The moment APP_TIMEZONE changed to Africa/Nairobi, expressed in the OLD timezone (UTC). Rows created before this were written under UTC}
                            {--force : Run again even though the marker file says this has already happened. Only for a database restored from a pre-fix backup}';

    protected $description = 'Shift rows written before the UTC -> Africa/Nairobi switch by +3 hours.';

    /**
     * How far the legacy values are behind East African Time.
     */
    private const SHIFT_HOURS = 3;

    /**
     * Every date/time column in the application's own tables.
     *
     * Listed explicitly rather than discovered from information_schema so
     * that this is reviewable: a new column is a deliberate decision
     * here, not something that silently joins the next run.
     *
     * Excluded on purpose: failed_jobs, password_reset_tokens,
     * personal_access_tokens and sessions. Those are framework
     * infrastructure, not application history - shifting a row that
     * represents "this token expires at X" would extend its life, and
     * the sessions table stores a unix integer, not a datetime.
     */
    private const COLUMNS = [
        'users' => ['created_at', 'updated_at', 'email_verified_at', 'suspended_at'],
        'departments' => ['created_at', 'updated_at'],
        'categories' => ['created_at', 'updated_at'],
        'lost_items' => ['created_at', 'updated_at', 'ai_processed_at', 'lost_at', 'deleted_at'],
        'found_items' => ['created_at', 'updated_at', 'ai_processed_at', 'found_at', 'verified_at', 'deleted_at'],
        'item_images' => ['created_at', 'updated_at'],
        'item_ai_analysis' => ['created_at', 'updated_at'],
        'item_embeddings' => ['created_at', 'updated_at'],
        'item_matches' => ['created_at', 'updated_at', 'dismissed_at'],
        'claims' => ['created_at', 'updated_at', 'submitted_at', 'reviewed_at', 'completed_at'],
        'claim_verifications' => ['created_at', 'updated_at'],
        'notifications' => ['created_at', 'updated_at', 'read_at'],
        'messages' => ['created_at', 'updated_at', 'read_at'],
        'audit_logs' => ['created_at', 'updated_at'],
        'item_status_history' => ['created_at', 'updated_at'],
    ];

    public function handle(): int
    {
        /**
         * The same value the cache below uses, kept in one place: the
         * cutoff is what makes the "before" boundary and the marker file
         * agree about which rows are legacy.
         */
        $cutoff = (string) $this->option('before');
        $apply = (bool) $this->option('apply');
        $marker = $this->markerPath();

        $this->newLine();
        $this->line('  Rows created before: <comment>'.$cutoff.'</> (UTC wall clock)');
        $this->line('  Shift applied:       <comment>+'.self::SHIFT_HOURS.' hours</> (Africa/Nairobi)');
        $this->line('  Mode:                <comment>'.($apply ? 'APPLY' : 'dry run').'</>');
        $this->newLine();

        if (File::exists($marker) && ! $this->option('force')) {
            $previous = json_decode(File::get($marker), true);

            $this->error('This backfill has already been applied to this database.');
            $this->line('  Applied at: '.($previous['applied_at'] ?? 'unknown'));

            /**
             * Refusing outright is the point. Running twice would push
             * every legacy row six hours forward instead of three, and
             * nothing in the data would show it had gone wrong - the
             * timestamps would simply be quietly wrong again.
             */
            $this->line('  Pass --force only if this database was restored from a pre-fix backup.');
            $this->newLine();

            return self::FAILURE;
        }

        $plan = $this->plan($cutoff);

        if ($plan === []) {
            $this->info('Nothing to do: no rows are older than the cutoff.');

            return self::SUCCESS;
        }

        $this->table(
            ['Table', 'Rows', 'Columns'],
            array_map(fn (string $table, array $row) => [
                $table,
                $row['count'],
                implode(', ', $row['columns']),
            ], array_keys($plan), array_values($plan)),
        );

        $total = array_sum(array_column($plan, 'count'));

        if (! $apply) {
            $this->newLine();
            $this->line("  <comment>{$total}</comment> rows would be shifted across ".count($plan).' tables.');
            $this->line('  Re-run with <comment>--apply</comment> to write the changes.');
            $this->newLine();

            return self::SUCCESS;
        }

        /**
         * One transaction: a half-shifted database, with some tables three
         * hours ahead of others, is worse than an untouched one - the
         * discrepancy would show up as matches appearing before the
         * reports they belong to.
         */
        DB::transaction(function () use ($plan, $cutoff) {
            foreach ($plan as $table => $row) {
                foreach ($row['columns'] as $column) {
                    /**
                     * The WHERE is evaluated against the original values,
                     * so shifting created_at and filtering on created_at
                     * in the same statement touches each row exactly once
                     * rather than re-shifting rows it just moved.
                     */
                    DB::table($table)
                        ->where('created_at', '<', $cutoff)
                        ->update([
                            $column => DB::raw(
                                'DATE_ADD(`'.$column.'`, INTERVAL '.self::SHIFT_HOURS.' HOUR)'
                            ),
                        ]);
                }
            }
        });

        File::put($marker, json_encode([
            'applied_at' => now()->toIso8601String(),
            'cutoff' => $cutoff,
            'shift_hours' => self::SHIFT_HOURS,
            'rows_shifted' => $total,
            'tables' => array_map(fn (array $row) => $row['count'], $plan),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->info("Shifted {$total} rows.");
        $this->line('  Marker written to '.$marker);
        $this->line('  Re-run this command any time; it will now refuse to repeat itself.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Which tables and columns actually have legacy rows.
     *
     * Only tables with at least one row older than the cutoff are
     * reported - a plan padded with empty tables hides the ones that
     * matter.
     *
     * @return array<string, array{count: int, columns: array<int, string>}>
     */
    private function plan(string $cutoff): array
    {
        $plan = [];

        foreach (self::COLUMNS as $table => $columns) {
            $count = DB::table($table)->where('created_at', '<', $cutoff)->count();

            if ($count === 0) {
                continue;
            }

            $plan[$table] = ['count' => $count, 'columns' => $columns];
        }

        return $plan;
    }

    /**
     * Storage rather than the cache store on purpose: `cache:clear`
     * would erase a cache-based guard and quietly re-arm this command,
     * and the file also doubles as a record of what was done and when.
     */
    private function markerPath(): string
    {
        return storage_path('app/legacy-timestamps-shifted.json');
    }
}
