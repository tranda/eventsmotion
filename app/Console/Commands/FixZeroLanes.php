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

        // Find every race that has at least one crew_result with lane=0,
        // then operate per-race so we can pick the best strategy.
        $raceIdsQuery = CrewResult::where('lane', 0)
            ->whereNotNull('race_result_id')
            ->with(['raceResult.discipline']);

        if ($eventId !== null) {
            $raceIdsQuery->whereHas('raceResult.discipline', fn($q) => $q->where('event_id', $eventId));
        }

        $raceIds = $raceIdsQuery->pluck('race_result_id')->unique()->values();
        if ($raceIds->isEmpty()) {
            $this->info('No crew_results with lane = 0 found.');
            return self::SUCCESS;
        }

        $this->info("Affected races: {$raceIds->count()}.");

        $stats = ['shifted' => 0, 'reassigned' => 0, 'skipped' => 0];

        foreach ($raceIds as $raceId) {
            $race = \App\Models\RaceResult::with('discipline.event')->find($raceId);
            if (!$race) continue;
            $event = $race->discipline?->event ?? ($race->event_id ? Event::find($race->event_id) : null);
            if (!$event) {
                $this->warn("  race {$raceId}: cannot resolve event, skipping");
                $stats['skipped']++;
                continue;
            }
            $laneCount = (int) ($event->lane_count ?? 6);

            $crews = CrewResult::where('race_result_id', $raceId)
                ->whereNotNull('lane')
                ->orderBy('lane')
                ->orderBy('id')
                ->get();

            $lanes = $crews->pluck('lane')->map(fn($l) => (int) $l)->all();
            $maxLane = empty($lanes) ? 0 : max($lanes);
            $hasDuplicates = count($lanes) !== count(array_unique($lanes));

            // Strategy 1 — shift +1 the whole race. Safe iff:
            //   - the current lanes are all distinct (no duplicates),
            //   - the highest lane after shift fits within lane_count.
            // This matches the Google Sheets origin where columns were
            // 0-indexed: shifting every row by +1 reconstructs the
            // intended 1..N lane mapping and preserves relative order.
            if (!$hasDuplicates && $maxLane + 1 <= $laneCount) {
                $eventLabel = "event {$event->id}" . ($event->name ? " '{$event->name}'" : '');
                $this->line("  race {$raceId} (#{$race->race_number}, {$race->stage}) [{$eventLabel}]: shift +1 [" . implode(',', $lanes) . " → " . implode(',', array_map(fn($l) => $l + 1, $lanes)) . "]");
                if (!$dryRun) {
                    \Illuminate\Support\Facades\DB::transaction(function () use ($crews) {
                        // Two-pass to avoid unique-constraint collisions on
                        // (race_result_id, lane) if one exists: negate first,
                        // then assign the final positive values.
                        foreach ($crews as $cr) {
                            $cr->lane = -((int) $cr->lane + 1);
                            $cr->save();
                        }
                        foreach ($crews as $cr) {
                            $cr->lane = abs((int) $cr->lane);
                            $cr->save();
                        }
                    });
                }
                $stats['shifted']++;
                continue;
            }

            // Strategy 2 — fallback. Shift not safe (duplicates, or a crew
            // already on the last lane). Move each lane=0 row individually
            // to the first free lane.
            $taken = $crews->where('lane', '!=', 0)->pluck('lane')->map(fn($l) => (int) $l)->all();
            $zeroes = $crews->where('lane', 0);
            $movedAny = false;
            foreach ($zeroes as $cr) {
                $freeLane = null;
                for ($l = 1; $l <= $laneCount; $l++) {
                    if (!in_array($l, $taken, true)) {
                        $freeLane = $l;
                        break;
                    }
                }
                $teamName = $cr->crew?->team?->name ?? '(no team)';
                if ($freeLane === null) {
                    $this->warn("  race {$raceId}: no free lane for cr#{$cr->id} ({$teamName}) — lanes 1..{$laneCount} all taken");
                    continue;
                }
                $eventLabel = "event {$event->id}" . ($event->name ? " '{$event->name}'" : '');
                $this->line("  race {$raceId} (#{$race->race_number}) [{$eventLabel}]: cr#{$cr->id} ({$teamName}) lane 0 → {$freeLane}");
                if (!$dryRun) {
                    $cr->lane = $freeLane;
                    $cr->save();
                }
                $taken[] = $freeLane;
                $movedAny = true;
            }
            if ($movedAny) {
                $stats['reassigned']++;
            } else {
                $stats['skipped']++;
            }
        }

        $this->info("Done — shifted: {$stats['shifted']}, reassigned-individually: {$stats['reassigned']}, skipped: {$stats['skipped']}.");
        if ($dryRun) {
            $this->comment('Re-run without --dry-run to apply.');
        }
        return self::SUCCESS;
    }
}
