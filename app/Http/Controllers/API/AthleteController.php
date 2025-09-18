<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;

use Illuminate\Http\Request;
use App\Http\Controllers\API\Helpers;
use App\Models\Athlete;
use App\Models\Discipline;
use App\Models\Crew;
use App\Models\CrewAthlete;
use App\Models\Event;
use App\Models\Club;
use App\Models\Team;
use App\Models\TeamClubs;

use Carbon\Carbon;

class AthleteController extends BaseController
{
    public function getAthletesByClubId(Request $request)
    {
        $user = $request->user();
        if ($user) {
            if ($user->access_level > 0 && $request->has('club_id')) {
                $club_id = $request->club_id;
            } else {
                $club_id = $user->club_id;
            }
            $athletes = Athlete::where('club_id', $club_id)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
            $athletes_ext = [];
            foreach ($athletes as $athlete) {
                $athlete_ext = $athlete->toArray();
                $athlete_ext['category'] = $this->getAthleteCategory($athlete->birth_date);
                $athlete_ext['eurocup'] = $this->isInEurocup($club_id, $athlete->id);
                $athletes_ext[] = $athlete_ext;
            }
            return response()->json($athletes_ext);
        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }

    protected function isInEurocup(int $clubId, int $athlete_id)
    {
        $crewAthletes = [];
        $teams = Team::where('club_id', $clubId)->get();
        foreach ($teams as $team) {
            $crewsBase = Crew::where('team_id', $team->id);
            $crews =
                $crewsBase->where(function ($query) {
                    $query->where('discipline_id', '=', 1)
                        ->orWhere('discipline_id', '=', 2)
                        ->orWhere('discipline_id', '=', 10)
                        ->orWhere('discipline_id', '=', 11);
                })->get();
            if (count($crews) > 0 && !empty($crews)) {
                foreach ($crews as $crew) {
                    $cAthletes = CrewAthlete::where('crew_id', $crew->id)->where('athlete_id', $athlete_id)->get();
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
        if (count(collect($crewAthletes)->groupBy('athlete_id')) > 0) {
            return "Eurocup";
        } else
            return "Festival";
    }

    public function getAthletesByTeamId(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $team_id = $request->team_id;
            $teamClubs = TeamClubs::where('team_id', $team_id)->get();
            $athletes_ext = [];
            foreach ($teamClubs as $team) {
                $club = Club::findOrFail($team->club_id);
                if ($club) {
                    $athletes = Athlete::where('club_id', $club->id)
                                ->orderBy('first_name')
                                ->orderBy('last_name')
                                ->get();
                    foreach ($athletes as $athlete) {
                        $athlete_ext = $athlete;
                        $athlete_ext['category'] = $this->getAthleteCategory($athlete->birth_date);
                        $athletes_ext[] = $athlete_ext;
                    }
                }
            }
            return response()->json($athletes_ext);
        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }

    protected function getAthleteCategory($myDate)
    {
        $date = Carbon::createFromFormat('Y-m-d', $myDate)->year;
        $now = Carbon::now()->year;
        $diff = $now - $date;

        // if ($diff <= 18) {
            // return 'Junior';
        if ($diff <= 16) {
            return 'Junior B';
        } elseif ($diff <= 18) {
            return 'Junior A';
        } elseif ($diff < 24) {
            return 'U24';
        } elseif ($diff < 40) {
            return 'Premier';
        } elseif ($diff < 50) {
            return 'Senior A';
        } elseif ($diff < 60) {
            return 'Senior B';
        } elseif ($diff < 70) {
            return 'Senior C';
        } else {
            return 'Senior D';
        }
    }

    public function getAthletesEligibleForCrewId(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $club_id = $user->club_id;
            $athletes = Athlete::where('club_id', $club_id)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
            $crewId = $request->input('crew_id');
            $crew = Crew::findOrFail($crewId);
            $discipline = Discipline::findOrFail($crew->discipline_id);
            $competition = Event::findOrFail($discipline->event_id);
            $position = $request->input('no');

            $standardSize = 22 + $competition->standardReserves;
            $smallSize = 12 + $competition->smallReserves;
            $size = ($discipline->boat_group == "Standard"
                ? $standardSize
                : $smallSize);
            $helmNo = $discipline->boat_group == "Standard"
                ? $standardSize - $competition->standardReserves
                : $smallSize - $competition->smallReserves;

            $athletesEligibleForCrew = array();
            foreach ($athletes as $athlete) {
                if ($position == 0) {
                    if ($this->isEligibleForCrew($athlete, $discipline, $crew, $competition)) {
                        $athletesEligibleForCrew[] = $athlete;
                    }
                } else if ($position == $helmNo - 1) {
                    if ($this->isEligibleForCrew($athlete, $discipline, $crew, $competition)) {
                        $athletesEligibleForCrew[] = $athlete;
                    }
                } else if ($this->isEligibleAgeGroup($athlete, $discipline)) {
                    if ($this->isEligibleGender($athlete, $discipline)) {
                        if ($this->isEligibleForCrew($athlete, $discipline, $crew, $competition)) {
                            $athletesEligibleForCrew[] = $athlete;
                        }
                    }
                }
            }

            return response()->json($athletesEligibleForCrew);
        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }

    public function getAthletesEligibleForCombinedCrewId(Request $request)
    {
        $user = $request->user();
        if ($user) {
            $club_id = $user->club_id;
            $crewId = $request->input('crew_id');
            $crew = Crew::findOrFail($crewId);
            // $athletes = Athlete::where('club_id', $club_id)->get();
            $team_id = $request->team_id;
            $teamClubs = TeamClubs::where('team_id', $crew->team_id)->get();


            $discipline = Discipline::findOrFail($crew->discipline_id);
            $competition = Event::findOrFail($discipline->event_id);
            $position = $request->input('no');

            $standardSize = 22 + $competition->standardReserves;
            $smallSize = 12 + $competition->smallReserves;
            $size = $discipline->boat_group == "Standard"
                ? $standardSize
                : $smallSize;
            $helmNo = $discipline->boat_group == "Standard"
                ? $standardSize - $competition->standardReserves
                : $smallSize - $competition->smallReserves;

            $athletesEligibleForCrew = array();
            foreach ($teamClubs as $team) {
                $club = Club::findOrFail($team->club_id);
                if ($club) {
                    $athletes = Athlete::where('club_id', $club->id)            
                        ->orderBy('first_name')
                        ->orderBy('last_name')
                        ->get();
                    foreach ($athletes as $athlete) {
                        if ($position == 0) {
                            if ($this->isEligibleForCrew($athlete, $discipline, $crew, $competition)) {
                                $athletesEligibleForCrew[] = $athlete;
                            }
                        } else if ($position == $helmNo - 1) {
                            if ($this->isEligibleForCrew($athlete, $discipline, $crew, $competition)) {
                                $athletesEligibleForCrew[] = $athlete;
                            }
                        } else if ($this->isEligibleAgeGroup($athlete, $discipline)) {
                            if ($this->isEligibleGender($athlete, $discipline)) {
                                if ($this->isEligibleForCrew($athlete, $discipline, $crew, $competition)) {
                                    $athletesEligibleForCrew[] = $athlete;
                                }
                            }
                        }
                    }
                }
            }

            return response()->json($athletesEligibleForCrew);
        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }

    protected function isEligibleAgeGroup($athlete, $discipline)
    {
        $myDate = $athlete->birth_date;
        $date = Carbon::createFromFormat('Y-m-d', $myDate)->year;
        $now = Carbon::now()->year;
        $diff = $now - $date;

        //no limits
        // return true;

        switch ($discipline->age_group) {
            case 'Premier':
                return true;
                break;
            case 'Senior':
                return ($diff >= 40);
                break;
            case 'Senior A':
                return ($diff >= 40);
                break;
            case 'Senior B':
                return ($diff >= 50);
                break;
            case 'Senior C':
                return ($diff >= 60);
                break;
            case 'Senior D':
                return ($diff >= 60);
                break;
            case 'U24':
                return ($diff < 24);
                break;
            case 'Junior':
                return ($diff <= 18);
                break;
            case 'Junior A':
                return ($diff <= 18);
                break;
            case 'Junior B':
                return ($diff <= 16);
                break;
            case 'BCP':
                return true;
                break;
            case 'ACP':
                return true;
                break;
            default:
                return false;
        }
        return false;
    }

    protected function isEligibleGender($athlete, $discipline)
    {
        switch ($athlete->gender) {
            case ('Male'):
                return $discipline->gender_group == 'Open' or $discipline->gender_group == 'Mix' or $discipline->gender_group == 'Mixed';
                break;
            case ('Female'):
                return true;
                break;
            default:
                return false;
        }
    }

    protected function isEligibleForCrew($athlete, $discipline, $crew, $competition)
    {
        $count = CrewAthlete::where('crew_id', $crew->id)->where('athlete_id', $athlete->id)->get()->count();

        return $count == 0;
    }

    public function createAthlete(Request $request)
    {
        $user = $request->user(); // Retrieve the authenticated user
        $competition = Event::where('id', 1)->first();

        if ($user) {
            if ($competition->name_entries_lock) {
                $clubId = $user->club_id;

                $athlete = new Athlete();
                $athlete->club_id = $clubId; //$request->input('club_id');
                $athlete->first_name = $request->input('first_name');
                $athlete->last_name = $request->input('last_name');
                $athlete->birth_date = $request->input('birth_date');
                $athlete->gender = $request->input('gender');
                $athlete->coach = $request->input('coach') == 1 ? true : false;
                $athlete->media = $request->input('media') == 1 ? true : false;
                $athlete->official = $request->input('official') == 1 ? true : false;
                $athlete->supporter = $request->input('supporter') == 1 ? true : false;
                $athlete->left_side = $request->input('left_side') == 1 ? true : false;
                $athlete->right_side = $request->input('right_side') == 1 ? true : false;
                $athlete->helm = $request->input('helm') == 1 ? true : false;
                $athlete->drummer = $request->input('drummer') == 1 ? true : false;
                if ($request->filled('photo')) {
                    $photoBase64 = $request->input('photo');
                    $photoData = base64_decode($photoBase64);
                    $fileName = $clubId . "_" . uniqid() . '.jpg';
                    $storagePath = 'photos/' . $fileName;
                    file_put_contents(public_path($storagePath), $photoData);
                    $athlete->photo = $fileName;
                } else if ($request->hasFile('image')) {
                    $file = $request->file('image');
                    $fileName = $clubId . "_" . uniqid() . '.jpg';
                    $storagePath = 'photos/';
                    $file->move(public_path($storagePath), $fileName);
                    $athlete->photo = $fileName;
                }
                $category = $this->getAthleteCategory($athlete->birth_date);
                $athlete->category = $category;
                $club = Club::where('id', $clubId)->first();
                $athlete->club_name = $club->name;
                
                $athlete->save();

                return response()->json($athlete, 201);
            } else {
                return response()->json(['error' => 'Operation locked'], 423);
            }

        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }

    public function updateAthlete(Request $request, $athleteId)
    {
        $user = $request->user(); // Retrieve the authenticated user

        if ($user) {
            $clubId = $user->club_id;
            $athlete = Athlete::findOrFail($athleteId);
            $athlete->first_name = $request->input('first_name');
            $athlete->last_name = $request->input('last_name');
            $athlete->birth_date = $request->input('birth_date');
            $athlete->gender = $request->input('gender');
            $athlete->coach = $request->input('coach') == 1 ? true : false;
            $athlete->media = $request->input('media') == 1 ? true : false;
            $athlete->official = $request->input('official') == 1 ? true : false;
            $athlete->supporter = $request->input('supporter') == 1 ? true : false;
            $athlete->left_side = $request->input('left_side') == 1 ? true : false;
            $athlete->right_side = $request->input('right_side') == 1 ? true : false;
            $athlete->helm = $request->input('helm') == 1 ? true : false;
            $athlete->drummer = $request->input('drummer') == 1 ? true : false;
            if ($request->filled('photo')) {
                $photoBase64 = $request->input('photo');
                $photoData = base64_decode($photoBase64);
                $fileName = $clubId . "_" . uniqid() . '.jpg';
                $storagePath = 'photos/' . $fileName;
                file_put_contents(public_path($storagePath), $photoData);
                $athlete->photo = $fileName;
            }
            $category = $this->getAthleteCategory($athlete->birth_date);
            $athlete->category = $category;
            $club = Club::where('id', $clubId)->first();
            $athlete->club_name = $club->name;

            $athlete->save();

            return response()->json($athlete);
        } else {
            return response()->json(['error' => 'User not found or does not have a club'], 404);
        }
    }

    public function deleteAthlete(Request $request, $athleteId)
    {
        $user = $request->user(); // Retrieve the authenticated user

        if ($user) {
            $clubId = $user->club_id;
            $athlete = Athlete::findOrFail($athleteId);
            if ($athlete) {
                // $athlete->delete();
                $athlete->club_id = 0;
                $athlete->save();
                return response()->json($athlete);
            } else {
                return response()->json(['error' => 'Athlete does not exists'], 404);
            }
        } else {
            return response()->json(['error' => 'User not found or does not have a club'], 404);
        }
    }

    public function getAllAthletes(Request $request)
    {
        $clubs = Athlete::all();
        return response()->json($clubs);
    }

    public function getAllClubAthletes(Request $request)
    {
        $clubs = Club::all();
        $clubs_ext = [];
        foreach ($clubs as $club) {
            $athletes = Athlete::where('club_id', $club->id)
                        ->orderBy('first_name')
                        ->orderBy('last_name')
                        ->get();
            $athletes_ext = [];
            foreach ($athletes as $athlete) {
                $athlete_ext = $athlete;
                $athlete_ext['category'] = $this->getAthleteCategory($athlete->birth_date);
                $athletes_ext[] = $athlete_ext;
            }
            $club_ext['club'] = $club;
            $club_ext['athletes_count'] = count($athletes);
            $club_ext['athletes'] = $athletes_ext;
            $clubs_ext[] = $club_ext;
        }
        return response()->json($clubs_ext);
    }

    public function updateAthleteDetail(Request $request)
    {
        $user = $request->user(); // Retrieve the authenticated user

        if ($user) {
            if ($request->has('club_id')) {
                $club_id = $request->club_id;
                $athletes = Athlete::where('club_id', $club_id)->get();
           } else {
                $athletes = Athlete::all();
            }
            $clubName = 'unknown';
            foreach ($athletes as $athlete) {
                $category = $this->getAthleteCategory($athlete->birth_date);
                if (is_null($athlete->$category) || empty($athlete->$category)) {
                  $athlete->category = $category;
                }
                $club = Club::where('id', $athlete->club_id)->first();
                // $club = Club::findOrFail($athlete->club_id);
                if ($club) {
                    if (is_null($athlete->club_name) || empty($athlete->club_name)) {
                        $athlete->club_name = $club->name;
                        $clubName = $club->name;
                    }   
                }
                $athlete->save();
            }

            // return response()->json($athletes, 200);
            return response()->json(['Success' => 'Updated category for '.$clubName], 200);
        } else {
            return response()->json(['error' => 'User not found or does not have a club'], 404);
        }
    }

    public function updateCertificate(Request $request, $athleteId)
    {
        $user = $request->user(); // Retrieve the authenticated user

        if ($user) {
            $clubId = $user->club_id;
            $athlete = Athlete::findOrFail($athleteId);
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $extension = $file->extension();
                $fileName = "certificate_" . $athlete->id . "." . $extension;
                $storagePath = 'certificates/';
                $file->move(public_path($storagePath), $fileName);
                $athlete->certificate = $fileName;

                $athlete->save();
            } else {
                return response()->json(['error' => 'No attached file'], 404);
            }



            return response()->json($athlete);
        } else {
            return response()->json(['error' => 'User not found or does not have a club'], 404);
        }
    }

    public function importAthletes(Request $request)
    {
        $user = $request->user();

        if ($user) {
                $clubId = $user->club_id;
            $jsonData = $request->json()->all();
            $dataArray = $jsonData;

            foreach ($dataArray as $item) {
                $athlete = Athlete::where('edbf_id', $item['edbf_id'])->first();
                if ($athlete) {
                    // $athlete->certificate = $item['certificate'];
                    $athlete->document_no = $item['document_no'];
                    $athlete->category = $item['category'];
                    $athlete->left_side = $item['left_side'];
                    $athlete->right_side = $item['right_side'];
                    $athlete->helm = $item['helm'];
                    $athlete->drummer = $item['drummer'];
                    $athlete->supporter = $item['supporter'];
                    $athlete->media = $item['media'];
                    $athlete->coach = $item['coach'];
                } else {
                    $athlete = new Athlete();
                    $athlete->club_id = $clubId;
                    $athlete->edbf_id = $item['edbf_id'];
                    // $athlete->checked = $item['checked'];
                    $athlete->first_name = $item['first_name'];
                    $athlete->last_name = $item['last_name'];
                    $athlete->birth_date = $item['birth_date'];
                    $athlete->gender = $item['gender'];
                    $athlete->category = $item['category'];
                    $athlete->document_no = $item['document_no'];
                    $athlete->left_side = $item['left_side'];
                    $athlete->right_side = $item['right_side'];
                    $athlete->helm = $item['helm'];
                    $athlete->drummer = $item['drummer'];
                    $athlete->supporter = $item['supporter'];
                    $athlete->media = $item['media'];
                    $athlete->coach = $item['coach'];
                    $athlete->official = $item['official'];
                }

                $success = $athlete->save();
                if (!$success) {
                    // Save operation failed
                    $errors = $athlete->getErrors();

                    // Display errors
                    foreach ($errors as $error) {
                        // echo $error . "<br>";
                    }
                    return response()->json(['errors' => $errors]);
                }
            }

            return response()->json(['success' => 'true'], 201);
        } else {
            return response()->json(['error' => 'User not found'], 404);
        }
    }

}
