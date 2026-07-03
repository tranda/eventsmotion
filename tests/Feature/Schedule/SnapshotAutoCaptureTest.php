<?php

namespace Tests\Feature\Schedule;

use App\Models\Crew;
use App\Models\Discipline;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\RaceResult;
use App\Models\ScheduleBlock;
use App\Models\ScheduleSnapshot;
use App\Models\Team;
use App\Services\Schedule\IdbfRacePlans;
use App\Services\Schedule\ScheduleGeneratorService;
use App\Services\Schedule\ScheduleSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Chunk-1 safety-net tests for the hull-aware scheduling work.
 *
 * Covers the auto-snapshot + retention + IN_PROGRESS guardrail added to
 * ScheduleGeneratorService. Dry-run and rollback endpoint behaviour is
 * tested in SchedulePreviewRollbackTest.
 */
class SnapshotAutoCaptureTest extends TestCase
{
    use RefreshDatabase;

    private ScheduleGeneratorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScheduleGeneratorService(new IdbfRacePlans(), new ScheduleSnapshotService());
    }

    public function test_generate_creates_one_auto_snapshot(): void
    {
        [$event] = $this->seed();

        $this->service->generate($event);

        $snaps = ScheduleSnapshot::where('event_id', $event->id)->get();
        $this->assertCount(1, $snaps);
        $this->assertSame('event_grid', $snaps[0]->category);
        $this->assertStringStartsWith('auto: regenerate', $snaps[0]->name);
    }

    public function test_regenerate_discipline_creates_one_auto_snapshot(): void
    {
        [$event, $discipline] = $this->seed();
        $this->service->generate($event);
        ScheduleSnapshot::query()->delete();  // clear the generate() snapshot

        $this->service->regenerateDiscipline($discipline->fresh());

        $snaps = ScheduleSnapshot::where('event_id', $event->id)->get();
        $this->assertCount(1, $snaps);
        $this->assertStringStartsWith('auto: regenerate discipline', $snaps[0]->name);
    }

    public function test_recompute_creates_one_auto_snapshot(): void
    {
        [$event] = $this->seed();
        $this->service->generate($event);
        ScheduleSnapshot::query()->delete();

        $this->service->recomputeAllBlockTimes($event->fresh());

        $snaps = ScheduleSnapshot::where('event_id', $event->id)->get();
        $this->assertCount(1, $snaps);
        $this->assertStringStartsWith('auto: recompute', $snaps[0]->name);
    }

    public function test_retention_keeps_last_ten_auto_snapshots(): void
    {
        [$event] = $this->seed();

        // 12 successive regenerates → 12 snapshot rows created, 2 pruned.
        for ($i = 0; $i < 12; $i++) {
            $this->service->generate($event->fresh());
        }

        $count = ScheduleSnapshot::where('event_id', $event->id)->count();
        $this->assertSame(10, $count, 'retention pass must keep only the most recent 10 auto snapshots');
    }

    public function test_manual_snapshots_are_never_pruned(): void
    {
        [$event] = $this->seed();

        // Insert 5 manual (non-auto:) snapshots directly.
        for ($i = 0; $i < 5; $i++) {
            ScheduleSnapshot::create([
                'event_id' => $event->id,
                'category' => 'event_grid',
                'name' => "manual snapshot #{$i}",
                'payload' => ['days' => []],
            ]);
        }

        // Then 12 auto snapshots via regenerate.
        for ($i = 0; $i < 12; $i++) {
            $this->service->generate($event->fresh());
        }

        $auto = ScheduleSnapshot::where('event_id', $event->id)->where('name', 'like', 'auto:%')->count();
        $manual = ScheduleSnapshot::where('event_id', $event->id)->where('name', 'not like', 'auto:%')->count();

        $this->assertSame(10, $auto, 'only auto: snapshots are subject to retention');
        $this->assertSame(5, $manual, 'manual snapshots must survive the retention pass');
    }

    public function test_in_progress_race_blocks_full_event_regenerate(): void
    {
        [$event, $discipline] = $this->seed();
        $this->service->generate($event);
        RaceResult::where('discipline_id', $discipline->id)->first()->update(['status' => 'IN_PROGRESS']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IN_PROGRESS');
        $this->service->generate($event->fresh());
    }

    public function test_in_progress_race_blocks_recompute(): void
    {
        [$event, $discipline] = $this->seed();
        $this->service->generate($event);
        RaceResult::where('discipline_id', $discipline->id)->first()->update(['status' => 'IN_PROGRESS']);

        // recomputeAllBlockTimes does not go through the guard directly —
        // it operates on SCHEDULED rows only, so IN_PROGRESS races are
        // naturally invisible to it. This test asserts that behaviour
        // holds: the recompute doesn't throw, and the IN_PROGRESS row
        // stays put.
        $inProgress = RaceResult::where('status', 'IN_PROGRESS')->first();
        $timeBefore = (string) $inProgress->race_time;

        $this->service->recomputeAllBlockTimes($event->fresh());

        $timeAfter = (string) RaceResult::find($inProgress->id)->race_time;
        $this->assertSame($timeBefore, $timeAfter, 'IN_PROGRESS races must not be moved by recompute');
    }

    public function test_generate_does_not_snapshot_when_event_has_no_days(): void
    {
        $event = $this->makeEvent(6);
        // No days added.

        try {
            $this->service->generate($event);
        } catch (InvalidArgumentException $e) {
            // expected — no days configured
        }

        $this->assertSame(0, ScheduleSnapshot::where('event_id', $event->id)->count());
    }

    // ----- helpers -----

    /** @return array{Event, Discipline} */
    private function seed(): array
    {
        $event = $this->makeEvent(6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 6);
        return [$event, $discipline];
    }

    private function makeEvent(int $laneCount): Event
    {
        return Event::create([
            'name' => 'Snapshot Test Event',
            'location' => 'Lake',
            'year' => 2026,
            'lane_count' => $laneCount,
            'schedule_status' => 'draft',
        ]);
    }

    private function addBlock(Event $event, string $name, string $startTime): ScheduleBlock
    {
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
            'gap_seconds' => 240,
            'sort_order' => 0,
        ]);
    }

    private function makeDiscipline(Event $event, int $crewCount): Discipline
    {
        $discipline = Discipline::create([
            'event_id' => $event->id,
            'distance' => '200m',
            'age_group' => 'Senior',
            'gender_group' => 'M',
            'boat_group' => 'Standard',
            'status' => 'active',
        ]);
        for ($i = 0; $i < $crewCount; $i++) {
            $team = Team::create(['name' => "Team #" . ($i + 1)]);
            Crew::create(['team_id' => $team->id, 'discipline_id' => $discipline->id]);
        }
        return $discipline;
    }
}
