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

class MultiRoundRaceResultTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that single round races are correctly identified as final rounds.
     */
    public function test_single_round_is_final_round()
    {
        // Create test data
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);

        // Create a single race
        $raceResult = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Finals',
            'status' => 'FINISHED'
        ]);

        $this->assertTrue($raceResult->isFinalRound());
    }

    /**
     * Test that multi-round races correctly identify the final round.
     */
    public function test_multi_round_final_round_detection()
    {
        // Create test data
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);

        // Create multiple rounds
        $round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 1',
            'status' => 'FINISHED'
        ]);

        $round2 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 2',
            'status' => 'FINISHED'
        ]);

        $round3 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 3',
            'status' => 'IN_PROGRESS'
        ]);

        // Only the last round should be considered final
        $this->assertFalse($round1->isFinalRound());
        $this->assertFalse($round2->isFinalRound());
        $this->assertTrue($round3->isFinalRound());
    }

    /**
     * Test final time calculation across multiple rounds.
     */
    public function test_final_time_calculation_across_rounds()
    {
        // Create test data
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        $team = Team::factory()->create();
        $crew = Crew::factory()->create([
            'team_id' => $team->id,
            'discipline_id' => $discipline->id
        ]);

        // Create multiple rounds
        $round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 1',
            'status' => 'FINISHED'
        ]);

        $round2 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 2',
            'status' => 'FINISHED'
        ]);

        $round3 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 3',
            'status' => 'IN_PROGRESS'
        ]);

        // Add crew results for each round
        CrewResult::factory()->create([
            'crew_id' => $crew->id,
            'race_result_id' => $round1->id,
            'time_ms' => 120000, // 2:00.000
            'status' => 'FINISHED'
        ]);

        CrewResult::factory()->create([
            'crew_id' => $crew->id,
            'race_result_id' => $round2->id,
            'time_ms' => 125000, // 2:05.000
            'status' => 'FINISHED'
        ]);

        CrewResult::factory()->create([
            'crew_id' => $crew->id,
            'race_result_id' => $round3->id,
            'time_ms' => 118000, // 1:58.000
            'status' => 'FINISHED'
        ]);

        // Test final time calculation
        $finalTimes = $round3->getFinalTimesForDiscipline();
        $this->assertTrue($finalTimes->has($crew->id));

        $crewFinalTime = $finalTimes->get($crew->id);
        $this->assertEquals(363000, $crewFinalTime['final_time_ms']); // Sum: 6:03.000
        $this->assertEquals('FINISHED', $crewFinalTime['final_status']);
    }

    /**
     * Test DSQ handling in multi-round races.
     */
    public function test_dsq_handling_in_multi_round_races()
    {
        // Create test data
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        $team = Team::factory()->create();
        $crew = Crew::factory()->create([
            'team_id' => $team->id,
            'discipline_id' => $discipline->id
        ]);

        // Create multiple rounds
        $round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 1',
            'status' => 'FINISHED'
        ]);

        $round2 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 2',
            'status' => 'FINISHED'
        ]);

        // Crew finishes Round 1 but gets DSQ in Round 2
        CrewResult::factory()->create([
            'crew_id' => $crew->id,
            'race_result_id' => $round1->id,
            'time_ms' => 120000, // 2:00.000
            'status' => 'FINISHED'
        ]);

        CrewResult::factory()->create([
            'crew_id' => $crew->id,
            'race_result_id' => $round2->id,
            'time_ms' => null,
            'status' => 'DSQ'
        ]);

        // Test final time calculation - should be DSQ
        $finalTimes = $round2->getFinalTimesForDiscipline();
        $this->assertTrue($finalTimes->has($crew->id));

        $crewFinalTime = $finalTimes->get($crew->id);
        $this->assertNull($crewFinalTime['final_time_ms']);
        $this->assertEquals('DSQ', $crewFinalTime['final_status']);
    }

    /**
     * Test API response for final round includes all multi-round data.
     */
    public function test_api_response_includes_multi_round_data()
    {
        // Create test data
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        $team1 = Team::factory()->create(['name' => 'Team A']);
        $team2 = Team::factory()->create(['name' => 'Team B']);

        $crew1 = Crew::factory()->create([
            'team_id' => $team1->id,
            'discipline_id' => $discipline->id
        ]);

        $crew2 = Crew::factory()->create([
            'team_id' => $team2->id,
            'discipline_id' => $discipline->id
        ]);

        // Create three rounds
        $round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 1',
            'status' => 'FINISHED'
        ]);

        $round2 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 2',
            'status' => 'FINISHED'
        ]);

        $round3 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 3',
            'status' => 'FINISHED'
        ]);

        // Add results for all rounds
        // Team A: Round 1: 2:00, Round 2: 2:05, Round 3: 1:58 = Total: 6:03
        CrewResult::factory()->create([
            'crew_id' => $crew1->id,
            'race_result_id' => $round1->id,
            'time_ms' => 120000,
            'status' => 'FINISHED'
        ]);

        CrewResult::factory()->create([
            'crew_id' => $crew1->id,
            'race_result_id' => $round2->id,
            'time_ms' => 125000,
            'status' => 'FINISHED'
        ]);

        CrewResult::factory()->create([
            'crew_id' => $crew1->id,
            'race_result_id' => $round3->id,
            'time_ms' => 118000,
            'status' => 'FINISHED'
        ]);

        // Team B: Round 1: 2:10, Round 2: 2:00, Round 3: 2:02 = Total: 6:12
        CrewResult::factory()->create([
            'crew_id' => $crew2->id,
            'race_result_id' => $round1->id,
            'time_ms' => 130000,
            'status' => 'FINISHED'
        ]);

        CrewResult::factory()->create([
            'crew_id' => $crew2->id,
            'race_result_id' => $round2->id,
            'time_ms' => 120000,
            'status' => 'FINISHED'
        ]);

        CrewResult::factory()->create([
            'crew_id' => $crew2->id,
            'race_result_id' => $round3->id,
            'time_ms' => 122000,
            'status' => 'FINISHED'
        ]);

        // Test the final round API response
        $response = $this->getJson("/api/race-results/{$round3->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Race result retrieved successfully'
            ]);

        $data = $response->json('data');

        // Check that it's marked as final round
        $this->assertTrue($data['is_final_round']);

        // Check crew results
        $crewResults = collect($data['crew_results']);

        // Team A should have the better final time and be first
        $teamAResult = $crewResults->firstWhere('crew.team.name', 'Team A');
        $this->assertNotNull($teamAResult);
        $this->assertEquals(363000, $teamAResult['final_time_ms']); // 6:03.000
        $this->assertEquals('06:03.000', $teamAResult['formatted_final_time']);
        $this->assertEquals(1, $teamAResult['final_position']);
        $this->assertEquals('FINISHED', $teamAResult['final_status']);

        // Team B should be second
        $teamBResult = $crewResults->firstWhere('crew.team.name', 'Team B');
        $this->assertNotNull($teamBResult);
        $this->assertEquals(372000, $teamBResult['final_time_ms']); // 6:12.000
        $this->assertEquals('06:12.000', $teamBResult['formatted_final_time']);
        $this->assertEquals(2, $teamBResult['final_position']);
        $this->assertEquals('FINISHED', $teamBResult['final_status']);
    }

    /**
     * Test that non-final rounds don't include final time data.
     */
    public function test_non_final_rounds_dont_include_final_data()
    {
        // Create test data
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        $team = Team::factory()->create();
        $crew = Crew::factory()->create([
            'team_id' => $team->id,
            'discipline_id' => $discipline->id
        ]);

        // Create multiple rounds
        $round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 1',
            'status' => 'FINISHED'
        ]);

        $round2 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 2',
            'status' => 'SCHEDULED'
        ]);

        // Add result for first round
        CrewResult::factory()->create([
            'crew_id' => $crew->id,
            'race_result_id' => $round1->id,
            'time_ms' => 120000,
            'status' => 'FINISHED'
        ]);

        // Test the first round API response
        $response = $this->getJson("/api/race-results/{$round1->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        // Check that it's NOT marked as final round
        $this->assertFalse($data['is_final_round']);

        // Check that crew results don't include final time data
        $crewResults = collect($data['crew_results']);
        $crewResult = $crewResults->first();

        $this->assertNull($crewResult['final_time_ms'] ?? null);
        $this->assertNull($crewResult['final_position'] ?? null);
        $this->assertNull($crewResult['formatted_final_time'] ?? null);
    }

    /**
     * Test crew results endpoint for multi-round races.
     */
    public function test_crew_results_endpoint_multi_round()
    {
        // Create test data
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        $team = Team::factory()->create();
        $crew = Crew::factory()->create([
            'team_id' => $team->id,
            'discipline_id' => $discipline->id
        ]);

        // Create two rounds
        $round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 1',
            'status' => 'FINISHED'
        ]);

        $round2 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 2',
            'status' => 'FINISHED'
        ]);

        // Add crew results
        CrewResult::factory()->create([
            'crew_id' => $crew->id,
            'race_result_id' => $round1->id,
            'time_ms' => 120000,
            'status' => 'FINISHED'
        ]);

        CrewResult::factory()->create([
            'crew_id' => $crew->id,
            'race_result_id' => $round2->id,
            'time_ms' => 125000,
            'status' => 'FINISHED'
        ]);

        // Test the crew results endpoint for final round
        $response = $this->getJson("/api/race-results/{$round2->id}/crew-results");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'is_final_round' => true,
                'message' => 'Crew results retrieved successfully'
            ]);

        $crewResults = $response->json('data');
        $this->assertCount(1, $crewResults);

        $crewResult = $crewResults[0];
        $this->assertEquals(245000, $crewResult['final_time_ms']); // 4:05.000
        $this->assertEquals('04:05.000', $crewResult['formatted_final_time']);
        $this->assertEquals(1, $crewResult['final_position']);
    }
}