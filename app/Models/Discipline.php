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
        'competition',
        'status',
        'combined_with_discipline_id',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function crews()
    {
        return $this->hasMany(Crew::class);
    }

    public function progression()
    {
        return $this->hasOne(DisciplineProgression::class);
    }

    /**
     * The HOST discipline this one races together with (set on the secondary).
     */
    public function combinedWith()
    {
        return $this->belongsTo(Discipline::class, 'combined_with_discipline_id');
    }

    /** True when this discipline races combined with another (as host or secondary). */
    public function isCombined(): bool
    {
        return $this->isCombinedSecondary() || $this->isCombinedHost();
    }

    /** A secondary points at its host via combined_with_discipline_id. */
    public function isCombinedSecondary(): bool
    {
        return $this->combined_with_discipline_id !== null;
    }

    /** A host has one or more secondaries pointing at it. */
    public function isCombinedHost(): bool
    {
        if ($this->combined_with_discipline_id !== null) {
            return false; // a secondary is never also a host (pairwise v1)
        }
        return Discipline::where('combined_with_discipline_id', $this->id)->exists();
    }

    /** The host discipline id for this combined group (self if host, else the FK). */
    public function combinedHostId(): int
    {
        return $this->combined_with_discipline_id ?? $this->id;
    }

    /**
     * All disciplines that race together in this group: the host followed by
     * every secondary pointing at it. For a non-combined discipline this is
     * just [$this].
     */
    public function combinedMembers()
    {
        $hostId = $this->combinedHostId();
        $host = $hostId === $this->id ? $this : (Discipline::find($hostId) ?? $this);
        $secondaries = Discipline::where('combined_with_discipline_id', $hostId)
            ->orderBy('id')
            ->get();

        return collect([$host])->concat($secondaries)->values();
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
