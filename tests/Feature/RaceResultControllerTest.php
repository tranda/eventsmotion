<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\RaceResult;
use App\Models\CrewResult;
use App\Models\Crew;
use App\Models\Team;
use App\Models\Discipline;
use App\Models\Event;

class RaceResultControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the race results API returns all races regardless of status.
     */
    public function test_index_returns_all_races_for_event()
    {
        // Create test data
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        
        // Create races with different statuses
        $scheduledRace = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'status' => 'SCHEDULED',
            'race_number' => 1
        ]);
        
        $finishedRace = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'status' => 'FINISHED',
            'race_number' => 2
        ]);

        $response = $this->getJson("/api/race-results?event_id={$event->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Race results retrieved successfully'
            ])
            ->assertJsonCount(2, 'data');
    }

    /**
     * Test that the race result detail shows all registered crews.
     */
    public function test_show_returns_all_registered_crews()
    {
        // Create test data
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        
        // Create teams and crews
        $team1 = Team::factory()->create();
        $team2 = Team::factory()->create();
        
        $crew1 = Crew::factory()->create([
            'team_id' => $team1->id,
            'discipline_id' => $discipline->id
        ]);
        
        $crew2 = Crew::factory()->create([
            'team_id' => $team2->id,
            'discipline_id' => $discipline->id
        ]);

        // Create a race
        $raceResult = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'status' => 'SCHEDULED',
            'race_number' => 1
        ]);

        // Only create result for one crew
        CrewResult::factory()->create([
            'crew_id' => $crew1->id,
            'race_result_id' => $raceResult->id,
            'position' => 1,
            'time' => '2:30.45',
            'status' => 'FINISHED'
        ]);

        // The second crew has no results yet

        $response = $this->getJson("/api/race-results/{$raceResult->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Race result retrieved successfully'
            ]);

        $data = $response->json('data');
        
        // Should have both crews in crew_results
        $this->assertCount(2, $data['crew_results']);
        
        // First crew should have results
        $crewResult1 = collect($data['crew_results'])->firstWhere('crew_id', $crew1->id);
        $this->assertNotNull($crewResult1);
        $this->assertEquals(1, $crewResult1['position']);
        $this->assertEquals('2:30.45', $crewResult1['time']);
        $this->assertEquals('FINISHED', $crewResult1['status']);
        
        // Second crew should have null results
        $crewResult2 = collect($data['crew_results'])->firstWhere('crew_id', $crew2->id);
        $this->assertNotNull($crewResult2);
        $this->assertNull($crewResult2['position']);
        $this->assertNull($crewResult2['time']);
        $this->assertNull($crewResult2['status']);
    }

    /**
     * Test the crew results endpoint returns all registered crews.
     */
    public function test_get_crew_results_returns_all_registered_crews()
    {
        // Create test data similar to test_show_returns_all_registered_crews
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        
        $team1 = Team::factory()->create();
        $team2 = Team::factory()->create();
        
        $crew1 = Crew::factory()->create([
            'team_id' => $team1->id,
            'discipline_id' => $discipline->id
        ]);
        
        $crew2 = Crew::factory()->create([
            'team_id' => $team2->id,
            'discipline_id' => $discipline->id
        ]);

        $raceResult = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'status' => 'IN_PROGRESS',
            'race_number' => 1
        ]);

        // Only one crew has results
        CrewResult::factory()->create([
            'crew_id' => $crew1->id,
            'race_result_id' => $raceResult->id,
            'position' => 1,
            'time' => '2:45.12',
            'status' => 'FINISHED'
        ]);

        $response = $this->getJson("/api/race-results/{$raceResult->id}/crew-results");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Crew results retrieved successfully'
            ])
            ->assertJsonCount(2, 'data');

        $data = $response->json('data');
        
        // Verify crew with results
        $crewWithResults = collect($data)->firstWhere('crew_id', $crew1->id);
        $this->assertNotNull($crewWithResults);
        $this->assertEquals(1, $crewWithResults['position']);
        
        // Verify crew without results
        $crewWithoutResults = collect($data)->firstWhere('crew_id', $crew2->id);
        $this->assertNotNull($crewWithoutResults);
        $this->assertNull($crewWithoutResults['position']);
    }

    /**
     * Test that updating crew results properly clears old values when new data doesn't include them.
     * This tests the fix for the issue where old time values were preserved when updates didn't include them.
     */
    public function test_crew_result_update_clears_old_values()
    {
        // Create test data
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        $team = Team::factory()->create();
        $crew = Crew::factory()->create([
            'team_id' => $team->id,
            'discipline_id' => $discipline->id
        ]);

        $raceResult = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'status' => 'IN_PROGRESS',
            'race_number' => 1
        ]);

        // First: Create crew result with time and position
        $initialCrewResultData = [
            'crew_results' => [
                [
                    'crew_id' => $crew->id,
                    'lane' => 1,
                    'position' => 1,
                    'time_ms' => 150000, // 2:30.000
                    'status' => 'FINISHED'
                ]
            ]
        ];

        $response = $this->postJson("/api/race-results/{$raceResult->id}/crew-results", $initialCrewResultData);
        $response->assertStatus(200);

        // Verify initial data is saved
        $crewResult = CrewResult::where('crew_id', $crew->id)->where('race_result_id', $raceResult->id)->first();
        $this->assertNotNull($crewResult);
        $this->assertEquals(150000, $crewResult->time_ms);
        $this->assertEquals(1, $crewResult->position);

        // Second: Update crew result WITHOUT time and position (they should be cleared)
        $updateCrewResultData = [
            'crew_results' => [
                [
                    'crew_id' => $crew->id,
                    'lane' => 2, // Change lane
                    'status' => 'FINISHED'
                    // Note: No time_ms, no position - these should be cleared
                ]
            ]
        ];

        $response = $this->postJson("/api/race-results/{$raceResult->id}/crew-results", $updateCrewResultData);
        $response->assertStatus(200);

        // Verify old values are cleared
        $crewResult->refresh();
        $this->assertNull($crewResult->time_ms, 'time_ms should be cleared when not provided in update');
        $this->assertNull($crewResult->position, 'position should be cleared when not provided in update');
        $this->assertEquals(2, $crewResult->lane, 'lane should be updated to new value');
        $this->assertEquals('FINISHED', $crewResult->status);
    }

    /**
     * Test that bulk update properly clears crew time values when not provided.
     * This tests the updateCrewAssignments method fix.
     */
    public function test_bulk_update_clears_crew_times_when_not_provided()
    {
        // Create test data
        $event = Event::factory()->create();
        $team = Team::factory()->create(['name' => 'Test Team']);

        // First: Bulk update with times
        $bulkUpdateWithTimes = [
            'event_id' => $event->id,
            'races' => [
                [
                    'race_number' => 1,
                    'start_time' => '10:00',
                    'delay' => '0:00',
                    'stage' => 'Heat 1',
                    'competition' => 'Test Competition',
                    'discipline_info' => 'Standard, Senior, Open 500m',
                    'boat_size' => 'standard',
                    'lanes' => [
                        1 => [
                            'team' => 'Test Team',
                            'time' => '02:30.123'
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/race-results/bulk-update', $bulkUpdateWithTimes);
        $response->assertStatus(200);

        // Verify time is saved
        $crewResult = CrewResult::whereHas('crew.team', function($query) {
            $query->where('name', 'Test Team');
        })->first();
        $this->assertNotNull($crewResult);
        $this->assertNotNull($crewResult->time_ms, 'Time should be saved when provided');

        // Second: Bulk update without times (should clear the time)
        $bulkUpdateWithoutTimes = [
            'event_id' => $event->id,
            'races' => [
                [
                    'race_number' => 1,
                    'start_time' => '10:00',
                    'delay' => '0:00',
                    'stage' => 'Heat 1',
                    'competition' => 'Test Competition',
                    'discipline_info' => 'Standard, Senior, Open 500m',
                    'boat_size' => 'standard',
                    'lanes' => [
                        1 => [
                            'team' => 'Test Team'
                            // Note: No time provided - should clear existing time
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/race-results/bulk-update', $bulkUpdateWithoutTimes);
        $response->assertStatus(200);

        // Verify time is cleared
        $crewResult->refresh();
        $this->assertNull($crewResult->time_ms, 'Time should be cleared when not provided in bulk update');
        $this->assertEquals(1, $crewResult->lane, 'Lane should remain the same');
    }

    /**
     * Test the new bulk import with cleanup functionality.
     */
    public function test_bulk_import_with_cleanup_removes_orphaned_races()
    {
        // Create test data
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);

        // Create existing races that should be cleaned up
        $orphanedRace1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Heat 1',
            'race_number' => 1,
            'status' => 'SCHEDULED'
        ]);

        $orphanedRace2 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Heat 2',
            'race_number' => 2,
            'status' => 'FINISHED'
        ]);

        // Create crew results for orphaned races
        $team1 = Team::factory()->create(['name' => 'Team 1']);
        $team2 = Team::factory()->create(['name' => 'Team 2']);
        $crew1 = Crew::factory()->create(['team_id' => $team1->id, 'discipline_id' => $discipline->id]);
        $crew2 = Crew::factory()->create(['team_id' => $team2->id, 'discipline_id' => $discipline->id]);

        CrewResult::factory()->create([
            'crew_id' => $crew1->id,
            'race_result_id' => $orphanedRace1->id,
            'position' => 1,
            'time_ms' => 150000,
            'status' => 'FINISHED'
        ]);

        CrewResult::factory()->create([
            'crew_id' => $crew2->id,
            'race_result_id' => $orphanedRace2->id,
            'position' => 1,
            'time_ms' => 160000,
            'status' => 'FINISHED'
        ]);

        // Verify orphaned data exists before cleanup
        $this->assertDatabaseHas('race_results', ['id' => $orphanedRace1->id]);
        $this->assertDatabaseHas('race_results', ['id' => $orphanedRace2->id]);
        $this->assertDatabaseHas('crew_results', ['race_result_id' => $orphanedRace1->id]);
        $this->assertDatabaseHas('crew_results', ['race_result_id' => $orphanedRace2->id]);

        // Import new races (only Heat 3 and Final - Heat 1 and 2 should be cleaned up)
        $importData = [
            'discipline_id' => $discipline->id,
            'perform_cleanup' => true,
            'races' => [
                [
                    'race_number' => 3,
                    'stage' => 'Heat 3',
                    'start_time' => '10:00',
                    'delay' => '0:00',
                    'status' => 'SCHEDULED',
                    'lanes' => [
                        1 => [
                            'team' => 'Team 1',
                            'time' => null
                        ]
                    ]
                ],
                [
                    'race_number' => 4,
                    'stage' => 'Final',
                    'start_time' => '11:00',
                    'delay' => '0:00',
                    'status' => 'SCHEDULED',
                    'lanes' => [
                        1 => [
                            'team' => 'Team 2',
                            'time' => null
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/race-results/bulk-import-with-cleanup', $importData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true
            ]);

        $responseData = $response->json();
        $this->assertArrayHasKey('cleanup_summary', $responseData['data']);
        $this->assertEquals(2, $responseData['data']['cleanup_summary']['deleted_count']);

        // Verify orphaned races and their crew results were deleted
        $this->assertDatabaseMissing('race_results', ['id' => $orphanedRace1->id]);
        $this->assertDatabaseMissing('race_results', ['id' => $orphanedRace2->id]);
        $this->assertDatabaseMissing('crew_results', ['race_result_id' => $orphanedRace1->id]);
        $this->assertDatabaseMissing('crew_results', ['race_result_id' => $orphanedRace2->id]);

        // Verify new races were created
        $this->assertDatabaseHas('race_results', [
            'discipline_id' => $discipline->id,
            'stage' => 'Heat 3',
            'race_number' => 3
        ]);
        $this->assertDatabaseHas('race_results', [
            'discipline_id' => $discipline->id,
            'stage' => 'Final',
            'race_number' => 4
        ]);
    }

    /**
     * Test that cleanup only affects the specified discipline.
     */
    public function test_cleanup_only_affects_specified_discipline()
    {
        // Create test data for two different disciplines
        $event = Event::factory()->create();
        $discipline1 = Discipline::factory()->create(['event_id' => $event->id]);
        $discipline2 = Discipline::factory()->create(['event_id' => $event->id]);

        // Create races for both disciplines
        $race1_discipline1 = RaceResult::factory()->create([
            'discipline_id' => $discipline1->id,
            'stage' => 'Heat 1',
            'race_number' => 1
        ]);

        $race1_discipline2 = RaceResult::factory()->create([
            'discipline_id' => $discipline2->id,
            'stage' => 'Heat 1',
            'race_number' => 1
        ]);

        // Import data for discipline1 only (should not affect discipline2)
        $importData = [
            'discipline_id' => $discipline1->id,
            'perform_cleanup' => true,
            'races' => [
                [
                    'race_number' => 2,
                    'stage' => 'Final',
                    'status' => 'SCHEDULED'
                ]
            ]
        ];

        $response = $this->postJson('/api/race-results/bulk-import-with-cleanup', $importData);

        $response->assertStatus(200);

        // Verify discipline1's old race was cleaned up
        $this->assertDatabaseMissing('race_results', ['id' => $race1_discipline1->id]);

        // Verify discipline2's race was NOT affected
        $this->assertDatabaseHas('race_results', ['id' => $race1_discipline2->id]);

        // Verify new race was created for discipline1
        $this->assertDatabaseHas('race_results', [
            'discipline_id' => $discipline1->id,
            'stage' => 'Final',
            'race_number' => 2
        ]);
    }

    /**
     * Test that cleanup can be disabled.
     */
    public function test_cleanup_can_be_disabled()
    {
        // Create test data
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);

        $existingRace = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Heat 1',
            'race_number' => 1
        ]);

        // Import with cleanup disabled
        $importData = [
            'discipline_id' => $discipline->id,
            'perform_cleanup' => false,
            'races' => [
                [
                    'race_number' => 2,
                    'stage' => 'Final',
                    'status' => 'SCHEDULED'
                ]
            ]
        ];

        $response = $this->postJson('/api/race-results/bulk-import-with-cleanup', $importData);

        $response->assertStatus(200);

        // Verify existing race was NOT deleted
        $this->assertDatabaseHas('race_results', ['id' => $existingRace->id]);

        // Verify new race was created
        $this->assertDatabaseHas('race_results', [
            'discipline_id' => $discipline->id,
            'stage' => 'Final',
            'race_number' => 2
        ]);
    }

    /**
     * Test transaction rollback on import failure.
     */
    public function test_transaction_rollback_on_import_failure()
    {
        // Create test data
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);

        $existingRace = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Heat 1',
            'race_number' => 1
        ]);

        // Import with invalid data that should cause failure
        $importData = [
            'discipline_id' => $discipline->id,
            'perform_cleanup' => true,
            'races' => [
                [
                    'race_number' => 'invalid', // This should cause validation error
                    'stage' => 'Final',
                    'status' => 'SCHEDULED'
                ]
            ]
        ];

        $response = $this->postJson('/api/race-results/bulk-import-with-cleanup', $importData);

        $response->assertStatus(422); // Validation error

        // Verify existing race was NOT deleted (transaction rolled back)
        $this->assertDatabaseHas('race_results', ['id' => $existingRace->id]);
    }

    /**
     * Test the enhanced bulk update method with cleanup functionality.
     */
    public function test_bulk_update_with_cleanup_parameter()
    {
        // Create test data
        $event = Event::factory()->create();

        // Create a discipline that matches what will be created by the bulk update
        $discipline = Discipline::factory()->create([
            'event_id' => $event->id,
            'distance' => 500,
            'age_group' => 'Senior',
            'gender_group' => 'Open',
            'boat_group' => 'standard'
        ]);

        // Create existing races that should be cleaned up
        $orphanedRace1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Heat 1',
            'race_number' => 10,
            'status' => 'SCHEDULED'
        ]);

        $orphanedRace2 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Heat 2',
            'race_number' => 11,
            'status' => 'SCHEDULED'
        ]);

        // Create crew results for orphaned races
        $team1 = Team::factory()->create(['name' => 'Orphaned Team 1']);
        $team2 = Team::factory()->create(['name' => 'Orphaned Team 2']);
        $crew1 = Crew::factory()->create(['team_id' => $team1->id, 'discipline_id' => $discipline->id]);
        $crew2 = Crew::factory()->create(['team_id' => $team2->id, 'discipline_id' => $discipline->id]);

        CrewResult::factory()->create([
            'crew_id' => $crew1->id,
            'race_result_id' => $orphanedRace1->id,
            'lane' => 1,
            'status' => 'FINISHED'
        ]);

        CrewResult::factory()->create([
            'crew_id' => $crew2->id,
            'race_result_id' => $orphanedRace2->id,
            'lane' => 1,
            'status' => 'FINISHED'
        ]);

        // Verify orphaned data exists before cleanup
        $this->assertDatabaseHas('race_results', ['id' => $orphanedRace1->id]);
        $this->assertDatabaseHas('race_results', ['id' => $orphanedRace2->id]);
        $this->assertDatabaseHas('crew_results', ['race_result_id' => $orphanedRace1->id]);
        $this->assertDatabaseHas('crew_results', ['race_result_id' => $orphanedRace2->id]);

        // Bulk update with cleanup enabled (only importing Heat 3 and Final)
        $bulkUpdateData = [
            'event_id' => $event->id,
            'perform_cleanup' => true,
            'races' => [
                [
                    'race_number' => 1,
                    'start_time' => '10:00',
                    'delay' => '0:00',
                    'stage' => 'Heat 3',
                    'competition' => 'Test Competition',
                    'discipline_info' => 'Standard, Senior, Open 500m',
                    'boat_size' => 'standard',
                    'lanes' => [
                        1 => [
                            'team' => 'New Team 1',
                            'time' => null
                        ]
                    ]
                ],
                [
                    'race_number' => 2,
                    'start_time' => '11:00',
                    'delay' => '0:00',
                    'stage' => 'Final',
                    'competition' => 'Test Competition',
                    'discipline_info' => 'Standard, Senior, Open 500m',
                    'boat_size' => 'standard',
                    'lanes' => [
                        1 => [
                            'team' => 'New Team 2',
                            'time' => null
                        ]
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/race-results/bulk-update', $bulkUpdateData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true
            ]);

        $responseData = $response->json();

        // Verify cleanup was performed
        $this->assertArrayHasKey('cleanup_summary', $responseData['data']);
        $this->assertNotEmpty($responseData['data']['cleanup_summary']);

        // Check that orphaned races were deleted
        $this->assertDatabaseMissing('race_results', ['id' => $orphanedRace1->id]);
        $this->assertDatabaseMissing('race_results', ['id' => $orphanedRace2->id]);
        $this->assertDatabaseMissing('crew_results', ['race_result_id' => $orphanedRace1->id]);
        $this->assertDatabaseMissing('crew_results', ['race_result_id' => $orphanedRace2->id]);

        // Verify new races were created
        $this->assertDatabaseHas('race_results', [
            'discipline_id' => $discipline->id,
            'stage' => 'Heat 3',
            'race_number' => 1
        ]);
        $this->assertDatabaseHas('race_results', [
            'discipline_id' => $discipline->id,
            'stage' => 'Final',
            'race_number' => 2
        ]);
    }

    /**
     * Test bulk update without cleanup (default behavior preserved).
     */
    public function test_bulk_update_without_cleanup_preserves_existing_behavior()
    {
        // Create test data
        $event = Event::factory()->create();

        // Create a discipline
        $discipline = Discipline::factory()->create([
            'event_id' => $event->id,
            'distance' => 500,
            'age_group' => 'Senior',
            'gender_group' => 'Open',
            'boat_group' => 'standard'
        ]);

        // Create existing race
        $existingRace = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Heat 1',
            'race_number' => 10,
            'status' => 'SCHEDULED'
        ]);

        // Bulk update without cleanup parameter (should default to false)
        $bulkUpdateData = [
            'event_id' => $event->id,
            // Note: no 'perform_cleanup' parameter - should default to false
            'races' => [
                [
                    'race_number' => 1,
                    'start_time' => '10:00',
                    'delay' => '0:00',
                    'stage' => 'Heat 2',
                    'competition' => 'Test Competition',
                    'discipline_info' => 'Standard, Senior, Open 500m',
                    'boat_size' => 'standard',
                    'lanes' => []
                ]
            ]
        ];

        $response = $this->postJson('/api/race-results/bulk-update', $bulkUpdateData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true
            ]);

        $responseData = $response->json();

        // Verify cleanup was NOT performed
        $this->assertArrayNotHasKey('cleanup_summary', $responseData['data']);

        // Check that existing race was NOT deleted (no cleanup)
        $this->assertDatabaseHas('race_results', ['id' => $existingRace->id]);

        // Verify new race was created
        $this->assertDatabaseHas('race_results', [
            'discipline_id' => $discipline->id,
            'stage' => 'Heat 2',
            'race_number' => 1
        ]);
    }

    /**
     * Test bulk update with cleanup disabled explicitly.
     */
    public function test_bulk_update_with_cleanup_explicitly_disabled()
    {
        // Create test data
        $event = Event::factory()->create();

        // Create a discipline
        $discipline = Discipline::factory()->create([
            'event_id' => $event->id,
            'distance' => 500,
            'age_group' => 'Senior',
            'gender_group' => 'Open',
            'boat_group' => 'standard'
        ]);

        // Create existing race
        $existingRace = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Heat 1',
            'race_number' => 10,
            'status' => 'SCHEDULED'
        ]);

        // Bulk update with cleanup explicitly disabled
        $bulkUpdateData = [
            'event_id' => $event->id,
            'perform_cleanup' => false,
            'races' => [
                [
                    'race_number' => 1,
                    'start_time' => '10:00',
                    'delay' => '0:00',
                    'stage' => 'Heat 2',
                    'competition' => 'Test Competition',
                    'discipline_info' => 'Standard, Senior, Open 500m',
                    'boat_size' => 'standard',
                    'lanes' => []
                ]
            ]
        ];

        $response = $this->postJson('/api/race-results/bulk-update', $bulkUpdateData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true
            ]);

        $responseData = $response->json();

        // Verify cleanup was NOT performed
        $this->assertArrayNotHasKey('cleanup_summary', $responseData['data']);

        // Check that existing race was NOT deleted
        $this->assertDatabaseHas('race_results', ['id' => $existingRace->id]);

        // Verify new race was created
        $this->assertDatabaseHas('race_results', [
            'discipline_id' => $discipline->id,
            'stage' => 'Heat 2',
            'race_number' => 1
        ]);
    }
}