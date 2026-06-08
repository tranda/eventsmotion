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
        'event_id',
        'race_time',
        'stage',
        'status',
        'images',
        'entry_type',
        'duration_seconds',
        'label',
        'shift_subsequent',
        'progression_note',
    ];

    protected $casts = [
        'race_time' => 'datetime',
        'images' => 'array',
        'shift_subsequent' => 'boolean',
    ];

    /**
     * Appends to array/JSON output.
     */
    protected $appends = ['title', 'show_accumulated_time'];

    /**
     * Get the discipline that this race result belongs to.
     * Null for break entries (lunch, ceremonies, etc.).
     */
    public function discipline()
    {
        return $this->belongsTo(Discipline::class);
    }

    /**
     * Direct event link (used by break entries that have no discipline).
     */
    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    /** Convenience: true if this row is a non-race entry (pause, ceremony, etc.). */
    public function isBreak(): bool
    {
        return $this->entry_type === 'break';
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

        \Log::info('🏁 Final round check for race', [
            'race_id' => $this->id,
            'stage' => $this->stage,
            'discipline_id' => $this->discipline_id,
            'is_final_round' => $isFinalRound
        ]);

        // Get final times if this is the final round
        $finalTimes = $isFinalRound ? $this->getFinalTimesForDiscipline() : collect();

        if ($isFinalRound) {
            \Log::info('🏁 Final times calculated', [
                'race_id' => $this->id,
                'stage' => $this->stage,
                'final_times_count' => $finalTimes->count(),
                'final_times' => $finalTimes->toArray()
            ]);
        }

        // Merge registered crews with existing results
        return $registeredCrews->map(function ($crew) use ($existingResults, $isFinalRound, $finalTimes) {
            if ($existingResults->has($crew->id)) {
                // Get the existing crew result and convert to plain object
                $crewResult = $existingResults->get($crew->id);

                // Convert to plain object to avoid Eloquent serialization issues
                $resultObject = (object) [
                    'id' => $crewResult->id,
                    'crew_id' => $crewResult->crew_id,
                    'race_result_id' => $crewResult->race_result_id,
                    'lane' => $crewResult->lane,
                    'position' => $crewResult->position,
                    'time_ms' => $crewResult->time_ms,
                    'delay_after_first' => $crewResult->delay_after_first,
                    'status' => $crewResult->status,
                    'crew' => $crewResult->crew,
                    'created_at' => $crewResult->created_at,
                    'updated_at' => $crewResult->updated_at
                ];

                // Add final round information if applicable
                if ($isFinalRound && $finalTimes->has($crew->id)) {
                    $finalTimeData = $finalTimes->get($crew->id);
                    $resultObject->final_time_ms = $finalTimeData['final_time_ms'];
                    $resultObject->final_status = $finalTimeData['final_status'];
                    $resultObject->is_final_round = true;
                } else {
                    $resultObject->final_time_ms = null;
                    $resultObject->final_status = null;
                    $resultObject->is_final_round = $isFinalRound;
                }

                return $resultObject;
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
        })->sortBy(function ($crewResult) use ($isFinalRound) {
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
     * A race is considered the final round if:
     * 1. Its stage is exactly "Grand Final" or "Final" (exact string matches), OR
     * 2. It is the highest race number in the discipline AND not an excluded stage type
     *
     * Stages like "Minor Final", "Semi Final", "Heat X" (when not the highest), etc.
     * are NOT considered final rounds.
     */
    public function isFinalRound()
    {
        // First check: Exact stage name matches for "Final" and "Grand Final"
        $finalStages = ['Grand Final', 'Final'];
        $isExactFinalStage = in_array($this->stage, $finalStages, true);

        if ($isExactFinalStage) {
            \Log::info('🏁 Final round determination - exact stage match', [
                'race_id' => $this->id,
                'stage' => $this->stage,
                'discipline_id' => $this->discipline_id,
                'is_final' => true,
                'reason' => 'exact_stage_match'
            ]);
            return true;
        }

        // Second check: Exclude stages that should never be considered final
        $excludedStages = [
            'Minor Final', 'Minor final', 'minor final',
            'Semi Final', 'Semifinal', 'semi final', 'semifinal',
            'Quarter Final', 'Quarterfinal', 'quarter final', 'quarterfinal',
            'Consolation Final', 'consolation final'
        ];

        $isExcludedStage = in_array($this->stage, $excludedStages, true);

        if ($isExcludedStage) {
            \Log::info('🏁 Final round determination - excluded stage', [
                'race_id' => $this->id,
                'stage' => $this->stage,
                'discipline_id' => $this->discipline_id,
                'is_final' => false,
                'reason' => 'excluded_stage_type'
            ]);
            return false;
        }

        // Third check: Is this the highest race number in the discipline?
        $isHighestRaceNumber = $this->isHighestRaceNumberInDiscipline();

        \Log::info('🏁 Final round determination', [
            'race_id' => $this->id,
            'stage' => $this->stage,
            'discipline_id' => $this->discipline_id,
            'race_number' => $this->race_number,
            'is_exact_final_stage' => $isExactFinalStage,
            'is_excluded_stage' => $isExcludedStage,
            'is_highest_race_number' => $isHighestRaceNumber,
            'is_final' => $isHighestRaceNumber,
            'reason' => $isHighestRaceNumber ? 'highest_race_number' : 'not_final'
        ]);

        return $isHighestRaceNumber;
    }

    /**
     * Check if this race result has the highest race number in its discipline.
     * Race numbers are the most reliable indicator of race sequence.
     *
     * @return bool
     */
    public function isHighestRaceNumberInDiscipline()
    {
        // Get the highest race number for this discipline, ignoring CANCELLED
        // races. If the last round of a Rounds plan is cancelled by the jury,
        // the previous round becomes the "highest" — that's the one whose
        // results should drive the final standings.
        $highestRaceNumber = RaceResult::where('discipline_id', $this->discipline_id)
            ->where('status', '!=', 'CANCELLED')
            ->max('race_number');

        // If there's no other race or this race has the highest number, it could be final
        $isHighest = $this->race_number === $highestRaceNumber;

        \Log::info('🏁 Race number check for final determination', [
            'race_id' => $this->id,
            'race_number' => $this->race_number,
            'highest_race_number' => $highestRaceNumber,
            'discipline_id' => $this->discipline_id,
            'is_highest' => $isHighest
        ]);

        return $isHighest;
    }


    /**
     * Determine whether accumulated times should be shown for this race.
     * Accumulated times should ONLY be shown for stages that contain "Round"
     * in the name AND are the chronologically last round in a discipline sequence.
     *
     * @return bool
     */
    public function shouldShowAccumulatedTime()
    {
        // First check: The stage name must contain "Round" (case insensitive)
        if (stripos($this->stage, 'Round') === false) {
            return false;
        }

        // Second check: It must be the chronologically last round (highest race number)
        return $this->isHighestRaceNumberInDiscipline();
    }

    /**
     * Accessor for the show_accumulated_time appended attribute.
     *
     * @return bool
     */
    public function getShowAccumulatedTimeAttribute()
    {
        return $this->shouldShowAccumulatedTime();
    }

    /**
     * Get final times for all crews in this discipline, keyed by crew_id.
     *
     * For round-based plans (Round 1 + Round 2 + …), final_time_ms is the
     * sum of all round times — every round counts toward the standing.
     *
     * For heat-based plans (Heat → Rep → Grand Final), the Grand Final time
     * alone determines the standing. Heats and repechages are pure
     * qualification; summing them across crews who took different paths
     * (e.g. Heat→Final vs Heat→Rep→Final) is structurally unfair.
     *
     * Returns a collection keyed by crew_id with final_time_ms and final_status.
     */
    public function getFinalTimesForDiscipline()
    {
        $finalTimes = collect();
        $isRoundBased = $this->shouldShowAccumulatedTime();

        if ($isRoundBased) {
            // Sum all rounds for every participating crew. Per IDBF rules,
            // a crew has to FINISH every round to be ranked — any DNS/DNF/DSQ
            // along the way disqualifies them from accumulated standings.
            // We deliberately do NOT filter the eager load by time_ms here:
            // we need the non-FINISHED rows so we can detect them. The old
            // filter let DNS/DNF crews skip the check and get ranked by
            // just their FINISHED round(s), which produced false-leader bugs
            // (a single 50.000 round outranking crews that did both rounds).
            //
            // CANCELLED races are excluded entirely — if Round 2 is called off
            // by the jury, final standings collapse to Round 1 alone.
            $allRaceResults = RaceResult::where('discipline_id', $this->discipline_id)
                ->where('status', '!=', 'CANCELLED')
                ->with('crewResults')
                ->get();
            $totalRounds = $allRaceResults->count();

            $allCrewIds = $allRaceResults
                ->flatMap(fn($r) => $r->crewResults->pluck('crew_id'))
                ->unique();

            foreach ($allCrewIds as $crewId) {
                $crewResults = $allRaceResults->flatMap(
                    fn($r) => $r->crewResults->where('crew_id', $crewId)
                );

                // Worst-status wins: DSQ > DNF > DNS. Any of them → final
                // = that status, no time.
                foreach (['DSQ', 'DNF', 'DNS'] as $terminal) {
                    if ($crewResults->contains('status', $terminal)) {
                        $finalTimes->put($crewId, ['final_time_ms' => null, 'final_status' => $terminal]);
                        continue 2;
                    }
                }

                // Otherwise: must be FINISHED with a positive time in every
                // round of the discipline to count. Missing a round (no row
                // at all) or sitting on a null status means incomplete data
                // — leave out of finalTimes so they don't get ranked.
                $finishedResults = $crewResults->where('status', 'FINISHED')
                    ->whereNotNull('time_ms')->where('time_ms', '>', 0);
                if ($totalRounds > 0 && $finishedResults->count() === $totalRounds) {
                    $finalTimes->put($crewId, [
                        'final_time_ms' => $finishedResults->sum('time_ms'),
                        'final_status' => 'FINISHED',
                    ]);
                }
            }
        } else {
            // Heat-based plan: only the Grand Final time counts. Use the
            // current race's crew results — we are by definition on the
            // final race of the discipline.
            foreach ($this->crewResults as $cr) {
                if ($cr->status === 'DSQ') {
                    $finalTimes->put($cr->crew_id, ['final_time_ms' => null, 'final_status' => 'DSQ']);
                } elseif ($cr->status === 'FINISHED' && $cr->time_ms && $cr->time_ms > 0) {
                    $finalTimes->put($cr->crew_id, [
                        'final_time_ms' => $cr->time_ms,
                        'final_status' => 'FINISHED',
                    ]);
                }
            }
        }

        return $finalTimes;
    }

    /**
     * Scope to get results for a specific event. Includes both races
     * (linked via discipline) and break entries (linked via event_id directly).
     */
    public function scopeForEvent($query, $eventId)
    {
        return $query->where(function ($q) use ($eventId) {
            $q->whereHas('discipline', fn($qq) => $qq->where('event_id', $eventId))
              ->orWhere('event_id', $eventId);
        });
    }

    /**
     * Scope to results that belong to events whose schedule has been published.
     * Used by public/non-admin callers so draft schedules stay hidden.
     * Covers both race rows (via discipline.event) and break rows (via event).
     */
    public function scopePublished($query)
    {
        return $query->where(function ($q) {
            $q->whereHas('discipline.event', fn($qq) => $qq->where('schedule_status', 'published'))
              ->orWhereHas('event', fn($qq) => $qq->where('schedule_status', 'published'));
        });
    }
}