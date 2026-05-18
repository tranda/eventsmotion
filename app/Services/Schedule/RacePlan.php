<?php

namespace App\Services\Schedule;

use InvalidArgumentException;

/**
 * Immutable value object wrapping one IDBF race plan from
 * config/idbf_race_plans.php. Resolves common queries (heat composition,
 * lane seedings) against the plan data.
 */
class RacePlan
{
    public string $code;
    private array $data;

    public function __construct(string $code, array $data)
    {
        $this->code = $code;
        $this->data = $data;
    }

    public function laneCount(): int
    {
        return $this->data['lane_count'];
    }

    /** @return array{0:int,1:int} */
    public function crewCountRange(): array
    {
        return $this->data['crew_count_range'];
    }

    public function supportsCrewCount(int $crewCount): bool
    {
        [$min, $max] = $this->crewCountRange();
        return $crewCount >= $min && $crewCount <= $max;
    }

    /** @return string[] */
    public function stages(): array
    {
        return $this->data['stages'];
    }

    public function isRoundsPlan(): bool
    {
        return isset($this->data['round_lane_seeding']);
    }

    /**
     * Number of crews in each heat for a given total crew count.
     * For multi-heat plans, applies the IDBF "earlier heats are fuller" rule
     * (missing-seed slots get promoted from later heats so the early-heat
     * roster stays full).
     *
     * @return int[] indexed 1-based by heat number
     */
    public function heatComposition(int $crewCount): array
    {
        $this->ensureSupports($crewCount);

        if ($this->isRoundsPlan()) {
            $sizes = [];
            foreach ($this->data['round_lane_seeding'] as $roundNum => $lanes) {
                $sizes[$roundNum] = count(array_filter(
                    $lanes,
                    fn($seed) => $seed !== null && $seed <= $crewCount
                ));
            }
            return $sizes;
        }

        $sizes = [];
        foreach ($this->resolveHeatPlacements($crewCount) as $heatNum => $lanes) {
            $sizes[$heatNum] = count(array_filter($lanes, fn($s) => $s !== null));
        }
        return $sizes;
    }

    /**
     * Lane → seed-number map for a given heat, filtered to the active crew count.
     * Lanes assigned to a seed > crewCount become null (empty). For multi-heat
     * plans, applies the IDBF "earlier heats are fuller" rebalance — see
     * resolveHeatPlacements() for the algorithm.
     *
     * @return array<int, int|null>
     */
    public function heatLaneSeeding(int $heatNum, int $crewCount): array
    {
        $this->ensureSupports($crewCount);

        if ($this->isRoundsPlan()) {
            if (!isset($this->data['round_lane_seeding'][$heatNum])) {
                throw new InvalidArgumentException("Plan {$this->code} has no round {$heatNum}");
            }
            $lanes = $this->data['round_lane_seeding'][$heatNum];
            $laneCount = $this->laneCount();
            if ($crewCount < $laneCount) {
                return $this->compactCentreOut($laneCount, $crewCount, $heatNum);
            }
            return array_map(
                fn($seed) => ($seed !== null && $seed <= $crewCount) ? $seed : null,
                $lanes
            );
        }

        if (!isset($this->data['heat_lane_seeding'][$heatNum])) {
            throw new InvalidArgumentException("Plan {$this->code} has no heat {$heatNum}");
        }

        $placements = $this->resolveHeatPlacements($crewCount);
        return $placements[$heatNum];
    }

    /**
     * Returns [heatNum => [lane => seed|null]] after applying IDBF heat
     * rebalancing for partial crew counts.
     *
     * Algorithm:
     *   1. Drop missing seeds at their original lanes (table-defined positions).
     *   2. Compute target sizes — distribute crewCount across heats so earlier
     *      heats hold the extras (ceil for first heats, floor for last).
     *   3. While any earlier heat is below target and any later heat is above:
     *      - Promote the highest seed (lowest-priority crew) from the later heat
     *        into the vacant lane in the earlier heat that originally held the
     *        highest seed (preserves "fastest in centre" — the promoted crew
     *        takes the most-outside slot in the receiving heat).
     *
     * For RP.3A-style plans where the IDBF table already distributes missing
     * seeds across later heats, this is a no-op. For RP.1 / RP.1A / RP.2
     * with non-max crew counts, it corrects the off-by-heat imbalance.
     *
     * @return array<int, array<int, int|null>>
     */
    private function resolveHeatPlacements(int $crewCount): array
    {
        $rawHeats = $this->data['heat_lane_seeding'];

        // Step 1 — drop missing seeds.
        $heats = [];
        foreach ($rawHeats as $heatNum => $lanes) {
            $heats[$heatNum] = array_map(
                fn($s) => ($s !== null && $s <= $crewCount) ? $s : null,
                $lanes
            );
        }

        // Step 2 — target sizes (earlier heats fuller).
        $heatNums = array_keys($rawHeats);
        $heatCount = count($heatNums);
        $base = intdiv($crewCount, $heatCount);
        $extra = $crewCount % $heatCount;
        $targets = [];
        foreach ($heatNums as $i => $heatNum) {
            $targets[$heatNum] = $base + ($i < $extra ? 1 : 0);
        }

        // Step 3 — rebalance.
        // Safety cap: at most one promotion per "missing" seed.
        $maxIterations = $heatCount * $this->laneCount();
        for ($iter = 0; $iter < $maxIterations; $iter++) {
            // Find earliest under-target heat.
            $earlier = null;
            foreach ($heats as $heatNum => $lanes) {
                $present = count(array_filter($lanes, fn($s) => $s !== null));
                if ($present < $targets[$heatNum]) {
                    $earlier = $heatNum;
                    break;
                }
            }
            if ($earlier === null) break;

            // Find latest over-target heat that comes after $earlier.
            $later = null;
            foreach (array_reverse($heats, true) as $heatNum => $lanes) {
                if ($heatNum <= $earlier) continue;
                $present = count(array_filter($lanes, fn($s) => $s !== null));
                if ($present > $targets[$heatNum]) {
                    $later = $heatNum;
                    break;
                }
            }
            if ($later === null) break;

            // Take the highest seed in $later (lowest-priority crew).
            $promotedLane = null;
            $promotedSeed = -1;
            foreach ($heats[$later] as $lane => $seed) {
                if ($seed !== null && $seed > $promotedSeed) {
                    $promotedSeed = $seed;
                    $promotedLane = $lane;
                }
            }
            $heats[$later][$promotedLane] = null;

            // Place into the vacant lane in $earlier whose original seed was
            // highest — that's the "outside" slot per IDBF centre-fastest rule.
            $targetLane = null;
            $highestOriginal = -1;
            foreach ($heats[$earlier] as $lane => $seed) {
                if ($seed !== null) continue;
                $originalSeed = $rawHeats[$earlier][$lane] ?? null;
                if ($originalSeed !== null && $originalSeed > $highestOriginal) {
                    $highestOriginal = $originalSeed;
                    $targetLane = $lane;
                }
            }
            // Fallback: first vacant lane (shouldn't fire unless the plan has
            // no non-null original seed in any vacant slot — e.g. RP.1A with
            // structurally-null edge lanes).
            if ($targetLane === null) {
                foreach ($heats[$earlier] as $lane => $seed) {
                    if ($seed === null && ($rawHeats[$earlier][$lane] ?? null) !== null) {
                        $targetLane = $lane;
                        break;
                    }
                }
            }
            if ($targetLane === null) break;
            $heats[$earlier][$targetLane] = $promotedSeed;
        }

        return $heats;
    }

    /**
     * Returns a [lane => seed|null] map placing seeds 1..$crewCount centre-out.
     * Rotates the seed order per round so crews still see varied lane positions
     * across multiple rounds.
     */
    private function compactCentreOut(int $laneCount, int $crewCount, int $roundNum): array
    {
        $assignment = [];
        for ($i = 1; $i <= $laneCount; $i++) {
            $assignment[$i] = null;
        }
        if ($crewCount <= 0) {
            return $assignment;
        }

        // Build lane order centre-out: e.g. 4 lanes → [3,2,4,1]; 6 → [4,3,5,2,6,1].
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

        // Build seed order with a per-round rotation so crews don't always sit
        // in the same lane across all rounds.
        $seeds = range(1, $crewCount);
        $shift = ($roundNum - 1) % $crewCount;
        $seeds = array_merge(array_slice($seeds, $shift), array_slice($seeds, 0, $shift));

        for ($i = 0; $i < $crewCount && $i < count($order); $i++) {
            $assignment[$order[$i]] = $seeds[$i];
        }
        return $assignment;
    }

    /**
     * Number of heats (or rounds) this plan defines.
     */
    public function heatCount(): int
    {
        $key = $this->isRoundsPlan() ? 'round_lane_seeding' : 'heat_lane_seeding';
        return count($this->data[$key] ?? []);
    }

    /**
     * Lane → position-reference map for repechages, semis, finals.
     * Returns null when the plan does not include this stage type.
     *
     * @return array<int, array<int, int|string|null>>|null
     */
    public function repechageSeeding(int $crewCount): ?array
    {
        if (isset($this->data['repechage_lane_seeding_by_crew_count'])) {
            return $this->data['repechage_lane_seeding_by_crew_count'][$crewCount] ?? null;
        }
        return $this->data['repechage_lane_seeding'] ?? null;
    }

    /** @return array<int, array<int, string|null>>|null */
    public function semiSeeding(): ?array
    {
        return $this->data['semi_lane_seeding'] ?? null;
    }

    /** @return array<int, string|null>|null */
    public function grandFinalSeeding(): ?array
    {
        return $this->data['grand_final_lane_seeding'] ?? null;
    }

    /** @return array<int, string|null>|null */
    public function minorFinalSeeding(): ?array
    {
        return $this->data['minor_final_lane_seeding'] ?? null;
    }

    /** @return array<int, string|null>|null */
    public function tailFinalSeeding(): ?array
    {
        return $this->data['tail_final_lane_seeding'] ?? null;
    }

    /** @return string[] */
    public function advancement(): array
    {
        return $this->data['advancement'] ?? [];
    }

    /** Raw plan data, primarily for debugging and tests. */
    public function toArray(): array
    {
        return ['code' => $this->code] + $this->data;
    }

    private function ensureSupports(int $crewCount): void
    {
        if (!$this->supportsCrewCount($crewCount)) {
            [$min, $max] = $this->crewCountRange();
            throw new InvalidArgumentException(
                "Plan {$this->code} supports {$min}-{$max} crews, got {$crewCount}"
            );
        }
    }
}
