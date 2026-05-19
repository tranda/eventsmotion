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
     * @throws InvalidArgumentException if no event days/blocks exist
     */
    public function generate(Event $event): GenerationResult
    {
        $eventDays = $event->eventDays()->with('blocks')->get();
        if ($eventDays->isEmpty()) {
            throw new InvalidArgumentException('Event has no days configured. Add days and blocks before generating.');
        }

        $orderedBlocks = $this->orderedBlocks($eventDays);
        if (empty($orderedBlocks)) {
            throw new InvalidArgumentException('Event days have no schedule blocks configured.');
        }

        $disciplines = $event->disciplines()->with(['crews', 'progression'])->orderBy('id')->get();

        $result = new GenerationResult();

        $defaultRounds = (int) ($event->default_rounds ?? 3);
        DB::transaction(function () use ($event, $disciplines, $orderedBlocks, $defaultRounds, $result) {
            $this->deleteScheduledRaces($event);

            // Create rows per discipline, then place into blocks.
            foreach ($disciplines as $discipline) {
                $this->generateForDiscipline($discipline, $event->lane_count, $defaultRounds, $result);
            }

            $this->placeRacesIntoBlocks($event, $orderedBlocks, $result);
            $this->renumberRaces($event);
        });

        return $result;
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
            $this->renumberRaces($event);
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

        // Centre-out lane order. 4 lanes → [3,2,4,1]; 6 → [4,3,5,2,6,1]; 3 → [2,1,3].
        $centre = (int) ceil(($laneCount + 1) / 2);
        $order = [$centre];
        for ($d = 1; $d < $laneCount; $d++) {
            $left = $centre - $d;
            $right = $centre + $d;
            if ($left >= 1) {
                $order[] = $left;
            }
            if ($right <= $laneCount) {
                $order[] = $right;
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
