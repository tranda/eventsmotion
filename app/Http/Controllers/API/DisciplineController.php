<?php

namespace App\Http\Controllers\API;
                                                                                                                                                                                                                                                                                                                                                                        
use App\Http\Controllers\API\BaseController as BaseController;
use App\Http\Requests\StoreDisciplineRequest;
use App\Http\Requests\UpdateDisciplineRequest;
use App\Models\Crew;
use App\Models\CrewAthlete;
use App\Models\Event;
use App\Models\Discipline;
use App\Models\Team;
use App\Models\TeamClubs;
use Illuminate\Http\Request;

class DisciplineController extends BaseController
{
    public function getAllDisciplines(Request $request)
    {
        if ($request->has('event_id'))
        {
            $eventId = $request->event_id;
            $disciplines = Discipline::where('event_id', $eventId)->get();
            foreach ($disciplines as $discipline) {
                $crews = Crew::where('discipline_id', $discipline->id)->get();
                $discipline->teams_count = $crews->count();
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
                $discipline->teams = $teams;

            }
            return response()->json($disciplines);
        }
        else {
            $disciplines = Discipline::all();
            return response()->json($disciplines);
        }
    }

    public function getCrewsByDisciplineId(Request $request)
    {
        $discipline_id = $request->input('discipline_id');
        $crews = Crew::where('discipline_id', $discipline_id)->get();
        return response()->json($crews);
    }

    public function getDisciplinesForClub(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $club_id = $user->club_id;
            // $alldisciplines = Discipline::all();
           $alldisciplines = Discipline::whereHas('event', function ($query) {$query->where('status', 'active');})->get();
            $teams = Team::where('club_id', $club_id)->get();

            $disciplines = [];
            foreach ($alldisciplines as $discipline) {
                $disc = null;
                $crews = null;
                foreach ($teams as $team) {
                    $crew = Crew::where('team_id', $team->id)->where('discipline_id', $discipline->id)->first();
                    if ($crew != null) {
                        $crew_ext = $crew->toArray();
                        $crew_ext['capacity'] = CrewAthlete::where('crew_id', $crew->id)->count();
                        $crews[] = [
                            'team' => $team,
                            'crew' => $crew_ext,
                        ];
                    }
                }
                if ($crews != null) {
                    $disc = [
                        'discipline' => $discipline,
                        'discipline_crews' => $crews
                    ];
                    $disciplines[] = $disc;
                }
            }

            return response()->json($disciplines);
        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }

    public function getDisciplinesForCombinedClubs(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $club_id = $user->club_id;
            // $alldisciplines = Discipline::all();
            if ($request->has('event_id')) {
                $event_id = $request->input('event_id');
                $alldisciplines = Discipline::where('event_id', $event_id)
                    ->whereHas('event', function ($query) {
                        $query->where('status', 'active');
                    })->get();
            }
            else {
                $alldisciplines = Discipline::where('status', 'active')->get();
            }
            $teamClubs = TeamClubs::where('club_id', $club_id)->get();
            $teams_all = [];
            foreach ($teamClubs as $teamClub) {
                $teams = Team::where('id', $teamClub->team_id)->get();
                foreach ($teams as $team) {
                    $teams_all[] = $team;
                }
            }

            $disciplines = [];
            foreach ($alldisciplines as $discipline) {
                $disc = null;
                $crews = null;
                foreach ($teams_all as $team) {
                    $crew = Crew::where('team_id', $team->id)->where('discipline_id', $discipline->id)->first();
                    if ($crew != null) {
                        $crew_ext = $crew;
                        $capacity = CrewAthlete::where('crew_id', $crew->id)->has('athlete')->count();
                        $crew_ext['capacity'] = $capacity;
                        $crews[] = [
                            'team' => $team,
                            'crew' => $crew_ext,
                        ];
                    }
                }
                if ($crews != null) {
                    $disc = [
                        'discipline' => $discipline,
                        'discipline_crews' => $crews
                    ];
                    $disciplines[] = $disc;
                }
            }

            return response()->json($disciplines);
        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }

    public function getDisciplinesForTeam(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $team_id = $request->team_id;
            $alldisciplines = Discipline::whereHas('event', function ($query) {$query->where('status', 'active');})->get();
            $teams = Team::where('id', $team_id)->get();

            $disciplines = [];
            foreach ($alldisciplines as $discipline) {
                $disc = null;
                $crews = null;
                foreach ($teams as $team) {
                    $crew = Crew::where('team_id', $team->id)->where('discipline_id', $discipline->id)->first();
                    if ($crew != null) {
                        $crews[] = [
                            'team' => $team,
                            'crew' => $crew
                        ];
                    }
                }
                if ($crews != null) {
                    $disc = [
                        'discipline' => $discipline,
                        'discipline_crews' => $crews
                    ];
                    $disciplines[] = $disc;
                }
            }

            return response()->json($disciplines);
        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }

    /**
     * Get a single discipline by ID
     * 
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        try {
            $discipline = Discipline::with('event')->findOrFail($id);
            return $this->sendResponse($discipline, 'Discipline retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Discipline not found.', [], 404);
        }
    }

    /**
     * Create a new discipline
     * 
     * @param StoreDisciplineRequest $request
     * @return \Illuminate\Http\Response
     */
    public function store(StoreDisciplineRequest $request)
    {
        try {
            $discipline = Discipline::create([
                'event_id' => $request->event_id,
                'distance' => $request->distance,
                'age_group' => $request->age_group,
                'gender_group' => $request->gender_group,
                'boat_group' => $request->boat_group,
                'status' => $request->status ?? 'active',
            ]);

            $discipline->load('event');
            
            return $this->sendResponse($discipline, 'Discipline created successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error creating discipline.', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing discipline
     * 
     * @param UpdateDisciplineRequest $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateDisciplineRequest $request, $id)
    {
        try {
            $discipline = Discipline::findOrFail($id);
            
            $updateData = [];
            if ($request->has('event_id')) $updateData['event_id'] = $request->event_id;
            if ($request->has('distance')) $updateData['distance'] = $request->distance;
            if ($request->has('age_group')) $updateData['age_group'] = $request->age_group;
            if ($request->has('gender_group')) $updateData['gender_group'] = $request->gender_group;
            if ($request->has('boat_group')) $updateData['boat_group'] = $request->boat_group;
            if ($request->has('status')) $updateData['status'] = $request->status;

            $discipline->update($updateData);
            $discipline->load('event');

            return $this->sendResponse($discipline, 'Discipline updated successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error updating discipline.', ['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Delete a discipline
     * 
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        try {
            $discipline = Discipline::findOrFail($id);
            
            // Check if discipline has crews - prevent deletion if crews exist
            $crewCount = Crew::where('discipline_id', $id)->count();
            if ($crewCount > 0) {
                return $this->sendError(
                    'Cannot delete discipline.',
                    ['error' => 'Discipline has associated crews and cannot be deleted.'],
                    409
                );
            }

            $discipline->delete();

            return $this->sendResponse([], 'Discipline deleted successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error deleting discipline.', ['error' => $e->getMessage()], 500);
        }
    }
}
