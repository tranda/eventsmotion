<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\RaceResult;
use App\Models\Discipline;
use Illuminate\Http\Request;

class PublicRaceResultsController extends Controller
{
    /**
     * Display race results for a specific event.
     * Public access - no authentication required.
     * 
     * @param int $eventId
     * @return \Illuminate\View\View
     */
    public function show($eventId)
    {
        try {
            // Get the event with basic validation
            $event = Event::findOrFail($eventId);
            
            // Get all race results for this event with related data
            $raceResults = RaceResult::forEvent($eventId)
                ->with([
                    'discipline',
                    'crewResults.crew.team',
                    'crewResults.crew.discipline'
                ])
                ->orderBy('race_number', 'asc')
                ->get();

            // Transform the data for better template usage
            $raceResultsData = $raceResults->map(function ($raceResult) {
                // Get all crew results (including those without results yet)
                $allCrewResults = $raceResult->allCrewResults();
                
                return [
                    'id' => $raceResult->id,
                    'race_number' => $raceResult->race_number,
                    'title' => $raceResult->getTitle(),
                    'discipline' => $raceResult->discipline,
                    'race_time' => $raceResult->race_time,
                    'stage' => $raceResult->stage,
                    'status' => $raceResult->status,
                    'crew_results' => $allCrewResults,
                    'has_results' => $allCrewResults->filter(function ($crewResult) {
                        return !is_null($crewResult->position);
                    })->isNotEmpty()
                ];
            });

            return view('public.race-results', [
                'event' => $event,
                'raceResults' => $raceResultsData,
                'totalRaces' => $raceResultsData->count(),
                'racesWithResults' => $raceResultsData->filter(function ($race) {
                    return $race['has_results'];
                })->count()
            ]);

        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Error displaying public race results', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Return a user-friendly error page
            return view('public.race-results-error', [
                'error' => 'Unable to load race results. Please try again later.',
                'event_id' => $eventId
            ]);
        }
    }

    /**
     * Display race results for a specific race within an event.
     * 
     * @param int $eventId
     * @param int $raceId
     * @return \Illuminate\View\View
     */
    public function showRace($eventId, $raceId)
    {
        try {
            $event = Event::findOrFail($eventId);
            
            $raceResult = RaceResult::where('id', $raceId)
                ->forEvent($eventId)
                ->with([
                    'discipline',
                    'crewResults.crew.team',
                    'crewResults.crew.discipline'
                ])
                ->firstOrFail();

            $allCrewResults = $raceResult->allCrewResults();

            $raceData = [
                'id' => $raceResult->id,
                'race_number' => $raceResult->race_number,
                'title' => $raceResult->getTitle(),
                'discipline' => $raceResult->discipline,
                'race_time' => $raceResult->race_time,
                'stage' => $raceResult->stage,
                'status' => $raceResult->status,
                'crew_results' => $allCrewResults,
                'has_results' => $allCrewResults->filter(function ($crewResult) {
                    return !is_null($crewResult->position);
                })->isNotEmpty()
            ];

            return view('public.single-race-result', [
                'event' => $event,
                'race' => $raceData
            ]);

        } catch (\Exception $e) {
            \Log::error('Error displaying single race result', [
                'event_id' => $eventId,
                'race_id' => $raceId,
                'error' => $e->getMessage()
            ]);

            return view('public.race-results-error', [
                'error' => 'Unable to load race result. Please try again later.',
                'event_id' => $eventId
            ]);
        }
    }
}