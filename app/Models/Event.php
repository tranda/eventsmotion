<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;
	
	protected $fillable = [
        'name',
        'location',
        'year',
        'status',
        'available',
        'standard_reserves',
        'standard_min_gende',
        'standard_max_gender',
        'small_reserves',
        'small_min_gender',
        'small_max_gender',
        'race_entries_lock',
        'name_entries_lock',
        'crew_entries_lock',
        'lane_count',
        'default_rounds',
        'min_crews_per_race',
        'schedule_status',
        'schedule_published_at',
    ];

    protected $dates = ['deleted_at'];

    protected $casts = [
        'available' => 'boolean',
        'race_entries_lock' => 'datetime',
        'name_entries_lock' => 'datetime',
        'crew_entries_lock' => 'datetime',
        'schedule_published_at' => 'datetime',
        'lane_count' => 'integer',
        'default_rounds' => 'integer',
        'min_crews_per_race' => 'integer',
    ];

    public function disciplines()
    {
        return $this->hasMany(Discipline::class);
    }

    public function eventDays()
    {
        return $this->hasMany(EventDay::class)->orderBy('sort_order');
    }
}
