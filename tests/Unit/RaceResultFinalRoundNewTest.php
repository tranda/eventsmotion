<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\RaceResult;
use App\Models\Discipline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

class RaceResultFinalRoundNewTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_final_stage_matching()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Test "Final" (should be final)
        $final = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Final'
        ]);

        // Test "Grand Final" (should be final)
        $grandFinal = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Grand Final'
        ]);

        // Only exact matches should be final
        $this->assertTrue($final->fresh()->isFinalRound(), '"Final" should be considered final');
        $this->assertTrue($grandFinal->fresh()->isFinalRound(), '"Grand Final" should be considered final');
    }

    public function test_non_final_stages_are_not_final()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Test stages that should NOT be final
        $testCases = [
            'Minor Final' => 'Minor Final should NOT be considered final',
            'Semi Final' => 'Semi Final should NOT be considered final',
            'Semifinal' => 'Semifinal should NOT be considered final',
            'Quarter Final' => 'Quarter Final should NOT be considered final',
            'Round 1' => 'Round 1 should NOT be considered final',
            'Round 2' => 'Round 2 should NOT be considered final',
            'Heat 1' => 'Heat 1 should NOT be considered final',
            'Preliminary' => 'Preliminary should NOT be considered final',
            'final' => 'final (lowercase) should NOT be considered final',
            'FINAL' => 'FINAL (uppercase) should NOT be considered final',
            'FiNaL' => 'FiNaL (mixed case) should NOT be considered final',
            'Grand final' => 'Grand final (lowercase) should NOT be considered final',
            'GRAND FINAL' => 'GRAND FINAL (uppercase) should NOT be considered final',
            'grand Final' => 'grand Final (mixed case) should NOT be considered final',
            'Finals' => 'Finals (plural) should NOT be considered final',
            'Grand Finals' => 'Grand Finals (plural) should NOT be considered final',
            'Final Round' => 'Final Round should NOT be considered final',
            'The Final' => 'The Final should NOT be considered final'
        ];

        foreach ($testCases as $stage => $message) {
            $race = RaceResult::factory()->create([
                'discipline_id' => $discipline->id,
                'stage' => $stage
            ]);

            $this->assertFalse($race->fresh()->isFinalRound(), $message);
        }
    }

    public function test_final_stage_with_other_stages()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Create various stages including a final
        $heat1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Heat 1',
            'created_at' => now()->subHours(4)
        ]);

        $round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 1',
            'created_at' => now()->subHours(3)
        ]);

        $semifinal = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Semifinal',
            'created_at' => now()->subHours(2)
        ]);

        $minorFinal = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Minor Final',
            'created_at' => now()->subHour()
        ]);

        $final = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Final',
            'created_at' => now()
        ]);

        // Only the "Final" stage should be considered final
        $this->assertFalse($heat1->fresh()->isFinalRound());
        $this->assertFalse($round1->fresh()->isFinalRound());
        $this->assertFalse($semifinal->fresh()->isFinalRound());
        $this->assertFalse($minorFinal->fresh()->isFinalRound());
        $this->assertTrue($final->fresh()->isFinalRound());
    }

    public function test_multiple_final_stages_in_same_discipline()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Create multiple "Final" stages (hypothetical scenario)
        $final1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Final',
            'race_number' => 1
        ]);

        $final2 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Final',
            'race_number' => 2
        ]);

        $grandFinal = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Grand Final',
            'race_number' => 3
        ]);

        // All should be considered final
        $this->assertTrue($final1->fresh()->isFinalRound());
        $this->assertTrue($final2->fresh()->isFinalRound());
        $this->assertTrue($grandFinal->fresh()->isFinalRound());
    }

    public function test_different_disciplines_are_independent()
    {
        // Create two disciplines
        $discipline1 = Discipline::factory()->create();
        $discipline2 = Discipline::factory()->create();

        // Create different stages for each discipline
        $d1_round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline1->id,
            'stage' => 'Round 1'
        ]);

        $d1_final = RaceResult::factory()->create([
            'discipline_id' => $discipline1->id,
            'stage' => 'Final'
        ]);

        $d2_semifinal = RaceResult::factory()->create([
            'discipline_id' => $discipline2->id,
            'stage' => 'Semifinal'
        ]);

        $d2_grandFinal = RaceResult::factory()->create([
            'discipline_id' => $discipline2->id,
            'stage' => 'Grand Final'
        ]);

        // Only the exact "Final" and "Grand Final" stages should be final
        $this->assertFalse($d1_round1->fresh()->isFinalRound());
        $this->assertTrue($d1_final->fresh()->isFinalRound());
        $this->assertFalse($d2_semifinal->fresh()->isFinalRound());
        $this->assertTrue($d2_grandFinal->fresh()->isFinalRound());
    }

    public function test_round_based_stages_are_not_final()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Create multiple rounds (old logic would make the last one final)
        $rounds = [];
        for ($i = 1; $i <= 5; $i++) {
            $rounds[$i] = RaceResult::factory()->create([
                'discipline_id' => $discipline->id,
                'stage' => "Round $i",
                'created_at' => now()->subHours(5 - $i) // Round 5 is newest
            ]);
        }

        // With new logic, none of the rounds should be final
        for ($i = 1; $i <= 5; $i++) {
            $this->assertFalse($rounds[$i]->fresh()->isFinalRound(), "Round $i should not be final with new logic");
        }
    }

    public function test_case_sensitivity_and_exact_matching()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Test case variations and similar strings
        $testCases = [
            // These should be final (exact matches)
            'Final' => true,
            'Grand Final' => true,

            // These should NOT be final (case sensitive)
            'final' => false,
            'FINAL' => false,
            'FiNaL' => false,
            'grand final' => false,
            'GRAND FINAL' => false,
            'Grand final' => false,
            'grand Final' => false,

            // These should NOT be final (partial matches)
            'Finals' => false,
            'Grand Finals' => false,
            'Final Round' => false,
            'The Final' => false,
            'A Final' => false,
            'Semi Final' => false,
            'Minor Final' => false,
            'Quarter Final' => false,
        ];

        foreach ($testCases as $stage => $shouldBeFinal) {
            $race = RaceResult::factory()->create([
                'discipline_id' => $discipline->id,
                'stage' => $stage
            ]);

            if ($shouldBeFinal) {
                $this->assertTrue($race->fresh()->isFinalRound(), "Stage '$stage' should be considered final");
            } else {
                $this->assertFalse($race->fresh()->isFinalRound(), "Stage '$stage' should NOT be considered final");
            }
        }
    }

    public function test_chronological_order_does_not_matter()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Create stages in non-chronological order
        $final = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Final',
            'created_at' => now()->subHours(3) // Created first, but should still be final
        ]);

        $semifinal = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Semifinal',
            'created_at' => now()->subHours(2)
        ]);

        $round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 1',
            'created_at' => now()->subHour()
        ]);

        $grandFinal = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Grand Final',
            'created_at' => now() // Created last
        ]);

        // Only stages with exact "Final" or "Grand Final" should be final, regardless of creation order
        $this->assertTrue($final->fresh()->isFinalRound());
        $this->assertFalse($semifinal->fresh()->isFinalRound());
        $this->assertFalse($round1->fresh()->isFinalRound());
        $this->assertTrue($grandFinal->fresh()->isFinalRound());
    }
}