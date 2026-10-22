<?php

namespace App\Support;

use App\Models\Athlete;
use App\Models\Crew;
use App\Models\Team;
use App\Models\TeamClubs;

/**
 * Keeps club users (access_level 0) to their own club's data. Officials and
 * managers (access_level >= 1) work across clubs and are not restricted here.
 * A team belongs to its own club plus any partner clubs in team_clubs
 * (combined teams).
 */
class ClubAccess
{
    public static function isStaff($user): bool
    {
        return $user && ($user->access_level ?? 0) >= 1;
    }

    /** Clubs that own a team: its club plus combined-team partner clubs. */
    public static function teamClubIds($teamId): array
    {
        $team = Team::find($teamId);
        if (!$team) {
            return [];
        }
        $ids = TeamClubs::where('team_id', $team->id)->pluck('club_id')->all();
        $ids[] = $team->club_id;
        return array_values(array_unique(array_map('intval', array_filter($ids))));
    }

    public static function ownsTeam($user, $teamId): bool
    {
        return self::isStaff($user)
            || ($user && in_array((int) $user->club_id, self::teamClubIds($teamId), true));
    }

    public static function ownsAthlete($user, ?Athlete $athlete): bool
    {
        return $athlete && (self::isStaff($user) || ($user && (int) $athlete->club_id === (int) $user->club_id));
    }

    /** Abort 403 unless the user's club owns the team. */
    public static function assertTeam($user, $teamId): void
    {
        if (!self::ownsTeam($user, $teamId)) {
            abort(403, 'This team belongs to another club.');
        }
    }

    /** Abort 403 unless the user's club owns the crew's team. */
    public static function assertCrew($user, $crewId): Crew
    {
        $crew = Crew::find($crewId);
        if (!$crew) {
            abort(404, 'Crew not found.');
        }
        self::assertTeam($user, $crew->team_id);
        return $crew;
    }

    /**
     * Abort 403 when a club user puts an athlete into a crew whose team the
     * athlete's club is not part of.
     */
    public static function assertAthleteFitsCrew($user, $athleteId, Crew $crew): void
    {
        if (self::isStaff($user) || $athleteId === null || $athleteId === '' || (int) $athleteId === 0) {
            return;
        }
        $athlete = Athlete::find($athleteId);
        if (!$athlete || !in_array((int) $athlete->club_id, self::teamClubIds($crew->team_id), true)) {
            abort(403, 'This athlete is not in a club of this team.');
        }
    }
}
