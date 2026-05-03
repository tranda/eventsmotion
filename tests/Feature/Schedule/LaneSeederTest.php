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
