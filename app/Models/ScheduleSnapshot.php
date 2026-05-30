<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleSnapshot extends Model
{
    protected $fillable = [
        'event_id', 'category', 'day', 'name', 'payload', 'created_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'day' => 'date',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
