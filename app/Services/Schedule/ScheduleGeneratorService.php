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
     * @throws InvalidArgumentException if event.lane_count is not 4/6/8 or if no event days/blocks exist
     */
    public function generate(Event $event): GenerationResult
    {
        if (!in_array($event->lane_count, [4, 6, 8], true)) {
            throw new InvalidArgumentException("event.lane_count must be 4, 6, or 8 (got {$event->lane_count})");
        }

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

        DB::transaction(function () use ($event, $disciplines, $orderedBlocks, $result) {
            $this->deleteScheduledRaces($event);

            // Create rows per discipline, then place into blocks.
            foreach ($disciplines as $discipline) {
                $this->generateForDiscipline($discipline, $event->lane_count, $result);
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
        if (!in_array($event->lane_count, [4, 6, 8], true)) {
            throw new InvalidArgumentException("event.lane_count must be 4, 6, or 8");
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

        DB::transaction(function () use ($discipline, $event, $orderedBlocks, $result) {
            RaceResult::where('discipline_id', $discipline->id)
                ->where('status', 'SCHEDULED')
                ->delete();

            $this->generateForDiscipline($discipline->fresh(['crews', 'progression']), $event->lane_count, $result);
            $this->placeRacesIntoBlocks($event, $orderedBlocks, $result);
            $this->renumberRaces($event);
        });

        return $result;
    }

    /** Picks the plan, ensures crews have seeds, and creates RaceResult+CrewResult rows. */
    private function generateForDiscipline(Discipline $discipline, int $laneCount, GenerationResult $result): void
    {
        $crews = $discipline->crews()->orderBy('id')->get();
        $crewCount = $crews->count();

        if ($crewCount < 2) {
            $result->addWarning("Skipped {$discipline->getDisplayName()}: needs at least 2 crews (has {$crewCount}).");
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
        $blockRaceCount = [];

        $races = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->where('status', 'SCHEDULED')
            ->with('discipline')
            ->orderBy('discipline_id')
            ->orderBy('id')
            ->get();

        foreach ($races as $race) {
            $block = $this->findMatchingBlock($race, $orderedBlocks);
            if (!$block) {
                $result->addWarning(
                    "No matching schedule block for {$race->discipline->getDisplayName()} {$race->stage}."
                );
                continue;
            }
            $count = $blockRaceCount[$block->id] ?? 0;

            $dateStr = $block->eventDay->date instanceof Carbon
                ? $block->eventDay->date->toDateString()
                : (string) $block->eventDay->date;
            $race->race_time = Carbon::parse($dateStr . ' ' . $block->start_time)
                ->addSeconds($count * $block->gap_seconds);
            $race->save();

            $blockRaceCount[$block->id] = $count + 1;
        }
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
            if (!in_array($discipline->gender_group, $block->gender_filter, true)) {
                return false;
            }
        }
        if (is_array($block->distance_filter) && !empty($block->distance_filter)) {
            if (!in_array((string) $discipline->distance, array_map('strval', $block->distance_filter), true)) {
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
}
