<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventDay extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'date',
        'name',
        'sort_order',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function blocks()
    {
        return $this->hasMany(ScheduleBlock::class)->orderBy('sort_order');
    }
}
