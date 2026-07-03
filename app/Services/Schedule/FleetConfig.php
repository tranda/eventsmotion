<?php

namespace App\Services\Schedule;

use App\Models\Event;

/**
 * Immutable parsed view of an event's boat-set configuration.
 *
 * Reads events.hulls_small / hulls_standard (comma-separated letter
 * lists like "D,E,F") and exposes them as clean uppercase arrays for
 * the placement pass to iterate. Discipline boat_group values are
 * mapped case-insensitively to their fleet:
 *
 *   "Small"    → hulls_small
 *   "Standard" → hulls_standard
 *   anything else, or empty → no fleet (returns []); the placement
 *   pass skips hull rotation for that race and emits one warning per
 *   unmapped boat_group per generate.
 */
class FleetConfig
{
    /** @var string[] */
    private array $small;
    /** @var string[] */
    private array $standard;

    /**
     * @param string[] $small
     * @param string[] $standard
     */
    public function __construct(array $small = [], array $standard = [])
    {
        $this->small = $small;
        $this->standard = $standard;
    }

    public static function fromEvent(Event $event): self
    {
        return new self(
            self::parseList($event->hulls_small ?? null),
            self::parseList($event->hulls_standard ?? null),
        );
    }

    /**
     * "D, e , d , F ,, " → ["D","E","F"]. Trims, uppercases, drops
     * empties, dedupes preserving first occurrence.
     *
     * @return string[]
     */
    private static function parseList(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $raw));
        $out = [];
        foreach ($parts as $p) {
            if ($p === '') continue;
            $upper = strtoupper($p);
            if (!in_array($upper, $out, true)) {
                $out[] = $upper;
            }
        }
        return $out;
    }

    /** @return string[] */
    public function small(): array { return $this->small; }
    /** @return string[] */
    public function standard(): array { return $this->standard; }

    /** True if either fleet is non-empty (i.e. hull rotation is on for the event). */
    public function isConfigured(): bool
    {
        return !empty($this->small) || !empty($this->standard);
    }

    /**
     * Fleet for a discipline's boat_group. Case-insensitive match.
     * Returns [] when boat_group is null / unmapped / the matched fleet
     * is empty — placement pass then places the race without a hull.
     *
     * @return string[]
     */
    public function fleetFor(?string $boatGroup): array
    {
        if ($boatGroup === null || trim($boatGroup) === '') {
            return [];
        }
        return match (strtolower(trim($boatGroup))) {
            'small' => $this->small,
            'standard' => $this->standard,
            default => [],
        };
    }
}
