<?php

namespace App\Support;

use App\Models\Crew;
use App\Models\Discipline;
use App\Models\Event;
use App\Models\RaceResult;

/**
 * Blocks data changes on events that are locked (inactive, or past their
 * correction grace window). Admins (access_level >= 3) always bypass the lock.
 */
class EventEditGuard
{
    /**
     * Abort with 403 when the given event is locked and the user is not an admin.
     * A null event fails closed for non-admins (nothing to edit safely).
     */
    public static function assertEditable(?Event $event, $user): void
    {
        // Admins can always make corrections.
        if ($user && $user->access_level >= 3) {
            return;
        }

        if (!$event || $event->isLocked()) {
            abort(403, 'This event is locked for changes.');
        }
    }

    /**
     * Guard a write scoped directly to an event.
     */
    public static function forEvent($eventId, $user): void
    {
        self::assertEditable($eventId ? Event::find($eventId) : null, $user);
    }

    /**
     * Guard a write scoped to a discipline (resolves discipline -> event).
     */
    public static function forDiscipline($disciplineId, $user): void
    {
        $event = optional(Discipline::find($disciplineId))->event;
        self::assertEditable($event, $user);
    }

    /**
     * Guard a write scoped to a crew (resolves crew -> discipline -> event).
     */
    public static function forCrew($crewId, $user): void
    {
        $event = optional(optional(Crew::find($crewId))->discipline)->event;
        self::assertEditable($event, $user);
    }

    /**
     * Guard a write scoped to a race result (uses its direct event link,
     * falling back to its discipline's event).
     */
    public static function forRaceResult(?RaceResult $raceResult, $user): void
    {
        $event = null;
        if ($raceResult) {
            $event = $raceResult->event_id
                ? Event::find($raceResult->event_id)
                : optional($raceResult->discipline)->event;
        }
        self::assertEditable($event, $user);
    }
}
