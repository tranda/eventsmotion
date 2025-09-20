<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\RaceResult;
use App\Models\Discipline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

class RaceResultFinalRoundTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_round_is_final_round()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Create a single race result
        $raceResult = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 1'
        ]);

        $this->assertTrue($raceResult->isFinalRound());
    }

    public function test_two_rounds_final_detection()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Create Round 1 first (older)
        $round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 1',
            'created_at' => now()->subHour()
        ]);

        // Create Round 2 second (newer)
        $round2 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 2',
            'created_at' => now()
        ]);

        // Round 1 should NOT be final
        $this->assertFalse($round1->fresh()->isFinalRound());

        // Round 2 should be final
        $this->assertTrue($round2->fresh()->isFinalRound());
    }

    public function test_multiple_rounds_final_detection()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Create multiple rounds
        $rounds = [];
        for ($i = 1; $i <= 5; $i++) {
            $rounds[$i] = RaceResult::factory()->create([
                'discipline_id' => $discipline->id,
                'stage' => "Round $i",
                'created_at' => now()->subHours(5 - $i) // Round 5 is newest
            ]);
        }

        // Only Round 5 should be final
        for ($i = 1; $i <= 4; $i++) {
            $this->assertFalse($rounds[$i]->fresh()->isFinalRound(), "Round $i should not be final");
        }
        $this->assertTrue($rounds[5]->fresh()->isFinalRound(), "Round 5 should be final");
    }

    public function test_non_round_stages_use_creation_time()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Create different stage types
        $heat1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Heat 1',
            'created_at' => now()->subHours(3)
        ]);

        $semifinal = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Semifinal',
            'created_at' => now()->subHours(2)
        ]);

        $final = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Final',
            'created_at' => now()->subHour()
        ]);

        // Only the last created should be final
        $this->assertFalse($heat1->fresh()->isFinalRound());
        $this->assertFalse($semifinal->fresh()->isFinalRound());
        $this->assertTrue($final->fresh()->isFinalRound());
    }

    public function test_mixed_round_and_other_stages()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Create mixed stages
        $round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 1',
            'created_at' => now()->subHours(4)
        ]);

        $round2 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 2',
            'created_at' => now()->subHours(3)
        ]);

        $semifinal = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Semifinal',
            'created_at' => now()->subHours(2)
        ]);

        $round3 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 3',
            'created_at' => now()->subHour()
        ]);

        // Round 3 should be final (highest round number)
        $this->assertFalse($round1->fresh()->isFinalRound());
        $this->assertFalse($round2->fresh()->isFinalRound());
        $this->assertFalse($semifinal->fresh()->isFinalRound());
        $this->assertTrue($round3->fresh()->isFinalRound());
    }

    public function test_different_disciplines_are_separate()
    {
        // Create two disciplines
        $discipline1 = Discipline::factory()->create();
        $discipline2 = Discipline::factory()->create();

        // Create rounds for each discipline
        $d1_round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline1->id,
            'stage' => 'Round 1',
            'created_at' => now()->subHour()
        ]);

        $d1_round2 = RaceResult::factory()->create([
            'discipline_id' => $discipline1->id,
            'stage' => 'Round 2',
            'created_at' => now()
        ]);

        $d2_round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline2->id,
            'stage' => 'Round 1'
        ]);

        // Each discipline should have its own final round
        $this->assertFalse($d1_round1->fresh()->isFinalRound());
        $this->assertTrue($d1_round2->fresh()->isFinalRound());
        $this->assertTrue($d2_round1->fresh()->isFinalRound()); // Only round in discipline 2
    }
}