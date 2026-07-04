<?php

namespace App\Services\Schedule;

use App\Models\Crew;
use App\Models\CrewResult;
use App\Models\Discipline;
use App\Models\Event;
use App\Models\RaceResult;
use App\Models\ScheduleBlock;
use App\Models\ScheduleSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use OutOfRangeException;

/**
 * Generates the full race schedule for an event from its days, blocks,
 * registered crews, and IDBF race plans.
 *
 * Replaces any existing RaceResult rows with status=SCHEDULED for the event
 * and writes draft state. Times are derived from block.start_time +
 * gap_seconds * (Nth race in block). Race numbers are assigned chronologically
 * across the whole event after placement.
 */
class ScheduleGeneratorService
{
    /** Number of `auto:*` snapshots retained per event; older ones pruned after each capture. */
    private const AUTO_SNAPSHOT_RETENTION = 10;

    public function __construct(
        private IdbfRacePlans $plans,
        private ScheduleSnapshotService $snapshots,
    ) {}

    /**
     * Take an event_grid snapshot before a mutating operation so the
     * operator can roll back if the new plan is worse. Called at the top
     * of generate() / regenerateDiscipline() / recomputeAllBlockTimes() /
     * shiftFrom() / copyDayOrder().
     *
     * Named `auto: {reason} @ {timestamp}` so it's obvious which rows are
     * auto-captured and safe to prune. Manual-named snapshots (any name
     * without an `auto:` prefix) are never touched by the retention pass.
     *
     * Runs inside the caller's DB::transaction — if the caller rolls back
     * (e.g. dry_run), the snapshot row rolls back with it. No orphans.
     */
    private function autoSnapshot(Event $event, string $reason): void
    {
        // Skip if the event has no days yet — nothing to snapshot.
        $event->loadMissing('eventDays');
        if ($event->eventDays->isEmpty()) {
            return;
        }

        try {
            $payload = $this->snapshots->capture($event, 'event_grid');
        } catch (\Throwable $e) {
            // Capture failing must not block a regenerate — log and move on.
            \Log::warning('Auto-snapshot capture failed', [
                'event_id' => $event->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        ScheduleSnapshot::create([
            'event_id' => $event->id,
            'category' => 'event_grid',
            'day' => null,
            'name' => 'auto: ' . $reason . ' @ ' . Carbon::now()->format('Y-m-d H:i:s'),
            'payload' => $payload,
            'created_by' => optional(auth()->user())->id,
        ]);

        // Retain last N auto-snapshots per event. Manual (non-auto:*)
        // snapshots are never touched.
        $keepIds = ScheduleSnapshot::where('event_id', $event->id)
            ->where('name', 'like', 'auto:%')
            ->orderByDesc('id')
            ->limit(self::AUTO_SNAPSHOT_RETENTION)
            ->pluck('id');
        ScheduleSnapshot::where('event_id', $event->id)
            ->where('name', 'like', 'auto:%')
            ->whereNotIn('id', $keepIds)
            ->delete();
    }

    /**
     * Refuse a mutating operation when any race in the affected scope is
     * currently IN_PROGRESS — regenerating over a running race would
     * delete its lane assignments and results.
     *
     * @throws InvalidArgumentException
     */
    private function refuseIfAnyRaceInProgress(Event $event, ?int $disciplineId = null): void
    {
        $query = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->where('status', 'IN_PROGRESS');
        if ($disciplineId !== null) {
            $query->where('discipline_id', $disciplineId);
        }
        $running = $query->first();
        if ($running) {
            throw new InvalidArgumentException(
                "Cannot regenerate: race {$running->race_number} ({$running->stage}) is currently IN_PROGRESS."
            );
        }
    }

    /**
     * Regenerate the full schedule for an event.
     *
     * @param bool        $clean When true, skips the snapshot/restore step
     *                           — every race is freshly placed from block
     *                           .start, ignoring any prior drag-edited
     *                           ordering. Use to "start over" when the
     *                           schedule has gone sideways.
     * @param string|null $day   YYYY-MM-DD. When set, only regenerates
     *                           disciplines whose first matching block
     *                           lands on that day. Other days' races are
     *                           preserved (not deleted, not re-placed).
     * @throws InvalidArgumentException if no event days/blocks exist
     */
    public function generate(Event $event, bool $clean = false, ?string $day = null): GenerationResult
    {
        $this->refuseIfAnyRaceInProgress($event);

        $eventDays = $event->eventDays()->with('blocks')->get();
        if ($eventDays->isEmpty()) {
            throw new InvalidArgumentException('Event has no days configured. Add days and blocks before generating.');
        }

        $orderedBlocks = $this->orderedBlocks($eventDays);
        if (empty($orderedBlocks)) {
            throw new InvalidArgumentException('Event days have no schedule blocks configured.');
        }

        // Inactive disciplines are excluded — generator won't create races for
        // them, and the Grid filters them out too. Operator can flip them back
        // to active (Plan & Seeds) or bump min_crews_per_race in Setup to
        // bring them back into the schedule.
        $disciplines = $event->disciplines()
            ->where('status', 'active')
            ->with(['crews', 'progression'])
            ->orderBy('id')
            ->get();

        // Per-day mode: narrow the discipline list to those whose first
        // matching block lands on the requested day. We delete + regen only
        // for those disciplines, leaving the rest of the schedule alone.
        if ($day !== null) {
            $disciplines = $disciplines->filter(function ($d) use ($orderedBlocks, $day) {
                $primary = $this->primaryDayForDiscipline($d, $orderedBlocks);
                return $primary === $day;
            })->values();
            if ($disciplines->isEmpty()) {
                $result = new GenerationResult();
                $result->addWarning("No active disciplines schedule into day {$day}.");
                return $result;
            }
        }

        $result = new GenerationResult();

        $defaultRounds = (int) ($event->default_rounds ?? 3);
        DB::transaction(function () use ($event, $disciplines, $orderedBlocks, $defaultRounds, $result, $clean, $day) {
            $this->autoSnapshot($event, $day ? "regenerate day {$day}" : 'regenerate');

            $preservedTimes = $clean ? [] : $this->snapshotRaceTimes($event);

            if ($day === null) {
                // Full-event regenerate: wipe all SCHEDULED races.
                $this->deleteScheduledRaces($event);
                // Sweep stale auto-inserted boarding breaks — the next
                // placement pass will insert fresh ones where needed.
                // Manual/named breaks (lunch, ceremonies) are left alone.
                RaceResult::where('event_id', $event->id)
                    ->where('entry_type', 'break')
                    ->where('label', 'like', 'auto: Boarding%')
                    ->delete();
            } else {
                // Per-day regenerate: wipe only the targeted disciplines'
                // races; other disciplines (other days) untouched.
                $disciplineIds = $disciplines->pluck('id')->all();
                RaceResult::whereIn('discipline_id', $disciplineIds)
                    ->where('status', 'SCHEDULED')
                    ->delete();
                // Sweep auto-boarding breaks landing on this day.
                $dayStart = Carbon::parse("{$day} 00:00:00");
                $dayEnd = $dayStart->copy()->addDay();
                RaceResult::where('event_id', $event->id)
                    ->where('entry_type', 'break')
                    ->where('label', 'like', 'auto: Boarding%')
                    ->whereBetween('race_time', [$dayStart, $dayEnd])
                    ->delete();
            }

            foreach ($disciplines as $discipline) {
                $this->generateForDiscipline($discipline, $event->lane_count, $defaultRounds, $result);
            }

            if (!$clean) {
                $this->restoreRaceTimes($event, $preservedTimes);
            }
            $this->placeRacesIntoBlocks($event, $orderedBlocks, $result);
            $this->recomputeAllBlockTimes($event);
        });

        return $result;
    }

    /**
     * Reorder target-day races to match the sequence of source-day races,
     * pairing by (boat_group, age_group, gender_group, stage) — i.e.
     * everything except distance. After matching, re-times the target day
     * via recomputeAllBlockTimes so the schedule lines up with the source
     * day's pattern.
     *
     * - Pairing is FIFO on both sides: the Nth source race of a given key
     *   claims the Nth target race of the same key.
     * - Source races with no target match are skipped.
     * - Target races with no source match are appended at the end (in
     *   their existing order).
     * - Breaks are not reordered; they keep their times and the recompute
     *   pass slots races around them.
     */
    public function copyDayOrder(Event $event, string $sourceDay, string $targetDay): array
    {
        if ($sourceDay === $targetDay) {
            throw new InvalidArgumentException('Source and target days must differ.');
        }
        $this->refuseIfAnyRaceInProgress($event);
        $stats = ['matched' => 0, 'unmatched_source' => 0, 'unmatched_target' => 0];

        $matchKey = fn($race) => strtolower(implode('|', [
            (string) optional($race->discipline)->boat_group,
            (string) optional($race->discipline)->age_group,
            (string) optional($race->discipline)->gender_group,
            (string) $race->stage,
        ]));

        DB::transaction(function () use ($event, $sourceDay, $targetDay, $matchKey, &$stats) {
            $this->autoSnapshot($event, "copy day order {$sourceDay} to {$targetDay}");

            $sourceStart = Carbon::parse("{$sourceDay} 00:00:00");
            $sourceEnd = $sourceStart->copy()->addDay();
            $targetStart = Carbon::parse("{$targetDay} 00:00:00");
            $targetEnd = $targetStart->copy()->addDay();

            $sourceRaces = RaceResult::whereHas(
                'discipline',
                fn($q) => $q->where('event_id', $event->id),
            )
                ->where('status', 'SCHEDULED')
                ->whereNotNull('race_time')
                ->whereBetween('race_time', [$sourceStart, $sourceEnd])
                ->with('discipline')
                ->orderBy('race_time')
                ->orderBy('id')
                ->get();

            $targetRaces = RaceResult::whereHas(
                'discipline',
                fn($q) => $q->where('event_id', $event->id),
            )
                ->where('status', 'SCHEDULED')
                ->whereNotNull('race_time')
                ->whereBetween('race_time', [$targetStart, $targetEnd])
                ->with('discipline')
                ->orderBy('race_time')
                ->orderBy('id')
                ->get();

            // Bucket target races by match key, FIFO queue per key.
            $targetByKey = [];
            foreach ($targetRaces as $r) {
                $targetByKey[$matchKey($r)][] = $r;
            }

            // Walk source races in order; pull the first target race with a
            // matching key. Stamp it with a temporary sort_time so the
            // recompute pass can re-order them deterministically.
            $base = Carbon::parse("{$targetDay} 00:00:00");
            $orderedTargets = [];
            $seenTargetIds = [];
            $i = 0;
            foreach ($sourceRaces as $src) {
                $k = $matchKey($src);
                if (empty($targetByKey[$k])) {
                    $stats['unmatched_source']++;
                    continue;
                }
                $tgt = array_shift($targetByKey[$k]);
                $orderedTargets[] = $tgt;
                $seenTargetIds[$tgt->id] = true;
                // Use a fake monotonically-increasing time so the recompute
                // step sorts them in this order. Real times come from the
                // recompute (block.start + N*gap).
                $tgt->race_time = $base->copy()->addSeconds($i * 60);
                $tgt->save();
                $stats['matched']++;
                $i++;
            }
            // Append unmatched target races at the end, preserving their
            // relative order from the original race_time sort.
            foreach ($targetRaces as $r) {
                if (isset($seenTargetIds[$r->id])) continue;
                $r->race_time = $base->copy()->addSeconds($i * 60);
                $r->save();
                $stats['unmatched_target']++;
                $i++;
            }

            // Recompute all block times so the day uses canonical
            // block.start + N*gap slots (with breaks honoured).
            $this->recomputeAllBlockTimes($event);
        });

        return $stats;
    }

    /**
     * Date (YYYY-MM-DD) of the first ordered block whose filters match this
     * discipline pre-generation. Returns null if no block matches.
     */
    private function primaryDayForDiscipline($discipline, array $orderedBlocks): ?string
    {
        foreach ($orderedBlocks as $block) {
            if ($this->disciplineMatchesBlockPreGen($discipline, $block)) {
                $day = $block->eventDay;
                if (!$day) continue;
                $date = $day->date instanceof \Carbon\Carbon
                    ? $day->date->toDateString()
                    : (string) $day->date;
                return $date;
            }
        }
        return null;
    }

    /**
     * Pre-generation block match: same as the matchesBlock used during
     * placement but skips the stage filter (we don't have race rows yet).
     */
    private function disciplineMatchesBlockPreGen($discipline, $block): bool
    {
        $gf = $block->gender_filter;
        if (is_array($gf) && !empty($gf)) {
            $map = ['M' => 'Open', 'W' => 'Women', 'X' => 'Mixed'];
            $needles = array_map(fn($v) => $map[strtoupper((string) $v)] ?? $v, $gf);
            if (!in_array($discipline->gender_group, $needles, true)) return false;
        }
        $df = $block->distance_filter;
        if (is_array($df) && !empty($df)) {
            $needles = array_values(array_filter(
                array_map(fn($v) => preg_replace('/\D/', '', (string) $v), $df),
                fn($v) => $v !== '',
            ));
            if (!in_array((string) $discipline->distance, $needles, true)) return false;
        }
        $cf = $block->competition_filter;
        if (is_array($cf) && !empty($cf)) {
            $c = strtolower((string) ($discipline->competition ?? ''));
            $needles = array_map(fn($v) => strtolower((string) $v), $cf);
            if (!in_array($c, $needles, true)) return false;
        }
        return true;
    }

    /**
     * Snapshot current race times keyed by "{discipline_id}|{stage}". Used by
     * generate() to preserve manual drag-reorders across a full regenerate
     * — see comment in generate() for details.
     *
     * @return array<string, \Carbon\Carbon>
     */
    private function snapshotRaceTimes(Event $event): array
    {
        return RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->where('status', 'SCHEDULED')
            ->whereNotNull('race_time')
            ->get()
            ->mapWithKeys(fn($r) => [
                "{$r->discipline_id}|{$r->stage}" => Carbon::parse($r->race_time),
            ])
            ->toArray();
    }

    /**
     * Apply a snapshot from snapshotRaceTimes() to freshly-created races
     * (race_time = null) where (discipline_id, stage) matches.
     */
    private function restoreRaceTimes(Event $event, array $preservedTimes): void
    {
        if (empty($preservedTimes)) {
            return;
        }
        $races = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->where('status', 'SCHEDULED')
            ->whereNull('race_time')
            ->get();
        foreach ($races as $race) {
            $key = "{$race->discipline_id}|{$race->stage}";
            if (isset($preservedTimes[$key])) {
                $race->race_time = $preservedTimes[$key];
                $race->save();
            }
        }
    }

    /**
     * Regenerate just one discipline's races, leaving the rest of the event untouched.
     * Refuses if any race in the discipline is past SCHEDULED status (already running or done).
     */
    public function regenerateDiscipline(Discipline $discipline): GenerationResult
    {
        $event = $discipline->event;
        if (!$event) {
            throw new InvalidArgumentException("Discipline {$discipline->id} has no event");
        }
        $this->refuseIfAnyRaceInProgress($event, $discipline->id);
        if (($discipline->status ?? 'active') !== 'active') {
            throw new InvalidArgumentException(
                "Cannot regenerate {$discipline->getDisplayName()}: discipline is inactive."
            );
        }
        $hasNonScheduled = RaceResult::where('discipline_id', $discipline->id)
            ->where('status', '!=', 'SCHEDULED')
            ->exists();
        if ($hasNonScheduled) {
            throw new InvalidArgumentException(
                "Cannot regenerate {$discipline->getDisplayName()}: some races have already started or finished. Edit them manually."
            );
        }

        $eventDays = $event->eventDays()->with('blocks')->get();
        $orderedBlocks = $this->orderedBlocks($eventDays);
        if (empty($orderedBlocks)) {
            throw new InvalidArgumentException('Event has no schedule blocks configured.');
        }

        $result = new GenerationResult();

        $defaultRounds = (int) ($event->default_rounds ?? 3);
        DB::transaction(function () use ($discipline, $event, $orderedBlocks, $defaultRounds, $result) {
            $this->autoSnapshot($event, "regenerate discipline {$discipline->id}");

            RaceResult::where('discipline_id', $discipline->id)
                ->where('status', 'SCHEDULED')
                ->delete();

            $this->generateForDiscipline(
                $discipline->fresh(['crews', 'progression']),
                $event->lane_count,
                $defaultRounds,
                $result,
            );
            $this->placeRacesIntoBlocks($event, $orderedBlocks, $result);
            $this->recomputeAllBlockTimes($event);
        });

        return $result;
    }

    /** Picks the plan, ensures crews have seeds, and creates RaceResult+CrewResult rows. */
    private function generateForDiscipline(
        Discipline $discipline,
        int $laneCount,
        int $defaultRounds,
        GenerationResult $result,
    ): void {
        $crews = $discipline->crews()->orderBy('id')->get();
        $crewCount = $crews->count();

        if ($crewCount < 2) {
            $result->addWarning("Skipped {$discipline->getDisplayName()}: needs at least 2 crews (has {$crewCount}).");
            return;
        }

        $override = optional($discipline->progression)->race_plan_code;

        // CUSTOM plan: use organizer-defined stage list, no IDBF seeding.
        if ($override === 'CUSTOM') {
            $stages = optional($discipline->progression)->custom_stages;
            if (!is_array($stages) || empty($stages)) {
                $result->addWarning("{$discipline->getDisplayName()}: CUSTOM plan has no stages defined.");
                return;
            }
            $disciplineRaceCount = 0;
            foreach ($stages as $stageName) {
                RaceResult::create([
                    'race_number' => 0,
                    'discipline_id' => $discipline->id,
                    'race_time' => null,
                    'stage' => (string) $stageName,
                    'status' => 'SCHEDULED',
                ]);
                $disciplineRaceCount++;
                $result->racesCreated++;
            }
            $result->racesPerDiscipline[$discipline->id] = $disciplineRaceCount;
            return;
        }

        // ROUNDS plan: when all crews fit on the course (crewCount <= laneCount)
        // we don't need elimination structure (heats/repechages/semis). Create
        // N rounds with all crews racing each round, centre-out lane seeding,
        // rotated per round so crews don't always sit in the same lane.
        // Skipped if the organizer set an explicit plan-code override.
        if (!$override && $crewCount <= $laneCount && $defaultRounds > 0) {
            $this->generateRoundsForDiscipline(
                $discipline,
                $laneCount,
                $crewCount,
                $defaultRounds,
                $result,
            );
            return;
        }

        try {
            $plan = $this->resolvePlan($discipline, $laneCount, $crewCount);
        } catch (OutOfRangeException $e) {
            $result->addWarning("No IDBF plan for {$discipline->getDisplayName()}: {$crewCount} crews on {$laneCount} lanes.");
            return;
        } catch (InvalidArgumentException $e) {
            $result->addWarning("{$discipline->getDisplayName()}: {$e->getMessage()}");
            return;
        }

        $this->ensureCrewSeeds($discipline, $crews);
        $crews = $discipline->crews()->orderBy('id')->get(); // reload with seeds
        $crewsBySeed = $crews->keyBy('seed_number');

        $disciplineRaceCount = 0;
        foreach ($plan->stages() as $stageName) {
            $race = RaceResult::create([
                'race_number' => 0, // placeholder, renumbered later
                'discipline_id' => $discipline->id,
                'race_time' => null,
                'stage' => $stageName,
                'status' => 'SCHEDULED',
            ]);
            $disciplineRaceCount++;
            $result->racesCreated++;

            if ($this->isInitialStage($plan, $stageName)) {
                $heatNum = $this->extractHeatNumber($stageName);
                $laneSeeding = $plan->heatLaneSeeding($heatNum, $crewCount);
                foreach ($laneSeeding as $lane => $seedNumber) {
                    if ($seedNumber === null) {
                        continue;
                    }
                    $crew = $crewsBySeed->get($seedNumber);
                    if (!$crew) {
                        continue;
                    }
                    CrewResult::create([
                        'race_result_id' => $race->id,
                        'crew_id' => $crew->id,
                        'lane' => $lane,
                        'status' => null,
                    ]);
                    $result->crewLanesAssigned++;
                }
            }
            // Non-initial stages get an empty race; LaneSeeder fills lanes after prior round runs.
        }

        $result->racesPerDiscipline[$discipline->id] = $disciplineRaceCount;
    }

    /**
     * @throws OutOfRangeException
     * @throws InvalidArgumentException
     */
    private function resolvePlan(Discipline $discipline, int $laneCount, int $crewCount): RacePlan
    {
        $override = optional($discipline->progression)->race_plan_code;
        if ($override) {
            $plan = $this->plans->getPlan($override);
            if ($plan->laneCount() !== $laneCount) {
                throw new InvalidArgumentException("override plan {$override} is for {$plan->laneCount()} lanes, event has {$laneCount}");
            }
            if (!$plan->supportsCrewCount($crewCount)) {
                [$min, $max] = $plan->crewCountRange();
                throw new InvalidArgumentException("override plan {$override} supports {$min}-{$max} crews, has {$crewCount}");
            }
            return $plan;
        }
        return $this->plans->pickPlan($laneCount, $crewCount);
    }

    /**
     * Fills in missing seed_number values with a deterministic random shuffle
     * (seeded by discipline.id) over the unused seed slots. Pre-existing seeds
     * set by the organizer are preserved.
     */
    private function ensureCrewSeeds(Discipline $discipline, $crews): void
    {
        $existingSeeds = $crews->pluck('seed_number')->filter()->values()->all();
        $totalSeeds = $crews->count();
        $availableSeeds = array_values(array_diff(range(1, $totalSeeds), $existingSeeds));

        if (empty($availableSeeds)) {
            return;
        }

        mt_srand($discipline->id);
        shuffle($availableSeeds);

        foreach ($crews as $crew) {
            if ($crew->seed_number === null) {
                $crew->seed_number = array_shift($availableSeeds);
                $crew->save();
            }
        }
    }

    /** True when crews are seeded into this stage from initial seeds (heats / rounds). */
    private function isInitialStage(RacePlan $plan, string $stageName): bool
    {
        if ($plan->isRoundsPlan()) {
            return str_starts_with($stageName, 'Round');
        }
        return str_starts_with($stageName, 'Heat');
    }

    /** "Heat 3" → 3, "Round 2" → 2 */
    private function extractHeatNumber(string $stageName): int
    {
        if (preg_match('/(\d+)$/', $stageName, $m)) {
            return (int) $m[1];
        }
        throw new InvalidArgumentException("Cannot extract heat/round number from stage '{$stageName}'");
    }

    private function deleteScheduledRaces(Event $event): void
    {
        RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->where('status', 'SCHEDULED')
            ->delete();
    }

    /**
     * Returns all blocks across the event ordered chronologically:
     * day.sort_order, then block.sort_order.
     *
     * @return ScheduleBlock[]
     */
    private function orderedBlocks($eventDays): array
    {
        $blocks = [];
        foreach ($eventDays->sortBy('sort_order') as $day) {
            foreach ($day->blocks->sortBy('sort_order') as $block) {
                $block->setRelation('eventDay', $day);
                $blocks[] = $block;
            }
        }
        return $blocks;
    }

    /**
     * For each scheduled race in the event, find the earliest matching block by
     * filters (gender, distance, stage) and assign race_time = block.start +
     * count_in_block * gap_seconds.
     */
    private function placeRacesIntoBlocks(Event $event, array $orderedBlocks, GenerationResult $result): void
    {
        // Races that already have a race_time set (existing schedule from a
        // prior generate, plus any manual drag-edits in the Grid) are left
        // alone. Only race_time = NULL rows — i.e. the ones we just created
        // in this regenerate pass — get placed.
        //
        // For each block, we track the next free time slot. If the block
        // already holds existing races (from any discipline), the next slot
        // is max(existing race_time in this block) + gap_seconds. Otherwise
        // it's block.start_time. This way:
        //   • Manual drag order is preserved on partial regenerates.
        //   • New races append after whatever's already in the block.
        //   • Operator can drag the new races wherever they want afterwards.

        $fleetConfig = FleetConfig::fromEvent($event);

        $existingRaces = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->where('status', 'SCHEDULED')
            ->whereNotNull('race_time')
            ->with('discipline')
            ->get();

        // Block-id → latest race_time currently in that block. Carbon for math.
        $blockLatestTime = [];
        // Block-id → [hull letter → Carbon of last use in this block]. Seeded
        // from already-placed races so the rotation cursor stays consistent
        // when we append new races into a block that's already half full.
        $blockLastHullUse = [];
        foreach ($existingRaces as $race) {
            $block = $this->findMatchingBlock($race, $orderedBlocks);
            if (!$block) {
                continue;
            }
            $current = Carbon::parse($race->race_time);
            $existing = $blockLatestTime[$block->id] ?? null;
            if ($existing === null || $current->gt($existing)) {
                $blockLatestTime[$block->id] = $current;
            }
            if (!empty($race->hull)) {
                $prev = $blockLastHullUse[$block->id][$race->hull] ?? null;
                if ($prev === null || $current->gt($prev)) {
                    $blockLastHullUse[$block->id][$race->hull] = $current;
                }
            }
        }

        $newRaces = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->where('status', 'SCHEDULED')
            ->whereNull('race_time')
            ->with('discipline')
            ->orderBy('discipline_id')
            ->orderBy('id')
            ->get();

        // Bucket new races by matching block, then apply wave ordering
        // within each block: (phase, discipline_id, stage_number). Races
        // with no matching block get a warning and are left unplaced.
        $racesPerBlock = [];
        foreach ($newRaces as $race) {
            $block = $this->findMatchingBlock($race, $orderedBlocks);
            if (!$block) {
                $result->addWarning(
                    "No matching schedule block for {$race->discipline->getDisplayName()} {$race->stage}."
                );
                continue;
            }
            $racesPerBlock[$block->id][] = ['race' => $race, 'block' => $block];
        }
        foreach ($racesPerBlock as $blockId => &$rows) {
            usort($rows, function ($a, $b) {
                $ra = $a['race'];
                $rb = $b['race'];
                $pa = StagePhase::of((string) $ra->stage);
                $pb = StagePhase::of((string) $rb->stage);
                if ($pa !== $pb) return $pa <=> $pb;
                if ($ra->discipline_id !== $rb->discipline_id) {
                    return $ra->discipline_id <=> $rb->discipline_id;
                }
                $sa = StagePhase::stageNumber((string) $ra->stage);
                $sb = StagePhase::stageNumber((string) $rb->stage);
                if ($sa !== $sb) return $sa <=> $sb;
                return ($ra->id ?? 0) <=> ($rb->id ?? 0);
            });
        }
        unset($rows);

        $warnedBoatGroups = [];
        // Per-discipline last-placed race across the whole event so the
        // boarding-gap rule and the 2-hour soft-warn can look back across
        // block boundaries. Key = discipline_id, value = ['time' => Carbon,
        // 'phase' => int, 'stage' => string].
        $lastRaceByDiscipline = [];
        // Seed from already-placed existing races so partial regenerates
        // respect the constraint too.
        foreach ($existingRaces as $er) {
            if (!$er->discipline_id) continue;
            $t = Carbon::parse($er->race_time);
            $ph = StagePhase::of((string) $er->stage);
            $prev = $lastRaceByDiscipline[$er->discipline_id] ?? null;
            if ($prev === null || $t->gt($prev['time'])) {
                $lastRaceByDiscipline[$er->discipline_id] = [
                    'time' => $t, 'phase' => $ph, 'stage' => (string) $er->stage,
                ];
            }
        }

        foreach ($racesPerBlock as $blockId => $rows) {
            foreach ($rows as $row) {
                /** @var RaceResult $race */
                $race = $row['race'];
                /** @var ScheduleBlock $block */
                $block = $row['block'];

                $latest = $blockLatestTime[$block->id] ?? null;
                if ($latest === null) {
                    $dateStr = $block->eventDay->date instanceof Carbon
                        ? $block->eventDay->date->toDateString()
                        : (string) $block->eventDay->date;
                    $cursor = Carbon::parse($dateStr . ' ' . $block->start_time);
                } else {
                    $cursor = $latest->copy()->addSeconds($block->gap_seconds);
                }

                // Boarding-gap rule: when this discipline's previous race
                // was in a different phase (i.e. crews just changed stage
                // type — heat→rep, rep→final, or round N → round N+1), the
                // same crews need to physically re-board. Enforce that at
                // least one other race sits between them by requiring the
                // cursor to be at least 2 × block.gap_seconds after the
                // previous same-discipline race.
                //
                // When there IS no other race to fill the intermediate
                // slot, drop an "auto: Boarding" break there so the
                // marshal can see WHY there's air on the schedule.
                $currentPhase = StagePhase::of((string) $race->stage);
                $prev = $lastRaceByDiscipline[$race->discipline_id] ?? null;
                if ($prev !== null && $prev['phase'] !== $currentPhase) {
                    $minNext = $prev['time']->copy()->addSeconds(2 * (int) $block->gap_seconds);
                    if ($cursor->lt($minNext)) {
                        $breakStart = $cursor->copy();
                        $slipSeconds = $breakStart->diffInSeconds($minNext);
                        $cursor = $minNext;
                        $dispName = optional($race->discipline)->getDisplayName() ?? "discipline #{$race->discipline_id}";
                        RaceResult::create([
                            'event_id' => $event->id,
                            'entry_type' => 'break',
                            'stage' => '',
                            'race_time' => $breakStart,
                            'duration_seconds' => $slipSeconds,
                            'label' => "auto: Boarding · {$dispName} · {$prev['stage']} → {$race->stage}",
                            'shift_subsequent' => false,
                            'status' => 'SCHEDULED',
                        ]);
                    }
                }

                $fleet = $fleetConfig->fleetFor(optional($race->discipline)->boat_group);
                $assignedHull = null;
                if (!empty($fleet)) {
                    // Loop until a hull in the fleet has served its
                    // turnaround. If none is free, advance the cursor by
                    // one gap and try again.
                    $turnaround = count($fleet) * (int) $block->gap_seconds;
                    while (true) {
                        foreach ($fleet as $letter) {
                            $lastUse = $blockLastHullUse[$block->id][$letter] ?? null;
                            if ($lastUse === null || $lastUse->copy()->addSeconds($turnaround)->lte($cursor)) {
                                $assignedHull = $letter;
                                break 2;
                            }
                        }
                        $cursor = $cursor->copy()->addSeconds($block->gap_seconds);
                    }
                    $blockLastHullUse[$block->id][$assignedHull] = $cursor;
                } elseif ($fleetConfig->isConfigured()) {
                    // Event has hulls, but this boat_group isn't mapped —
                    // one warning per group per generate keeps the noise
                    // down.
                    $bg = (string) (optional($race->discipline)->boat_group ?? '(none)');
                    if (!isset($warnedBoatGroups[$bg])) {
                        $result->addWarning(
                            "Hull rotation skipped for boat group '{$bg}' — not mapped to small/standard."
                        );
                        $warnedBoatGroups[$bg] = true;
                    }
                }

                // 2-hour soft-warn: crews should not sit idle for hours
                // between two of their stages. Hard scheduling constraints
                // can force this in tight programmes, but flag it so the
                // operator sees it in the Preview / warnings banner.
                if ($prev !== null && $prev['phase'] !== $currentPhase) {
                    $gapSeconds = $cursor->diffInSeconds($prev['time']);
                    if ($gapSeconds > 7200) {
                        $mins = intdiv($gapSeconds, 60);
                        $dispName = optional($race->discipline)->getDisplayName() ?? "discipline #{$race->discipline_id}";
                        $result->addWarning(
                            "{$dispName}: {$prev['stage']} → {$race->stage} is {$mins} min apart (>2 h); consider re-ordering."
                        );
                    }
                }

                $race->race_time = $cursor;
                $race->hull = $assignedHull;
                $race->save();

                $blockLatestTime[$block->id] = $cursor;
                $lastRaceByDiscipline[$race->discipline_id] = [
                    'time' => $cursor, 'phase' => $currentPhase, 'stage' => (string) $race->stage,
                ];
            }
        }
    }

    /**
     * Shift all SCHEDULED races at-or-after the pivot's race_time by N minutes
     * (signed). If $sameDayOnly is true, only races on the same calendar date
     * as the pivot are affected. Returns the number of races shifted.
     */
    public function shiftFrom(RaceResult $pivot, int $minutes, bool $sameDayOnly = true): int
    {
        if ($minutes === 0 || $pivot->race_time === null) {
            return 0;
        }
        // Pivot may be a race (event via discipline) or a break (event_id direct).
        $eventId = $pivot->discipline?->event_id ?? $pivot->event_id;
        if (!$eventId) {
            return 0;
        }
        $event = Event::find($eventId);
        if ($event) {
            $this->refuseIfAnyRaceInProgress($event);
        }
        $pivotTime = Carbon::parse($pivot->race_time);

        // Reuse RaceResult::scopeForEvent so both races and breaks are picked up.
        $query = RaceResult::forEvent($eventId)
            ->where('status', 'SCHEDULED')
            ->whereNotNull('race_time')
            ->where('race_time', '>=', $pivotTime);

        if ($sameDayOnly) {
            $query->whereDate('race_time', $pivotTime->toDateString());
        }

        $count = 0;
        DB::transaction(function () use ($query, $minutes, $event, &$count) {
            if ($event) {
                $this->autoSnapshot($event, "shift {$minutes}min");
            }
            $races = $query->get();
            foreach ($races as $race) {
                $race->race_time = Carbon::parse($race->race_time)->addMinutes($minutes);
                $race->save();
                $count++;
            }
        });
        return $count;
    }

    /**
     * Resolve the schedule date for a race by matching its discipline + stage to
     * the first matching Setup block (same matcher the generator uses) and
     * returning that block's event-day date as 'Y-m-d'. Used by the gsheet
     * import so races land on the Setup day's date while keeping their own
     * start time. Null when nothing matches (e.g. no Setup days defined).
     */
    public function resolveRaceDate(Event $event, Discipline $discipline, string $stage): ?string
    {
        if (!$event->relationLoaded('eventDays')) {
            $event->load('eventDays.blocks');
        }
        foreach ($this->orderedBlocks($event->eventDays) as $block) {
            if ($this->blockMatches($block, $discipline, $stage)) {
                $date = $block->eventDay->date;
                return $date instanceof Carbon ? $date->toDateString() : (string) $date;
            }
        }
        return null;
    }

    /** @param ScheduleBlock[] $orderedBlocks */
    private function findMatchingBlock(RaceResult $race, array $orderedBlocks): ?ScheduleBlock
    {
        $discipline = $race->discipline;
        foreach ($orderedBlocks as $block) {
            if (!$this->blockMatches($block, $discipline, $race->stage)) {
                continue;
            }
            return $block;
        }
        return null;
    }

    private function blockMatches(ScheduleBlock $block, Discipline $discipline, string $stageName): bool
    {
        if (is_array($block->gender_filter) && !empty($block->gender_filter)) {
            // Map legacy short codes (M/W/X) to discipline.gender_group values.
            $map = ['M' => 'Open', 'W' => 'Women', 'X' => 'Mixed'];
            $needles = array_map(
                fn($v) => $map[strtoupper((string) $v)] ?? $v,
                $block->gender_filter,
            );
            if (!in_array($discipline->gender_group, $needles, true)) {
                return false;
            }
        }
        if (is_array($block->distance_filter) && !empty($block->distance_filter)) {
            // Normalize both sides to digits-only so "200m" / "200" / 200 all match.
            $needles = array_map(
                fn($v) => preg_replace('/\D/', '', (string) $v),
                $block->distance_filter,
            );
            $needles = array_filter($needles, fn($v) => $v !== '');
            if (!in_array((string) $discipline->distance, $needles, true)) {
                return false;
            }
        }
        if (is_array($block->stage_filter) && !empty($block->stage_filter)) {
            $stageLower = strtolower($stageName);
            $matched = false;
            foreach ($block->stage_filter as $needle) {
                if (str_contains($stageLower, strtolower((string) $needle))) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }
        if (is_array($block->competition_filter) && !empty($block->competition_filter)) {
            $competition = (string) ($discipline->competition ?? '');
            $needles = array_map(fn($v) => strtolower((string) $v), $block->competition_filter);
            if (!in_array(strtolower($competition), $needles, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Public entry point to renumber an event's races by chronological order.
     * Used by edit/reorder flows that update race_time outside generation.
     */
    public function renumberEventRaces(Event $event): void
    {
        $this->renumberRaces($event);
    }

    /**
     * Normalize race_time of every race + break in the event by walking each
     * block's entries in current race_time order and reassigning:
     *   first race → block.start
     *   next race  → previous + gap_seconds
     *   shift-break → previous + duration_seconds
     *   parallel break → doesn't advance the cursor
     *
     * Then renumbers races. Called after generate / regenerate / reorder /
     * break-edit so the schedule always starts at block.start with no gaps
     * and every race lands on a canonical slot.
     */
    public function recomputeAllBlockTimes(Event $event): void
    {
        $eventDays = $event->eventDays()->with('blocks')->get();
        $orderedBlocks = $this->orderedBlocks($eventDays);
        if (empty($orderedBlocks)) {
            return;
        }

        // Bucket every race into the block it matches by filter, every break
        // into the latest block on the same day that starts at-or-before its
        // current race_time. Entries without a bucket keep their current time
        // (typically orphans — operator should fix manually).
        $bucketsByBlockId = [];

        $races = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->where('status', 'SCHEDULED')
            ->whereNotNull('race_time')
            ->with('discipline')
            ->get();
        foreach ($races as $race) {
            $block = $this->findMatchingBlock($race, $orderedBlocks);
            if ($block) {
                $bucketsByBlockId[$block->id][] = $race;
            }
        }

        $breaks = RaceResult::where('event_id', $event->id)
            ->where('entry_type', 'break')
            ->where('status', 'SCHEDULED')
            ->whereNotNull('race_time')
            ->get();
        foreach ($breaks as $brk) {
            $block = $this->findBlockForBreak($brk, $orderedBlocks);
            if ($block) {
                $bucketsByBlockId[$block->id][] = $brk;
            }
        }

        // Group ordered blocks by day so each day's blocks can chain by sort
        // order. Block N+1's effective start is max(block.start_time,
        // end-of-block-N) — one continuous timeline per day. Blocks keep
        // their own start_time as a "not-before" hint; they never run earlier
        // than configured, but the configured time stops mattering once the
        // previous block has already pushed the cursor past it.
        $blocksByDay = [];
        foreach ($orderedBlocks as $block) {
            $day = $block->eventDay;
            if (!$day) continue;
            $dateStr = $day->date instanceof Carbon
                ? $day->date->toDateString()
                : (string) $day->date;
            $blocksByDay[$dateStr][] = $block;
        }

        $fleetConfig = FleetConfig::fromEvent($event);

        DB::transaction(function () use ($event, $bucketsByBlockId, $blocksByDay, $fleetConfig) {
            $this->autoSnapshot($event, 'recompute times');

            foreach ($blocksByDay as $dateStr => $dayBlocks) {
                /** @var Carbon|null $cursor */
                $cursor = null;
                foreach ($dayBlocks as $block) {
                    $entries = $bucketsByBlockId[$block->id] ?? [];
                    $cursor = $this->recomputeBlockEntryTimes($block, $entries, $dateStr, $cursor, $fleetConfig);
                }
            }
        });

        $this->renumberRaces($event);
    }

    /**
     * Lays out one block's entries from a starting cursor. Returns the cursor
     * value after the last entry, so the caller can chain into the next block
     * on the same day. The block's start_time is a "not-before" anchor —
     * effective start is max(cursor, block.start_time).
     *
     * Recompute keeps the drag-sorted order (order by race_time). Wave order
     * is NOT re-applied here — a drag operator has explicitly chosen a
     * position, and recompute preserves that.
     *
     * Hulls are re-derived from the sorted order using a fresh per-block
     * lastUse map, so a drag to a new slot picks up whatever hull fits the
     * new position's rotation. Fleet lookup skipped entirely when the
     * event has no hulls configured — behaves exactly as pre-hull code.
     */
    private function recomputeBlockEntryTimes(
        $block,
        array $entries,
        string $dateStr,
        ?Carbon $cursor,
        ?FleetConfig $fleetConfig = null,
    ): Carbon {
        usort($entries, function ($a, $b) {
            $ta = $a->race_time;
            $tb = $b->race_time;
            if ($ta === null && $tb === null) {
                return ($a->id ?? 0) <=> ($b->id ?? 0);
            }
            if ($ta === null) return 1;
            if ($tb === null) return -1;
            $cmp = strcmp((string) $ta, (string) $tb);
            return $cmp !== 0 ? $cmp : (($a->id ?? 0) <=> ($b->id ?? 0));
        });

        $blockStart = Carbon::parse($dateStr . ' ' . $block->start_time);
        $next = ($cursor !== null && $cursor->gt($blockStart)) ? $cursor->copy() : $blockStart;

        $hullsOn = $fleetConfig !== null && $fleetConfig->isConfigured();
        $lastHullUse = [];  // fresh per-block, per-call
        $lastRaceByDiscipline = [];  // discipline_id → ['time' => Carbon, 'phase' => int]

        foreach ($entries as $entry) {
            // Boarding-gap rule (mirrors placeRacesIntoBlocks): when this
            // discipline's previous race was in a different phase, force
            // the cursor forward by at least 2 × block.gap_seconds. Drag
            // preserved the ORDER, we still enforce the physical spacing.
            if (!$entry->isBreak() && $entry->discipline_id) {
                $phase = StagePhase::of((string) $entry->stage);
                $prev = $lastRaceByDiscipline[$entry->discipline_id] ?? null;
                if ($prev !== null && $prev['phase'] !== $phase) {
                    $minNext = $prev['time']->copy()->addSeconds(2 * (int) $block->gap_seconds);
                    if ($next->lt($minNext)) {
                        $next = $minNext;
                    }
                }
            }

            $entry->race_time = $next;

            if (!$entry->isBreak() && $hullsOn) {
                $fleet = $fleetConfig->fleetFor(optional($entry->discipline)->boat_group);
                if (!empty($fleet)) {
                    // Assign the first hull whose turnaround has been served
                    // by $next. If none, fall back to the least-recently-used
                    // — recompute preserves drag time, so we can't slip the
                    // cursor here the way placeRacesIntoBlocks can.
                    $turnaround = count($fleet) * (int) $block->gap_seconds;
                    $chosen = null;
                    foreach ($fleet as $letter) {
                        $lu = $lastHullUse[$letter] ?? null;
                        if ($lu === null || $lu->copy()->addSeconds($turnaround)->lte($next)) {
                            $chosen = $letter;
                            break;
                        }
                    }
                    if ($chosen === null) {
                        // No hull satisfies turnaround at this slot — pick
                        // the least-recently-used and flag the row. This
                        // only happens when drag has crammed races closer
                        // than the fleet can serve; operator's on the hook.
                        $bestLetter = null;
                        $bestTime = null;
                        foreach ($fleet as $letter) {
                            $lu = $lastHullUse[$letter] ?? null;
                            if ($bestTime === null || ($lu !== null && $lu->lt($bestTime))) {
                                $bestLetter = $letter;
                                $bestTime = $lu;
                            }
                        }
                        $chosen = $bestLetter;
                    }
                    $entry->hull = $chosen;
                    if ($chosen !== null) {
                        $lastHullUse[$chosen] = $next;
                    }
                } else {
                    // Fleet not applicable to this race — leave the
                    // existing hull value alone. (Was already null from
                    // an earlier generate, or was set manually.)
                }
            }

            $entry->save();

            if (!$entry->isBreak() && $entry->discipline_id) {
                $lastRaceByDiscipline[$entry->discipline_id] = [
                    'time' => $next->copy(),
                    'phase' => StagePhase::of((string) $entry->stage),
                ];
            }

            if ($entry->isBreak()) {
                if ($entry->shift_subsequent) {
                    $next = $next->copy()->addSeconds((int) ($entry->duration_seconds ?? 0));
                }
                // Parallel break: cursor doesn't advance — next race uses the
                // same slot logic as if the break wasn't there.
            } else {
                $next = $next->copy()->addSeconds((int) $block->gap_seconds);
            }
        }

        return $next;
    }

    /**
     * Latest block on the same day that starts at-or-before the break's
     * current race_time. Null if the break sits before any block start.
     */
    private function findBlockForBreak(RaceResult $break, array $orderedBlocks)
    {
        if (!$break->race_time) {
            return null;
        }
        $breakTime = Carbon::parse($break->race_time);
        $breakDate = $breakTime->toDateString();
        $candidate = null;
        foreach ($orderedBlocks as $block) {
            $day = $block->eventDay;
            if (!$day) continue;
            $blockDate = $day->date instanceof Carbon
                ? $day->date->toDateString()
                : (string) $day->date;
            if ($blockDate !== $breakDate) continue;
            $blockStart = Carbon::parse($blockDate . ' ' . $block->start_time);
            if ($blockStart->lte($breakTime)) {
                $candidate = $block;
            }
        }
        return $candidate;
    }

    /**
     * Assign sequential race_number across the event in chronological order.
     * Races without a race_time (no matching block) get pushed to the end and
     * keep race_number 0 so they're easy to spot in the UI.
     */
    private function renumberRaces(Event $event): void
    {
        $races = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->where('status', 'SCHEDULED')
            ->orderByRaw('race_time IS NULL')
            ->orderBy('race_time')
            ->orderBy('id')
            ->get();

        $num = 1;
        foreach ($races as $race) {
            if ($race->race_time === null) {
                $race->race_number = 0;
            } else {
                $race->race_number = $num++;
            }
            $race->save();
        }
    }

    /**
     * Generate N "Round k" races with all crews racing each round. Lane seeding
     * is centre-out (fastest seed in the centre lane, slower outward) and
     * rotated per round so crews don't always sit in the same lane.
     */
    private function generateRoundsForDiscipline(
        Discipline $discipline,
        int $laneCount,
        int $crewCount,
        int $rounds,
        GenerationResult $result,
    ): void {
        $this->ensureCrewSeeds($discipline, $discipline->crews()->orderBy('id')->get());
        $crews = $discipline->crews()->orderBy('id')->get();
        $crewsBySeed = $crews->keyBy('seed_number');

        $disciplineRaceCount = 0;
        for ($r = 1; $r <= $rounds; $r++) {
            $race = RaceResult::create([
                'race_number' => 0, // renumbered later
                'discipline_id' => $discipline->id,
                'race_time' => null,
                'stage' => "Round {$r}",
                'status' => 'SCHEDULED',
            ]);
            $disciplineRaceCount++;
            $result->racesCreated++;

            foreach ($this->roundLaneSeeding($laneCount, $crewCount, $r) as $lane => $seedNumber) {
                if ($seedNumber === null) {
                    continue;
                }
                $crew = $crewsBySeed->get($seedNumber);
                if (!$crew) {
                    continue;
                }
                CrewResult::create([
                    'race_result_id' => $race->id,
                    'crew_id' => $crew->id,
                    'lane' => $lane,
                    'status' => null,
                ]);
                $result->crewLanesAssigned++;
            }
        }
        $result->racesPerDiscipline[$discipline->id] = $disciplineRaceCount;
    }

    /**
     * @return array<int, int|null> lane (1..laneCount) → seed_number or null
     */
    private function roundLaneSeeding(int $laneCount, int $crewCount, int $roundNum): array
    {
        $assignment = [];
        for ($i = 1; $i <= $laneCount; $i++) {
            $assignment[$i] = null;
        }
        if ($crewCount <= 0) {
            return $assignment;
        }

        // Centre-out lane order per IDBF: for even lane counts the "centre"
        // lane is the LOWER of the two middle lanes (lane 2 of 4, lane 3 of
        // 6, lane 4 of 8), then alternate RIGHT, LEFT. So 4 lanes →
        // [2,3,1,4]; 6 → [3,4,2,5,1,6]; 8 → [4,5,3,6,2,7,1,8]. Empty lanes
        // always end up on the outside (highest lane number), never lane 1.
        $centre = intdiv($laneCount + 1, 2);
        $order = [$centre];
        for ($d = 1; $d < $laneCount; $d++) {
            $right = $centre + $d;
            $left = $centre - $d;
            if ($right <= $laneCount) {
                $order[] = $right;
            }
            if ($left >= 1) {
                $order[] = $left;
            }
        }

        // Per-round rotation so crews don't always sit in the same lane.
        $seeds = range(1, $crewCount);
        $shift = ($roundNum - 1) % $crewCount;
        $seeds = array_merge(array_slice($seeds, $shift), array_slice($seeds, 0, $shift));

        for ($i = 0; $i < $crewCount && $i < count($order); $i++) {
            $assignment[$order[$i]] = $seeds[$i];
        }
        return $assignment;
    }
}
