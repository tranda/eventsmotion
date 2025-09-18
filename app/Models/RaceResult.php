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

        // Merge registered crews with existing results
        return $registeredCrews->map(function ($crew) use ($existingResults) {
            if ($existingResults->has($crew->id)) {
                // Return the existing crew result
                return $existingResults->get($crew->id);
            } else {
                // Create a virtual crew result for crews without results
                return (object) [
                    'id' => null,
                    'crew_id' => $crew->id,
                    'race_result_id' => $this->id,
                    'position' => null,
                    'time' => null,
                    'delay_after_first' => null,
                    'status' => null,
                    'crew' => $crew,
                    'created_at' => null,
                    'updated_at' => null
                ];
            }
        })->sortBy(function ($crewResult) {
            // Sort by position first (nulls last), then by time_ms (fastest first), then by crew team name
            return [
                $crewResult->position ?? 999999,
                $crewResult->time_ms ?? 999999999,
                $crewResult->crew->team->name ?? ''
            ];
        })->values();
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