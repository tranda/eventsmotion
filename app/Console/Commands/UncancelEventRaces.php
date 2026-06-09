<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\RaceResult;

class UncancelEventRaces extends Command
{
    /**
     * Flip CANCELLED races back to SCHEDULED for one or more events. Used to
     * undo a partial-sheet auto-cancel that wrongly cancelled races which had
     * recorded times. Crew results/times are never touched — only the status.
     */
    protected $signature = 'races:uncancel {--event= : Event id(s), comma-separated (required)} {--dry-run : Show what would change without writing}';

    protected $description = 'Set CANCELLED race_results back to SCHEDULED for the given event(s)';

    public function handle()
    {
        $eventOpt = $this->option('event');
        if (!$eventOpt) {
            $this->error('--event= is required (comma-separated ids allowed). Refusing to run event-wide.');
            return Command::FAILURE;
        }
        $dryRun = $this->option('dry-run');
        $eventIds = array_values(array_filter(array_map('trim', explode(',', $eventOpt)), 'strlen'));

        $query = RaceResult::with('discipline')
            ->whereHas('discipline', fn($q) => $q->whereIn('event_id', $eventIds))
            ->where('status', 'CANCELLED');

        $races = $query->get();
        if ($races->isEmpty()) {
            $this->info('No CANCELLED races found for event(s): ' . implode(', ', $eventIds));
            return Command::SUCCESS;
        }

        $this->info(($dryRun ? 'DRY RUN — ' : '') . "Found {$races->count()} CANCELLED race(s) for event(s): " . implode(', ', $eventIds));
        $this->newLine();
        $this->table(
            ['race_id', 'race#', 'stage', 'discipline_id', 'crew_results_with_time'],
            $races->map(fn($r) => [
                $r->id,
                $r->race_number,
                $r->stage,
                $r->discipline_id,
                $r->crewResults()->where('time_ms', '>', 0)->count(),
            ])->toArray()
        );

        if ($dryRun) {
            $this->warn('Dry run — no changes written. Re-run without --dry-run to apply.');
            return Command::SUCCESS;
        }

        if (!$this->confirm("Set these {$races->count()} race(s) back to SCHEDULED?")) {
            $this->info('Aborted.');
            return Command::SUCCESS;
        }

        $updated = $query->update(['status' => 'SCHEDULED']);
        $this->info("Updated {$updated} race(s) to SCHEDULED. Crew times left untouched.");
        return Command::SUCCESS;
    }
}
