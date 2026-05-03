<?php

namespace App\Services\Schedule;

use InvalidArgumentException;
use OutOfRangeException;

/**
 * Lookup over the IDBF Race Plans (v2, August 2020) tables encoded in
 * config/idbf_race_plans.php.
 *
 * Reference: docs/idbf/Race-Plans-v2-2020-08.pdf
 *
 * Plans are picked by (lane_count, crew_count). Lane counts of 4, 6, 8 are
 * supported per the IDBF standard. Lane counts outside that set must be
 * rejected at the call site (event setup).
 */
class IdbfRacePlans
{
    /** Cached array loaded from config; keyed by plan code. */
    private array $plans;

    public function __construct(?array $plans = null)
    {
        $this->plans = $plans ?? config('idbf_race_plans') ?? [];
    }

    /**
     * Look up the plan that applies to (lane_count, crew_count).
     *
     * @throws OutOfRangeException when no plan covers the given counts
     */
    public function pickPlan(int $laneCount, int $crewCount): RacePlan
    {
        foreach ($this->plans as $code => $data) {
            if ($data['lane_count'] !== $laneCount) {
                continue;
            }
            [$min, $max] = $data['crew_count_range'];
            if ($crewCount >= $min && $crewCount <= $max) {
                return new RacePlan($code, $data);
            }
        }

        throw new OutOfRangeException(
            "No IDBF race plan covers {$crewCount} crews on a {$laneCount}-lane course"
        );
    }

    /**
     * Fetch a plan by its IDBF code (e.g. "RP.3A", "ROUNDS_6L").
     *
     * @throws InvalidArgumentException when the code is unknown
     */
    public function getPlan(string $code): RacePlan
    {
        if (!isset($this->plans[$code])) {
            throw new InvalidArgumentException("Unknown IDBF race plan code: {$code}");
        }
        return new RacePlan($code, $this->plans[$code]);
    }

    /**
     * All plan codes valid for the given lane count and crew count. Used by the
     * admin UI to populate the override dropdown.
     *
     * @return string[]
     */
    public function planOptions(int $laneCount, int $crewCount): array
    {
        $codes = [];
        foreach ($this->plans as $code => $data) {
            if ($data['lane_count'] !== $laneCount) {
                continue;
            }
            [$min, $max] = $data['crew_count_range'];
            if ($crewCount >= $min && $crewCount <= $max) {
                $codes[] = $code;
            }
        }
        return $codes;
    }

    /** All plan codes registered in the lookup. */
    public function allCodes(): array
    {
        return array_keys($this->plans);
    }
}
