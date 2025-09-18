<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\Event;
use App\Models\Discipline;
use App\Models\RaceResult;
use App\Models\Team;
use App\Models\Crew;
use App\Models\CrewResult;

class PublicRaceResultsTest extends TestCase
{
    use RefreshDatabase;

    private $event;
    private $discipline;
    private $raceResult;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->event = Event::factory()->create([
            'name' => 'Test EuroCup Event',
            'location' => 'Test Location',
            'year' => 2024,
            'status' => 'ACTIVE'
        ]);

        $this->discipline = Discipline::factory()->create([
            'event_id' => $this->event->id,
            'distance' => 500,
            'age_group' => 'Senior',
            'gender_group' => 'Open',
            'boat_group' => 'Small boat',
            'status' => 'SCHEDULED'
        ]);

        $this->raceResult = RaceResult::factory()->create([
            'race_number' => 1,
            'discipline_id' => $this->discipline->id,
            'stage' => 'Final',
            'status' => 'FINISHED'
        ]);

        // Create some test teams and crews
        $team1 = Team::factory()->create(['name' => 'Team Alpha']);
        $team2 = Team::factory()->create(['name' => 'Team Beta']);

        $crew1 = Crew::factory()->create([
            'team_id' => $team1->id,
            'discipline_id' => $this->discipline->id
        ]);

        $crew2 = Crew::factory()->create([
            'team_id' => $team2->id,
            'discipline_id' => $this->discipline->id
        ]);

        // Create crew results
        CrewResult::factory()->create([
            'race_result_id' => $this->raceResult->id,
            'crew_id' => $crew1->id,
            'position' => 1,
            'time_ms' => 120000, // 2:00.000
            'status' => 'FINISHED'
        ]);

        CrewResult::factory()->create([
            'race_result_id' => $this->raceResult->id,
            'crew_id' => $crew2->id,
            'position' => 2,
            'time_ms' => 125000, // 2:05.000
            'status' => 'FINISHED'
        ]);
    }

    /** @test */
    public function test_public_race_results_endpoint_requires_event_id()
    {
        $response = $this->getJson('/api/public/race-results');

        $response->assertStatus(422)
                 ->assertJson([
                     'success' => false,
                     'message' => 'Event ID is required'
                 ]);
    }

    /** @test */
    public function test_public_race_results_endpoint_returns_race_results()
    {
        $response = $this->getJson('/api/public/race-results?event_id=' . $this->event->id);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'message' => 'Race results retrieved successfully'
                 ])
                 ->assertJsonStructure([
                     'success',
                     'data' => [
                         '*' => [
                             'id',
                             'race_number',
                             'title',
                             'stage',
                             'status',
                             'discipline',
                             'crewResults'
                         ]
                     ],
                     'message'
                 ]);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($this->raceResult->id, $data[0]['id']);
        $this->assertEquals('Final', $data[0]['stage']);
    }

    /** @test */
    public function test_public_race_result_detail_endpoint_returns_specific_result()
    {
        $response = $this->getJson('/api/public/race-results/' . $this->raceResult->id);

        $response->assertStatus(200)
                 ->assertJson([
                     'success' => true,
                     'message' => 'Race result retrieved successfully'
                 ])
                 ->assertJsonStructure([
                     'success',
                     'data' => [
                         'id',
                         'race_number',
                         'title',
                         'stage',
                         'status',
                         'discipline',
                         'crew_results'
                     ],
                     'message'
                 ]);

        $data = $response->json('data');
        $this->assertEquals($this->raceResult->id, $data['id']);
        $this->assertEquals('Final', $data['stage']);
        $this->assertCount(2, $data['crew_results']);
    }

    /** @test */
    public function test_public_race_result_detail_returns_404_for_invalid_id()
    {
        $response = $this->getJson('/api/public/race-results/99999');

        $response->assertStatus(404)
                 ->assertJson([
                     'success' => false,
                     'message' => 'Race result not found'
                 ]);
    }

    /** @test */
    public function test_public_endpoints_include_cors_headers()
    {
        $response = $this->getJson('/api/public/race-results?event_id=' . $this->event->id);

        $response->assertHeader('Access-Control-Allow-Origin', '*')
                 ->assertHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
                 ->assertHeader('Access-Control-Allow-Headers', 'Content-Type, Accept');
    }

    /** @test */
    public function test_cors_options_request_works()
    {
        $response = $this->json('OPTIONS', '/api/public/race-results');

        $response->assertStatus(200)
                 ->assertHeader('Access-Control-Allow-Origin', '*')
                 ->assertHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
                 ->assertHeader('Access-Control-Allow-Headers', 'Content-Type, Accept');
    }
}