<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Discipline extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'distance',
        'age_group',
        'gender_group',
        'boat_group',
        'status',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function crews()
    {
        return $this->hasMany(Crew::class);
    }

    /**
     * Get the display name for this discipline.
     * Generates a title like "Men's K1 500m" from the discipline attributes.
     * 
     * @return string
     */
    public function getDisplayName()
    {
        return trim($this->boat_group  . $this->age_group . ' ' . $this->gender_group . ' ' . $this->distance . 'm ');
    }
}
