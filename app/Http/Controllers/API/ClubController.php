<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;

use Illuminate\Http\Request;
use App\Models\Club;
use App\Models\Athlete;
use App\Models\Crew;
use App\Models\CrewAthlete;
use App\Models\Team;
use App\Models\User;

class ClubController extends BaseController
{
    public function getAllClubs(Request $request)
    {
        if ($request->has('active')) {
            $clubs = Club::where('active', $request->active)
            ->orderBy('name')
            ->get();
        } else {
            $clubs = Club::all();
        }
        return response()->json($clubs);
    }

    public function getAllClubsForAdel(Request $request)
    {
        if ($request->has('req_adel')) {
            $clubs = Club::where('req_adel', $request->req_adel)
            ->orderBy('name')
            ->get();
        } else {
            $clubs = Club::all();
        }
        return response()->json($clubs);
    }

    public function getClubDetails(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $club_id = $request->club_id;
            $club = Club::where('id', $club_id)->get()->first();
            $clubDetail = [];
            if ($club) {
                $athletes = Athlete::where('club_id', $club_id)->get();

                $clubDetail['Total'] = Athlete::where('club_id', $club_id)->count();
                $clubDetail['Male'] = Athlete::where('club_id', $club_id)->where('gender', 'Male')->count();
                $clubDetail['Female'] = Athlete::where('club_id', $club_id)->where('gender', 'Female')->count();
                $clubDetail['Junior'] = $this->countAthletes($club_id, 'junior a', 77, 111)
                + $this->countAthletes($club_id, 'junior b', 77, 111)
                + $this->countAthletes($club_id, 'junior c', 77, 111)
                + $this->countAthletes($club_id, 'junior d', 77, 111);
                $clubDetail['Junior EC'] = $this->countAthletes($club_id, 'junior a', 77, 88)
                + $this->countAthletes($club_id, 'junior b', 77, 88)
                + $this->countAthletes($club_id, 'junior c', 77, 88)
                + $this->countAthletes($club_id, 'junior d', 77, 88);
                $clubDetail['U24'] = $this->countAthletes($club_id, 'U24', 77, 111);
                $clubDetail['U24 EC'] = $this->countAthletes($club_id, 'U24', 77, 88);
                $clubDetail['Premier'] =  $this->countAthletes($club_id, 'premier', 77, 111);
                $clubDetail['Premier EC'] =  $this->countAthletes($club_id, 'premier', 77, 88);
                $clubDetail['Senior A'] = $this->countAthletes($club_id, 'Senior A', 77, 111);
                $clubDetail['Senior A EC'] = $this->countAthletes($club_id, 'Senior A', 77, 88);
                $clubDetail['Senior B'] = $this->countAthletes($club_id, 'senior B', 77, 111);
                $clubDetail['Senior B EC'] = $this->countAthletes($club_id, 'senior B', 77, 88);
                $clubDetail['Senior C'] = $this->countAthletes($club_id, 'Senior C', 77, 111);
                $clubDetail['Senior C EC'] = $this->countAthletes($club_id, 'Senior C', 77, 88);
                $clubDetail['Senior D'] = $this->countAthletes($club_id, 'Senior D', 77, 111);
                $clubDetail['Senior D EC'] = $this->countAthletes($club_id, 'Senior D', 77, 88);
                $clubDetail['BCP'] = $this->countAthletes($club_id, 'BCP', 77, 111);
                // $clubDetail['BCP'] = Athlete::where('club_id', $club_id)->whereRaw('LOWER(category) = ?', ['BCP'])->count();
                $clubDetail['Eurocup'] = $this->countAllAthletes($club_id, 77, 88);
                $clubDetail['plus Festival'] = $this->countAllAthletes($club_id, 77, 111);
                $clubDetail['withCertificate'] = Athlete::where('club_id', $club_id)->whereNotNull('certificate')->count();
                return response()->json($clubDetail);
            } else {
                return response()->json(['error' => 'No detail for Club'], 404);
            }
        } else {
            return response()->json(['error' => 'User not found or does not have a club'], 404);
        }
    }

    protected function countAthletes(int $clubId, $category, int $lowId, int $hiId)
    {
        $crewAthletes = [];
        $teams = Team::where('club_id', $clubId)->get();
        foreach ($teams as $team) {
            $crewsBase = Crew::where('team_id', $team->id);
            $crews =
                $crewsBase->where(function ($query) use ($lowId, $hiId) {
                    $query->where('discipline_id', '>=', $lowId)
                        ->where('discipline_id', '<=', $hiId);
                })->get();
            if (count($crews) > 0 && !empty($crews)) {
                foreach ($crews as $crew) {
                    $cAthletes = CrewAthlete::where('crew_id', $crew->id)
                    ->whereHas('athlete', function ($query) use ($category) {
                        $query->whereRaw('LOWER(category) = ?', [strtolower($category)]);
                    })
                    ->get();
                    
                    foreach ($cAthletes as $cAthlete) {
                        $ext = $cAthlete;
                        $a = Athlete::where('id', $cAthlete->athlete_id)->first();
                        if ($a) {
                            $ext['name'] = $a->first_name . ' ' . $a->last_name;
                        }
                        $crewAthletes[] = $cAthlete;
                    }
                }
            }
        }

        return count(collect($crewAthletes)->groupBy('athlete_id'));
    }

    protected function countAllAthletes(int $clubId, int $lowId, int $hiId)
    {
        $crewAthletes = [];
        $teams = Team::where('club_id', $clubId)->get();
        foreach ($teams as $team) {
            $crewsBase = Crew::where('team_id', $team->id);
            $crews =
                $crewsBase->where(function ($query) use ($lowId, $hiId) {
                    $query->where('discipline_id', '>=', $lowId)
                        ->where('discipline_id', '<=', $hiId);
                })->get();
            if (count($crews) > 0 && !empty($crews)) {
                foreach ($crews as $crew) {
                    $cAthletes = CrewAthlete::where('crew_id', $crew->id)->has('athlete')->get();
                    foreach ($cAthletes as $cAthlete) {
                        $ext = $cAthlete;
                        $a = Athlete::where('id', $cAthlete->athlete_id)->get()->first();
                        if ($a) {
                            $ext['name'] = $a->first_name . ' ' . $a->last_name;
                        }
                        $crewAthletes[] = $cAthlete;
                    }
                }
            }
        }

        return count(collect($crewAthletes)->groupBy('athlete_id'));
    }

    public function createClub(Request $request)
    {
        // $user = $request->user(); // Retrieve the authenticated user
        // $competition = Event::where('id', 1)->first();

        $club = new Club();
        $club->name = $request->input('name');
        $club->country = $request->input('country');

        $club->save();

        return response()->json($club, 201);
    }

    public function createClubAndUser(Request $request)
    {
        $club = new Club();
        $club->name = $request->clubname;
        $club->country = $request->country;
        $club->save();

        $user = new User;
        $user->club_id = $club->id;
        $user->name = $request->name;
        $user->username = $request->username;
        $user->email = $request->email;
        $user->password = bcrypt($request->password);
        $user->event_id = $request->event_id;
        $user->access_level = 0;
        $user->save();

        // return response()->json($club, 201);
        return response()->json($user, 201);
    }

    public function updateClub(Request $request, $id)
    {
        $club = Club::find($id);

        if (!$club) {
            return response()->json(['error' => 'Club not found'], 404);
        }

        // Update club fields
        if ($request->has('name')) {
            $club->name = $request->input('name');
        }

        if ($request->has('country')) {
            $club->country = $request->input('country');
        }

        $club->save();

        return response()->json($club, 200);
    }

    public function deleteClub($id)
    {
        $club = Club::find($id);

        if (!$club) {
            return response()->json(['error' => 'Club not found'], 404);
        }

        // Check if club has associated teams
        $teamsCount = Team::where('club_id', $id)->count();
        if ($teamsCount > 0) {
            return response()->json([
                'error' => 'Cannot delete club with existing teams',
                'teams_count' => $teamsCount
            ], 400);
        }

        // Check if club has associated athletes
        $athletesCount = Athlete::where('club_id', $id)->count();
        if ($athletesCount > 0) {
            return response()->json([
                'error' => 'Cannot delete club with existing athletes',
                'athletes_count' => $athletesCount
            ], 400);
        }

        $club->delete();

        return response()->json(['message' => 'Club deleted successfully'], 200);
    }

}
