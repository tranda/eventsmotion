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
        'crew_entries_lock'
    ];

    protected $dates = ['deleted_at'];

    protected $casts = [
        'available' => 'boolean',
        'race_entries_lock' => 'datetime',
        'name_entries_lock' => 'datetime',
        'crew_entries_lock' => 'datetime',
    ];

    public function disciplines()
    {
        return $this->hasMany(Discipline::class);
    }
}
