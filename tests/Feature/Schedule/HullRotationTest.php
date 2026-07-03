<?php

namespace Tests\Feature\Schedule;

use App\Models\Crew;
use App\Models\Discipline;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\RaceResult;
use App\Models\ScheduleBlock;
use App\Models\Team;
use App\Services\Schedule\IdbfRacePlans;
use App\Services\Schedule\ScheduleGeneratorService;
use App\Services\Schedule\ScheduleSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chunk-2 hull rotation + waves ordering integration tests.
 *
 * Uses ROUNDS_6L (6-lane, 3-round) disciplines throughout because they
 * produce a clean, predictable stage vocabulary (Round 1 / 2 / 3) and
 * one race per stage.
 */
class HullRotationTest extends TestCase
{
    use RefreshDatabase;

    private ScheduleGeneratorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScheduleGeneratorService(new IdbfRacePlans(), new ScheduleSnapshotService());
    }

    public function test_all_races_hull_is_null_when_no_fleet_configured(): void
    {
        $event = $this->makeEvent();  // hulls_small = null, hulls_standard = null
        $this->addBlock($event, '09:00:00', 240);
        $this->makeDiscipline($event, 6, 'Small', distance: '200m');

        $this->service->generate($event);

        $hulls = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->pluck('hull')
            ->all();
        $this->assertSame([null, null, null], $hulls);
    }

    public function test_hull_rotation_over_two_small_disciplines(): void
    {
        // 2 small disciplines × 3 rounds = 6 races in one block. Fleet = 3 hulls.
        // Expect a clean D,E,F,D,E,F rotation and a turnaround ≥ 3×gap between
        // any two uses of the same hull letter.
        $event = $this->makeEvent(['hulls_small' => 'D,E,F']);
        $this->addBlock($event, '09:00:00', 240);
        $this->makeDiscipline($event, 6, 'Small', distance: '200m');
        $this->makeDiscipline($event, 6, 'Small', distance: '500m');

        $this->service->generate($event);

        $races = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->orderBy('race_time')
            ->get();

        $letters = $races->pluck('hull')->all();
        $this->assertSame(['D', 'E', 'F', 'D', 'E', 'F'], $letters);

        // Turnaround check: same letter appears at slot N and N+3, gap = 240s.
        $times = $races->pluck('race_time')->map(fn($t) => \Carbon\Carbon::parse($t))->all();
        $this->assertGreaterThanOrEqual(3 * 240, $times[3]->diffInSeconds($times[0]));
    }

    public function test_small_and_standard_interleave_on_shared_block_cursor(): void
    {
        // One small, one standard, both with 3 rounds. Cursor is per-block
        // shared, so the sequence alternates between fleets and each race
        // gets its own hull letter.
        $event = $this->makeEvent(['hulls_small' => 'D,E,F', 'hulls_standard' => 'A,B,C']);
        $this->addBlock($event, '09:00:00', 240);
        $this->makeDiscipline($event, 6, 'Small', distance: '200m');
        $this->makeDiscipline($event, 6, 'Standard', distance: '500m');

        $this->service->generate($event);

        $races = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->orderBy('race_time')
            ->orderBy('id')
            ->get()
            ->groupBy(function ($r) {
                return optional($r->discipline)->distance;
            });

        // Small discipline uses only small-fleet letters.
        $smallLetters = $races['200m']->pluck('hull')->all();
        foreach ($smallLetters as $l) {
            $this->assertContains($l, ['D', 'E', 'F'], "small race got '$l' outside small fleet");
        }
        // Standard discipline uses only standard-fleet letters.
        $stdLetters = $races['500m']->pluck('hull')->all();
        foreach ($stdLetters as $l) {
            $this->assertContains($l, ['A', 'B', 'C'], "standard race got '$l' outside standard fleet");
        }
    }

    public function test_unmapped_boat_group_produces_null_hull_and_warning(): void
    {
        $event = $this->makeEvent(['hulls_small' => 'D,E,F']);
        $this->addBlock($event, '09:00:00', 240);
        $this->makeDiscipline($event, 6, 'K1', distance: '200m');  // unmapped boat_group

        $result = $this->service->generate($event);

        $hulls = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->pluck('hull')
            ->all();
        $this->assertSame([null, null, null], $hulls);

        $this->assertNotEmpty($result->warnings);
        $matched = collect($result->warnings)->contains(fn($w) => str_contains($w, "'K1'"));
        $this->assertTrue($matched, 'expected a warning naming the unmapped boat group');
    }

    public function test_waves_ordering_places_all_round1_before_round2(): void
    {
        // Three disciplines, ROUNDS_6L → each has R1, R2, R3. Waves ordering
        // must produce: R1a R1b R1c R2a R2b R2c R3a R3b R3c — everyone runs
        // Round 1 before anyone starts Round 2.
        $event = $this->makeEvent();
        $this->addBlock($event, '09:00:00', 240);
        $this->makeDiscipline($event, 6, 'Small', distance: '200m');
        $this->makeDiscipline($event, 6, 'Small', distance: '500m');
        $this->makeDiscipline($event, 6, 'Small', distance: '1000m');

        $this->service->generate($event);

        $stages = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->orderBy('race_time')
            ->orderBy('id')
            ->pluck('stage')
            ->all();

        // Slots 0..2 must all be Round 1 (across the three disciplines),
        // slots 3..5 all Round 2, slots 6..8 all Round 3.
        $this->assertSame(['Round 1', 'Round 1', 'Round 1'], array_slice($stages, 0, 3));
        $this->assertSame(['Round 2', 'Round 2', 'Round 2'], array_slice($stages, 3, 3));
        $this->assertSame(['Round 3', 'Round 3', 'Round 3'], array_slice($stages, 6, 3));
    }

    // ----- helpers -----

    private function makeEvent(array $extra = []): Event
    {
        return Event::create(array_merge([
            'name' => 'Hull Test Event',
            'location' => 'Lake',
            'year' => 2026,
            'lane_count' => 6,
            'schedule_status' => 'draft',
        ], $extra));
    }

    private function addBlock(Event $event, string $startTime, int $gap): ScheduleBlock
    {
        $day = EventDay::create([
            'event_id' => $event->id,
            'date' => '2026-06-12',
            'name' => 'Day 1',
            'sort_order' => 0,
        ]);
        return ScheduleBlock::create([
            'event_day_id' => $day->id,
            'name' => 'Morning',
            'start_time' => $startTime,
            'gap_seconds' => $gap,
            'sort_order' => 0,
        ]);
    }

    private function makeDiscipline(Event $event, int $crewCount, string $boatGroup, string $distance): Discipline
    {
        $discipline = Discipline::create([
            'event_id' => $event->id,
            'distance' => $distance,
            'age_group' => 'Senior',
            'gender_group' => 'M',
            'boat_group' => $boatGroup,
            'status' => 'active',
        ]);
        for ($i = 0; $i < $crewCount; $i++) {
            $team = Team::create(['name' => "Team {$boatGroup}{$distance}#" . ($i + 1)]);
            Crew::create([
                'team_id' => $team->id,
                'discipline_id' => $discipline->id,
                'seed_number' => $i + 1,
            ]);
        }
        return $discipline;
    }
}
