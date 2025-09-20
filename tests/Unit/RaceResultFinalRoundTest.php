<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\RaceResult;
use App\Models\Discipline;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

class RaceResultFinalRoundTest extends TestCase
{
    use RefreshDatabase;

    protected $event;
    protected $discipline1;
    protected $discipline2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test event and disciplines
        $this->event = Event::factory()->create(['name' => 'Test Event']);
        $this->discipline1 = Discipline::factory()->create(['event_id' => $this->event->id]);
        $this->discipline2 = Discipline::factory()->create(['event_id' => $this->event->id]);
    }

    /** @test */
    public function exact_final_stage_names_are_detected_as_final()
    {
        // Test "Final"
        $finalRace = RaceResult::factory()->create([
            'race_number' => 5,
            'stage' => 'Final',
            'discipline_id' => $this->discipline1->id
        ]);

        $this->assertTrue($finalRace->isFinalRound(), 'Race with stage "Final" should be detected as final round');

        // Test "Grand Final"
        $grandFinalRace = RaceResult::factory()->create([
            'race_number' => 3,
            'stage' => 'Grand Final',
            'discipline_id' => $this->discipline2->id
        ]);

        $this->assertTrue($grandFinalRace->isFinalRound(), 'Race with stage "Grand Final" should be detected as final round');
    }

    /** @test */
    public function minor_final_is_not_detected_as_final()
    {
        $minorFinalRace = RaceResult::factory()->create([
            'race_number' => 9,
            'stage' => 'Minor Final',
            'discipline_id' => $this->discipline1->id
        ]);

        $this->assertFalse($minorFinalRace->isFinalRound(), 'Race with stage "Minor Final" should NOT be detected as final round');
    }

    /** @test */
    public function excluded_stages_are_not_final()
    {
        $excludedStages = [
            'Minor Final',
            'Semi Final',
            'Semifinal',
            'Quarter Final',
            'Quarterfinal',
            'Consolation Final'
        ];

        foreach ($excludedStages as $stage) {
            $race = RaceResult::factory()->create([
                'race_number' => 10, // Make it highest race number
                'stage' => $stage,
                'discipline_id' => $this->discipline1->id
            ]);

            $this->assertFalse(
                $race->isFinalRound(),
                "Race with stage '{$stage}' should NOT be detected as final round even if it has highest race number"
            );

            // Clean up for next iteration
            $race->delete();
        }
    }

    /** @test */
    public function heat_1_is_not_final_when_there_are_higher_race_numbers()
    {
        // Create Heat 1
        $heat1 = RaceResult::factory()->create([
            'race_number' => 3,
            'stage' => 'Heat 1',
            'discipline_id' => $this->discipline1->id
        ]);

        // Create a race with higher number
        $finalRace = RaceResult::factory()->create([
            'race_number' => 5,
            'stage' => 'Final',
            'discipline_id' => $this->discipline1->id
        ]);

        $this->assertFalse($heat1->isFinalRound(), 'Heat 1 should NOT be final when there are races with higher numbers');
        $this->assertTrue($finalRace->isFinalRound(), 'Race with stage "Final" should be detected as final');
    }

    /** @test */
    public function single_heat_is_final()
    {
        $singleHeat = RaceResult::factory()->create([
            'race_number' => 1,
            'stage' => 'Heat 1',
            'discipline_id' => $this->discipline1->id
        ]);

        $this->assertTrue($singleHeat->isFinalRound(), 'Single heat should be considered final when it\'s the only race');
    }

    /** @test */
    public function highest_race_number_is_final_if_not_excluded()
    {
        // Create multiple races
        RaceResult::factory()->create([
            'race_number' => 1,
            'stage' => 'Heat 1',
            'discipline_id' => $this->discipline1->id
        ]);

        RaceResult::factory()->create([
            'race_number' => 2,
            'stage' => 'Heat 2',
            'discipline_id' => $this->discipline1->id
        ]);

        $highestRace = RaceResult::factory()->create([
            'race_number' => 3,
            'stage' => 'Heat 3',
            'discipline_id' => $this->discipline1->id
        ]);

        $this->assertTrue($highestRace->isFinalRound(), 'Race with highest race number should be final if not excluded');
    }

    /** @test */
    public function races_in_different_disciplines_are_independent()
    {
        // Discipline 1: Heat 1 with race_number 3
        $disc1Heat1 = RaceResult::factory()->create([
            'race_number' => 3,
            'stage' => 'Heat 1',
            'discipline_id' => $this->discipline1->id
        ]);

        // Discipline 1: Final with race_number 5
        $disc1Final = RaceResult::factory()->create([
            'race_number' => 5,
            'stage' => 'Final',
            'discipline_id' => $this->discipline1->id
        ]);

        // Discipline 2: Heat 1 with race_number 9 (highest in its discipline)
        $disc2Heat1 = RaceResult::factory()->create([
            'race_number' => 9,
            'stage' => 'Heat 1',
            'discipline_id' => $this->discipline2->id
        ]);

        $this->assertFalse($disc1Heat1->isFinalRound(), 'Discipline 1 Heat 1 should not be final');
        $this->assertTrue($disc1Final->isFinalRound(), 'Discipline 1 Final should be final');
        $this->assertTrue($disc2Heat1->isFinalRound(), 'Discipline 2 Heat 1 should be final (highest in its discipline)');
    }

    /** @test */
    public function complex_scenario_matches_requirements()
    {
        // Create the exact scenario from the issue
        $heat1 = RaceResult::factory()->create([
            'race_number' => 3,
            'stage' => 'Heat 1',
            'discipline_id' => $this->discipline1->id
        ]);

        $final = RaceResult::factory()->create([
            'race_number' => 5,
            'stage' => 'Final',
            'discipline_id' => $this->discipline1->id
        ]);

        $minorFinal = RaceResult::factory()->create([
            'race_number' => 9,
            'stage' => 'Minor Final',
            'discipline_id' => $this->discipline2->id
        ]);

        // Verify expected behavior
        $this->assertFalse($heat1->isFinalRound(), 'Race #3 "Heat 1" should NOT be final');
        $this->assertTrue($final->isFinalRound(), 'Race #5 "Final" should be final');
        $this->assertFalse($minorFinal->isFinalRound(), 'Race #9 "Minor Final" should NOT be final');
    }
}