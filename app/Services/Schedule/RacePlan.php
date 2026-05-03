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
    public function __construct(
        public readonly string $code,
        private readonly array $data,
    ) {}

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
     * Derived from heat_lane_seeding by counting seeds <= crewCount.
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
        foreach ($this->data['heat_lane_seeding'] as $heatNum => $lanes) {
            $sizes[$heatNum] = count(array_filter(
                $lanes,
                fn($seed) => $seed !== null && $seed <= $crewCount
            ));
        }
        return $sizes;
    }

    /**
     * Lane → seed-number map for a given heat, filtered to the active crew count.
     * Lanes assigned to a seed > crewCount become null (empty).
     *
     * @return array<int, int|null>
     */
    public function heatLaneSeeding(int $heatNum, int $crewCount): array
    {
        $this->ensureSupports($crewCount);

        $key = $this->isRoundsPlan() ? 'round_lane_seeding' : 'heat_lane_seeding';
        if (!isset($this->data[$key][$heatNum])) {
            throw new InvalidArgumentException("Plan {$this->code} has no heat/round {$heatNum}");
        }

        $lanes = $this->data[$key][$heatNum];
        return array_map(
            fn($seed) => ($seed !== null && $seed <= $crewCount) ? $seed : null,
            $lanes
        );
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
