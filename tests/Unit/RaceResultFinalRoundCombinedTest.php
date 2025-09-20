<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\RaceResult;
use App\Models\Discipline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

class RaceResultFinalRoundCombinedTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_stage_matching_takes_precedence()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Create multiple rounds with "Final" being created first but should still be final
        $final = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Final',
            'created_at' => now()->subHours(3) // Created first, but should still be final
        ]);

        $round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 1',
            'created_at' => now()->subHours(2)
        ]);

        $round2 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 2',
            'created_at' => now()->subHour() // Latest chronologically
        ]);

        // "Final" stage should be final regardless of chronological order
        $this->assertTrue($final->fresh()->isFinalRound(), '"Final" should be considered final due to exact stage match');

        // Round 2 should also be final as it's the chronologically last
        $this->assertTrue($round2->fresh()->isFinalRound(), '"Round 2" should be considered final as chronologically last');

        // Round 1 should not be final
        $this->assertFalse($round1->fresh()->isFinalRound(), '"Round 1" should not be considered final');
    }

    public function test_grand_final_exact_matching()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

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

        $grandFinal = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Grand Final',
            'created_at' => now()->subHour()
        ]);

        $round3 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 3',
            'created_at' => now() // Latest chronologically
        ]);

        // Both "Grand Final" and "Round 3" should be final
        $this->assertTrue($grandFinal->fresh()->isFinalRound(), '"Grand Final" should be considered final due to exact stage match');
        $this->assertTrue($round3->fresh()->isFinalRound(), '"Round 3" should be considered final as chronologically last');

        // Others should not be final
        $this->assertFalse($round1->fresh()->isFinalRound());
        $this->assertFalse($semifinal->fresh()->isFinalRound());
    }

    public function test_last_round_chronological_detection()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Create multiple numbered rounds
        $rounds = [];
        for ($i = 1; $i <= 4; $i++) {
            $rounds[$i] = RaceResult::factory()->create([
                'discipline_id' => $discipline->id,
                'stage' => "Round $i",
                'created_at' => now()->subHours(4 - $i) // Round 4 is newest
            ]);
        }

        // Only Round 4 should be final (chronologically last)
        for ($i = 1; $i <= 3; $i++) {
            $this->assertFalse($rounds[$i]->fresh()->isFinalRound(), "Round $i should not be final");
        }
        $this->assertTrue($rounds[4]->fresh()->isFinalRound(), "Round 4 should be final as chronologically last");
    }

    public function test_single_round_is_always_final()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Test various single round scenarios
        $testCases = [
            'Round 1',
            'Semifinal',
            'Minor Final',
            'Heat 1',
            'Preliminary',
            'Qualification'
        ];

        foreach ($testCases as $stage) {
            // Create a fresh discipline for each test to ensure isolation
            $freshDiscipline = Discipline::factory()->create();

            $singleRound = RaceResult::factory()->create([
                'discipline_id' => $freshDiscipline->id,
                'stage' => $stage
            ]);

            $this->assertTrue($singleRound->fresh()->isFinalRound(), "Single '$stage' should be considered final");
        }
    }

    public function test_exact_final_stages_never_lose_final_status()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        $final = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Final',
            'created_at' => now()->subHours(5) // Very old
        ]);

        // Add many rounds after the "Final"
        for ($i = 1; $i <= 10; $i++) {
            RaceResult::factory()->create([
                'discipline_id' => $discipline->id,
                'stage' => "Round $i",
                'created_at' => now()->subHours(4 - ($i * 0.1))
            ]);
        }

        // "Final" should still be final despite being created first
        $this->assertTrue($final->fresh()->isFinalRound(), '"Final" should always be final regardless of chronological order');
    }

    public function test_non_final_stages_respect_chronological_order()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        $heat1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Heat 1',
            'created_at' => now()->subHours(4)
        ]);

        $semifinal = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Semifinal',
            'created_at' => now()->subHours(3)
        ]);

        $minorFinal = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Minor Final',
            'created_at' => now()->subHours(2)
        ]);

        $round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 1',
            'created_at' => now()->subHour()
        ]);

        $round2 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 2',
            'created_at' => now() // Latest
        ]);

        // Only the chronologically last round should be final
        $this->assertFalse($heat1->fresh()->isFinalRound());
        $this->assertFalse($semifinal->fresh()->isFinalRound());
        $this->assertFalse($minorFinal->fresh()->isFinalRound(), '"Minor Final" should not be final without exact match');
        $this->assertFalse($round1->fresh()->isFinalRound());
        $this->assertTrue($round2->fresh()->isFinalRound(), '"Round 2" should be final as chronologically last');
    }

    public function test_case_sensitivity_still_applies_to_exact_matches()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Test case variations - only exact "Final" and "Grand Final" should match
        $testCases = [
            'Final' => true,
            'Grand Final' => true,
            'final' => false,
            'FINAL' => false,
            'FiNaL' => false,
            'grand final' => false,
            'GRAND FINAL' => false,
            'Finals' => false,
            'Grand Finals' => false,
        ];

        foreach ($testCases as $stage => $shouldBeExactMatch) {
            // Create a fresh discipline for each test
            $freshDiscipline = Discipline::factory()->create();

            // Create the test race
            $race = RaceResult::factory()->create([
                'discipline_id' => $freshDiscipline->id,
                'stage' => $stage,
                'created_at' => now()->subHour()
            ]);

            // Add another race after it to test chronological fallback
            $laterRace = RaceResult::factory()->create([
                'discipline_id' => $freshDiscipline->id,
                'stage' => 'Round 1',
                'created_at' => now()
            ]);

            if ($shouldBeExactMatch) {
                $this->assertTrue($race->fresh()->isFinalRound(), "Stage '$stage' should be final due to exact match");
                $this->assertTrue($laterRace->fresh()->isFinalRound(), "'Round 1' should also be final as chronologically last");
            } else {
                $this->assertFalse($race->fresh()->isFinalRound(), "Stage '$stage' should not be final (no exact match) and not chronologically last");
                $this->assertTrue($laterRace->fresh()->isFinalRound(), "'Round 1' should be final as chronologically last");
            }
        }
    }

    public function test_different_disciplines_are_independent()
    {
        // Create two disciplines
        $discipline1 = Discipline::factory()->create();
        $discipline2 = Discipline::factory()->create();

        // Discipline 1: Multiple rounds
        $d1_round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline1->id,
            'stage' => 'Round 1',
            'created_at' => now()->subHours(2)
        ]);

        $d1_round2 = RaceResult::factory()->create([
            'discipline_id' => $discipline1->id,
            'stage' => 'Round 2',
            'created_at' => now()->subHour()
        ]);

        // Discipline 2: Has a "Final" stage but also other rounds
        $d2_final = RaceResult::factory()->create([
            'discipline_id' => $discipline2->id,
            'stage' => 'Final',
            'created_at' => now()->subHours(3)
        ]);

        $d2_round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline2->id,
            'stage' => 'Round 1',
            'created_at' => now()
        ]);

        // Check results
        $this->assertFalse($d1_round1->fresh()->isFinalRound(), 'D1 Round 1 should not be final');
        $this->assertTrue($d1_round2->fresh()->isFinalRound(), 'D1 Round 2 should be final (chronologically last)');

        $this->assertTrue($d2_final->fresh()->isFinalRound(), 'D2 Final should be final (exact match)');
        $this->assertTrue($d2_round1->fresh()->isFinalRound(), 'D2 Round 1 should be final (chronologically last)');
    }

    public function test_id_as_tiebreaker_for_same_creation_time()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Create races with the same creation time
        $sameTime = now();

        $race1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 1',
            'created_at' => $sameTime
        ]);

        $race2 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 2',
            'created_at' => $sameTime
        ]);

        // The race with higher ID should be considered last (and thus final)
        $higherIdRace = $race1->id > $race2->id ? $race1 : $race2;
        $lowerIdRace = $race1->id < $race2->id ? $race1 : $race2;

        $this->assertTrue($higherIdRace->fresh()->isFinalRound(), 'Race with higher ID should be final when creation times are equal');
        $this->assertFalse($lowerIdRace->fresh()->isFinalRound(), 'Race with lower ID should not be final when creation times are equal');
    }

    public function test_mixed_scenarios_with_both_behaviors()
    {
        // Create a discipline
        $discipline = Discipline::factory()->create();

        // Create a complex scenario with both exact matches and chronological ordering
        $round1 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 1',
            'created_at' => now()->subHours(6)
        ]);

        $final = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Final', // Exact match - should be final
            'created_at' => now()->subHours(5)
        ]);

        $semifinal = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Semifinal',
            'created_at' => now()->subHours(4)
        ]);

        $grandFinal = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Grand Final', // Exact match - should be final
            'created_at' => now()->subHours(3)
        ]);

        $round2 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 2',
            'created_at' => now()->subHours(2)
        ]);

        $minorFinal = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Minor Final',
            'created_at' => now()->subHour()
        ]);

        $round3 = RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => 'Round 3',
            'created_at' => now() // Chronologically last
        ]);

        // Check results
        $this->assertFalse($round1->fresh()->isFinalRound());
        $this->assertTrue($final->fresh()->isFinalRound(), '"Final" should be final due to exact match');
        $this->assertFalse($semifinal->fresh()->isFinalRound());
        $this->assertTrue($grandFinal->fresh()->isFinalRound(), '"Grand Final" should be final due to exact match');
        $this->assertFalse($round2->fresh()->isFinalRound());
        $this->assertFalse($minorFinal->fresh()->isFinalRound(), '"Minor Final" should not be final (no exact match and not chronologically last)');
        $this->assertTrue($round3->fresh()->isFinalRound(), '"Round 3" should be final as chronologically last');
    }
}