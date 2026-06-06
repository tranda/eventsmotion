<?php

namespace App\Console\Commands;

use App\Models\CrewResult;
use App\Models\Event;
use Illuminate\Console\Command;

/**
 * Find crew_results with lane = 0 (a legacy bug from Google Sheets sync that
 * sent 0-indexed lane keys) and move each one to the first free lane in its
 * race. lane = 0 rows are invisible in the Schedule Builder Grid (which
 * filters by lane >= 1) but still appear on the public Race Results page,
 * which makes the schedule inconsistent.
 *
 * Usage:
 *   php artisan crew-results:fix-zero-lanes                    (all events)
 *   php artisan crew-results:fix-zero-lanes --event=42         (one event)
 *   php artisan crew-results:fix-zero-lanes --dry-run          (no writes)
 */
class FixZeroLanes extends Command
{
    protected $signature = 'crew-results:fix-zero-lanes
        {--event= : Restrict to a single event id}
        {--dry-run : Report only, do not write}';

    protected $description = 'Move crew_results.lane=0 rows to the first free lane in their race.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $eventId = $this->option('event');
        $eventId = $eventId !== null ? (int) $eventId : null;

        $this->info($dryRun ? 'DRY RUN — no changes will be saved.' : 'LIVE — changes will be saved.');

        $query = CrewResult::where('lane', 0)
            ->with(['raceResult.discipline.event'])
            ->orderBy('id');

        if ($eventId !== null) {
            $query->whereHas('raceResult.discipline', fn($q) => $q->where('event_id', $eventId));
        }

        $offenders = $query->get();
        if ($offenders->isEmpty()) {
            $this->info('No crew_results with lane = 0 found.');
            return self::SUCCESS;
        }

        $this->info("Found {$offenders->count()} crew_results with lane = 0.");

        $fixed = 0;
        $orphans = 0;
        $skippedNoEvent = 0;

        foreach ($offenders as $cr) {
            $race = $cr->raceResult;
            if (!$race) {
                $this->warn("  skip cr#{$cr->id}: race_result not found");
                $orphans++;
                continue;
            }
            $event = $race->discipline?->event ?? ($race->event_id ? Event::find($race->event_id) : null);
            if (!$event) {
                $this->warn("  skip cr#{$cr->id}: cannot resolve event for race {$race->id}");
                $skippedNoEvent++;
                continue;
            }
            $laneCount = (int) ($event->lane_count ?? 6);

            $taken = CrewResult::where('race_result_id', $race->id)
                ->where('id', '!=', $cr->id)
                ->whereNotNull('lane')
                ->pluck('lane')
                ->map(fn($l) => (int) $l)
                ->all();

            $freeLane = null;
            for ($l = 1; $l <= $laneCount; $l++) {
                if (!in_array($l, $taken, true)) {
                    $freeLane = $l;
                    break;
                }
            }

            $teamName = $cr->crew?->team?->name ?? '(no team)';
            if ($freeLane === null) {
                $this->warn("  skip cr#{$cr->id} ({$teamName}) in race {$race->id}: no free lane (lanes 1..{$laneCount} all taken)");
                continue;
            }

            $this->line("  cr#{$cr->id} ({$teamName}) race {$race->id} (#{$race->race_number}): lane 0 → {$freeLane}");
            if (!$dryRun) {
                $cr->lane = $freeLane;
                $cr->save();
            }
            $fixed++;
        }

        $this->info("Done — fixed: {$fixed}, orphans: {$orphans}, missing event: {$skippedNoEvent}.");
        if ($dryRun) {
            $this->comment('Re-run without --dry-run to apply.');
        }
        return self::SUCCESS;
    }
}
