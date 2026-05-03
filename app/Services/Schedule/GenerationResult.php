<?php

namespace App\Services\Schedule;

/**
 * Outcome of running ScheduleGeneratorService or DisciplineRegenerator.
 * Carries summary counts and any non-fatal warnings to surface in the UI.
 */
class GenerationResult
{
    /** @var string[] */
    public array $warnings = [];

    public int $racesCreated = 0;
    public int $crewLanesAssigned = 0;

    /** @var array<int, int>  discipline_id => race count */
    public array $racesPerDiscipline = [];

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function toArray(): array
    {
        return [
            'races_created' => $this->racesCreated,
            'crew_lanes_assigned' => $this->crewLanesAssigned,
            'races_per_discipline' => $this->racesPerDiscipline,
            'warnings' => $this->warnings,
        ];
    }
}
