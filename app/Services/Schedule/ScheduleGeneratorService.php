<?php

namespace App\Services\Schedule;

use App\Models\Crew;
use App\Models\CrewResult;
use App\Models\Discipline;
use App\Models\Event;
use App\Models\RaceResult;
use App\Models\ScheduleBlock;
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
    public function __construct(private IdbfRacePlans $plans) {}

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
            $preservedTimes = $clean ? [] : $this->snapshotRaceTimes($event);

            if ($day === null) {
                // Full-event regenerate: wipe all SCHEDULED races.
                $this->deleteScheduledRaces($event);
            } else {
                // Per-day regenerate: wipe only the targeted disciplines'
                // races; other disciplines (other days) untouched.
                $disciplineIds = $disciplines->pluck('id')->all();
                RaceResult::whereIn('discipline_id', $disciplineIds)
                    ->where('status', 'SCHEDULED')
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
        $stats = ['matched' => 0, 'unmatched_source' => 0, 'unmatched_target' => 0];

        $matchKey = fn($race) => strtolower(implode('|', [
            (string) optional($race->discipline)->boat_group,
            (string) optional($race->discipline)->age_group,
            (string) optional($race->discipline)->gender_group,
            (string) $race->stage,
        ]));

        DB::transaction(function () use ($event, $sourceDay, $targetDay, $matchKey, &$stats) {
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

        $existingRaces = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->where('status', 'SCHEDULED')
            ->whereNotNull('race_time')
            ->get();

        // Block-id → latest race_time currently in that block. Carbon for math.
        $blockLatestTime = [];
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
        }

        $newRaces = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->where('status', 'SCHEDULED')
            ->whereNull('race_time')
            ->with('discipline')
            ->orderBy('discipline_id')
            ->orderBy('id')
            ->get();

        foreach ($newRaces as $race) {
            $block = $this->findMatchingBlock($race, $orderedBlocks);
            if (!$block) {
                $result->addWarning(
                    "No matching schedule block for {$race->discipline->getDisplayName()} {$race->stage}."
                );
                continue;
            }

            $latest = $blockLatestTime[$block->id] ?? null;
            if ($latest === null) {
                $dateStr = $block->eventDay->date instanceof Carbon
                    ? $block->eventDay->date->toDateString()
                    : (string) $block->eventDay->date;
                $next = Carbon::parse($dateStr . ' ' . $block->start_time);
            } else {
                $next = $latest->copy()->addSeconds($block->gap_seconds);
            }

            $race->race_time = $next;
            $race->save();

            $blockLatestTime[$block->id] = $next;
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
        DB::transaction(function () use ($query, $minutes, &$count) {
            $races = $query->get();
            foreach ($races as $race) {
                $race->race_time = Carbon::parse($race->race_time)->addMinutes($minutes);
                $race->save();
                $count++;
            }
        });
        return $count;
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

        DB::transaction(function () use ($bucketsByBlockId, $blocksByDay) {
            foreach ($blocksByDay as $dateStr => $dayBlocks) {
                /** @var Carbon|null $cursor */
                $cursor = null;
                foreach ($dayBlocks as $block) {
                    $entries = $bucketsByBlockId[$block->id] ?? [];
                    $cursor = $this->recomputeBlockEntryTimes($block, $entries, $dateStr, $cursor);
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
     */
    private function recomputeBlockEntryTimes($block, array $entries, string $dateStr, ?Carbon $cursor): Carbon
    {
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

        foreach ($entries as $entry) {
            $entry->race_time = $next;
            $entry->save();

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
