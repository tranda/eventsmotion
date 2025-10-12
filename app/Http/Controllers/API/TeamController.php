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
        // $competition = Event::where('id', 1)->first();

        // Allow club_id to be passed in request, otherwise use user's club_id
        // This allows referees, event managers, and admins to create teams for any club
        $clubId = $request->input('club_id');

        // If club_id not provided or user has access_level 0 (club manager), use user's club_id
        if (!$clubId || $user->access_level == 0) {
            $clubId = $user->club_id;
        }

        $team = new Team();
        $team->name = $request->input('name');
        $team->club_id = $clubId;

        $team->save();

        $teamclub = new TeamClubs();
        $teamclub->club_id = $clubId;
        $teamclub->team_id = $team->id;
        $teamclub->save();

        return response()->json($team);
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

        // Check if team has records in team_clubs
        $teamClubsCount = TeamClubs::where('team_id', $id)->count();
        if ($teamClubsCount > 0) {
            return response()->json([
                'error' => 'Cannot delete team with existing team_clubs records',
                'team_clubs_count' => $teamClubsCount
            ], 400);
        }

        $team->delete();
        return response()->json(['message' => 'Team deleted successfully'], 200);
    }

}
