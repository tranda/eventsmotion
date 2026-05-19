<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Discipline;
use App\Models\Team;
use App\Models\Crew;
use App\Models\Club;
use App\Models\TeamClubs;
use Illuminate\Http\Request;

class TeamController extends BaseController
{
    public function getAllTeams(Request $request)
    {
        if ($request->has('active')) {
            $teams = Team::whereHas('club', function ($query) {$query->where('active', 1);})
            ->orderBy('name')
            ->get();
        } else {
           $teams = Team::orderBy('name')
                        ->get();
        }
        return response()->json($teams);
    }

    public function getAllCombinedTeams(Request $request)
    {
        if ($request->has('active')) {
            $teams = TeamClubs::whereHas('club', function ($query) {$query->where('active', 1);})
            ->with('team')
            // ->orderBy('name')
            ->get();
        } else {
           $teams = Team::orderBy('name')
                        ->get();
        }
        return response()->json($teams);
    }

    public function getTeamsForClub(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $club_id = $user->club_id;
            $teams = Team::where('club_id', $club_id)
            ->orderBy('name')
            ->get();
            return response()->json($teams);
        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }

    public function getTeamsForDiscipline(Request $request)
    {
        $user = $request->user();
        if ($user) {
            // $discipline = Discipline::findOrFail($request->discipline_id);
            $discipline = Discipline::where('id', $request->discipline_id)->first();
            if ($discipline) {
                $discipline_id = $discipline->id;
                $crews = Crew::where('discipline_id', $discipline_id)->get();
                $teams = [];
                foreach ($crews as $crew) {
                    $team = Team::where('id', $crew->team_id)->first();
                    if ($team) {
                        $crew_ext = [
                            'crew' => $crew,
                            'team' => $team
                        ];
                        $teams[] = $crew_ext;
                    }
                }

                return response()->json($teams);
            } else {
                return response()->json(
                    ['error' => 'Discipline not found'],
                    404
                );
            }
        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }

    public function createTeam(Request $request)
    {
        $user = $request->user(); // Retrieve the authenticated user

        // Allow club_id to be passed in request, otherwise use user's club_id.
        // This lets referees, event managers, and admins create teams for any club.
        $requestedClubId = $request->input('club_id');
        $accessLevel = (int) $user->access_level;

        \Log::info('createTeam request', [
            'user_id' => $user->id,
            'user_access_level' => $user->access_level,
            'user_club_id' => $user->club_id,
            'requested_club_id' => $requestedClubId,
        ]);

        // Strict comparison: only fall back to user's own club when the user is
        // a club manager (access_level === 0). For everyone else, honor the
        // requested club_id whenever it is provided (and non-zero).
        $clubId = $requestedClubId;
        if ($accessLevel === 0 || $clubId === null || $clubId === '' || (int) $clubId === 0) {
            $clubId = $user->club_id;
        } else {
            $clubId = (int) $clubId;
        }

        \Log::info('createTeam resolved club', [
            'final_club_id' => $clubId,
        ]);

        $team = new Team();
        $team->name = $request->input('name');
        $team->club_id = $clubId;
        $team->save();

        $teamclub = new TeamClubs();
        $teamclub->club_id = $clubId;
        $teamclub->team_id = $team->id;
        $teamclub->save();

        // TEMP DEBUG: echo back what the server actually saw and resolved so we
        // can verify which branch fired without needing log access.
        return response()->json([
            'team' => $team,
            '_debug' => [
                'user_id' => $user->id,
                'user_access_level' => $user->access_level,
                'user_access_level_cast' => $accessLevel,
                'user_club_id' => $user->club_id,
                'requested_club_id' => $requestedClubId,
                'final_club_id' => $clubId,
            ],
        ]);
    }

    public function deleteTeam($id)
    {
        $team = Team::find($id);
        if (!$team) {
            return response()->json(['error' => 'Team not found'], 404);
        }

        // Check if team has associated crews
        $crewsCount = Crew::where('team_id', $id)->count();
        if ($crewsCount > 0) {
            return response()->json([
                'error' => 'Cannot delete team with existing crews',
                'crews_count' => $crewsCount
            ], 400);
        }

        // Delete associated team_clubs records (created automatically when team is created)
        TeamClubs::where('team_id', $id)->delete();

        // Delete the team
        $team->delete();
        return response()->json(['message' => 'Team deleted successfully'], 200);
    }

}
