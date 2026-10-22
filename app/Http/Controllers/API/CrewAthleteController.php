<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;

use Illuminate\Http\Request;
use App\Models\Athlete;
use App\Models\CrewAthlete;
use App\Support\EventEditGuard;
use App\Support\ClubAccess;

class CrewAthleteController extends BaseController
{
    public function getCrewAthletesByCrewId(Request $request)
    {
        $user = $request->user();
        if ($user) {
            ClubAccess::assertCrew($user, $request->crew_id);

            $crewathletes = CrewAthlete::where('crew_id', $request->crew_id)->get();
            $crew = [];
            foreach ($crewathletes as $crewathlete) {
                $athlete = Athlete::where('id', $crewathlete->athlete_id)->get()->first();
                if ($athlete) {
                    $crew[] = [
                        'crew_athlete' => $crewathlete,
                        'athlete' => $athlete
                    ];
                }
            }
            return response()->json($crew);
        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }

    public function createCrewAthlete(Request $request)
    {
        $user = $request->user();

        if ($user) {
            $crew = ClubAccess::assertCrew($user, $request->crew_id);
            ClubAccess::assertAthleteFitsCrew($user, $request->athlete_id, $crew);

            EventEditGuard::forCrew($request->crew_id, $user);

            $athlete = new CrewAthlete();
            $athlete->crew_id = $request->crew_id;
            $athlete->athlete_id = $request->athlete_id;
            $athlete->no = $request->no;

            $athlete->save();

            return response()->json($athlete, 201);
        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }

    public function updateCrewAthlete(Request $request, $crewathleteId)
    {
        $user = $request->user();

        if ($user) {
            $crew = CrewAthlete::findOrFail($crewathleteId);
            $parent = ClubAccess::assertCrew($user, $crew->crew_id);
            ClubAccess::assertAthleteFitsCrew($user, $request->athlete_id, $parent);
            EventEditGuard::forCrew($crew->crew_id, $user);
            $crew->athlete_id = $request->athlete_id;
            // $crew->crew_id = $crewId;

            $crew->save();

            return response()->json($crew);
        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }

    public function deleteCrewAthlete(Request $request, $crewathleteId)
    {
        $user = $request->user();

        if ($user) {
            $athlete = CrewAthlete::findOrFail($crewathleteId);
            ClubAccess::assertCrew($user, $athlete->crew_id);
            EventEditGuard::forCrew($athlete->crew_id, $user);
            if ($athlete) {
                $athlete->delete();
                return response()->json($athlete);
            } else {
                return response()->json(['error' => 'Athlete does not exists'], 404);
            }
        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }
}
