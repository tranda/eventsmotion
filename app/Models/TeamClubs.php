<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TeamClubs extends Model
{
    use HasFactory;
	
	protected $fillable = [
        'name',
        'team_id',
        'club_id'
    ];

    public function crews()
    {
        return $this->hasMany(Crew::class);
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
