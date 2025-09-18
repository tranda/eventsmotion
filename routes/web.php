<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\CrewController;
use App\Http\Controllers;
use App\Http\Controllers\PublicRaceResultsController;
use App\Models\Discipline;
use App\Models\Club;
use App\Models\Team;
use App\Models\Crew;
use App\Models\CrewAthlete;
use App\Models\Athlete;
use App\Http\Controllers\TeamApplicationController;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function () {
    return redirect('/web');
    // return view('welcome');
});

Route::get('/racecrews', function () {
    $disciplines = Discipline::where('status', 'active')->where('event_id', '6')->orWhere('event_id', '7')->get();
    $disciplines_ext = [];
    foreach ($disciplines as $discipline) {
        if ($discipline) {
            $discipline_id = $discipline->id;
            $crews = Crew::where('discipline_id', $discipline_id)->get();
            $teams = [];
            foreach ($crews as $crew) {
                $crewAthletes = [];
                $team = Team::where('id', $crew->team_id)->first();
                if ($team) {
                    $cAthletes = CrewAthlete::where('crew_id', $crew->id)->has('athlete')->get();
                    foreach ($cAthletes as $cAthlete) {
                        $ext = $cAthlete;
                        $a = Athlete::where('id', $cAthlete->athlete_id)->get()->first();
                        if ($a) {
                            $ext['name'] = $a->first_name . ' ' . $a->last_name;
                        }
                        $crewAthletes[] = $cAthlete;
                    }
                    $crew_ext = [
                        'crew' => $crew,
                        'team' => $team,
                        'athletes' => $crewAthletes
                    ];
                    $teams[] = $crew_ext;
                }
            }
        }
         if (count($teams) >= 3) {
            $discipline_ext['discipline'] = $discipline;
            $discipline_ext['teams'] = $teams;
            $disciplines_ext[] = $discipline_ext;
         }
    }

    return view('racecrews', ['teams' => $teams, 'crews' => $crews, 'disciplines' => $disciplines_ext]);
});

Route::get('/clubcrews', function () {
    $disciplines = Discipline::where('status', 'active')->where('event_id', '6')->orWhere('event_id', '7')->get();
    $disciplines_ext = [];
    $club_id = 1;
    if (request()->has('club_id')) {
        $club_id = request('club_id');
    }
    foreach ($disciplines as $discipline) {
        // $discipline = Discipline::where('id', 1)->first();
        if ($discipline) {
            $discipline_id = $discipline->id;
            $crews = Crew::where('discipline_id', $discipline_id)->get();
            $teams = [];
            foreach ($crews as $crew) {
                $crewAthletes = [];
                $team = Team::where('id', $crew->team_id)->where('club_id', $club_id)->first();
                if ($team) {
                    $cAthletes = CrewAthlete::where('crew_id', $crew->id)->has('athlete')->get();
                    foreach ($cAthletes as $cAthlete) {
                        $ext = $cAthlete;
                        $a = Athlete::where('id', $cAthlete->athlete_id)->get()->first();
                        if ($a) {
                            $ext['name'] = $a->first_name . ' ' . $a->last_name;
                        }
                        $crewAthletes[] = $cAthlete;
                    }
                    $crew_ext = [
                        'crew' => $crew,
                        'team' => $team,
                        'athletes' => $crewAthletes
                    ];
                    $teams[] = $crew_ext;
                }
            }
        }
        if (count($teams) > 0) {
            $discipline_ext['discipline'] = $discipline;
            $discipline_ext['teams'] = $teams;
            $disciplines_ext[] = $discipline_ext;
        }
    }

    return view('clubcrews', ['teams' => $teams, 'crews' => $crews, 'disciplines' => $disciplines_ext]);
});

// Route::get('/registercrews', function () {
//     $clubs = Club::all();
//     $teams = Team::all();
//     $disciplines = Discipline::all();
//     $crews = Crew::all();
//     return view('crews', ['clubs' => $clubs, 'teams' => $teams, 'crews' => $crews, 'disciplines' => $disciplines]);
// });

// Route::post('registercrews', [CrewController::class, 'registerMulti']);

// Route::get('/token', function () {
// 	return csrf_token(); 
// });

Route::get('/team-applications', [TeamApplicationController::class, 'index'])->name('team-applications');

// Public Race Results Routes (no authentication required)
Route::prefix('public')->name('public.')->group(function () {
    Route::get('/events/{event}/race-results', [PublicRaceResultsController::class, 'show'])
        ->name('race-results');
    Route::get('/events/{event}/races/{race}', [PublicRaceResultsController::class, 'showRace'])
        ->name('single-race-result');
});
