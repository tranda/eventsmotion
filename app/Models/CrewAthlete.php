<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CrewAthlete extends Model
{
    use HasFactory;
	
	protected $fillable = [
        'no',
        'crew_id',
        'athlete_id'
    ];

    public function athlete()
    {
        return $this->belongsTo(Athlete::class);
    }
}
