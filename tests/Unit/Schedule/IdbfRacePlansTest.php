<?php

namespace Tests\Unit\Schedule;

use App\Services\Schedule\IdbfRacePlans;
use App\Services\Schedule\RacePlan;
use InvalidArgumentException;
use OutOfRangeException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the IDBF race plan lookup matches the canonical PDF tables in
 * docs/idbf/Race-Plans-v2-2020-08.pdf.
 *
 * The selected assertions cover:
 *   - every plan code listed in the PDF table of contents
 *   - sampled heat lane seeding values that are easy to misread
 *   - heat composition derivation for tricky crew counts (e.g. 13/RP.3A)
 *   - boundary auto-pick behavior at plan transitions
 */
class IdbfRacePlansTest extends TestCase
{
    private IdbfRacePlans $plans;

    protected function setUp(): void
    {
        parent::setUp();
        // Load the config file directly so the test does not require Laravel boot.
        $config = require __DIR__ . '/../../../config/idbf_race_plans.php';
        $this->plans = new IdbfRacePlans($config);
    }

    public function test_all_expected_plan_codes_are_registered(): void
    {
        $expected = [
            // 4-lane
            'ROUNDS_4L', 'RP.1', 'RP.2', 'RP.3',
            // 6-lane
            'ROUNDS_6L', 'RP.1A', 'RP.2A', 'RP.3A', 'RP.4A', 'RP.5A', 'RP.6A', 'RP.7A',
            // 8-lane
            'RP.1B', 'RP.2B', 'RP.3B', 'RP.4B', 'RP.5B', 'RP.6B', 'RP.7B',
        ];

        $this->assertSame($expected, $this->plans->allCodes());
    }

    /** @dataProvider pickPlanCases */
    public function test_pick_plan_returns_expected_code(int $laneCount, int $crewCount, string $expectedCode): void
    {
        $this->assertSame($expectedCode, $this->plans->pickPlan($laneCount, $crewCount)->code);
    }

    public static function pickPlanCases(): array
    {
        return [
            // 4-lane boundaries
            'rounds 4L lower' => [4, 2, 'ROUNDS_4L'],
            'rounds 4L upper' => [4, 4, 'ROUNDS_4L'],
            'RP.1 lower'      => [4, 5, 'RP.1'],
            'RP.1 upper'      => [4, 8, 'RP.1'],
            'RP.2 lower'      => [4, 9, 'RP.2'],
            'RP.2 upper'      => [4, 12, 'RP.2'],
            'RP.3 lower'      => [4, 13, 'RP.3'],
            'RP.3 upper'      => [4, 16, 'RP.3'],
            // 6-lane boundaries
            'rounds 6L lower' => [6, 3, 'ROUNDS_6L'],
            'rounds 6L upper' => [6, 6, 'ROUNDS_6L'],
            'RP.1A lower'     => [6, 7, 'RP.1A'],
            'RP.2A lower'     => [6, 9, 'RP.2A'],
            'RP.3A lower'     => [6, 13, 'RP.3A'],
            'RP.3A mid'       => [6, 14, 'RP.3A'],
            'RP.3A upper'     => [6, 18, 'RP.3A'],
            'RP.4A lower'     => [6, 19, 'RP.4A'],
            'RP.5A lower'     => [6, 25, 'RP.5A'],
            'RP.6A lower'     => [6, 31, 'RP.6A'],
            'RP.7A upper'     => [6, 42, 'RP.7A'],
            // 8-lane boundaries
            'RP.1B lower'     => [8, 7, 'RP.1B'],
            'RP.2B lower'     => [8, 9, 'RP.2B'],
            'RP.3B lower'     => [8, 13, 'RP.3B'],
            'RP.4B lower'     => [8, 17, 'RP.4B'],
            'RP.5B lower'     => [8, 25, 'RP.5B'],
            'RP.6B lower'     => [8, 33, 'RP.6B'],
            'RP.7B upper'     => [8, 48, 'RP.7B'],
        ];
    }

    public function test_pick_plan_throws_when_out_of_range(): void
    {
        $this->expectException(OutOfRangeException::class);
        $this->plans->pickPlan(6, 100);
    }

    public function test_pick_plan_throws_for_unsupported_lane_count(): void
    {
        $this->expectException(OutOfRangeException::class);
        $this->plans->pickPlan(5, 12);
    }

    public function test_get_plan_throws_for_unknown_code(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->plans->getPlan('RP.99X');
    }

    public function test_rp_3a_heat_lane_seeding_matches_pdf(): void
    {
        // RP.3A on page 11: Heat 1 row "13 7 1 6 12 18", Heat 2 "14 8 2 5 11 17", Heat 3 "15 9 3 4 10 16"
        $plan = $this->plans->getPlan('RP.3A');

        $this->assertSame([1 => 13, 2 => 7, 3 => 1, 4 => 6, 5 => 12, 6 => 18], $plan->heatLaneSeeding(1, 18));
        $this->assertSame([1 => 14, 2 => 8, 3 => 2, 4 => 5, 5 => 11, 6 => 17], $plan->heatLaneSeeding(2, 18));
        $this->assertSame([1 => 15, 2 => 9, 3 => 3, 4 => 4, 5 => 10, 6 => 16], $plan->heatLaneSeeding(3, 18));
    }

    public function test_rp_3a_heat_lane_seeding_filters_seeds_above_crew_count(): void
    {
        $plan = $this->plans->getPlan('RP.3A');

        // 13 crews → seeds 14–18 don't exist; their lanes go null.
        // Heat 1 raw: [13, 7, 1, 6, 12, 18] → seed 18 invalid
        $this->assertSame(
            [1 => 13, 2 => 7, 3 => 1, 4 => 6, 5 => 12, 6 => null],
            $plan->heatLaneSeeding(1, 13)
        );
        // Heat 2 raw: [14, 8, 2, 5, 11, 17] → seeds 14, 17 invalid
        $this->assertSame(
            [1 => null, 2 => 8, 3 => 2, 4 => 5, 5 => 11, 6 => null],
            $plan->heatLaneSeeding(2, 13)
        );
        // Heat 3 raw: [15, 9, 3, 4, 10, 16] → seeds 15, 16 invalid
        $this->assertSame(
            [1 => null, 2 => 9, 3 => 3, 4 => 4, 5 => 10, 6 => null],
            $plan->heatLaneSeeding(3, 13)
        );
    }

    public function test_rp_3a_heat_composition_derives_correctly(): void
    {
        $plan = $this->plans->getPlan('RP.3A');

        // 13 crews split: 5 (H1: 1,6,7,12,13) / 4 (H2: 2,5,8,11) / 4 (H3: 3,4,9,10)
        $this->assertSame([1 => 5, 2 => 4, 3 => 4], $plan->heatComposition(13));
        // 18 crews fully fill all 18 slots.
        $this->assertSame([1 => 6, 2 => 6, 3 => 6], $plan->heatComposition(18));
    }

    public function test_rp_5a_heat_lane_seeding_matches_pdf(): void
    {
        // RP.5A on page 13: 5 heats, 6 lanes each, snake seeding from seed 1.
        $plan = $this->plans->getPlan('RP.5A');

        $this->assertSame([1 => 21, 2 => 11, 3 => 1, 4 => 10, 5 => 20, 6 => 30], $plan->heatLaneSeeding(1, 30));
        $this->assertSame([1 => 25, 2 => 15, 3 => 5, 4 => 6,  5 => 16, 6 => 26], $plan->heatLaneSeeding(5, 30));
    }

    public function test_rp_1a_heat_seeding_uses_only_inner_four_lanes(): void
    {
        // RP.1A page 9: heats use lanes 2-5 only; lanes 1 and 6 are null.
        $plan = $this->plans->getPlan('RP.1A');

        $this->assertSame([1 => null, 2 => 5, 3 => 1, 4 => 4, 5 => 8, 6 => null], $plan->heatLaneSeeding(1, 8));
        $this->assertSame([1 => null, 2 => 6, 3 => 2, 4 => 3, 5 => 7, 6 => null], $plan->heatLaneSeeding(2, 8));
    }

    public function test_rp_1_compact_is_not_auto_picked_for_seven_crews(): void
    {
        // The compact variant exists in the catalogue but ranks below RP.1
        // so the standard plan stays the default.
        $picked = $this->plans->pickPlan(4, 7);
        $this->assertSame('RP.1', $picked->code);

        $options = $this->plans->planOptions(4, 7);
        $this->assertContains('RP.1', $options);
        $this->assertContains('RP.1_COMPACT', $options);
    }

    public function test_rp_1_compact_seven_crew_layout(): void
    {
        $plan = $this->plans->getPlan('RP.1_COMPACT');

        $this->assertSame(['Heat 1', 'Heat 2', 'Repechage 1', 'Grand Final'], $plan->stages());
        // Heats inherit RP.1's lane table, so the rebalance for 7 crews
        // promotes seed 7 from H2L4 into H1L4.
        $this->assertSame([1 => 4, 2 => 3], $plan->heatComposition(7));
        $this->assertSame([1 => 5, 2 => 1, 3 => 4, 4 => 7], $plan->heatLaneSeeding(1, 7));
        $this->assertSame([1 => 6, 2 => 2, 3 => 3, 4 => null], $plan->heatLaneSeeding(2, 7));
    }

    public function test_rp_1_promotes_seed_into_earlier_heat_for_seven_crews(): void
    {
        // RP.1 page 6: with 7 crews the PDF specifies H1=4, H2=3.
        // The raw table puts seed 8 in H1L4, so naively dropping seed 8 would
        // give H1=3, H2=4. The "earlier heats fuller" IDBF rule promotes
        // seed 7 from H2L4 into the vacant H1L4 slot.
        $plan = $this->plans->getPlan('RP.1');

        $this->assertSame([1 => 4, 2 => 3], $plan->heatComposition(7));
        $this->assertSame([1 => 5, 2 => 1, 3 => 4, 4 => 7], $plan->heatLaneSeeding(1, 7));
        $this->assertSame([1 => 6, 2 => 2, 3 => 3, 4 => null], $plan->heatLaneSeeding(2, 7));
    }

    public function test_rp_1a_promotes_seed_into_earlier_heat_for_seven_crews(): void
    {
        // Same rebalance on the 6-lane inner-four variant (RP.1A).
        // Outer lanes 1 and 6 stay null per the plan's structural layout.
        $plan = $this->plans->getPlan('RP.1A');

        $this->assertSame([1 => 4, 2 => 3], $plan->heatComposition(7));
        $this->assertSame(
            [1 => null, 2 => 5, 3 => 1, 4 => 4, 5 => 7, 6 => null],
            $plan->heatLaneSeeding(1, 7),
        );
        $this->assertSame(
            [1 => null, 2 => 6, 3 => 2, 4 => 3, 5 => null, 6 => null],
            $plan->heatLaneSeeding(2, 7),
        );
    }

    public function test_rounds_plan_returns_round_seeding(): void
    {
        $plan = $this->plans->getPlan('ROUNDS_6L');

        $this->assertTrue($plan->isRoundsPlan());
        $this->assertSame(['Round 1', 'Round 2', 'Round 3'], $plan->stages());
        // Page 8: Round 1 lanes are 5,3,1,2,4,6
        $this->assertSame([1 => 5, 2 => 3, 3 => 1, 4 => 2, 5 => 4, 6 => 6], $plan->heatLaneSeeding(1, 6));
    }

    public function test_rounds_plan_drops_seeds_above_crew_count(): void
    {
        // ROUNDS_6L with 4 crews: seeds 5 and 6 don't exist.
        $plan = $this->plans->getPlan('ROUNDS_6L');

        $this->assertSame(
            [1 => null, 2 => 3, 3 => 1, 4 => 2, 5 => 4, 6 => null],
            $plan->heatLaneSeeding(1, 4)
        );
        $this->assertSame([1 => 4, 2 => 4, 3 => 4], $plan->heatComposition(4));
    }

    public function test_rounds_4l_with_3_crews_leaves_outside_lane_empty_not_lane_1(): void
    {
        // ROUNDS_4L with 3 crews: lane 4 (outside) should be empty every round,
        // never lane 1. Best seed sits centre (lane 2), then lane 3, then lane 1.
        // Per-round rotation rotates which seed gets the centre slot, but the
        // empty lane stays on the outside.
        $plan = $this->plans->getPlan('ROUNDS_4L');

        $this->assertSame([1 => 3, 2 => 1, 3 => 2, 4 => null], $plan->heatLaneSeeding(1, 3));
        $this->assertSame([1 => 1, 2 => 2, 3 => 3, 4 => null], $plan->heatLaneSeeding(2, 3));
        $this->assertSame([1 => 2, 2 => 3, 3 => 1, 4 => null], $plan->heatLaneSeeding(3, 3));
    }

    public function test_grand_final_seeding_present_for_progression_plans(): void
    {
        $rp3a = $this->plans->getPlan('RP.3A');

        $this->assertSame(
            [1 => '5th in SF', 2 => '3rd in SF', 3 => '1st in SF', 4 => '2nd in SF', 5 => '4th in SF', 6 => '6th in SF'],
            $rp3a->grandFinalSeeding()
        );
        $this->assertNotNull($rp3a->minorFinalSeeding());
        $this->assertNull($rp3a->tailFinalSeeding());
    }

    public function test_rp_7a_includes_tail_final(): void
    {
        $plan = $this->plans->getPlan('RP.7A');

        $this->assertNotNull($plan->tailFinalSeeding());
        $this->assertContains('Tail Final', $plan->stages());
    }

    public function test_rounds_plan_has_no_final_seedings(): void
    {
        $plan = $this->plans->getPlan('ROUNDS_4L');

        $this->assertNull($plan->grandFinalSeeding());
        $this->assertNull($plan->minorFinalSeeding());
        $this->assertNull($plan->tailFinalSeeding());
        $this->assertNull($plan->semiSeeding());
    }

    public function test_rp_2a_repechage_seeding_varies_by_crew_count(): void
    {
        // Page 10: 9 crews → single repechage; 10-12 crews → two repechages.
        $plan = $this->plans->getPlan('RP.2A');

        $nine = $plan->repechageSeeding(9);
        $this->assertCount(1, $nine);
        $this->assertSame('9th in hts', $nine[1][6]);

        $twelve = $plan->repechageSeeding(12);
        $this->assertCount(2, $twelve);
    }

    public function test_plan_options_returns_only_compatible_plans(): void
    {
        // 6-lane, 14 crews → only RP.3A covers it (range 13-18).
        $this->assertSame(['RP.3A'], $this->plans->planOptions(6, 14));
        // No plan covers 50 crews on 8 lanes.
        $this->assertSame([], $this->plans->planOptions(8, 50));
    }

    public function test_supports_crew_count_enforces_range(): void
    {
        $rp3a = $this->plans->getPlan('RP.3A');

        $this->assertTrue($rp3a->supportsCrewCount(13));
        $this->assertTrue($rp3a->supportsCrewCount(18));
        $this->assertFalse($rp3a->supportsCrewCount(12));
        $this->assertFalse($rp3a->supportsCrewCount(19));
    }

    public function test_heat_lane_seeding_throws_for_unsupported_crew_count(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->plans->getPlan('RP.3A')->heatLaneSeeding(1, 5);
    }

    public function test_every_plan_loads_into_a_value_object(): void
    {
        // Smoke test: confirm each registered plan is well-formed enough to
        // expose stages and a non-zero heat count.
        foreach ($this->plans->allCodes() as $code) {
            $plan = $this->plans->getPlan($code);

            $this->assertInstanceOf(RacePlan::class, $plan);
            $this->assertNotEmpty($plan->stages(), "Plan {$code} has no stages");
            $this->assertGreaterThan(0, $plan->heatCount(), "Plan {$code} has no heats/rounds");
            $this->assertContains($plan->laneCount(), [4, 6, 8], "Plan {$code} has invalid lane count");
        }
    }
}
