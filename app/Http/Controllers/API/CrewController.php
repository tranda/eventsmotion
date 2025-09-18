<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;

use Illuminate\Http\Request;
use App\Models\Crew;
use App\Models\CrewAthlete;
use App\Models\Team;

class CrewController extends BaseController
{
    public function getAllCrews()
    {
        $crews = Crew::all();
        return response()->json($crews);
    }

    public function registerMulti(Request $request)
    {
        $team_id = $request->input('team_id');
        $crews = Crew::where('team_id', $team_id)->delete();
        $disciplines = $request->input('discipline_ids');

        $crews = [];
        if ($disciplines != null) {
            foreach ($disciplines as $discipline) {
                $crew = new Crew();
                $crew->team_id = $team_id;
                $crew->discipline_id = $discipline;

                $crew->save();
                $crews[] = $crew;
            }
        }
        return response()->json($crews, 201);
        // else
        // {
        //     return response()->json(['error' => 'User not found or does not have a club'], 404);
        // }
    }

    public function register(Request $request)
    {
        $team_id = $request->input('team_id');
        $discipline_id = $request->input('discipline_id');

        $crew = new Crew();
        $crew->team_id = $team_id;
        $crew->discipline_id = $discipline_id;

        $crew->save();

        return response()->json($crew, 201);
        // else
        // {
        //     return response()->json(['error' => 'User not found or does not have a club'], 404);
        // }
    }

    public function unregister(Request $request)
    {
        $team_id = $request->input('team_id');
        $discipline_id = $request->input('discipline_id');

        $crew = Crew::where('team_id', $team_id)->where('discipline_id', $discipline_id)->first();
        $crewAthletes = CrewAthlete::where('crew_id', $crew->id)->get();
        foreach($crewAthletes as $crewAthlete) {
            $crewAthlete->delete();
        }
        $crew->delete();

        return response()->json($crew, 201);
        // else
        // {
        //     return response()->json(['error' => 'User not found or does not have a club'], 404);
        // }
    }

    public function getCrewsByTeamId(Request $request)
    {
        $team_id = $request->input('team_id');
        $crews = Crew::where('team_id', $team_id)->get();
        return response()->json($crews);
    }

    public function getCrewsForClub(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $club_id = $user->club_id;
            $teams = Team::where('club_id', $club_id)->get();
            $crews = [];
            foreach ($teams as $team) {
                $crews[] = Crew::where('team_id', $team->id)->get();
            }
            return response()->json($crews);
        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }
}
