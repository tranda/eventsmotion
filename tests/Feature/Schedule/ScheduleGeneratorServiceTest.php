<?php

namespace Tests\Feature\Schedule;

use App\Models\Crew;
use App\Models\Discipline;
use App\Models\DisciplineProgression;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\RaceResult;
use App\Models\ScheduleBlock;
use App\Models\Team;
use App\Services\Schedule\IdbfRacePlans;
use App\Services\Schedule\ScheduleGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Integration tests for ScheduleGeneratorService.
 *
 * These tests exercise the end-to-end pipeline: discipline → IDBF plan →
 * RaceResult/CrewResult rows with lanes, times, and chronological numbering.
 *
 * Asserts the most error-prone behaviors: lane assignment matching IDBF tables,
 * deterministic crew seeding, time placement within block windows, and
 * regenerate scoping.
 */
class ScheduleGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScheduleGeneratorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScheduleGeneratorService(new IdbfRacePlans());
    }

    public function test_generates_expected_stage_count_for_rp_2a(): void
    {
        // RP.2A: 6 lanes, 9-12 crews, stages = Heat 1, Heat 2, Repechage 1, Repechage 2, Grand Final = 5 races
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 12);

        $result = $this->service->generate($event);

        $this->assertSame(5, $result->racesCreated);
        $this->assertSame(5, $result->racesPerDiscipline[$discipline->id]);
        $this->assertEmpty($result->warnings);

        $races = RaceResult::where('discipline_id', $discipline->id)->orderBy('id')->get();
        $this->assertSame(
            ['Heat 1', 'Heat 2', 'Repechage 1', 'Repechage 2', 'Grand Final'],
            $races->pluck('stage')->all()
        );
    }

    public function test_assigns_round1_lanes_per_idbf_heat_seeding_table(): void
    {
        // RP.2A Heat 1 lane seeding from PDF p10: [9, 5, 1, 4, 8, 12]
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 12);

        // Pre-set seeds 1..12 in registration order so we can map back.
        foreach ($discipline->crews()->orderBy('id')->get() as $i => $crew) {
            $crew->update(['seed_number' => $i + 1]);
        }

        $this->service->generate($event);

        $heat1 = RaceResult::where('discipline_id', $discipline->id)->where('stage', 'Heat 1')->firstOrFail();
        $laneToSeed = $heat1->crewResults()->get()->mapWithKeys(
            fn($cr) => [$cr->lane => $cr->crew->seed_number]
        )->all();

        $this->assertSame([1 => 9, 2 => 5, 3 => 1, 4 => 4, 5 => 8, 6 => 12], $laneToSeed);
    }

    public function test_later_stages_have_no_crew_results_until_lane_seeded(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 12);

        $this->service->generate($event);

        $final = RaceResult::where('discipline_id', $discipline->id)->where('stage', 'Grand Final')->firstOrFail();
        $this->assertSame(0, $final->crewResults()->count());
    }

    public function test_populates_seed_numbers_when_missing_deterministically(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 6);

        $this->assertNull($discipline->crews()->first()->seed_number);

        $this->service->generate($event);

        $seeds = $discipline->crews()->pluck('seed_number')->sort()->values()->all();
        $this->assertSame([1, 2, 3, 4, 5, 6], $seeds, 'seeds must be a 1..N permutation');

        // Re-running the generator with the same discipline.id seed produces the same shuffle.
        $firstAssignment = $discipline->crews()->orderBy('id')->pluck('seed_number')->all();
        $discipline->crews()->update(['seed_number' => null]);
        $this->service->generate($event->fresh());
        $secondAssignment = $discipline->fresh()->crews()->orderBy('id')->pluck('seed_number')->all();
        $this->assertSame($firstAssignment, $secondAssignment, 'seed shuffle must be deterministic per discipline');
    }

    public function test_preserves_pre_existing_seeds(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 6);

        $crews = $discipline->crews()->orderBy('id')->get();
        $crews[0]->update(['seed_number' => 1]);
        $crews[1]->update(['seed_number' => 6]);

        $this->service->generate($event);

        $this->assertSame(1, $crews[0]->fresh()->seed_number);
        $this->assertSame(6, $crews[1]->fresh()->seed_number);
    }

    public function test_assigns_race_times_within_block_window(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00', gapSeconds: 240);
        $discipline = $this->makeDiscipline($event, 6);  // ROUNDS_6L → 3 round races

        $this->service->generate($event);

        $times = RaceResult::where('discipline_id', $discipline->id)
            ->orderBy('race_time')
            ->pluck('race_time')
            ->map(fn($t) => $t->format('H:i:s'))
            ->all();

        $this->assertSame(['09:00:00', '09:04:00', '09:08:00'], $times);
    }

    public function test_assigns_sequential_race_numbers_chronologically(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $a = $this->makeDiscipline($event, 6, distance: '200m');
        $b = $this->makeDiscipline($event, 6, distance: '500m');

        $this->service->generate($event);

        $races = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->orderBy('race_time')
            ->get();
        $this->assertSame(range(1, 6), $races->pluck('race_number')->all());
    }

    public function test_replaces_existing_scheduled_races_on_regenerate(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 6);

        $this->service->generate($event);
        $firstIds = RaceResult::where('discipline_id', $discipline->id)->pluck('id')->all();

        $this->service->generate($event->fresh());
        $secondIds = RaceResult::where('discipline_id', $discipline->id)->pluck('id')->all();

        $this->assertEmpty(array_intersect($firstIds, $secondIds), 'old race rows should be deleted');
        $this->assertCount(3, $secondIds);
    }

    public function test_warns_and_skips_discipline_with_fewer_than_two_crews(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $this->makeDiscipline($event, 1);

        $result = $this->service->generate($event);

        $this->assertSame(0, $result->racesCreated);
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('at least 2 crews', $result->warnings[0]);
    }

    public function test_throws_when_lane_count_unsupported(): void
    {
        $event = $this->makeEvent(laneCount: 5);
        $this->addBlock($event, 'Morning', '09:00:00');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lane_count must be 4, 6, or 8');
        $this->service->generate($event);
    }

    public function test_throws_when_no_event_days(): void
    {
        $event = $this->makeEvent(laneCount: 6);  // no addBlock call

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no days configured');
        $this->service->generate($event);
    }

    public function test_warns_when_no_block_matches_discipline(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        // Block only matches Mixed gender; discipline below is Men's.
        $this->addBlock($event, 'Mixed only', '09:00:00', genderFilter: ['X']);
        $this->makeDiscipline($event, 6, gender: 'M');

        $result = $this->service->generate($event);

        $this->assertNotEmpty($result->warnings);
        $this->assertStringContainsString('No matching schedule block', $result->warnings[0]);
        $unplaced = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->whereNull('race_time')
            ->count();
        $this->assertSame(3, $unplaced); // ROUNDS_6L creates 3 races, all unplaced
    }

    public function test_uses_progression_override_when_set(): void
    {
        // 12 crews on 6 lanes auto-picks RP.2A. Override to RP.3A (also valid for 6 lanes 13-18) → should reject.
        // Better: override to a valid alternative if any exists; for 12 crews on 6 lanes, only RP.2A fits.
        // So instead: test that an override for an INCOMPATIBLE plan code surfaces a warning.
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 12);
        DisciplineProgression::create([
            'discipline_id' => $discipline->id,
            'race_plan_code' => 'RP.3A',  // RP.3A needs 13-18 crews; 12 doesn't fit
        ]);

        $result = $this->service->generate($event);

        $this->assertNotEmpty($result->warnings);
        $this->assertStringContainsString('RP.3A', $result->warnings[0]);
        $this->assertSame(0, $result->racesCreated);
    }

    public function test_regenerate_discipline_only_affects_that_discipline(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $a = $this->makeDiscipline($event, 6, distance: '200m');
        $b = $this->makeDiscipline($event, 6, distance: '500m');

        $this->service->generate($event);
        $bIdsBefore = RaceResult::where('discipline_id', $b->id)->pluck('id')->all();

        $this->service->regenerateDiscipline($a->fresh());

        $bIdsAfter = RaceResult::where('discipline_id', $b->id)->pluck('id')->all();
        $this->assertSame($bIdsBefore, $bIdsAfter, 'B discipline rows should be untouched');
    }

    public function test_regenerate_refuses_when_races_have_started(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 6);

        $this->service->generate($event);
        // Mark one race as IN_PROGRESS to simulate a started discipline.
        RaceResult::where('discipline_id', $discipline->id)->first()->update(['status' => 'IN_PROGRESS']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already started or finished');
        $this->service->regenerateDiscipline($discipline->fresh());
    }

    // ----- helpers -----

    private function makeEvent(int $laneCount): Event
    {
        return Event::create([
            'name' => 'Test Event',
            'location' => 'Lake',
            'year' => 2026,
            'lane_count' => $laneCount,
            'schedule_status' => 'draft',
        ]);
    }

    private function addBlock(
        Event $event,
        string $name,
        string $startTime,
        int $gapSeconds = 240,
        ?array $genderFilter = null,
        ?array $distanceFilter = null,
        ?array $stageFilter = null,
    ): ScheduleBlock {
        $day = EventDay::create([
            'event_id' => $event->id,
            'date' => '2026-06-12',
            'name' => 'Day 1',
            'sort_order' => 0,
        ]);
        return ScheduleBlock::create([
            'event_day_id' => $day->id,
            'name' => $name,
            'start_time' => $startTime,
            'gap_seconds' => $gapSeconds,
            'gender_filter' => $genderFilter,
            'distance_filter' => $distanceFilter,
            'stage_filter' => $stageFilter,
            'sort_order' => 0,
        ]);
    }

    private function makeDiscipline(Event $event, int $crewCount, string $gender = 'M', string $distance = '200m'): Discipline
    {
        $discipline = Discipline::create([
            'event_id' => $event->id,
            'distance' => $distance,
            'age_group' => 'Senior',
            'gender_group' => $gender,
            'boat_group' => 'Standard',
            'status' => 'active',
        ]);
        for ($i = 0; $i < $crewCount; $i++) {
            $team = Team::create(['name' => "Team {$gender}{$distance}#" . ($i + 1)]);
            Crew::create(['team_id' => $team->id, 'discipline_id' => $discipline->id]);
        }
        return $discipline;
    }
}
