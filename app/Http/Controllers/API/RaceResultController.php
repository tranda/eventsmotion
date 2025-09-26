<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use Illuminate\Http\Request;
use App\Models\RaceResult;
use App\Models\CrewResult;
use App\Models\Discipline;
use App\Models\Crew;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RaceResultController extends BaseController
{
    /**
     * Get race results for a specific event.
     * Returns ALL races regardless of completion status.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        \Log::info('🚀 RaceResultController index method called', [
            'event_id' => $request->query('event_id'),
            'timestamp' => now()->toDateTimeString()
        ]);

        try {
            $eventId = $request->query('event_id');
            
            if (!$eventId) {
                return $this->sendError('Event ID is required', [], 422);
            }

            // Get all races for the event regardless of status
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
                // Get all crew results with final time calculations
                $allCrewResults = $raceResult->crewResults()
                    ->with(['crew.team', 'crew.discipline'])
                    ->get();

                // Convert to array for proper JSON serialization
                $raceResultArray = $raceResult->toArray();

                // Add enhanced crew results (convert each item to array)
                // allCrewResults returns mixed objects, so we need to handle them properly
                $raceResultArray['crew_results'] = $allCrewResults->map(function($crewResult) {
                    // Handle both Eloquent models and stdClass objects
                    if ($crewResult instanceof \Illuminate\Database\Eloquent\Model) {
                        return $crewResult->toArray();
                    } else {
                        // For stdClass objects, cast to array
                        return (array) $crewResult;
                    }
                })->values()->toArray();

                // Add additional computed properties
                $raceResultArray['is_final_round'] = $raceResult->isFinalRound();
                $raceResultArray['show_accumulated_time'] = $raceResult->shouldShowAccumulatedTime();

                return $raceResultArray;
            });

            return response()->json([
                'success' => true,
                'data' => $enhancedRaceResults,
                'message' => 'Race results retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return $this->sendError('Error retrieving race results', [$e->getMessage()], 500);
        }
    }

    /**
     * Get a specific race result with ALL registered crews.
     * Includes crews that haven't completed results yet.
     * For final rounds, includes accumulated times and final rankings.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $raceResult = RaceResult::with(['discipline'])->find($id);

            if (!$raceResult) {
                return $this->sendError('Race result not found', [], 404);
            }

            // Get all crew results (including crews without results)
            $allCrewResults = $raceResult->allCrewResults();

            // Enhance crew results with formatted times and positions for final rounds
            $enhancedCrewResults = $this->enhanceCrewResultsForResponse($allCrewResults, $raceResult);

            // Convert to array for proper JSON serialization
            $raceResultArray = $raceResult->toArray();

            // Add enhanced crew results (enhanceCrewResultsForResponse already returns an array)
            $raceResultArray['crew_results'] = $enhancedCrewResults;

            // Add additional computed properties
            $raceResultArray['is_final_round'] = $raceResult->isFinalRound();
            $raceResultArray['show_accumulated_time'] = $raceResult->shouldShowAccumulatedTime();

            return response()->json([
                'success' => true,
                'data' => $raceResultArray,
                'message' => 'Race result retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return $this->sendError('Error retrieving race result', [$e->getMessage()], 500);
        }
    }

    /**
     * Create a new race result.
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'race_number' => 'required|integer',
                'discipline_id' => 'required|exists:disciplines,id',
                'race_time' => 'nullable|date',
                'stage' => 'required|string|max:100',
                'status' => 'required|in:SCHEDULED,IN_PROGRESS,FINISHED,CANCELLED'
            ]);

            $raceResult = RaceResult::create($request->all());
            
            // Load discipline relationship to enable title computation
            $raceResult->load('discipline');

            return response()->json([
                'success' => true,
                'data' => $raceResult,
                'message' => 'Race result created successfully'
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->sendError('Validation error', $e->errors(), 422);
        } catch (\Exception $e) {
            return $this->sendError('Error creating race result', [$e->getMessage()], 500);
        }
    }

    /**
     * Update a race result.
     * 
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $raceResult = RaceResult::find($id);

            if (!$raceResult) {
                return $this->sendError('Race result not found', [], 404);
            }

            $request->validate([
                'race_number' => 'sometimes|integer',
                'discipline_id' => 'sometimes|exists:disciplines,id',
                'race_time' => 'nullable|date',
                'stage' => 'sometimes|string|max:100',
                'status' => 'sometimes|in:SCHEDULED,IN_PROGRESS,FINISHED,CANCELLED'
            ]);

            $raceResult->update($request->all());
            
            // Load discipline relationship to enable title computation
            $raceResult->load('discipline');

            return response()->json([
                'success' => true,
                'data' => $raceResult,
                'message' => 'Race result updated successfully'
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->sendError('Validation error', $e->errors(), 422);
        } catch (\Exception $e) {
            return $this->sendError('Error updating race result', [$e->getMessage()], 500);
        }
    }

    /**
     * Delete a race result.
     * 
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $raceResult = RaceResult::find($id);

            if (!$raceResult) {
                return $this->sendError('Race result not found', [], 404);
            }

            $raceResult->delete();

            return response()->json([
                'success' => true,
                'message' => 'Race result deleted successfully'
            ], 200);

        } catch (\Exception $e) {
            return $this->sendError('Error deleting race result', [$e->getMessage()], 500);
        }
    }

    /**
     * Get crew results for a specific race.
     * Includes ALL registered crews, even those without results.
     * For final rounds, includes accumulated times and final rankings.
     *
     * @param int $raceResultId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCrewResults($raceResultId)
    {
        try {
            $raceResult = RaceResult::find($raceResultId);

            if (!$raceResult) {
                return $this->sendError('Race result not found', [], 404);
            }

            // Get all crew results (including crews without results)
            $allCrewResults = $raceResult->allCrewResults();

            // Enhance crew results with formatted times and positions for final rounds
            $enhancedCrewResults = $this->enhanceCrewResultsForResponse($allCrewResults, $raceResult);

            return response()->json([
                'success' => true,
                'data' => $enhancedCrewResults,
                'is_final_round' => $raceResult->isFinalRound(),
                'show_accumulated_time' => $raceResult->shouldShowAccumulatedTime(),
                'message' => 'Crew results retrieved successfully'
            ], 200);

        } catch (\Exception $e) {
            return $this->sendError('Error retrieving crew results', [$e->getMessage()], 500);
        }
    }

    /**
     * Recalculate positions for a specific race based on times.
     * 
     * @param int $raceResultId
     * @return \Illuminate\Http\JsonResponse
     */
    public function recalculatePositions($raceResultId)
    {
        try {
            $raceResult = RaceResult::find($raceResultId);

            if (!$raceResult) {
                return $this->sendError('Race result not found', [], 404);
            }

            // Recalculate positions
            $this->calculatePositions($raceResultId);

            // Get updated crew results
            $updatedCrewResults = CrewResult::where('race_result_id', $raceResultId)
                ->with(['crew.team'])
                ->orderBy('position', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $updatedCrewResults,
                'message' => 'Positions recalculated successfully'
            ], 200);

        } catch (\Exception $e) {
            return $this->sendError('Error recalculating positions', [$e->getMessage()], 500);
        }
    }

    /**
     * Create or update crew results for a race.
     * 
     * @param Request $request
     * @param int $raceResultId
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeCrewResults(Request $request, $raceResultId)
    {
        try {
            $raceResult = RaceResult::find($raceResultId);

            if (!$raceResult) {
                return $this->sendError('Race result not found', [], 404);
            }

            $request->validate([
                'crew_results' => 'required|array',
                'crew_results.*.crew_id' => 'required|exists:crews,id',
                'crew_results.*.lane' => 'nullable|integer|min:1|max:10',
                'crew_results.*.position' => 'nullable|integer|min:1',
                'crew_results.*.time' => 'nullable|string',
                'crew_results.*.time_ms' => 'nullable|integer|min:0',
                'crew_results.*.delay_after_first' => 'nullable|string',
                'crew_results.*.status' => 'nullable|in:FINISHED,DNS,DNF,DSQ'
            ]);

            $crewResults = [];

            foreach ($request->crew_results as $crewResultData) {
                // Prepare update data with explicit null handling
                $updateData = [
                    'race_result_id' => $raceResultId,
                    'lane' => $crewResultData['lane'] ?? null,
                    'position' => $crewResultData['position'] ?? null,
                    'time_ms' => null, // Default to null, will be set below if provided
                    'delay_after_first' => $crewResultData['delay_after_first'] ?? null,
                    'status' => null // Default to null, will be set below based on data
                ];

                // Handle time conversion and assignment
                $hasTime = false;
                if (isset($crewResultData['time_ms']) && $crewResultData['time_ms'] !== null) {
                    $updateData['time_ms'] = $crewResultData['time_ms'];
                    $hasTime = true;
                } elseif (isset($crewResultData['time']) && $crewResultData['time'] !== null && $crewResultData['time'] !== '') {
                    $updateData['time_ms'] = $this->convertTimeToMilliseconds($crewResultData['time']);
                    $hasTime = true;
                }

                // Set status based on provided status or time availability
                if (isset($crewResultData['status']) && $crewResultData['status'] !== null) {
                    // Use explicitly provided status (DNS, DNF, DSQ, or FINISHED)
                    $updateData['status'] = $crewResultData['status'];
                } elseif ($hasTime) {
                    // If time is provided but no explicit status, mark as FINISHED
                    $updateData['status'] = 'FINISHED';
                }
                // If no time and no explicit status provided, status remains null (registered but not finished)

                $crewResult = CrewResult::updateOrCreate(
                    [
                        'crew_id' => $crewResultData['crew_id'],
                        'race_result_id' => $raceResultId
                    ],
                    $updateData
                );

                $crewResults[] = $crewResult;
            }

            // Auto-calculate positions based on race times
            $this->calculatePositions($raceResultId);

            // Reload crew results with updated positions
            $updatedCrewResults = CrewResult::where('race_result_id', $raceResultId)
                ->with(['crew.team'])
                ->orderBy('position', 'asc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $updatedCrewResults,
                'message' => 'Crew results saved and positions calculated successfully'
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->sendError('Validation error', $e->errors(), 422);
        } catch (\Exception $e) {
            return $this->sendError('Error saving crew results', [$e->getMessage()], 500);
        }
    }

    /**
     * Bulk update race plan data.
     * Uses discipline + stage as unique identifier.
     * Optionally includes cleanup functionality for orphaned races by discipline.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkUpdate(Request $request)
    {
        \Log::info('🚀 bulkUpdate method ENTRY at ' . now()->toDateTimeString());

        try {
            $request->validate([
                'races' => 'required|array',
                'races.*.race_number' => 'required|integer',
                'races.*.start_time' => 'required|string',
                'races.*.delay' => 'required|string',
                'races.*.stage' => 'required|string|max:100',
                'races.*.competition' => 'required|string',
                'races.*.discipline_info' => 'required|string',
                'races.*.boat_size' => 'required|string',
                'races.*.lanes' => 'nullable|array',
                'races.*.lanes.*.team' => 'nullable|string',
                'races.*.lanes.*.time' => 'nullable|string',
                'event_id' => 'required|exists:events,id',
                'perform_cleanup' => 'boolean'
            ]);

            $performCleanup = $request->input('perform_cleanup', true);

            \Log::info('=== bulkUpdate method called ===', [
                'perform_cleanup' => $performCleanup,
                'races_count' => count($request->races ?? []),
                'event_id' => $request->event_id
            ]);

            // Use database transaction for safety
            \DB::beginTransaction();

            try {
                $updatedRaces = [];
                $cleanupResults = [];
                $disciplinesProcessed = collect();

                foreach ($request->races as $raceData) {
                    \Log::info("Processing race: " . json_encode($raceData));

                    // 1. Find or create discipline
                    $discipline = $this->findOrCreateDiscipline($raceData, $request->event_id);
                    \Log::info("Discipline created/found: " . json_encode($discipline->toArray()));

                    // Track disciplines for cleanup
                    if ($performCleanup && !$disciplinesProcessed->contains('id', $discipline->id)) {
                        $disciplinesProcessed->push($discipline);
                        \Log::info('Added discipline for cleanup tracking', [
                            'discipline_id' => $discipline->id,
                            'total_disciplines_tracked' => $disciplinesProcessed->count()
                        ]);
                    }

                    // 2. Calculate race time
                    $raceTime = $this->calculateRaceTime($raceData['start_time'], $raceData['delay']);

                    // 3. Update or create RaceResult using discipline + stage as unique key
                    $raceResult = RaceResult::updateOrCreate(
                        [
                            'discipline_id' => $discipline->id,
                            'stage' => $raceData['stage']
                        ],
                        [
                            'race_number' => $raceData['race_number'],
                            'race_time' => $raceTime,
                            'status' => 'SCHEDULED'
                        ]
                    );

                    // 4. Update crew assignments if lanes are provided
                    if (isset($raceData['lanes']) && is_array($raceData['lanes'])) {
                        $this->updateCrewAssignments($raceResult, $raceData['lanes']);
                    }

                    try {
                        $raceResult->load('discipline', 'crewResults.crew.team');
                    } catch (\Exception $e) {
                        \Log::error("Error loading relationships: " . $e->getMessage());
                        // Try loading without crew relationship
                        $raceResult->load('discipline', 'crewResults.crew');
                    }
                    $updatedRaces[] = $raceResult;
                }

                // Perform event-wide cleanup if requested
                \Log::info('Cleanup check', [
                    'perform_cleanup' => $performCleanup,
                    'disciplines_processed_count' => count($disciplinesProcessed),
                    'disciplines_processed' => $disciplinesProcessed->pluck('id')->toArray()
                ]);

                if ($performCleanup) {
                    // Step 1: Discipline-specific cleanup (existing logic)
                    foreach ($disciplinesProcessed as $discipline) {
                        // Get stages for this discipline from import data
                        $disciplineStages = collect($request->races)
                            ->filter(function($race) use ($discipline, $request) {
                                $raceDiscParams = $this->extractDisciplineParams($race, $request->event_id);
                                return $this->disciplineMatches($discipline, $raceDiscParams);
                            })
                            ->pluck('stage')
                            ->unique()
                            ->values()
                            ->toArray();

                        \Log::info('Stages for discipline cleanup', [
                            'discipline_id' => $discipline->id,
                            'stages_in_import' => $disciplineStages
                        ]);

                        $disciplineCleanup = $this->performRaceCleanup($discipline->id, collect($request->races)->filter(function($race) use ($discipline, $request) {
                            $raceDiscParams = $this->extractDisciplineParams($race, $request->event_id);
                            return $this->disciplineMatches($discipline, $raceDiscParams);
                        })->toArray());

                        if ($disciplineCleanup['deleted_count'] > 0) {
                            $cleanupResults[] = [
                                'discipline_id' => $discipline->id,
                                'discipline_title' => $discipline->title,
                                'cleanup' => $disciplineCleanup
                            ];
                        }
                    }

                    // Step 2: Event-wide cleanup - Delete races not represented in payload
                    $eventCleanup = $this->performEventWideCleanup($request->event_id, $updatedRaces);
                    if ($eventCleanup['deleted_count'] > 0) {
                        $cleanupResults[] = [
                            'scope' => 'event_wide',
                            'event_id' => $request->event_id,
                            'cleanup' => $eventCleanup
                        ];
                    }
                }

                // Auto-cancel races that were removed from the sheet (existing functionality)
                $processedRaceIds = collect($updatedRaces)->pluck('id');
                $cancelledCount = RaceResult::whereHas('discipline', function($query) use ($request) {
                        $query->where('event_id', $request->event_id);
                    })
                    ->whereNotIn('id', $processedRaceIds)
                    ->where('status', '!=', 'FINISHED')
                    ->where('status', '!=', 'CANCELLED')
                    ->update(['status' => 'CANCELLED']);

                \DB::commit();

                // Build response message
                $message = 'Race plan updated successfully';
                if ($cancelledCount > 0) {
                    $message .= ". {$cancelledCount} races automatically cancelled (removed from sheet)";
                }
                if (!empty($cleanupResults)) {
                    $totalCleaned = collect($cleanupResults)->sum('cleanup.deleted_count');
                    $message .= ". Cleaned up {$totalCleaned} orphaned races from " . count($cleanupResults) . " discipline(s)";
                }

                $responseData = [
                    'updated_races' => $updatedRaces
                ];

                // Include cleanup results if cleanup was performed
                if ($performCleanup) {
                    $responseData['cleanup_summary'] = $cleanupResults;
                }

                return response()->json([
                    'success' => true,
                    'data' => $responseData,
                    'message' => $message
                ], 200);

            } catch (\Exception $e) {
                \DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->sendError('Validation error', $e->errors(), 422);
        } catch (\Exception $e) {
            \Log::error('Race plan bulk update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'event_id' => $request->event_id ?? null
            ]);
            return $this->sendError('Error updating race plan', [$e->getMessage()], 500);
        }
    }

    /**
     * Bulk import race results with comprehensive cleanup logic.
     * Safely removes orphaned races and crew results for specific discipline.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkImportWithCleanup(Request $request)
    {
        try {
            $request->validate([
                'discipline_id' => 'required|exists:disciplines,id',
                'races' => 'required|array',
                'races.*.race_number' => 'required|integer',
                'races.*.start_time' => 'nullable|string',
                'races.*.delay' => 'nullable|string',
                'races.*.stage' => 'required|string|max:100',
                'races.*.status' => 'nullable|in:SCHEDULED,IN_PROGRESS,FINISHED,CANCELLED',
                'races.*.lanes' => 'nullable|array',
                'races.*.lanes.*.team' => 'nullable|string',
                'races.*.lanes.*.time' => 'nullable|string',
                'perform_cleanup' => 'boolean'
            ]);

            $disciplineId = $request->discipline_id;
            $performCleanup = $request->input('perform_cleanup', true);

            // Use database transaction for safety
            \DB::beginTransaction();

            try {
                $cleanupResults = [];

                // Step 1: Perform cleanup if requested
                if ($performCleanup) {
                    $cleanupResults = $this->performRaceCleanup($disciplineId, $request->races);
                }

                // Step 2: Process import data
                $importResults = $this->processRaceImport($disciplineId, $request->races);

                \DB::commit();

                $message = "Race import completed successfully";
                if (!empty($cleanupResults['deleted_count'])) {
                    $message .= ". Cleaned up {$cleanupResults['deleted_count']} orphaned races";
                }

                return response()->json([
                    'success' => true,
                    'data' => [
                        'imported_races' => $importResults,
                        'cleanup_summary' => $cleanupResults
                    ],
                    'message' => $message
                ], 200);

            } catch (\Exception $e) {
                \DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->sendError('Validation error', $e->errors(), 422);
        } catch (\Exception $e) {
            \Log::error('Race import with cleanup failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'discipline_id' => $request->discipline_id ?? null
            ]);
            return $this->sendError('Error importing race results', [$e->getMessage()], 500);
        }
    }

    /**
     * Find or create discipline based on race data.
     * 
     * @param array $raceData
     * @param int $eventId
     * @return Discipline
     */
    private function findOrCreateDiscipline($raceData, $eventId)
    {
        // Parse discipline info: "Small boat, Premier, Open 500m"
        $disciplineInfo = $raceData['discipline_info'];
        $boatSize = $raceData['boat_size'];
        
        // Parse structured format: "boat_group, age_group, gender_group distance"
        $parts = array_map('trim', explode(',', $disciplineInfo));
        
        // Part 1: boat_group (mapped from boat_size parameter)
        $boatGroup = $this->mapBoatSizeToGroup($boatSize);
        
        // Part 2: age_group  
        $ageGroup = isset($parts[1]) ? trim($parts[1]) : 'Senior';
        
        // Part 3: gender_group + distance
        if (isset($parts[2])) {
            $lastPart = trim($parts[2]);
            // Extract distance from end (e.g., "Open 500m" -> distance=500, gender="Open")
            if (preg_match('/(.+?)\s+(\d+)m?$/', $lastPart, $matches)) {
                $genderGroup = trim($matches[1]);
                $distance = (int)$matches[2];
            } else {
                // Fallback if no distance found
                $genderGroup = $lastPart;
                $distance = 500; // default
            }
        } else {
            $genderGroup = 'Open';
            $distance = 500;
        }
        
        // Find or create discipline
        return Discipline::firstOrCreate(
            [
                'event_id' => $eventId,
                'distance' => $distance,
                'age_group' => $ageGroup,
                'gender_group' => $genderGroup,
                'boat_group' => $boatGroup
            ],
            [
                'status' => 'SCHEDULED'
            ]
        );
    }

    /**
     * Map boat size to boat group.
     * 
     * @param string $boatSize
     * @return string
     */
    private function mapBoatSizeToGroup($boatSize)
    {
        // Return the boat size as-is since it should be "small" or "standard"
        return $boatSize;
    }

    /**
     * Calculate race time from start time and delay.
     * 
     * @param string $startTime
     * @param string $delay
     * @return Carbon
     */
    private function calculateRaceTime($startTime, $delay)
    {
        try {
            // Parse start time (e.g., "10:00", "13:00")
            $time = Carbon::createFromFormat('H:i', $startTime);
            
            // Parse delay (e.g., "0:05", "1:30", "3:00")
            $delayParts = explode(':', $delay);
            $delayMinutes = (int)$delayParts[0] * 60 + (int)$delayParts[1];
            
            $result = $time->addMinutes($delayMinutes);
            
            // Debug logging
            \Log::info("Time calculation: {$startTime} + {$delay} = {$result->format('Y-m-d H:i:s')}");
            
            return $result;
        } catch (\Exception $e) {
            \Log::error("Time calculation error: " . $e->getMessage());
            // Return a default time if parsing fails
            return Carbon::now()->setTime(10, 0);
        }
    }

    /**
     * Update crew assignments for a race.
     * 
     * @param RaceResult $raceResult
     * @param array $lanes
     * @return void
     */
    private function updateCrewAssignments($raceResult, $lanes)
    {
        $hasTimeUpdates = false;

        foreach ($lanes as $laneNumber => $laneData) {
            // Handle both old format (string) and new format (object with team/time)
            if (is_string($laneData)) {
                $teamName = $laneData;
                $time = null;
            } else {
                $teamName = $laneData['team'] ?? null;
                $time = $laneData['time'] ?? null;
            }

            if ($teamName) {
                // Find crew by team name and discipline
                $crew = $this->findCrewByTeamAndDiscipline($teamName, $raceResult->discipline_id);

                if ($crew) {
                    $updateData = [
                        'lane' => (int)$laneNumber,
                        'time_ms' => null,      // Explicitly clear time by default
                        'position' => null,     // Clear position by default
                        'status' => null        // Default to null (registered but not finished)
                    ];

                    // Add time if provided, otherwise keep it null (cleared)
                    if ($time && $time !== '') {
                        // Check if time is actually a status (DNS, DNF, DSQ)
                        $statusValues = ['DNS', 'DNF', 'DSQ', 'dns', 'dnf', 'dsq'];
                        if (in_array(strtoupper($time), array_map('strtoupper', $statusValues))) {
                            // Time field contains a status, not an actual time
                            $updateData['time_ms'] = null;
                            $updateData['status'] = strtoupper($time);
                            $hasTimeUpdates = true;
                        } else {
                            // Time field contains actual time
                            $updateData['time_ms'] = $this->convertTimeToMilliseconds($time);
                            $updateData['status'] = 'FINISHED';
                            $hasTimeUpdates = true;
                        }
                    }

                    // Use updateOrCreate to handle existing records
                    CrewResult::updateOrCreate(
                        [
                            'crew_id' => $crew->id,
                            'race_result_id' => $raceResult->id
                        ],
                        $updateData
                    );
                }
            }
        }
        
        // Remove crew results for lanes that are no longer in the sheet
        $currentLanes = array_keys(array_filter($lanes, function($lane) {
            return is_string($lane) ? !empty($lane) : !empty($lane['team'] ?? '');
        }));
        CrewResult::where('race_result_id', $raceResult->id)
            ->whereNotIn('lane', $currentLanes)
            ->delete();
            
        // Auto-calculate positions if times were updated
        if ($hasTimeUpdates) {
            $this->calculatePositions($raceResult->id);
        }
    }

    /**
     * Find crew by team name and discipline.
     * Creates team and crew if they don't exist.
     * 
     * @param string $teamName
     * @param int $disciplineId
     * @return Crew|null
     */
    private function findCrewByTeamAndDiscipline($teamName, $disciplineId)
    {
        // Find or create team
        $team = Team::firstOrCreate(
            ['name' => $teamName]
            // Remove status, let the model handle defaults
        );
        
        // Find or create crew for this team and discipline
        return Crew::firstOrCreate([
            'team_id' => $team->id,
            'discipline_id' => $disciplineId
        ]);
    }

    /**
     * Calculate and assign positions based on race times.
     * Crews with faster times get better (lower) positions.
     * 
     * @param int $raceResultId
     * @return void
     */
    private function calculatePositions($raceResultId)
    {
        // Get all finished crew results for this race, ordered by time (fastest first)
        $finishedResults = CrewResult::where('race_result_id', $raceResultId)
            ->where('status', 'FINISHED')
            ->whereNotNull('time_ms')
            ->where('time_ms', '>', 0)
            ->orderBy('time_ms', 'asc')
            ->get();

        // Assign positions starting from 1
        $position = 1;
        foreach ($finishedResults as $crewResult) {
            $crewResult->position = $position;
            $crewResult->save();
            $position++;
        }

        // Clear positions for non-finished or no-time results
        CrewResult::where('race_result_id', $raceResultId)
            ->where(function ($query) {
                $query->where('status', '!=', 'FINISHED')
                    ->orWhereNull('time_ms')
                    ->orWhere('time_ms', '<=', 0);
            })
            ->update(['position' => null]);

        \Log::info("Calculated positions for race {$raceResultId}: {$finishedResults->count()} crews positioned");
    }

    /**
     * Convert time string to milliseconds.
     * 
     * @param string $timeString Format: "02:05.123"
     * @return int
     */
    private function convertTimeToMilliseconds($timeString)
    {
        // Parse "02:05.123" format
        if (preg_match('/(\d+):(\d+)\.(\d+)/', $timeString, $matches)) {
            $minutes = (int)$matches[1];
            $seconds = (int)$matches[2];
            $milliseconds = (int)str_pad($matches[3], 3, '0', STR_PAD_RIGHT);
            
            return ($minutes * 60 * 1000) + ($seconds * 1000) + $milliseconds;
        }
        
        return 0;
    }

    /**
     * Perform cleanup of orphaned races for a specific discipline.
     *
     * @param int $disciplineId
     * @param array $importRaces
     * @return array
     */
    private function performRaceCleanup($disciplineId, $importRaces)
    {
        // Get stages from import data
        $importStages = collect($importRaces)->pluck('stage')->unique()->values()->toArray();

        \Log::info('Starting race cleanup', [
            'discipline_id' => $disciplineId,
            'import_stages' => $importStages
        ]);

        // Find races that exist in database but not in import data
        $orphanedRaces = $this->findOrphanedRaces($disciplineId, $importStages);

        $deletedRaces = [];
        $deletedCrewResultsCount = 0;

        foreach ($orphanedRaces as $race) {
            // Count crew results before deletion for logging
            $crewResultsCount = $race->crewResults()->count();
            $deletedCrewResultsCount += $crewResultsCount;

            $deletedRaces[] = [
                'id' => $race->id,
                'race_number' => $race->race_number,
                'stage' => $race->stage,
                'status' => $race->status,
                'crew_results_count' => $crewResultsCount
            ];

            \Log::info('Deleting orphaned race', [
                'race_id' => $race->id,
                'race_number' => $race->race_number,
                'stage' => $race->stage,
                'discipline_id' => $disciplineId,
                'crew_results_count' => $crewResultsCount
            ]);

            // Delete the race (crew results will be cascade deleted)
            $race->delete();
        }

        \Log::info('Race cleanup completed', [
            'discipline_id' => $disciplineId,
            'deleted_races_count' => count($deletedRaces),
            'deleted_crew_results_count' => $deletedCrewResultsCount
        ]);

        return [
            'deleted_count' => count($deletedRaces),
            'deleted_races' => $deletedRaces,
            'deleted_crew_results_count' => $deletedCrewResultsCount
        ];
    }

    /**
     * Perform event-wide cleanup to remove races not represented in the payload.
     * This deletes any race in the event that wasn't processed/updated.
     *
     * @param int $eventId
     * @param array $processedRaces Array of race results that were processed
     * @return array
     */
    private function performEventWideCleanup($eventId, $processedRaces)
    {
        $processedRaceIds = collect($processedRaces)->pluck('id')->filter();

        \Log::info('Starting event-wide cleanup', [
            'event_id' => $eventId,
            'processed_race_ids' => $processedRaceIds->toArray(),
            'processed_count' => $processedRaceIds->count()
        ]);

        // Find all races in the event that were NOT processed
        $orphanedRaces = RaceResult::whereHas('discipline', function($query) use ($eventId) {
                $query->where('event_id', $eventId);
            })
            ->whereNotIn('id', $processedRaceIds)
            ->with(['crewResults', 'discipline'])
            ->get();

        $deletedRaces = [];
        $deletedCrewResultsCount = 0;

        foreach ($orphanedRaces as $race) {
            // Count crew results before deletion for logging
            $crewResultsCount = $race->crewResults()->count();
            $deletedCrewResultsCount += $crewResultsCount;

            $deletedRaces[] = [
                'id' => $race->id,
                'race_number' => $race->race_number,
                'stage' => $race->stage,
                'status' => $race->status,
                'discipline_info' => $race->discipline ? "{$race->discipline->boat_group}, {$race->discipline->age_group}, {$race->discipline->gender_group} {$race->discipline->distance}" : 'Unknown',
                'crew_results_count' => $crewResultsCount
            ];

            \Log::info('Deleting orphaned race from event', [
                'race_id' => $race->id,
                'race_number' => $race->race_number,
                'stage' => $race->stage,
                'event_id' => $eventId,
                'discipline_id' => $race->discipline_id,
                'crew_results_count' => $crewResultsCount
            ]);

            // Delete the race (crew results will be cascade deleted)
            $race->delete();
        }

        \Log::info('Event-wide cleanup completed', [
            'event_id' => $eventId,
            'deleted_races_count' => count($deletedRaces),
            'deleted_crew_results_count' => $deletedCrewResultsCount
        ]);

        return [
            'deleted_count' => count($deletedRaces),
            'deleted_races' => $deletedRaces,
            'deleted_crew_results_count' => $deletedCrewResultsCount
        ];
    }

    /**
     * Find races that exist in database but not in import data.
     *
     * @param int $disciplineId
     * @param array $importStages
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function findOrphanedRaces($disciplineId, $importStages)
    {
        return RaceResult::where('discipline_id', $disciplineId)
            ->whereNotIn('stage', $importStages)
            ->with(['crewResults']) // Load relationships for counting
            ->get();
    }

    /**
     * Process the race import data.
     *
     * @param int $disciplineId
     * @param array $races
     * @return array
     */
    private function processRaceImport($disciplineId, $races)
    {
        $importedRaces = [];

        foreach ($races as $raceData) {
            // Calculate race time if start_time and delay are provided
            $raceTime = null;
            if (isset($raceData['start_time']) && isset($raceData['delay'])) {
                $raceTime = $this->calculateRaceTime($raceData['start_time'], $raceData['delay']);
            }

            // Update or create race result
            $raceResult = RaceResult::updateOrCreate(
                [
                    'discipline_id' => $disciplineId,
                    'stage' => $raceData['stage']
                ],
                [
                    'race_number' => $raceData['race_number'],
                    'race_time' => $raceTime,
                    'status' => $raceData['status'] ?? 'SCHEDULED'
                ]
            );

            // Update crew assignments if lanes are provided
            if (isset($raceData['lanes']) && is_array($raceData['lanes'])) {
                $this->updateCrewAssignments($raceResult, $raceData['lanes']);
            }

            // Load relationships
            try {
                $raceResult->load('discipline', 'crewResults.crew.team');
            } catch (\Exception $e) {
                \Log::error("Error loading relationships: " . $e->getMessage());
                $raceResult->load('discipline', 'crewResults.crew');
            }

            $importedRaces[] = $raceResult;
        }

        return $importedRaces;
    }

    /**
     * Extract discipline parameters from race data.
     *
     * @param array $raceData
     * @param int $eventId
     * @return array
     */
    private function extractDisciplineParams($raceData, $eventId)
    {
        // Parse discipline info: "Small boat, Premier, Open 500m"
        $disciplineInfo = $raceData['discipline_info'];
        $boatSize = $raceData['boat_size'];

        // Parse structured format: "boat_group, age_group, gender_group distance"
        $parts = array_map('trim', explode(',', $disciplineInfo));

        // Part 1: boat_group (mapped from boat_size parameter)
        $boatGroup = $this->mapBoatSizeToGroup($boatSize);

        // Part 2: age_group
        $ageGroup = isset($parts[1]) ? trim($parts[1]) : 'Senior';

        // Part 3: gender_group + distance
        if (isset($parts[2])) {
            $lastPart = trim($parts[2]);
            // Extract distance from end (e.g., "Open 500m" -> distance=500, gender="Open")
            if (preg_match('/(.+?)\s+(\d+)m?$/', $lastPart, $matches)) {
                $genderGroup = trim($matches[1]);
                $distance = (int)$matches[2];
            } else {
                // Fallback if no distance found
                $genderGroup = $lastPart;
                $distance = 500; // default
            }
        } else {
            $genderGroup = 'Open';
            $distance = 500;
        }

        return [
            'event_id' => $eventId,
            'distance' => $distance,
            'age_group' => $ageGroup,
            'gender_group' => $genderGroup,
            'boat_group' => $boatGroup
        ];
    }

    /**
     * Check if a discipline matches the given parameters.
     *
     * @param Discipline $discipline
     * @param array $params
     * @return bool
     */
    private function disciplineMatches($discipline, $params)
    {
        return $discipline->event_id == $params['event_id'] &&
               $discipline->distance == $params['distance'] &&
               $discipline->age_group === $params['age_group'] &&
               $discipline->gender_group === $params['gender_group'] &&
               $discipline->boat_group === $params['boat_group'];
    }

    /**
     * Enhance crew results with additional data for API response.
     * Adds formatted times, final positions, and other display data.
     *
     * @param \Illuminate\Support\Collection $crewResults
     * @param RaceResult $raceResult
     * @return array
     */
    private function enhanceCrewResultsForResponse($crewResults, $raceResult)
    {
        $isFinalRound = $raceResult->isFinalRound();
        $finalPosition = 1;

        return $crewResults->map(function($crewResult, $index) use ($isFinalRound, &$finalPosition) {
            // Convert to array for consistent handling
            $result = is_object($crewResult) ? (array)$crewResult : $crewResult;

            // Add current round formatted time
            if (isset($result['time_ms']) && $result['time_ms']) {
                $result['formatted_time'] = CrewResult::formatTimeFromMs($result['time_ms']);
            } else {
                $result['formatted_time'] = null;
            }

            // Add final round specific data
            if ($isFinalRound) {
                // Add formatted final time
                if (isset($result['final_time_ms']) && $result['final_time_ms']) {
                    $result['formatted_final_time'] = CrewResult::formatTimeFromMs($result['final_time_ms']);
                } else {
                    $result['formatted_final_time'] = null;
                }

                // Add final position for crews with valid final times or DSQ status
                if (isset($result['final_status'])) {
                    if ($result['final_status'] === 'DSQ') {
                        $result['final_position'] = 'DSQ';
                    } elseif (isset($result['final_time_ms']) && $result['final_time_ms']) {
                        $result['final_position'] = $finalPosition;
                        $finalPosition++;
                    } else {
                        $result['final_position'] = null;
                    }
                } else {
                    $result['final_position'] = null;
                }
            }

            // Ensure consistent structure
            $result['is_final_round'] = $isFinalRound;

            return $result;
        })->values()->toArray();
    }

    /**
     * Fetch race plans from external app endpoint.
     * Protected with the same API key as bulk-update.
     * Returns all race results/plans for a given event with comprehensive race information.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function fetchRacePlans(Request $request)
    {
        \Log::info('🚀 fetchRacePlans method called', [
            'event_id' => $request->query('event_id'),
            'timestamp' => now()->toDateTimeString()
        ]);

        try {
            $eventId = $request->query('event_id');

            if (!$eventId) {
                return $this->sendError('Event ID is required', [], 422);
            }

            // Verify event exists
            $event = \App\Models\Event::find($eventId);
            if (!$event) {
                return $this->sendError('Event not found', [], 404);
            }

            // Get all race results for the event with full relationships
            $raceResults = RaceResult::with([
                'discipline' => function($query) {
                    $query->select('id', 'event_id', 'boat_group', 'age_group', 'gender_group', 'distance');
                },
                'crewResults.crew.team' => function($query) {
                    $query->select('id', 'name', 'club_id');
                }
            ])
            ->forEvent($eventId)
            ->orderBy('race_number', 'asc')
            ->get();

            // Transform the data for external consumption
            $racePlans = $raceResults->map(function ($raceResult) {
                $discipline = $raceResult->discipline;

                // Format boat group and age group with proper spacing
                $boatSize = $this->formatDisciplineField($discipline->boat_group);
                $ageGroup = $this->formatDisciplineField($discipline->age_group);

                // Build discipline info string
                $disciplineInfo = "{$boatSize}, {$ageGroup}, {$discipline->gender_group} {$discipline->distance}";

                // Get crew/lane assignments
                $lanes = $raceResult->crewResults->map(function ($crewResult) {
                    return [
                        'lane' => $crewResult->lane,
                        'team' => $crewResult->crew->team->name ?? 'Unknown Team',
                        'crew_id' => $crewResult->crew->id,
                        'time' => $crewResult->time_ms ? CrewResult::formatTimeFromMs($crewResult->time_ms) : null,
                        'status' => $crewResult->status,
                        'position' => $crewResult->position
                    ];
                })->values()->toArray();

                return [
                    'id' => $raceResult->id,
                    'race_number' => $raceResult->race_number,
                    'stage' => $raceResult->stage,
                    'discipline_id' => $raceResult->discipline_id,
                    'discipline_info' => $disciplineInfo,
                    'boat_size' => $boatSize,
                    'race_time' => $raceResult->race_time ? $raceResult->race_time->toDateTimeString() : null,
                    'status' => $raceResult->status,
                    'lanes' => $lanes,
                    'title' => $raceResult->title,
                    'created_at' => $raceResult->created_at,
                    'updated_at' => $raceResult->updated_at
                ];
            });

            \Log::info('🏁 fetchRacePlans completed successfully', [
                'event_id' => $eventId,
                'races_returned' => $racePlans->count(),
                'timestamp' => now()->toDateTimeString()
            ]);

            return $this->sendResponse([
                'event_id' => $eventId,
                'event_name' => $event->name,
                'race_count' => $racePlans->count(),
                'races' => $racePlans
            ], 'Race plans retrieved successfully');

        } catch (\Exception $e) {
            \Log::error('❌ Error in fetchRacePlans', [
                'error' => $e->getMessage(),
                'event_id' => $request->query('event_id'),
                'timestamp' => now()->toDateTimeString()
            ]);

            return $this->sendError('Error retrieving race plans', [$e->getMessage()], 500);
        }
    }

    /**
     * Public test endpoint for debugging fetch-plans functionality.
     * Returns test data and helps debug API connectivity issues.
     * NO AUTHENTICATION REQUIRED - for debugging only.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testFetchPlans(Request $request)
    {
        \Log::info('🧪 testFetchPlans method called for debugging', [
            'event_id' => $request->query('event_id'),
            'timestamp' => now()->toDateTimeString(),
            'user_agent' => $request->header('User-Agent'),
            'ip' => $request->ip()
        ]);

        try {
            $eventId = $request->query('event_id');

            // Test 1: Basic connectivity
            $tests = [
                'basic_connectivity' => 'OK',
                'timestamp' => now()->toDateTimeString(),
                'request_method' => $request->method(),
                'event_id_provided' => $eventId ? 'YES' : 'NO',
                'event_id_value' => $eventId
            ];

            // Test 2: Database connectivity
            try {
                $eventCount = \App\Models\Event::count();
                $tests['database_connection'] = 'OK';
                $tests['total_events_in_db'] = $eventCount;
            } catch (\Exception $e) {
                $tests['database_connection'] = 'ERROR';
                $tests['database_error'] = $e->getMessage();
            }

            // Test 3: Event existence check
            if ($eventId) {
                try {
                    $event = \App\Models\Event::find($eventId);
                    if ($event) {
                        $tests['event_exists'] = 'YES';
                        $tests['event_name'] = $event->name;

                        // Test 4: Race results count
                        $raceCount = RaceResult::forEvent($eventId)->count();
                        $tests['race_results_count'] = $raceCount;

                        // Test 5: Sample race data
                        if ($raceCount > 0) {
                            $sampleRace = RaceResult::with(['discipline', 'crewResults.crew.team'])
                                ->forEvent($eventId)
                                ->first();

                            if ($sampleRace) {
                                $tests['sample_race'] = [
                                    'id' => $sampleRace->id,
                                    'race_number' => $sampleRace->race_number,
                                    'stage' => $sampleRace->stage,
                                    'status' => $sampleRace->status,
                                    'discipline_loaded' => $sampleRace->discipline ? 'YES' : 'NO',
                                    'crew_results_count' => $sampleRace->crewResults->count()
                                ];
                            }
                        }
                    } else {
                        $tests['event_exists'] = 'NO';
                        $tests['available_events'] = \App\Models\Event::select('id', 'name')->limit(5)->get();
                    }
                } catch (\Exception $e) {
                    $tests['event_check_error'] = $e->getMessage();
                }
            }

            // Test 6: Try the actual fetchRacePlans method
            if ($eventId) {
                try {
                    $actualResult = $this->fetchRacePlans($request);
                    $tests['fetch_race_plans_test'] = 'SUCCESS';
                    $tests['actual_response_status'] = $actualResult->getStatusCode();
                } catch (\Exception $e) {
                    $tests['fetch_race_plans_test'] = 'ERROR';
                    $tests['fetch_error'] = $e->getMessage();
                    $tests['fetch_error_line'] = $e->getLine();
                    $tests['fetch_error_file'] = basename($e->getFile());
                }
            }

            return response()->json([
                'success' => true,
                'debug_mode' => true,
                'message' => 'Debug test completed',
                'tests' => $tests,
                'usage' => [
                    'description' => 'This is a public debug endpoint',
                    'usage' => 'Add ?event_id=X to test with a specific event',
                    'protected_endpoint' => '/api/race-results/fetch-plans (requires API key)',
                    'debug_endpoint' => '/api/race-results/test-fetch-plans (public, no auth)'
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error('❌ Error in testFetchPlans', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'event_id' => $request->query('event_id')
            ]);

            return response()->json([
                'success' => false,
                'debug_mode' => true,
                'message' => 'Debug test failed',
                'error' => [
                    'message' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => basename($e->getFile())
                ],
                'timestamp' => now()->toDateTimeString()
            ], 500);
        }
    }

    /**
     * Update results for a single race by race ID from external app.
     * Protected with the same API key as bulk-update.
     * Updates race status, crew results, and handles multiple image uploads.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateSingleRaceById(Request $request)
    {
        \Log::info('🏁 updateSingleRaceById method called', [
            'race_id' => $request->input('race_id'),
            'timestamp' => now()->toDateTimeString(),
            'has_files' => $request->hasFile('images')
        ]);

        try {
            // Handle both JSON and multipart requests
            $isMultipart = $request->hasFile('images');

            if ($isMultipart) {
                $request->validate([
                    'race_id' => 'required|exists:race_results,id',
                    'status' => 'nullable|in:SCHEDULED,IN_PROGRESS,FINISHED,CANCELLED',
                    'lanes' => 'nullable|array',
                    'lanes.*.team' => 'nullable|string',
                    'lanes.*.time' => 'nullable|string',
                    'images' => 'nullable|array',
                    'images.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:10240'
                ]);
            } else {
                $request->validate([
                    'race_id' => 'required|exists:race_results,id',
                    'status' => 'nullable|in:SCHEDULED,IN_PROGRESS,FINISHED,CANCELLED',
                    'lanes' => 'nullable|array',
                    'lanes.*.team' => 'nullable|string',
                    'lanes.*.time' => 'nullable|string'
                ]);
            }

            $raceId = $request->input('race_id');
            $raceStatus = $request->input('status');
            $lanes = $request->input('lanes', []);

            // Get the race result
            $raceResult = RaceResult::with(['discipline'])->find($raceId);
            if (!$raceResult) {
                return $this->sendError('Race not found', [], 404);
            }

            \DB::beginTransaction();

            try {
                $updateData = [];

                // Update race status if provided
                if ($raceStatus) {
                    $updateData['status'] = $raceStatus;
                }

                // Handle image uploads
                if ($request->hasFile('images')) {
                    $uploadedImages = $this->handleImageUploads($request->file('images'), $raceId);

                    // Get existing images and merge with new ones
                    $existingImages = $raceResult->images ?? [];
                    $allImages = array_merge($existingImages, $uploadedImages);
                    $updateData['images'] = $allImages;

                    \Log::info('🖼️ Images processed for race', [
                        'race_id' => $raceId,
                        'existing_count' => count($existingImages),
                        'new_count' => count($uploadedImages),
                        'total_count' => count($allImages)
                    ]);
                }

                // Update race result if there's data to update
                if (!empty($updateData)) {
                    $raceResult->update($updateData);
                }

                // Update crew assignments/results if provided
                if (!empty($lanes)) {
                    $this->updateCrewAssignments($raceResult, $lanes);
                }

                \DB::commit();

                // Reload with relationships for response
                $raceResult->load(['discipline', 'crewResults.crew.team']);

                // Prepare crew results for response
                $crewResults = $raceResult->crewResults->map(function($crewResult) {
                    return [
                        'id' => $crewResult->id,
                        'crew_id' => $crewResult->crew_id,
                        'crew_name' => $crewResult->crew->team->name ?? 'Unknown Team',
                        'lane' => $crewResult->lane,
                        'time_ms' => $crewResult->time_ms,
                        'formatted_time' => $crewResult->time_ms ? CrewResult::formatTimeFromMs($crewResult->time_ms) : null,
                        'status' => $crewResult->status,
                        'position' => $crewResult->position,
                        'updated_at' => $crewResult->updated_at
                    ];
                })->sortBy('lane')->values();

                \Log::info('🏁 updateSingleRaceById completed successfully', [
                    'race_id' => $raceId,
                    'updated_crews' => $crewResults->count(),
                    'race_status' => $raceResult->status,
                    'images_count' => count($raceResult->images ?? [])
                ]);

                return $this->sendResponse([
                    'race_id' => $raceId,
                    'race_number' => $raceResult->race_number,
                    'race_title' => $raceResult->title,
                    'stage' => $raceResult->stage,
                    'status' => $raceResult->status,
                    'discipline_info' => "{$raceResult->discipline->boat_group}, {$raceResult->discipline->age_group}, {$raceResult->discipline->gender_group} {$raceResult->discipline->distance}",
                    'crew_results' => $crewResults,
                    'total_crews' => $crewResults->count(),
                    'images' => $raceResult->images ?? [],
                    'images_count' => count($raceResult->images ?? [])
                ], 'Single race updated successfully');

            } catch (\Exception $e) {
                \DB::rollBack();
                throw $e;
            }

        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->sendError('Validation error', $e->errors(), 422);
        } catch (\Exception $e) {
            \Log::error('❌ Error in updateSingleRaceById', [
                'error' => $e->getMessage(),
                'race_id' => $request->input('race_id'),
                'timestamp' => now()->toDateTimeString()
            ]);

            return $this->sendError('Error updating single race', [$e->getMessage()], 500);
        }
    }

    /**
     * Handle multiple image uploads for a race.
     * Saves images to public/racephotos directory with unique names.
     *
     * @param array $images Array of uploaded files
     * @param int $raceId The race ID for filename prefix
     * @return array Array of saved image filenames
     */
    private function handleImageUploads($images, $raceId)
    {
        $savedImages = [];

        foreach ($images as $image) {
            if ($image && $image->isValid()) {
                // Generate unique filename: race_{raceId}_{timestamp}_{originalname}
                $timestamp = now()->format('YmdHis');
                $originalName = pathinfo($image->getClientOriginalName(), PATHINFO_FILENAME);
                $extension = $image->getClientOriginalExtension();
                $filename = "race_{$raceId}_{$timestamp}_{$originalName}.{$extension}";

                // Save to public/racephotos directory
                $path = $image->move(public_path('racephotos'), $filename);

                if ($path) {
                    $savedImages[] = $filename;

                    \Log::info('🖼️ Image uploaded successfully', [
                        'race_id' => $raceId,
                        'filename' => $filename,
                        'original_name' => $image->getClientOriginalName(),
                        'size' => $image->getSize()
                    ]);
                } else {
                    \Log::error('❌ Failed to save image', [
                        'race_id' => $raceId,
                        'original_name' => $image->getClientOriginalName()
                    ]);
                }
            }
        }

        return $savedImages;
    }

    /**
     * Format discipline fields by adding spaces between words.
     * Converts "SmallPremier" to "Small Premier", "BigBeat" to "Big Beat", etc.
     *
     * @param string $field
     * @return string
     */
    private function formatDisciplineField($field)
    {
        if (!$field) {
            return $field;
        }

        // Add space before uppercase letters that follow lowercase letters
        // "SmallPremier" -> "Small Premier"
        // "BigBoat" -> "Big Boat"
        return preg_replace('/([a-z])([A-Z])/', '$1 $2', $field);
    }
}