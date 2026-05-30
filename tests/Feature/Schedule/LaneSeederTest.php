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
        $this->generator = new ScheduleGeneratorService($plans);
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
