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

    // Automatically append these relationships when converting to array/JSON
    protected $with = ['club'];

    public function crews()
    {
        return $this->hasMany(Crew::class);
    }

    public function club()
    {
        return $this->belongsTo(Club::class);
    }
}
