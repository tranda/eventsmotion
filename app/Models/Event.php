<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory;

    /**
     * Days after the last event day during which corrections are still
     * allowed before the event locks for good.
     */
    public const EDIT_GRACE_DAYS = 14;
	
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
        'hulls_small',
        'hulls_standard',
        'long_race_max_small',
        'long_race_max_standard',
        'default_rounds',
        'min_crews_per_race',
        'color_map',
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
        'long_race_max_small' => 'integer',
        'long_race_max_standard' => 'integer',
        'color_map' => 'array',
    ];

    public function disciplines()
    {
        return $this->hasMany(Discipline::class);
    }

    public function eventDays()
    {
        return $this->hasMany(EventDay::class)->orderBy('sort_order');
    }

    /**
     * The latest scheduled day of the event, or null if no days are set.
     */
    public function lastEventDate(): ?Carbon
    {
        $max = $this->eventDays()->max('date');
        return $max ? Carbon::parse($max) : null;
    }

    /**
     * Whether the event is locked for data changes.
     *
     * Locked when the event is not active, or when it is more than
     * EDIT_GRACE_DAYS past its last scheduled day. An active event with no
     * days set is never date-locked.
     */
    public function isLocked(): bool
    {
        if ($this->status !== 'active') {
            return true;
        }

        $last = $this->lastEventDate();
        if ($last) {
            $deadline = $last->copy()->endOfDay()->addDays(self::EDIT_GRACE_DAYS);
            return now()->greaterThan($deadline);
        }

        return false;
    }
}
