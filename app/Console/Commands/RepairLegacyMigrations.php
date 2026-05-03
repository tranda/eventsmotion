<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off repair: marks pre-2026_05 migrations as applied so artisan migrate
 * skips them. Needed on environments whose schema was deployed without going
 * through Laravel migrations (or where stray migration files exist on disk
 * but not in git).
 *
 * Idempotent — running twice is a no-op.
 */
class RepairLegacyMigrations extends Command
{
    protected $signature = 'migrate:repair-legacy';
    protected $description = 'Mark already-applied legacy migrations as tracked so future migrate runs skip them.';

    /** Migrations whose effects are already in production but which are not in the migrations table. */
    private array $legacy = [
        '2023_04_19_004051_create_events_table',
        '2024_01_01_000000_create_disciplines_table',
        '2024_01_01_add_deleted_at_to_events_table',
        '2025_01_01_000002_remove_title_from_race_results_table',
        '2025_09_12_180959_create_race_results_table',
        '2025_09_12_181017_create_crew_results_table',
        '2025_10_12_000000_add_available_to_events_table',
    ];

    public function handle(): int
    {
        if (!Schema::hasTable('migrations')) {
            $this->error('migrations table missing; run: php artisan migrate:install');
            return 1;
        }

        $existing = DB::table('migrations')->pluck('migration')->all();
        $batch = ((int) DB::table('migrations')->max('batch')) + 1;
        $inserted = 0;

        foreach ($this->legacy as $name) {
            if (in_array($name, $existing, true)) {
                $this->line("skip:    {$name} (already tracked)");
                continue;
            }
            DB::table('migrations')->insert(['migration' => $name, 'batch' => $batch]);
            $this->info("marked:  {$name}");
            $inserted++;
        }

        $this->newLine();
        $this->info("Done. {$inserted} legacy migration(s) marked as applied.");
        $this->info('Now run: php artisan migrate');

        return 0;
    }
}
