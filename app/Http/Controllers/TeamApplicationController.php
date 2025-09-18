<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\Discipline;
use Illuminate\Http\Request;

class TeamApplicationController extends Controller
{
    public function index()
    {
        // Fetch all teams and races
        $teams = Team::all();
        $races = Discipline::all();

        return view('team-applications', compact('teams', 'races'));
    }
}