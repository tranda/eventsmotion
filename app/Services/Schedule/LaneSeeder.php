<?php

namespace App\Services\Schedule;

use App\Models\Crew;
use App\Models\CrewResult;
use App\Models\Discipline;
use App\Models\DisciplineProgression;
use App\Models\RaceResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Seeds the next un-seeded stage for a discipline using IDBF lane-seeding
 * tables and the rankings of prior-round results.
 *
 * Stages with initial seeding (Heats / Rounds) are populated by the generator,
 * not by this service. LaneSeeder fills Repechages / Semis / Finals in order
 * after the prior rounds finish.
 *
 * Position references in the IDBF tables follow a "winners first, then
 * lucky losers by time" convention (per the IDBF Race Plans document —
 * "Winner in each heat progress to Final ... Remainder to repechages
 * based on heat times"):
 *   - Positions 1..N (where N = number of races in the source stage) →
 *     literal winners of each race, in race order (race 1 winner, race
 *     2 winner, …).
 *   - Positions N+1..M → remaining crews ranked by combined time across
 *     the stage.
 *
 * So for RP.1 with 2 heats:
 *   "1st in hts" → winner of Heat 1
 *   "2nd in hts" → winner of Heat 2
 *   "3rd in hts" → fastest non-winner across both heats (lucky loser)
 *   "4th in hts" → next fastest non-winner
 *   …
 *
 * Same rule for "Nth in reps" / "Nth in SF" (rps/rsp typos accepted).
 */
class LaneSeeder
{
    private const SOURCE_HEATS = 'hts';
    private const SOURCE_REPS  = 'reps';
    private const SOURCE_SEMIS = 'sf';

    public function __construct(private IdbfRacePlans $plans) {}

    /**
     * Seed the next un-seeded stage for the discipline. Returns details of
     * what was filled (or skipped, with a reason).
     *
     * @throws InvalidArgumentException when the discipline has no plan or the
     *                                  plan is a Rounds plan (no progression
     *                                  to seed).
     */
    public function seedNextRound(Discipline $discipline): SeedingResult
    {
        $result = new SeedingResult();
        $plan = $this->resolvePlan($discipline);
        if ($plan->isRoundsPlan()) {
            throw new InvalidArgumentException('Rounds-only plans have no stages to re-seed.');
        }

        $races = RaceResult::where('discipline_id', $discipline->id)
            ->orderBy('id')
            ->get();
        $racesByStage = $races->groupBy('stage');

        foreach ($plan->stages() as $stageName) {
            if (str_starts_with($stageName, 'Heat')) {
                continue; // initial-seed stages live with the generator
            }
            $stageRaces = $racesByStage->get($stageName, collect());
            if ($stageRaces->isEmpty()) {
                $result->skipped = true;
                $result->skippedReason = "Stage '{$stageName}' missing from schedule. Regenerate first.";
                return $result;
            }

            // Already seeded?
            $alreadySeeded = $stageRaces->every(
                fn(RaceResult $r) => $r->crewResults()->exists()
            );
            if ($alreadySeeded) {
                continue;
            }

            // Find the seeding map for this stage in the plan.
            $seedingMap = $this->seedingMapForStage($plan, $stageName, $discipline);
            if ($seedingMap === null) {
                $result->skipped = true;
                $result->skippedReason = "No IDBF seeding table for stage '{$stageName}'.";
                return $result;
            }

            // Verify all source races (hts/reps/sf referenced in the map) are FINISHED.
            $sources = $this->collectSources($seedingMap);
            $blocker = $this->firstUnfinishedSource($discipline, $sources);
            if ($blocker !== null) {
                $result->skipped = true;
                $result->skippedReason =
                    "Cannot seed '{$stageName}' yet: {$blocker} have unfinished races.";
                return $result;
            }

            $rankings = $this->buildRankings($discipline, $plan, $sources);

            DB::transaction(function () use ($stageRaces, $seedingMap, $rankings, $result, $stageName) {
                // For multi-race stages (e.g. Semi 1, Semi 2), each race gets its own seeding row.
                $races = $stageRaces->values();
                foreach ($races as $i => $race) {
                    $rowKey = $i + 1; // 1-indexed within the stage type
                    $laneToRef = $this->seedingRowForRaceIndex($seedingMap, $rowKey);
                    foreach ($laneToRef as $lane => $ref) {
                        if ($ref === null) continue;
                        $crew = $this->resolveCrewByRef($ref, $rankings, $result);
                        if ($crew === null) continue;
                        CrewResult::updateOrCreate(
                            ['race_result_id' => $race->id, 'crew_id' => $crew->id],
                            ['lane' => $lane, 'status' => null]
                        );
                        $result->crewLanesAssigned++;
                    }
                }
            });

            $result->seededStage = $stageName;
            return $result;
        }

        $result->skipped = true;
        $result->skippedReason = 'All progression stages already seeded.';
        return $result;
    }

    private function resolvePlan(Discipline $discipline): RacePlan
    {
        $progression = DisciplineProgression::where('discipline_id', $discipline->id)->first();
        if ($progression && $progression->race_plan_code) {
            return $this->plans->getPlan($progression->race_plan_code);
        }
        $event = $discipline->event;
        $crewCount = $discipline->crews()->count();
        return $this->plans->pickPlan($event->lane_count, $crewCount);
    }

    /**
     * Return the seeding map for a stage. Map shape varies by stage type:
     *   - Repechage stages → list of [lane => ref] keyed by rep number
     *   - Semi stages       → list of [lane => ref] keyed by semi number
     *   - Grand/Minor/Tail Final → single [lane => ref] (wrapped as list)
     *
     * @return array<int, array<int, string|null>>|null  null if plan has no map
     */
    private function seedingMapForStage(RacePlan $plan, string $stageName, Discipline $discipline): ?array
    {
        if (str_starts_with($stageName, 'Repechage')) {
            return $plan->repechageSeeding($discipline->crews()->count());
        }
        if (str_starts_with($stageName, 'Semi')) {
            return $plan->semiSeeding();
        }
        if ($stageName === 'Grand Final') {
            $row = $plan->grandFinalSeeding();
            return $row === null ? null : [1 => $row];
        }
        if ($stageName === 'Minor Final') {
            $row = $plan->minorFinalSeeding();
            return $row === null ? null : [1 => $row];
        }
        if ($stageName === 'Tail Final') {
            $row = $plan->tailFinalSeeding();
            return $row === null ? null : [1 => $row];
        }
        return null;
    }

    /** Pull out unique source keys ('hts', 'reps', 'sf') referenced in the map. */
    private function collectSources(array $seedingMap): array
    {
        $sources = [];
        foreach ($seedingMap as $row) {
            foreach ($row as $ref) {
                if ($ref === null) continue;
                $parsed = $this->parseRef((string) $ref);
                if ($parsed !== null) {
                    $sources[$parsed['source']] = true;
                }
            }
        }
        return array_keys($sources);
    }

    /**
     * Returns a human label of the first source that still has an unfinished
     * race for this discipline, or null if all sources are finished.
     */
    private function firstUnfinishedSource(Discipline $discipline, array $sources): ?string
    {
        foreach ($sources as $source) {
            $stagePrefix = $this->stagePrefixForSource($source);
            $unfinished = RaceResult::where('discipline_id', $discipline->id)
                ->where('stage', 'like', "{$stagePrefix}%")
                ->where('status', '!=', 'FINISHED')
                ->exists();
            if ($unfinished) {
                return $this->humanSource($source);
            }
        }
        return null;
    }

    /**
     * Compute the ranking for each source stage under IDBF tiered semantics.
     *
     * Each plan declares a `tiers` count per source ("hts", "reps", "sf")
     * — the number of explicit positions per source race that are
     * guaranteed-advancing slots. The IDBF advancement text maps to it:
     *   "Winner of each heat (...)"                    → tiers = 1
     *   "1st and 2nd in each heat"                     → tiers = 2
     *   "1st, 2nd and 3rd in each heat"                → tiers = 3
     *
     * Resolution rule:
     *   1. Enumerate tier by tier (tier 1: 1st-place of race 1, 1st-place
     *      of race 2, …; then tier 2: 2nd-place of race 1, 2nd-place of
     *      race 2, …; …).
     *   2. After all guaranteed tiers are placed, append remaining crews
     *      ordered by combined time across the stage (these are the
     *      "next K fastest overall" lucky losers that some plans add on
     *      top of the explicit tiers).
     *   3. DSQ/DNS/DNF (no time) sort last in either group.
     *
     * Example RP.3 (tiers=2, 4 heats):
     *   pos 1..4 = winners of H1..H4
     *   pos 5..8 = 2nd-placers of H1..H4
     *   pos 9..  = remaining (3rd, 4th …) by overall time
     *
     * Default tier count is 1 (the previous winners-then-time behaviour,
     * correct for RP.1 / RP.2 / RP.1A / RP.2A / etc.).
     *
     * @return array<string, int[]> source key → ordered crew_id list (1-indexed)
     */
    private function buildRankings(Discipline $discipline, RacePlan $plan, array $sources): array
    {
        $rankings = [];
        foreach ($sources as $source) {
            $stagePrefix = $this->stagePrefixForSource($source);
            $races = RaceResult::where('discipline_id', $discipline->id)
                ->where('stage', 'like', "{$stagePrefix}%")
                ->orderBy('id')
                ->get();

            $sortCrews = function ($a, $b) {
                $aFinished = $a->status === 'FINISHED' && $a->time_ms !== null;
                $bFinished = $b->status === 'FINISHED' && $b->time_ms !== null;
                if ($aFinished !== $bFinished) return $aFinished ? -1 : 1;
                if ($aFinished && $bFinished) return $a->time_ms <=> $b->time_ms;
                return $a->id <=> $b->id;
            };

            // Per-race results sorted best-to-worst by time.
            $perRace = $races->map(
                fn($r) => $r->crewResults()->get()->sort($sortCrews)->values()
            );

            $tiers = max(1, $plan->sourceOrderingTiers($source));

            $ordered = [];
            $usedIds = [];

            // Tiered round-robin: tier T → take Tth-best from each race in order.
            for ($t = 1; $t <= $tiers; $t++) {
                foreach ($perRace as $raceResults) {
                    $cr = $raceResults[$t - 1] ?? null;
                    if ($cr === null) continue;
                    $ordered[] = $cr->crew_id;
                    $usedIds[$cr->crew_id] = true;
                }
            }

            // Tail: remaining crews, globally by time.
            $remaining = collect();
            foreach ($perRace as $raceResults) {
                foreach ($raceResults as $cr) {
                    if (!isset($usedIds[$cr->crew_id])) {
                        $remaining->push($cr);
                    }
                }
            }
            foreach ($remaining->sort($sortCrews)->values() as $cr) {
                $ordered[] = $cr->crew_id;
            }

            $rankings[$source] = $ordered;
        }
        return $rankings;
    }

    private function seedingRowForRaceIndex(array $seedingMap, int $rowKey): array
    {
        // The map keys come from PHP arrays (1..N) — pull the Nth row.
        $values = array_values($seedingMap);
        return $values[$rowKey - 1] ?? [];
    }

    private function resolveCrewByRef(string $ref, array $rankings, SeedingResult $result): ?Crew
    {
        $parsed = $this->parseRef($ref);
        if ($parsed === null) {
            $result->addWarning("Could not parse seeding reference '{$ref}'.");
            return null;
        }
        $orderedCrewIds = $rankings[$parsed['source']] ?? [];
        $position = $parsed['position'];
        if ($position < 1 || $position > count($orderedCrewIds)) {
            $result->addWarning("No crew at position {$position} in {$parsed['source']} (have " . count($orderedCrewIds) . ").");
            return null;
        }
        $crewId = $orderedCrewIds[$position - 1];
        return Crew::find($crewId);
    }

    /** "1st in hts" → ['source' => 'hts', 'position' => 1]; tolerates rsp/rps/RPS typos */
    public function parseRef(string $ref): ?array
    {
        if (preg_match('/^\s*(\d+)\s*(?:st|nd|rd|th)?\s+in\s+([A-Za-z]+)\s*$/i', $ref, $m)) {
            $rawSource = strtolower($m[2]);
            $source = match ($rawSource) {
                'hts' => self::SOURCE_HEATS,
                'rps', 'rsp', 'reps', 'rep' => self::SOURCE_REPS,
                'sf', 'semi', 'semis' => self::SOURCE_SEMIS,
                default => null,
            };
            if ($source === null) return null;
            return ['source' => $source, 'position' => (int) $m[1]];
        }
        return null;
    }

    private function stagePrefixForSource(string $source): string
    {
        return match ($source) {
            self::SOURCE_HEATS => 'Heat',
            self::SOURCE_REPS  => 'Repechage',
            self::SOURCE_SEMIS => 'Semi',
            default => '',
        };
    }

    private function humanSource(string $source): string
    {
        return match ($source) {
            self::SOURCE_HEATS => 'heats',
            self::SOURCE_REPS  => 'repechages',
            self::SOURCE_SEMIS => 'semi-finals',
            default => $source,
        };
    }
}
