<?php

namespace App\Services\Schedule;

/**
 * Outcome of a single LaneSeeder pass: which stage was seeded, lane count
 * filled, and any warnings (e.g., position references that pointed at a crew
 * which doesn't exist due to DSQ/DNS attrition).
 */
class SeedingResult
{
    public ?string $seededStage = null;
    public int $crewLanesAssigned = 0;

    /** @var string[] */
    public array $warnings = [];

    public bool $skipped = false;
    public ?string $skippedReason = null;

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function toArray(): array
    {
        return [
            'seeded_stage' => $this->seededStage,
            'crew_lanes_assigned' => $this->crewLanesAssigned,
            'warnings' => $this->warnings,
            'skipped' => $this->skipped,
            'skipped_reason' => $this->skippedReason,
        ];
    }
}
