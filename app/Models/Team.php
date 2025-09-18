<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;
	
	protected $fillable = [
        'name',
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
}
