<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BoatCheckin extends Model
{
    protected $fillable = [
        'race_result_id',
        'crew_id',
        'athlete_id',
        'stage',
        'race_number',
        'seat_no',
        'role',
        'checked_by',
        'checked_at',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'seat_no' => 'integer',
        'race_number' => 'integer',
    ];

    public function raceResult()
    {
        return $this->belongsTo(RaceResult::class);
    }

    public function crew()
    {
        return $this->belongsTo(Crew::class);
    }

    public function athlete()
    {
        return $this->belongsTo(Athlete::class);
    }
}
