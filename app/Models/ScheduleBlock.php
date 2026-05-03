<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScheduleBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_day_id',
        'name',
        'start_time',
        'gap_seconds',
        'gender_filter',
        'distance_filter',
        'stage_filter',
        'sort_order',
    ];

    protected $casts = [
        'gender_filter' => 'array',
        'distance_filter' => 'array',
        'stage_filter' => 'array',
    ];

    public function eventDay()
    {
        return $this->belongsTo(EventDay::class);
    }
}
