<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RaceResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'race_number',
        'discipline_id',
        'race_time',
        'stage',
        'status',
    ];

    protected $casts = [
        'race_time' => 'datetime',
    ];

    /**
     * Appends to array/JSON output.
     */
    protected $appends = ['title'];

    /**
     * Get the discipline that this race result belongs to.
     */
    public function discipline()
    {
        return $this->belongsTo(Discipline::class);
    }

    /**
     * Get the title for this race result.
     * Dynamically generates the title from the discipline's display name.
     * 
     * @return string
     */
    public function getTitleAttribute()
    {
        if ($this->discipline) {
            return $this->discipline->getDisplayName();
        }
        
        return 'Unknown Race';
    }

    /**
     * Alternative method to get the title (for explicit calls).
     * 
     * @return string
     */
    public function getTitle()
    {
        return $this->getTitleAttribute();
    }

    /**
     * Get the crew results for this race.
     */
    public function crewResults()
    {
        return $this->hasMany(CrewResult::class);
    }

    /**
     * Get the crew results ordered by position.
     */
    public function crewResultsOrdered()
    {
        return $this->hasMany(CrewResult::class)->orderBy('position', 'asc');
    }

    /**
     * Scope to filter by status.
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by discipline.
     */
    public function scopeByDiscipline($query, $disciplineId)
    {
        return $query->where('discipline_id', $disciplineId);
    }

    /**
     * Get all crews registered for this race (through discipline).
     * This includes crews that may not have results yet.
     */
    public function registeredCrews()
    {
        return $this->hasManyThrough(
            Crew::class,
            Discipline::class,
            'id', // Foreign key on disciplines table
            'discipline_id', // Foreign key on crews table
            'discipline_id', // Local key on race_results table
            'id' // Local key on disciplines table
        );
    }

    /**
     * Get all crew results with registered crews merged in.
     * Shows crews even if they don't have results yet.
     */
    public function allCrewResults()
    {
        // Get all registered crews for this discipline
        $registeredCrews = $this->registeredCrews()
            ->with(['team', 'discipline'])
            ->get();

        // Get existing crew results
        $existingResults = $this->crewResults()
            ->with(['crew.team', 'crew.discipline'])
            ->get()
            ->keyBy('crew_id');

        // Check if this is the final round for this discipline
        $isFinalRound = $this->isFinalRound();

        // Get final times if this is the final round
        $finalTimes = $isFinalRound ? $this->getFinalTimesForDiscipline() : collect();

        // Merge registered crews with existing results
        return $registeredCrews->map(function ($crew) use ($existingResults, $isFinalRound, $finalTimes) {
            if ($existingResults->has($crew->id)) {
                // Return the existing crew result
                $crewResult = $existingResults->get($crew->id);

                // Add final round information if applicable
                if ($isFinalRound && $finalTimes->has($crew->id)) {
                    $finalTimeData = $finalTimes->get($crew->id);
                    $crewResult->final_time_ms = $finalTimeData['final_time_ms'];
                    $crewResult->final_status = $finalTimeData['final_status'];
                    $crewResult->is_final_round = true;
                } else {
                    $crewResult->final_time_ms = null;
                    $crewResult->final_status = null;
                    $crewResult->is_final_round = $isFinalRound;
                }

                return $crewResult;
            } else {
                // Create a virtual crew result for crews without results
                $virtualResult = (object) [
                    'id' => null,
                    'crew_id' => $crew->id,
                    'race_result_id' => $this->id,
                    'position' => null,
                    'time_ms' => null,
                    'delay_after_first' => null,
                    'status' => null,
                    'crew' => $crew,
                    'final_time_ms' => null,
                    'final_status' => null,
                    'is_final_round' => $isFinalRound,
                    'created_at' => null,
                    'updated_at' => null
                ];

                // Add final time data if this is final round and crew has times from previous rounds
                if ($isFinalRound && $finalTimes->has($crew->id)) {
                    $finalTimeData = $finalTimes->get($crew->id);
                    $virtualResult->final_time_ms = $finalTimeData['final_time_ms'];
                    $virtualResult->final_status = $finalTimeData['final_status'];
                }

                return $virtualResult;
            }
        })->sortBy(function ($crewResult) {
            // For final rounds, sort by final accumulated time, otherwise by current round time
            if ($isFinalRound && isset($crewResult->final_time_ms)) {
                return [
                    $crewResult->final_status === 'DSQ' ? 999999998 : ($crewResult->final_time_ms ?? 999999999),
                    $crewResult->crew->team->name ?? ''
                ];
            } else {
                // Sort by position first (nulls last), then by time_ms (fastest first), then by crew team name
                return [
                    $crewResult->position ?? 999999,
                    $crewResult->time_ms ?? 999999999,
                    $crewResult->crew->team->name ?? ''
                ];
            }
        })->values();
    }

    /**
     * Check if this race result represents the final round for its discipline.
     * A race is considered the final round if it's the last stage chronologically.
     */
    public function isFinalRound()
    {
        // Get all race results for this discipline ordered by stage
        $allRounds = RaceResult::where('discipline_id', $this->discipline_id)
            ->orderBy('stage')
            ->get();

        // If there's only one round, it's the final round
        if ($allRounds->count() <= 1) {
            return true;
        }

        // Check if this is the last stage alphabetically/numerically
        $lastRound = $allRounds->last();
        return $lastRound->id === $this->id;
    }

    /**
     * Get final accumulated times for all crews in this discipline.
     * Returns a collection keyed by crew_id with final_time_ms and final_status.
     */
    public function getFinalTimesForDiscipline()
    {
        // Get all race results for this discipline
        $allRaceResults = RaceResult::where('discipline_id', $this->discipline_id)
            ->with(['crewResults' => function($query) {
                $query->whereNotNull('time_ms')->where('time_ms', '>', 0);
            }])
            ->get();

        $finalTimes = collect();

        // Get all unique crew IDs that participated in any round
        $allCrewIds = $allRaceResults->flatMap(function($raceResult) {
            return $raceResult->crewResults->pluck('crew_id');
        })->unique();

        foreach ($allCrewIds as $crewId) {
            $crewResults = $allRaceResults->flatMap(function($raceResult) use ($crewId) {
                return $raceResult->crewResults->where('crew_id', $crewId);
            });

            // Check if any round has DSQ status
            $hasDSQ = $crewResults->contains('status', 'DSQ');

            if ($hasDSQ) {
                $finalTimes->put($crewId, [
                    'final_time_ms' => null,
                    'final_status' => 'DSQ'
                ]);
            } else {
                // Calculate total time across all finished rounds
                $finishedResults = $crewResults->where('status', 'FINISHED')
                    ->whereNotNull('time_ms')
                    ->where('time_ms', '>', 0);

                if ($finishedResults->count() > 0) {
                    $totalTimeMs = $finishedResults->sum('time_ms');
                    $finalTimes->put($crewId, [
                        'final_time_ms' => $totalTimeMs,
                        'final_status' => 'FINISHED'
                    ]);
                }
            }
        }

        return $finalTimes;
    }

    /**
     * Scope to get results for a specific event (through discipline).
     */
    public function scopeForEvent($query, $eventId)
    {
        return $query->whereHas('discipline', function ($q) use ($eventId) {
            $q->where('event_id', $eventId);
        });
    }
}