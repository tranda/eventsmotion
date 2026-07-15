<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Discipline;
use App\Models\Event;
use App\Models\RaceResult;
use App\Services\Schedule\ProgressionDescriber;
use Illuminate\Http\Request;

class PublicController extends BaseController
{
    public function __construct(
        private ?ProgressionDescriber $progressionDescriber = null,
    ) {}

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
            // Verify the event exists and its schedule has been published.
            // Drafts are hidden from public/non-admin callers.
            $event = Event::where('schedule_status', 'published')->find($eventId);
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
            ->header('Access-Control-Allow-Headers', 'Content-Type, Accept')
            ->header('Cache-Control', 'public, max-age=30')
;

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
            ->header('Access-Control-Allow-Headers', 'Content-Type, Accept')
            ->header('Cache-Control', 'public, max-age=30')
;

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

            // Drafts stay hidden from the public — schedule_status='published'
            // on the parent event is required. Admins (access_level >= 3) who
            // pass a Sanctum bearer token bypass the filter so they can preview
            // a draft on the live page; anonymous callers cannot.
            $authUser = auth('sanctum')->user();
            $isAdmin = $authUser && ($authUser->access_level ?? 0) >= 3;
            $applyPublishedFilter = fn($q) => $isAdmin ? $q : $q->published();

            // Filter + dedupe crew_results so Race Results sees exactly what
            // the Schedule Builder Grid renders. The Grid iterates lanes 1..N
            // and assigns crewByLane[lane] = cr while walking the list —
            // the LAST crew_result for each lane wins. Mirror that here so
            // duplicate-lane rows (auto-fill / re-seed leaves the old one
            // around when a re-assignment lands on the same lane) collapse
            // to the same single row the Grid shows.
            $crewLaneFilter = fn($q) => $q
                ->whereNotNull('lane')
                ->where('lane', '>', 0)
                ->orderBy('id'); // ascending — the highest-id wins via the dedupe pass below

            try {
                $raceResults = $applyPublishedFilter(
                    RaceResult::with([
                        'discipline',
                        'crewResults' => $crewLaneFilter,
                        'crewResults.crew.team.club',
                    ])->forEvent($eventId)
                )
                    ->orderBy('race_number', 'asc')
                    ->get();
            } catch (\Exception $e) {
                \Log::error("Error loading race results with crews: " . $e->getMessage());
                // Fallback to basic loading
                $raceResults = $applyPublishedFilter(
                    RaceResult::with([
                        'discipline',
                        'crewResults' => $crewLaneFilter,
                        'crewResults.crew',
                    ])->forEvent($eventId)
                )
                    ->orderBy('race_number', 'asc')
                    ->get();
            }

            // Dedupe per lane — same "last-write-wins" rule as the Grid's
            // crewByLane map. Picks the highest-id crew_result per lane and
            // drops the rest (typically older orphans from a re-assignment).
            foreach ($raceResults as $rr) {
                if (!$rr->relationLoaded('crewResults')) continue;
                $deduped = $rr->crewResults
                    ->groupBy('lane')
                    ->map(fn($g) => $g->sortByDesc('id')->first())
                    ->values();
                $rr->setRelation('crewResults', $deduped);
            }

            // Auto-derived "where do these crews go next" line, keyed by
            // race_id. Failures (missing service, bad data, etc.) leave the
            // map empty so every race gets an empty progression_rule but the
            // response itself is unaffected.
            $progressionByRace = [];
            if ($this->progressionDescriber) {
                try {
                    $event = Event::find($eventId);
                    $laneCount = (int) ($event?->lane_count ?? 6);
                    $byDiscipline = $raceResults
                        ->filter(fn($r) => ($r->entry_type ?? 'race') === 'race' && $r->discipline_id)
                        ->groupBy('discipline_id');
                    foreach ($byDiscipline as $disciplineRaces) {
                        try {
                            $discipline = $disciplineRaces->first()->discipline;
                            if (!$discipline) continue;
                            $msgs = $this->progressionDescriber->forDiscipline(
                                $discipline,
                                $disciplineRaces,
                                $laneCount,
                            );
                            foreach ($msgs as $rid => $m) {
                                $progressionByRace[$rid] = $m;
                            }
                        } catch (\Throwable $inner) {
                            \Log::warning('Public progression (discipline) failed: ' . $inner->getMessage());
                        }
                    }
                } catch (\Throwable $e) {
                    \Log::warning('Public progression derivation failed: ' . $e->getMessage());
                }
            }

            // Enhance race results with final round information and crew results
            $enhancedRaceResults = $raceResults->map(function($raceResult) use ($progressionByRace) {
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

                    // Convert to array (admin pattern) so we can attach
                    // progression_rule without touching Eloquent's $appends
                    // machinery. Catching Throwable below covers TypeError /
                    // Error too — previously the inner catch was \Exception,
                    // which let those slip through and crash the response.
                    $arr = $raceResult->toArray();
                    $arr['progression_rule'] = $progressionByRace[$raceResult->id] ?? '';
                    return $arr;
                } catch (\Throwable $e) {
                    \Log::error('Error enhancing race result', [
                        'race_id' => $raceResult->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);

                    // Fall back to the raw model so the response keeps its
                    // shape even when one race's enhancement blows up.
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
            ->header('Access-Control-Allow-Headers', 'Content-Type, Accept')
            ->header('Cache-Control', 'public, max-age=30')
;

        } catch (\Throwable $e) {
            // Catching Throwable (not just Exception) so PHP 7+ Errors
            // — TypeError, undefined method, autoloader failures, etc. —
            // surface as a proper 500 instead of crashing the worker and
            // leaving the frontend with a silent empty response.
            \Log::error('getPublicRaceResults failed', [
                'event_id' => $request->query('event_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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

            // Get crew results for this specific race only (same as list view)
            $crewResults = $raceResult->crewResults()
                ->with(['crew.team', 'crew.discipline'])
                ->get();

            // Check if this is a final round that needs accumulated times
            $isFinalRound = $raceResult->isFinalRound();

            // Get final times if this is a final round
            if ($isFinalRound) {
                $finalTimes = $raceResult->getFinalTimesForDiscipline();

                // Add final time data to crew results
                $crewResults = $crewResults->map(function($crewResult) use ($finalTimes) {
                    if ($finalTimes->has($crewResult->crew_id)) {
                        $finalTimeData = $finalTimes->get($crewResult->crew_id);
                        $crewResult->final_time_ms = $finalTimeData['final_time_ms'];
                        $crewResult->final_status = $finalTimeData['final_status'];
                        $crewResult->is_final_round = true;
                    } else {
                        $crewResult->final_time_ms = null;
                        $crewResult->final_status = null;
                        $crewResult->is_final_round = true;
                    }
                    return $crewResult;
                });
            }

            // Convert race result to array for proper JSON serialization
            $raceResultArray = $raceResult->toArray();

            // Add additional properties
            $raceResultArray['is_final_round'] = $isFinalRound;
            $raceResultArray['show_accumulated_time'] = $raceResult->shouldShowAccumulatedTime();

            // Add properly serialized crew results (handle Eloquent models)
            $raceResultArray['crew_results'] = $crewResults->map(function($crewResult) {
                // crewResults returns Eloquent models, so use toArray()
                return $crewResult->toArray();
            })->values()->toArray();

            return response()->json([
                'success' => true,
                'data' => $raceResultArray,
                'message' => 'Race result retrieved successfully'
            ], 200)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type, Accept')
            ->header('Cache-Control', 'public, max-age=30')
;

        } catch (\Exception $e) {
            return $this->sendError('Error retrieving race result', [$e->getMessage()], 500)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Accept');
        }
    }

    /**
     * Per-club medal standings for an event, grouped by competition.
     *
     * Walks every FINISHED race with isFinalRound=true, takes the top 3
     * finishers ranked by finalTimeMs (round-based: sum across rounds; heat-
     * based: Grand Final time alone — the backend's getFinalTimesForDiscipline
     * already encodes this split), and buckets medals per club, per
     * competition. Same visibility rule as public/race-results: published
     * events are visible to anyone, drafts to admin Bearer-token callers.
     *
     * Response:
     *   { success, data: { event_id, event_name, competitions: [
     *       { name: "Club",
     *         standings: [ { club_id, club_name, country, gold, silver,
     *                        bronze, total }, ... sorted G→S→B→name ] },
     *       ...
     *     ] } }
     *
     * @param int $eventId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPublicMedalStandings($eventId)
    {
        try {
            $authUser = auth('sanctum')->user();
            $isAdmin = $authUser && ($authUser->access_level ?? 0) >= 3;

            $eventQuery = Event::query();
            if (!$isAdmin) {
                $eventQuery->where('schedule_status', 'published');
            }
            $event = $eventQuery->find($eventId);
            if (!$event) {
                return $this->sendError('Event not found', [], 404)
                    ->header('Access-Control-Allow-Origin', '*');
            }

            $applyPublishedFilter = fn($q) => $isAdmin ? $q : $q->published();

            $raceResults = $applyPublishedFilter(
                RaceResult::with(['discipline', 'crewResults.crew.team.club'])
                    ->forEvent($eventId)
            )
                ->where('status', 'FINISHED')
                ->whereNotNull('discipline_id')
                ->get();

            // competition => [ groupKey => [club_id, club_name, country, gold, silver, bronze] ]
            $byCompetition = [];

            // Pre-populate every participating club/team so non-medalists
            // still appear in the tally (with 0/0/0). Walks all disciplines
            // of the event, groups by competition, and picks up the club
            // from each registered crew's team.
            $disciplines = Discipline::with(['crews.team.club'])
                ->where('event_id', $eventId)
                ->get();
            foreach ($disciplines as $disc) {
                $competition = $disc->competition;
                if (!$competition) continue;
                foreach ($disc->crews as $crew) {
                    $team = $crew->team;
                    if (!$team) continue;
                    $club = $team->club;
                    $clubId = $club?->id;
                    $displayName = $club?->name ?? $team->name;
                    if (!$displayName) continue;
                    $groupKey = $clubId !== null ? "club:{$clubId}" : "team:{$displayName}";
                    if (!isset($byCompetition[$competition])) {
                        $byCompetition[$competition] = [];
                    }
                    if (!isset($byCompetition[$competition][$groupKey])) {
                        $byCompetition[$competition][$groupKey] = [
                            'club_id' => $clubId,
                            'club_name' => $displayName,
                            'country' => $club?->country,
                            'gold' => 0,
                            'silver' => 0,
                            'bronze' => 0,
                        ];
                    }
                }
            }

            foreach ($raceResults as $race) {
                if (!$race->isFinalRound()) continue;

                $competition = $race->discipline?->competition;
                if (!$competition) continue;

                // Rank crews by their medal-worthy time. getFinalTimesForDiscipline
                // returns [crew_id => ['final_time_ms' => int, 'final_status' => str]]
                // with the correct semantics per plan type.
                $finalTimes = $race->getFinalTimesForDiscipline();

                $medalists = $race->crewResults
                    ->filter(function ($cr) use ($finalTimes) {
                        $ft = $finalTimes->get($cr->crew_id);
                        return $ft
                            && ($ft['final_status'] ?? null) === 'FINISHED'
                            && ($ft['final_time_ms'] ?? null) !== null;
                    })
                    ->sortBy(function ($cr) use ($finalTimes) {
                        return $finalTimes->get($cr->crew_id)['final_time_ms'];
                    })
                    ->values()
                    ->take(3);

                foreach ($medalists as $i => $cr) {
                    $team = $cr->crew?->team;
                    if (!$team) continue;

                    $club = $team->club;
                    $clubId = $club?->id;
                    $displayName = $club?->name ?? $team->name;
                    if (!$displayName) continue;

                    // Group key: prefer club_id; fall back to name so
                    // team-only records still sum among themselves.
                    $groupKey = $clubId !== null ? "club:{$clubId}" : "team:{$displayName}";

                    if (!isset($byCompetition[$competition])) {
                        $byCompetition[$competition] = [];
                    }
                    if (!isset($byCompetition[$competition][$groupKey])) {
                        $byCompetition[$competition][$groupKey] = [
                            'club_id' => $clubId,
                            'club_name' => $displayName,
                            'country' => $club?->country,
                            'gold' => 0,
                            'silver' => 0,
                            'bronze' => 0,
                        ];
                    }

                    if ($i === 0) $byCompetition[$competition][$groupKey]['gold']++;
                    elseif ($i === 1) $byCompetition[$competition][$groupKey]['silver']++;
                    elseif ($i === 2) $byCompetition[$competition][$groupKey]['bronze']++;
                }
            }

            // Sort competitions alphabetically, standings by G→S→B→name.
            $competitions = [];
            ksort($byCompetition);
            foreach ($byCompetition as $name => $entries) {
                $standings = array_values($entries);
                usort($standings, function ($a, $b) {
                    if ($a['gold'] !== $b['gold']) return $b['gold'] <=> $a['gold'];
                    if ($a['silver'] !== $b['silver']) return $b['silver'] <=> $a['silver'];
                    if ($a['bronze'] !== $b['bronze']) return $b['bronze'] <=> $a['bronze'];
                    return strcasecmp($a['club_name'], $b['club_name']);
                });
                foreach ($standings as &$s) {
                    $s['total'] = $s['gold'] + $s['silver'] + $s['bronze'];
                }
                unset($s);
                $competitions[] = [
                    'name' => $name,
                    'standings' => $standings,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'event_id' => (int) $eventId,
                    'event_name' => $event->name,
                    'competitions' => $competitions,
                ],
                'message' => 'Medal standings retrieved successfully',
            ], 200)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization')
                ->header('Cache-Control', 'public, max-age=30');
        } catch (\Throwable $e) {
            \Log::error('getPublicMedalStandings failed', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->sendError('Error retrieving medal standings', [$e->getMessage()], 500)
                ->header('Access-Control-Allow-Origin', '*');
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