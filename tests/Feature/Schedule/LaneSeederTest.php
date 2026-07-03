<?php

namespace Tests\Feature\Schedule;

use App\Models\Crew;
use App\Models\CrewResult;
use App\Models\Discipline;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\RaceResult;
use App\Models\ScheduleBlock;
use App\Models\Team;
use App\Services\Schedule\IdbfRacePlans;
use App\Services\Schedule\LaneSeeder;
use App\Services\Schedule\ScheduleGeneratorService;
use App\Services\Schedule\ScheduleSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * End-to-end test for LaneSeeder using a 6-lane / 12-crew event (plan RP.2A).
 *
 * Flow under test:
 *   1. Generator creates heats + reps + grand final.
 *   2. Heats are simulated finished with deterministic times.
 *   3. LaneSeeder fills repechage lanes per the IDBF table refs ("9th in hts" etc.).
 *   4. Repechages are simulated finished.
 *   5. LaneSeeder fills the grand final lanes from rep + heat positions.
 */
class LaneSeederTest extends TestCase
{
    use RefreshDatabase;

    private ScheduleGeneratorService $generator;
    private LaneSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();
        $plans = new IdbfRacePlans();
        $this->generator = new ScheduleGeneratorService($plans, new ScheduleSnapshotService());
        $this->seeder = new LaneSeeder($plans);
    }

    public function test_seeds_repechages_from_heat_results(): void
    {
        [$event, $discipline] = $this->makeEventWithGenerator(crewCount: 12);
        $this->finishHeats($discipline);

        $result = $this->seeder->seedNextRound($discipline->fresh());

        $this->assertSame('Repechage 1', $result->seededStage);
        $this->assertGreaterThan(0, $result->crewLanesAssigned);

        $rep1 = RaceResult::where('discipline_id', $discipline->id)
            ->where('stage', 'Repechage 1')
            ->firstOrFail();
        $this->assertGreaterThan(0, $rep1->crewResults()->count());
    }

    public function test_refuses_when_heats_unfinished(): void
    {
        [$event, $discipline] = $this->makeEventWithGenerator(crewCount: 12);
        // Mark only one heat finished; leave the other SCHEDULED.
        $heat = RaceResult::where('discipline_id', $discipline->id)
            ->where('stage', 'Heat 1')
            ->firstOrFail();
        $this->finishRace($heat);

        $result = $this->seeder->seedNextRound($discipline->fresh());

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('heats', $result->skippedReason);
    }

    public function test_throws_on_rounds_only_plan(): void
    {
        [$event, $discipline] = $this->makeEventWithGenerator(crewCount: 5); // ROUNDS_6L
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rounds-only plans');
        $this->seeder->seedNextRound($discipline->fresh());
    }

    public function test_advances_to_next_unseeded_stage_after_reps_seeded(): void
    {
        [$event, $discipline] = $this->makeEventWithGenerator(crewCount: 12);
        $this->finishHeats($discipline);
        $this->seeder->seedNextRound($discipline->fresh()); // seeds Repechage 1
        $this->seeder->seedNextRound($discipline->fresh()); // seeds Repechage 2

        // Reps now have CrewResults. Calling again without finishing reps should
        // skip with a message about repechages being unfinished.
        $result = $this->seeder->seedNextRound($discipline->fresh());

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('repechages', $result->skippedReason);
    }

    public function test_seeds_grand_final_after_reps_finished(): void
    {
        [$event, $discipline] = $this->makeEventWithGenerator(crewCount: 12);
        $this->finishHeats($discipline);
        $this->seeder->seedNextRound($discipline->fresh()); // Rep 1
        $this->seeder->seedNextRound($discipline->fresh()); // Rep 2
        $this->finishStage($discipline, 'Repechage');

        $result = $this->seeder->seedNextRound($discipline->fresh());

        $this->assertSame('Grand Final', $result->seededStage);
        $final = RaceResult::where('discipline_id', $discipline->id)
            ->where('stage', 'Grand Final')
            ->firstOrFail();
        $this->assertGreaterThan(0, $final->crewResults()->count());
    }

    /**
     * IDBF rule: position refs in seeding tables resolve to literal race
     * winners first (positions 1..N in race order), then lucky losers by
     * time. NOT a global time ranking.
     *
     * Builds a 7-crew RP.1 event (2 heats: H1=4, H2=3), times all heats
     * so H2 finishers are FASTER than H1 finishers, and asserts that
     * the Repechage / Grand Final lanes still pull H1's winner where the
     * IDBF table says "1st in hts" — not the globally fastest crew.
     */
    public function test_winners_advance_first_even_when_other_heat_is_faster(): void
    {
        // Use 4-lane / 7-crew so we land on RP.1 (the case we discussed).
        $event = Event::create([
            'name' => 'Winner-First Test',
            'location' => 'Pool',
            'year' => 2026,
            'lane_count' => 4,
            'schedule_status' => 'draft',
        ]);
        $day = EventDay::create([
            'event_id' => $event->id,
            'date' => '2026-06-12',
            'name' => 'Day 1',
            'sort_order' => 0,
        ]);
        ScheduleBlock::create([
            'event_day_id' => $day->id,
            'name' => 'Morning',
            'start_time' => '09:00:00',
            'gap_seconds' => 240,
            'sort_order' => 0,
        ]);
        $discipline = Discipline::create([
            'event_id' => $event->id,
            'distance' => '200m',
            'age_group' => 'Senior',
            'gender_group' => 'M',
            'boat_group' => 'Standard',
            'status' => 'active',
        ]);
        for ($i = 0; $i < 7; $i++) {
            $team = Team::create(['name' => 'T' . ($i + 1)]);
            Crew::create([
                'team_id' => $team->id,
                'discipline_id' => $discipline->id,
                'seed_number' => $i + 1,
            ]);
        }
        $this->generator->generate($event);

        // Make Heat 2 SLOWER on average so "winner of H1" is also globally
        // fastest — but make Heat 1's 2nd-place faster than Heat 2's winner.
        // That way time-based ranking would put H1's 2nd ahead of H2's
        // winner; winner-first ranking must still seat H2's winner as
        // "2nd in hts".
        $h1 = RaceResult::where('discipline_id', $discipline->id)
            ->where('stage', 'Heat 1')->firstOrFail();
        $h2 = RaceResult::where('discipline_id', $discipline->id)
            ->where('stage', 'Heat 2')->firstOrFail();

        $h1->update(['status' => 'FINISHED']);
        foreach ($h1->crewResults()->orderBy('lane')->get() as $i => $cr) {
            // H1 times: 60_000, 60_100, 60_200, 60_300 ms (winner @ lane 2)
            // (lane 1 may be seed 5; the actual fastest is the first by lane
            // after sort — for the test we just want a deterministic order)
            CrewResult::where('id', $cr->id)->update([
                'status' => 'FINISHED',
                'time_ms' => 60_000 + $i * 100,
            ]);
        }
        $h2->update(['status' => 'FINISHED']);
        foreach ($h2->crewResults()->orderBy('lane')->get() as $i => $cr) {
            // H2 times: 60_500, 60_600, 60_700 ms — H2 winner is SLOWER
            // than H1's 2nd-, 3rd-, 4th-placers globally.
            CrewResult::where('id', $cr->id)->update([
                'status' => 'FINISHED',
                'time_ms' => 60_500 + $i * 100,
            ]);
        }

        // Use reflection to invoke the private buildRankings + compare crew_ids.
        $seederRef = new \ReflectionClass($this->seeder);
        $method = $seederRef->getMethod('buildRankings');
        $method->setAccessible(true);
        $rankings = $method->invoke($this->seeder, $discipline->fresh(), ['hts']);
        $ordered = $rankings['hts'];

        // Positions 1..2 must be the H1 + H2 winners (the fastest crew in
        // each heat — lane-1 crew above, since we ordered by lane).
        $h1WinnerId = $h1->crewResults()->orderBy('lane')->first()->crew_id;
        $h2WinnerId = $h2->crewResults()->orderBy('lane')->first()->crew_id;
        $this->assertSame($h1WinnerId, $ordered[0], '1st in hts should be H1 winner');
        $this->assertSame($h2WinnerId, $ordered[1], '2nd in hts should be H2 winner');

        // Position 3 ("3rd in hts") = best NON-winner overall by time.
        // H1's 2nd-place crew (time 60_100) beats every H2 non-winner.
        $h1Second = $h1->crewResults()->orderBy('lane')->get()[1];
        $this->assertSame(
            $h1Second->crew_id,
            $ordered[2],
            '3rd in hts should be the best lucky loser (H1 2nd, time 60_100)',
        );
    }

    /**
     * Tiered semantics: when a plan declares tiers >= 2 for a source stage,
     * positions are filled round-robin by tier (1st of each race, then 2nd
     * of each race, …) BEFORE any lucky-loser fill-in.
     *
     * Uses RP.3 (4 lanes, 13 crews, tiers['hts']=2). Times are arranged so
     * Heat 1's 3rd-placer is globally faster than Heat 2/3/4's 2nd-placers.
     * Under winners-only-then-time, "5th in hts" would be H1's 3rd. Under
     * IDBF tiered semantics it must be H1's 2nd-placer (tier 2, race 1).
     */
    public function test_tiered_ranking_uses_explicit_positions_before_lucky_losers(): void
    {
        $event = Event::create([
            'name' => 'RP.3 Tier Test',
            'location' => 'Pool',
            'year' => 2026,
            'lane_count' => 4,
            'schedule_status' => 'draft',
        ]);
        $day = EventDay::create([
            'event_id' => $event->id,
            'date' => '2026-06-12',
            'name' => 'Day 1',
            'sort_order' => 0,
        ]);
        ScheduleBlock::create([
            'event_day_id' => $day->id,
            'name' => 'Morning',
            'start_time' => '09:00:00',
            'gap_seconds' => 240,
            'sort_order' => 0,
        ]);
        $discipline = Discipline::create([
            'event_id' => $event->id,
            'distance' => '200m',
            'age_group' => 'Senior',
            'gender_group' => 'M',
            'boat_group' => 'Standard',
            'status' => 'active',
        ]);
        for ($i = 0; $i < 13; $i++) {
            $team = Team::create(['name' => 'T' . ($i + 1)]);
            Crew::create([
                'team_id' => $team->id,
                'discipline_id' => $discipline->id,
                'seed_number' => $i + 1,
            ]);
        }
        $this->generator->generate($event);

        // RP.3 has 4 heats (composition varies: 13 crews → H1=4, H2=3, H3=3, H4=3).
        // Stamp times so Heat 1's 3rd place (lane index 2) is faster than
        // the other heats' 2nd-placers. Specifically:
        //   H1 times: 60_000 / 60_100 / 60_200 / 60_300
        //   H2 times: 60_500 / 60_600 / 60_700        (2nd=60_600)
        //   H3 times: 60_550 / 60_650 / 60_750        (2nd=60_650)
        //   H4 times: 60_580 / 60_680 / 60_780        (2nd=60_680)
        // Time ranking globally: H1.1, H1.2, H1.3, H1.4, H2.1, H3.1, H4.1, H2.2, H3.2, H4.2, ...
        $heatStartTimes = [
            'Heat 1' => 60_000,
            'Heat 2' => 60_500,
            'Heat 3' => 60_550,
            'Heat 4' => 60_580,
        ];
        foreach ($heatStartTimes as $stage => $base) {
            $heat = RaceResult::where('discipline_id', $discipline->id)
                ->where('stage', $stage)->firstOrFail();
            $heat->update(['status' => 'FINISHED']);
            foreach ($heat->crewResults()->orderBy('lane')->get() as $i => $cr) {
                CrewResult::where('id', $cr->id)->update([
                    'status' => 'FINISHED',
                    'time_ms' => $base + $i * 100,
                ]);
            }
        }

        $plans = new IdbfRacePlans();
        $plan = $plans->getPlan('RP.3');
        $this->assertSame(2, $plan->sourceOrderingTiers('hts'),
            'sanity: RP.3 should declare 2 tiers from heats');

        // Build rankings via reflection.
        $seederRef = new \ReflectionClass($this->seeder);
        $method = $seederRef->getMethod('buildRankings');
        $method->setAccessible(true);
        $rankings = $method->invoke($this->seeder, $discipline->fresh(), $plan, ['hts']);
        $ordered = $rankings['hts'];

        // Helper: get the crew at a heat's Nth lane (lanes ordered by time).
        $crewAt = function (string $stage, int $place) use ($discipline) {
            $heat = RaceResult::where('discipline_id', $discipline->id)
                ->where('stage', $stage)->firstOrFail();
            $sorted = $heat->crewResults()->orderBy('lane')->get();
            return $sorted[$place - 1]->crew_id;
        };

        // Tier 1 (positions 1..4) = winners in race order: H1, H2, H3, H4.
        $this->assertSame($crewAt('Heat 1', 1), $ordered[0], '1st = H1 winner');
        $this->assertSame($crewAt('Heat 2', 1), $ordered[1], '2nd = H2 winner');
        $this->assertSame($crewAt('Heat 3', 1), $ordered[2], '3rd = H3 winner');
        $this->assertSame($crewAt('Heat 4', 1), $ordered[3], '4th = H4 winner');

        // Tier 2 (positions 5..8) = 2nd-placers in race order, NOT lucky losers.
        // Under the old winners-only logic, position 5 would be H1's 3rd
        // (time 60_200, globally 3rd fastest non-winner). Under tiered
        // semantics it must be H1's 2nd-placer.
        $this->assertSame($crewAt('Heat 1', 2), $ordered[4], '5th = H1 2nd (tier 2)');
        $this->assertSame($crewAt('Heat 2', 2), $ordered[5], '6th = H2 2nd (tier 2)');
        $this->assertSame($crewAt('Heat 3', 2), $ordered[6], '7th = H3 2nd (tier 2)');
        $this->assertSame($crewAt('Heat 4', 2), $ordered[7], '8th = H4 2nd (tier 2)');
    }

    public function test_skips_when_all_progression_stages_seeded(): void
    {
        [$event, $discipline] = $this->makeEventWithGenerator(crewCount: 12);
        $this->finishHeats($discipline);
        $this->seeder->seedNextRound($discipline->fresh()); // Rep 1
        $this->seeder->seedNextRound($discipline->fresh()); // Rep 2
        $this->finishStage($discipline, 'Repechage');
        $this->seeder->seedNextRound($discipline->fresh()); // Grand Final

        $result = $this->seeder->seedNextRound($discipline->fresh());

        $this->assertTrue($result->skipped);
        $this->assertStringContainsString('already seeded', $result->skippedReason);
    }

    // ---------------- helpers ----------------

    /** @return array{0: Event, 1: Discipline} */
    private function makeEventWithGenerator(int $crewCount): array
    {
        $event = Event::create([
            'name' => 'Seed Test',
            'location' => 'Pool',
            'year' => 2026,
            'lane_count' => 6,
            'schedule_status' => 'draft',
        ]);
        $day = EventDay::create([
            'event_id' => $event->id,
            'date' => '2026-06-12',
            'name' => 'Day 1',
            'sort_order' => 0,
        ]);
        ScheduleBlock::create([
            'event_day_id' => $day->id,
            'name' => 'Morning',
            'start_time' => '09:00:00',
            'gap_seconds' => 240,
            'sort_order' => 0,
        ]);
        $discipline = Discipline::create([
            'event_id' => $event->id,
            'distance' => '200m',
            'age_group' => 'Senior',
            'gender_group' => 'M',
            'boat_group' => 'Standard',
            'status' => 'active',
        ]);
        for ($i = 0; $i < $crewCount; $i++) {
            $team = Team::create(['name' => 'Team #' . ($i + 1)]);
            Crew::create([
                'team_id' => $team->id,
                'discipline_id' => $discipline->id,
                'seed_number' => $i + 1,
            ]);
        }
        $this->generator->generate($event);
        return [$event->fresh(), $discipline->fresh()];
    }

    /** Mark all heat races FINISHED with sequential time_ms so rankings are deterministic. */
    private function finishHeats(Discipline $discipline): void
    {
        $heats = RaceResult::where('discipline_id', $discipline->id)
            ->where('stage', 'like', 'Heat%')
            ->get();
        foreach ($heats as $heat) {
            $this->finishRace($heat);
        }
    }

    private function finishStage(Discipline $discipline, string $stagePrefix): void
    {
        $races = RaceResult::where('discipline_id', $discipline->id)
            ->where('stage', 'like', "{$stagePrefix}%")
            ->get();
        foreach ($races as $race) {
            $this->finishRace($race);
        }
    }

    private function finishRace(RaceResult $race): void
    {
        $race->update(['status' => 'FINISHED']);
        $crewResults = $race->crewResults()->orderBy('lane')->get();
        $base = 60_000 + $race->id * 1000; // unique ms per race so rankings are stable
        foreach ($crewResults as $i => $cr) {
            CrewResult::where('id', $cr->id)->update([
                'status' => 'FINISHED',
                'time_ms' => $base + $i * 100,
            ]);
        }
    }
}
