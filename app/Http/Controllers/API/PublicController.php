<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use Illuminate\Http\Request;
use App\Models\RaceResult;
use App\Models\Event;

class PublicController extends BaseController
{
    /**
     * Get public race results for a specific event.
     * This endpoint doesn't require authentication and can be accessed by anyone.
     * 
     * @param int $eventId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRaceResults($eventId)
    {
        try {
            // Verify the event exists
            $event = Event::find($eventId);
            if (!$event) {
                return $this->sendError('Event not found', [], 404);
            }

            // Get all race results for the event with complete data
            $raceResults = RaceResult::with([
                'discipline',
                'crewResults' => function ($query) {
                    $query->orderBy('position', 'asc')
                          ->orderBy('time_ms', 'asc');
                },
                'crewResults.crew.team',
                'crewResults.crew.discipline'
            ])
            ->forEvent($eventId)
            ->orderBy('race_number', 'asc')
            ->get();

            // Transform the data to include all necessary information
            $formattedResults = $raceResults->map(function ($race) {
                return [
                    'id' => $race->id,
                    'race_number' => $race->race_number,
                    'title' => $race->title,
                    'stage' => $race->stage,
                    'status' => $race->status,
                    'race_time' => $race->race_time ? $race->race_time->format('Y-m-d H:i:s') : null,
                    'discipline' => [
                        'id' => $race->discipline->id,
                        'distance' => $race->discipline->distance,
                        'age_group' => $race->discipline->age_group,
                        'gender_group' => $race->discipline->gender_group,
                        'boat_group' => $race->discipline->boat_group,
                        'display_name' => $race->discipline->getDisplayName(),
                    ],
                    'crew_results' => $race->crewResults->map(function ($crewResult) {
                        return [
                            'id' => $crewResult->id,
                            'lane' => $crewResult->lane,
                            'position' => $crewResult->position,
                            'time_ms' => $crewResult->time_ms,
                            'formatted_time' => $crewResult->formatted_time,
                            'delay_after_first' => $crewResult->delay_after_first,
                            'status' => $crewResult->status,
                            'crew' => [
                                'id' => $crewResult->crew->id,
                                'team' => [
                                    'id' => $crewResult->crew->team->id,
                                    'name' => $crewResult->crew->team->name,
                                ],
                            ],
                        ];
                    })->values()
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'event' => [
                        'id' => $event->id,
                        'name' => $event->name,
                        'location' => $event->location,
                        'year' => $event->year,
                        'status' => $event->status,
                    ],
                    'race_results' => $formattedResults
                ],
                'message' => 'Public race results retrieved successfully'
            ], 200)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');

        } catch (\Exception $e) {
            return $this->sendError('Error retrieving public race results', [$e->getMessage()], 500)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');
        }
    }

    /**
     * Get public race results for a specific race.
     * Returns detailed information including all crew results.
     * 
     * @param int $eventId
     * @param int $raceId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getRaceResultDetail($eventId, $raceId)
    {
        try {
            // Verify the event exists
            $event = Event::find($eventId);
            if (!$event) {
                return $this->sendError('Event not found', [], 404);
            }

            // Get the specific race result with all crew data
            $raceResult = RaceResult::with([
                'discipline',
                'crewResults' => function ($query) {
                    $query->orderBy('position', 'asc')
                          ->orderBy('time_ms', 'asc');
                },
                'crewResults.crew.team',
                'crewResults.crew.discipline'
            ])
            ->forEvent($eventId)
            ->find($raceId);

            if (!$raceResult) {
                return $this->sendError('Race result not found', [], 404);
            }

            // Get all registered crews (including those without results)
            $allCrewResults = $raceResult->allCrewResults();

            $formattedResult = [
                'id' => $raceResult->id,
                'race_number' => $raceResult->race_number,
                'title' => $raceResult->title,
                'stage' => $raceResult->stage,
                'status' => $raceResult->status,
                'race_time' => $raceResult->race_time ? $raceResult->race_time->format('Y-m-d H:i:s') : null,
                'discipline' => [
                    'id' => $raceResult->discipline->id,
                    'distance' => $raceResult->discipline->distance,
                    'age_group' => $raceResult->discipline->age_group,
                    'gender_group' => $raceResult->discipline->gender_group,
                    'boat_group' => $raceResult->discipline->boat_group,
                    'display_name' => $raceResult->discipline->getDisplayName(),
                ],
                'crew_results' => $allCrewResults->map(function ($crewResult) {
                    // Handle both actual CrewResult models and virtual objects
                    if (is_object($crewResult) && isset($crewResult->crew)) {
                        return [
                            'id' => $crewResult->id ?? null,
                            'lane' => $crewResult->lane ?? null,
                            'position' => $crewResult->position ?? null,
                            'time_ms' => $crewResult->time_ms ?? null,
                            'formatted_time' => isset($crewResult->time_ms) ? $this->formatTime($crewResult->time_ms) : null,
                            'delay_after_first' => $crewResult->delay_after_first ?? null,
                            'status' => $crewResult->status ?? 'REGISTERED',
                            'crew' => [
                                'id' => $crewResult->crew->id,
                                'team' => [
                                    'id' => $crewResult->crew->team->id ?? null,
                                    'name' => $crewResult->crew->team->name ?? 'Unknown Team',
                                ],
                            ],
                        ];
                    }
                    return null;
                })->filter()->values()
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'event' => [
                        'id' => $event->id,
                        'name' => $event->name,
                        'location' => $event->location,
                        'year' => $event->year,
                        'status' => $event->status,
                    ],
                    'race_result' => $formattedResult
                ],
                'message' => 'Public race result detail retrieved successfully'
            ], 200)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');

        } catch (\Exception $e) {
            return $this->sendError('Error retrieving public race result detail', [$e->getMessage()], 500)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');
        }
    }

    /**
     * Format time from milliseconds to MM:SS.mmm format.
     * 
     * @param int $timeMs
     * @return string|null
     */
    private function formatTime($timeMs)
    {
        if (!$timeMs) return null;
        
        $totalMs = $timeMs;
        $minutes = floor($totalMs / 60000);
        $seconds = floor(($totalMs % 60000) / 1000);
        $milliseconds = $totalMs % 1000;
        
        return sprintf('%02d:%02d.%03d', $minutes, $seconds, $milliseconds);
    }

    /**
     * Get public race results with event_id query parameter.
     * This endpoint matches the structure used by the authenticated API.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPublicRaceResults(Request $request)
    {
        \Log::info('🚀 PublicController getPublicRaceResults called', [
            'event_id' => $request->query('event_id'),
            'timestamp' => now()->toDateTimeString()
        ]);

        try {
            $eventId = $request->query('event_id');

            if (!$eventId) {
                return $this->sendError('Event ID is required', [], 422)
                    ->header('Access-Control-Allow-Origin', '*')
                    ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');
            }

            // Get all races for the event regardless of status (same as authenticated API)
            try {
                $raceResults = RaceResult::with(['discipline', 'crewResults.crew.team'])
                    ->forEvent($eventId)
                    ->orderBy('race_number', 'asc')
                    ->get();
            } catch (\Exception $e) {
                \Log::error("Error loading race results with crews: " . $e->getMessage());
                // Fallback to basic loading
                $raceResults = RaceResult::with(['discipline', 'crewResults.crew'])
                    ->forEvent($eventId)
                    ->orderBy('race_number', 'asc')
                    ->get();
            }

            // Enhance race results with final round information and crew results
            $enhancedRaceResults = $raceResults->map(function($raceResult) {
                try {
                    // Always add is_final_round flag for tracking
                    $isFinalRound = $raceResult->isFinalRound();
                    $raceResult->is_final_round = $isFinalRound;

                    // For final rounds, calculate final times and manually add them to existing crew results
                    if ($isFinalRound) {
                        $finalTimes = $raceResult->getFinalTimesForDiscipline();

                        // Ensure crew_results relationship is loaded
                        if (!$raceResult->relationLoaded('crewResults')) {
                            $raceResult->load('crewResults.crew.team');
                        }

                        // Add final time fields to existing crew_results
                        $raceResult->crewResults->each(function($crewResult) use ($finalTimes) {
                            $finalData = $finalTimes->get($crewResult->crew_id);
                            if ($finalData) {
                                $crewResult->setAttribute('final_time_ms', $finalData['final_time_ms']);
                                $crewResult->setAttribute('final_status', $finalData['final_status']);
                            } else {
                                $crewResult->setAttribute('final_time_ms', null);
                                $crewResult->setAttribute('final_status', null);
                            }
                            $crewResult->setAttribute('is_final_round', true);
                        });
                    } else {
                        // For non-final rounds, just add the flag
                        if ($raceResult->relationLoaded('crewResults')) {
                            $raceResult->crewResults->each(function($crewResult) {
                                $crewResult->setAttribute('final_time_ms', null);
                                $crewResult->setAttribute('final_status', null);
                                $crewResult->setAttribute('is_final_round', false);
                            });
                        }
                    }

                    // Make sure the final time fields are appended to JSON
                    if ($raceResult->relationLoaded('crewResults')) {
                        $raceResult->crewResults->each(function($crewResult) {
                            $crewResult->append(['final_time_ms', 'final_status', 'is_final_round']);
                        });
                    }

                    \Log::info('🔍 Race result enhanced', [
                        'race_id' => $raceResult->id,
                        'stage' => $raceResult->stage,
                        'is_final_round' => $isFinalRound,
                        'crew_results_count' => $raceResult->relationLoaded('crewResults') ? $raceResult->crewResults->count() : 0,
                        'first_crew_final_time' => $isFinalRound && $raceResult->relationLoaded('crewResults') && $raceResult->crewResults->count() > 0
                            ? $raceResult->crewResults->first()->final_time_ms
                            : 'null'
                    ]);

                    return $raceResult;
                } catch (\Exception $e) {
                    \Log::error('Error enhancing race result', [
                        'race_id' => $raceResult->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);

                    // Return the original race result if enhancement fails
                    return $raceResult;
                }
            });

            return response()->json([
                'success' => true,
                'data' => $enhancedRaceResults,
                'message' => 'Race results retrieved successfully'
            ], 200)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');

        } catch (\Exception $e) {
            return $this->sendError('Error retrieving race results', [$e->getMessage()], 500)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');
        }
    }

    /**
     * Get a specific public race result with ALL registered crews.
     * This endpoint matches the structure used by the authenticated API.
     * 
     * @param int $raceResultId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPublicRaceResult($raceResultId)
    {
        try {
            $raceResult = RaceResult::with(['discipline'])->find($raceResultId);

            if (!$raceResult) {
                return $this->sendError('Race result not found', [], 404)
                    ->header('Access-Control-Allow-Origin', '*')
                    ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');
            }

            // Get all crew results (including crews without results) - same as authenticated API
            $allCrewResults = $raceResult->allCrewResults();

            // Convert race result to array for proper JSON serialization
            $raceResultArray = $raceResult->toArray();

            // Add properly serialized crew results (handle stdClass objects)
            $raceResultArray['crew_results'] = $allCrewResults->map(function($crewResult) {
                // allCrewResults returns stdClass objects, so cast to array
                return (array) $crewResult;
            })->values()->toArray();

            return response()->json([
                'success' => true,
                'data' => $raceResultArray,
                'message' => 'Race result retrieved successfully'
            ], 200)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');

        } catch (\Exception $e) {
            return $this->sendError('Error retrieving race result', [$e->getMessage()], 500)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');
        }
    }

    /**
     * Handle OPTIONS request for CORS preflight.
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleCorsOptions()
    {
        return response()->json([], 200)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');
    }
}