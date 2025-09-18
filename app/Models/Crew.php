<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Team;

class Crew extends Model
{
    use HasFactory;
	
	protected $fillable = [
        'team_id',
        'discipline_id'
    ];

    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id', 'id');
    }

    public function discipline()
    {
        return $this->belongsTo(Discipline::class);
    }
}
