<?php

namespace App\Support;

use App\Models\Team;

/**
 * Blocks race registration for inactive teams (or teams whose club is
 * inactive). Admins (access_level >= 3) always bypass the check, mirroring
 * EventEditGuard. A null/legacy `active` value is treated as active.
 */
class TeamRegistrationGuard
{
    /**
     * Abort with 403 when the team (or its club) is inactive and the user
     * is not an admin. Aborts 404 when the team does not exist.
     */
    public static function assertCanRegister($teamId, $user): void
    {
        // Admins can always register, even for inactive teams.
        if ($user && $user->access_level >= 3) {
            return;
        }

        $team = Team::find($teamId);
        if (!$team) {
            abort(404, 'Team not found.');
        }

        $teamInactive = $team->active !== null && !$team->active;
        $clubInactive = $team->club
            && $team->club->active !== null
            && !$team->club->active;

        if ($teamInactive || $clubInactive) {
            abort(403, 'This team is inactive and cannot register for races.');
        }
    }
}
