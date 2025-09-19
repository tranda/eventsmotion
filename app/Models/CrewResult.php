<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CrewResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'crew_id',
        'race_result_id',
        'lane',
        'position',
        'time_ms',
        'delay_after_first',
        'status',
    ];

    /**
     * Get the crew that this result belongs to.
     */
    public function crew()
    {
        return $this->belongsTo(Crew::class);
    }

    /**
     * Get the race result that this crew result belongs to.
     */
    public function raceResult()
    {
        return $this->belongsTo(RaceResult::class);
    }

    /**
     * Get the team through the crew relationship.
     */
    public function team()
    {
        return $this->hasOneThrough(Team::class, Crew::class, 'id', 'id', 'crew_id', 'team_id');
    }

    /**
     * Scope to filter by status.
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get finished results (exclude DNS, DNF, DSQ).
     */
    public function scopeFinished($query)
    {
        return $query->where('status', 'FINISHED');
    }

    /**
     * Scope to order by position.
     */
    public function scopeOrderedByPosition($query)
    {
        return $query->orderBy('position', 'asc');
    }

    /**
     * Check if the crew result is a valid finishing position.
     */
    public function isFinished()
    {
        return $this->status === 'FINISHED' && !is_null($this->position);
    }

    /**
     * Check if the crew did not start (DNS).
     */
    public function didNotStart()
    {
        return $this->status === 'DNS';
    }

    /**
     * Check if the crew did not finish (DNF).
     */
    public function didNotFinish()
    {
        return $this->status === 'DNF';
    }

    /**
     * Check if the crew was disqualified (DSQ).
     */
    public function wasDisqualified()
    {
        return $this->status === 'DSQ';
    }

    /**
     * Get formatted time as string (MM:SS.mmm).
     */
    public function getFormattedTimeAttribute()
    {
        if (!$this->time_ms) return null;
        
        $totalMs = $this->time_ms;
        $minutes = floor($totalMs / 60000);
        $seconds = floor(($totalMs % 60000) / 1000);
        $milliseconds = $totalMs % 1000;
        
        return sprintf('%02d:%02d.%03d', $minutes, $seconds, $milliseconds);
    }

    /**
     * Get time in seconds (for calculations).
     */
    public function getTimeInSecondsAttribute()
    {
        return $this->time_ms ? $this->time_ms / 1000 : null;
    }

    /**
     * Get formatted final time as string (MM:SS.mmm).
     * This is used for multi-round races to display the accumulated time.
     */
    public function getFormattedFinalTimeAttribute()
    {
        if (!isset($this->final_time_ms) || !$this->final_time_ms) return null;

        $totalMs = $this->final_time_ms;
        $minutes = floor($totalMs / 60000);
        $seconds = floor(($totalMs % 60000) / 1000);
        $milliseconds = $totalMs % 1000;

        return sprintf('%02d:%02d.%03d', $minutes, $seconds, $milliseconds);
    }

    /**
     * Get final time in seconds (for calculations).
     */
    public function getFinalTimeInSecondsAttribute()
    {
        return isset($this->final_time_ms) && $this->final_time_ms ? $this->final_time_ms / 1000 : null;
    }

    /**
     * Static method to format time from milliseconds.
     * Useful for formatting times without a model instance.
     */
    public static function formatTimeFromMs($timeMs)
    {
        if (!$timeMs) return null;

        $minutes = floor($timeMs / 60000);
        $seconds = floor(($timeMs % 60000) / 1000);
        $milliseconds = $timeMs % 1000;

        return sprintf('%02d:%02d.%03d', $minutes, $seconds, $milliseconds);
    }
}