<?php

namespace Tests\Feature;

use App\Models\RaceResult;
use App\Models\CrewResult;
use App\Models\Crew;
use App\Models\Team;
use App\Models\Discipline;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PositionCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_positions_are_calculated_based_on_time()
    {
        // Create test data
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        $raceResult = RaceResult::create([
            'race_number' => 1,
            'discipline_id' => $discipline->id,
            'stage' => 'Final',
            'status' => 'FINISHED'
        ]);

        // Create teams and crews
        $team1 = Team::create(['name' => 'Team Alpha']);
        $team2 = Team::create(['name' => 'Team Beta']);
        $team3 = Team::create(['name' => 'Team Gamma']);

        $crew1 = Crew::create(['team_id' => $team1->id, 'discipline_id' => $discipline->id]);
        $crew2 = Crew::create(['team_id' => $team2->id, 'discipline_id' => $discipline->id]);
        $crew3 = Crew::create(['team_id' => $team3->id, 'discipline_id' => $discipline->id]);

        // Create crew results with different times (in milliseconds)
        // Team Beta should be 1st (120500ms = 2:00.500)
        // Team Alpha should be 2nd (121000ms = 2:01.000)
        // Team Gamma should be 3rd (122500ms = 2:02.500)
        CrewResult::create([
            'crew_id' => $crew1->id,
            'race_result_id' => $raceResult->id,
            'time_ms' => 121000, // 2:01.000
            'status' => 'FINISHED'
        ]);

        CrewResult::create([
            'crew_id' => $crew2->id,
            'race_result_id' => $raceResult->id,
            'time_ms' => 120500, // 2:00.500 (fastest)
            'status' => 'FINISHED'
        ]);

        CrewResult::create([
            'crew_id' => $crew3->id,
            'race_result_id' => $raceResult->id,
            'time_ms' => 122500, // 2:02.500 (slowest)
            'status' => 'FINISHED'
        ]);

        // Test the API endpoint that should trigger position calculation
        $response = $this->postJson("/api/race-results/{$raceResult->id}/recalculate-positions");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Positions recalculated successfully'
        ]);

        // Verify positions are correctly assigned
        $this->assertDatabaseHas('crew_results', [
            'crew_id' => $crew2->id, // Team Beta (fastest time)
            'position' => 1
        ]);

        $this->assertDatabaseHas('crew_results', [
            'crew_id' => $crew1->id, // Team Alpha (middle time)
            'position' => 2
        ]);

        $this->assertDatabaseHas('crew_results', [
            'crew_id' => $crew3->id, // Team Gamma (slowest time)
            'position' => 3
        ]);
    }

    public function test_dns_dnf_dsq_crews_get_no_position()
    {
        // Create test data
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        $raceResult = RaceResult::create([
            'race_number' => 1,
            'discipline_id' => $discipline->id,
            'stage' => 'Final',
            'status' => 'FINISHED'
        ]);

        // Create teams and crews
        $team1 = Team::create(['name' => 'Team Finished']);
        $team2 = Team::create(['name' => 'Team DNS']);
        $team3 = Team::create(['name' => 'Team DNF']);

        $crew1 = Crew::create(['team_id' => $team1->id, 'discipline_id' => $discipline->id]);
        $crew2 = Crew::create(['team_id' => $team2->id, 'discipline_id' => $discipline->id]);
        $crew3 = Crew::create(['team_id' => $team3->id, 'discipline_id' => $discipline->id]);

        // Create crew results with different statuses
        CrewResult::create([
            'crew_id' => $crew1->id,
            'race_result_id' => $raceResult->id,
            'time_ms' => 121000,
            'status' => 'FINISHED'
        ]);

        CrewResult::create([
            'crew_id' => $crew2->id,
            'race_result_id' => $raceResult->id,
            'time_ms' => null,
            'status' => 'DNS'
        ]);

        CrewResult::create([
            'crew_id' => $crew3->id,
            'race_result_id' => $raceResult->id,
            'time_ms' => null,
            'status' => 'DNF'
        ]);

        // Trigger position calculation
        $response = $this->postJson("/api/race-results/{$raceResult->id}/recalculate-positions");

        $response->assertStatus(200);

        // Verify only finished crew gets a position
        $this->assertDatabaseHas('crew_results', [
            'crew_id' => $crew1->id,
            'position' => 1
        ]);

        $this->assertDatabaseHas('crew_results', [
            'crew_id' => $crew2->id,
            'position' => null
        ]);

        $this->assertDatabaseHas('crew_results', [
            'crew_id' => $crew3->id,
            'position' => null
        ]);
    }
}