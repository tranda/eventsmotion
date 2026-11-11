<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A non-crew person with an event badge (volunteer, VIP, media, staff). */
class BadgePerson extends Model
{
    protected $table = 'badge_people';

    public const TYPES = ['coach', 'official', 'support', 'media', 'volunteer', 'vip'];

    protected $fillable = [
        'event_id',
        'type',
        'first_name',
        'last_name',
        'organisation',
        'country',
        'photo',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }
}
