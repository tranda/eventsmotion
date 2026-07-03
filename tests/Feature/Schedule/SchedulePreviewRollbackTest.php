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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Chunk-1 dry-run + rollback HTTP tests.
 *
 * Covers the ?dry_run=true flag on the generate endpoint and the
 * snapshot restore endpoint's IN_PROGRESS guardrail.
 */
class SchedulePreviewRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_generate_returns_preview_and_does_not_write(): void
    {
        [$user, $event] = $this->seed();
        Sanctum::actingAs($user);

        // First real generate to establish a baseline.
        $this->postJson("/api/events/{$event->id}/schedule/generate")->assertOk();
        $baselineRaceIds = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $snapshotCountBefore = ScheduleSnapshot::where('event_id', $event->id)->count();

        // Dry-run regenerate — must return a preview and NOT touch the DB.
        $response = $this->postJson("/api/events/{$event->id}/schedule/generate", ['dry_run' => true]);

        $response->assertOk();
        $response->assertJsonPath('data.preview.days.0.day', '2026-06-12');

        $afterRaceIds = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->orderBy('id')
            ->pluck('id')
            ->all();
        $this->assertSame($baselineRaceIds, $afterRaceIds, 'dry-run must not change any race row');
        $this->assertSame(
            $snapshotCountBefore,
            ScheduleSnapshot::where('event_id', $event->id)->count(),
            'dry-run rollback must undo the auto-snapshot row too',
        );
    }

    public function test_restore_refuses_when_race_in_progress(): void
    {
        [$user, $event, $discipline] = $this->seed();
        Sanctum::actingAs($user);

        // Generate + take a manual snapshot as the rollback target.
        $this->postJson("/api/events/{$event->id}/schedule/generate")->assertOk();
        $snapResp = $this->postJson("/api/events/{$event->id}/snapshots", [
            'category' => 'event_grid',
            'name' => 'pre-race checkpoint',
        ]);
        $snapResp->assertOk();
        $snapId = $snapResp->json('data.id');

        // Mark one race as IN_PROGRESS to simulate a running race.
        RaceResult::where('discipline_id', $discipline->id)->first()->update(['status' => 'IN_PROGRESS']);

        $response = $this->postJson("/api/snapshots/{$snapId}/restore");

        // The restore call surfaces the InvalidArgumentException as a 500
        // through the generic catch — we assert the payload contains the
        // guard's message so the frontend can render "cannot rollback:
        // race running".
        $response->assertStatus(500);
        $this->assertStringContainsString('IN_PROGRESS', $response->json('message') . ' ' . implode(' ', (array) $response->json('data', [])));
    }

    public function test_event_grid_snapshot_captures_all_days(): void
    {
        [$user, $event] = $this->seed();
        Sanctum::actingAs($user);

        // Add a second day so the event_grid payload has to loop.
        $day2 = EventDay::create([
            'event_id' => $event->id,
            'date' => '2026-06-13',
            'name' => 'Day 2',
            'sort_order' => 1,
        ]);
        ScheduleBlock::create([
            'event_day_id' => $day2->id,
            'name' => 'Afternoon',
            'start_time' => '14:00:00',
            'gap_seconds' => 240,
            'sort_order' => 0,
        ]);

        $snapResp = $this->postJson("/api/events/{$event->id}/snapshots", [
            'category' => 'event_grid',
            'name' => 'both days',
        ]);
        $snapResp->assertOk();

        $snap = ScheduleSnapshot::find($snapResp->json('data.id'));
        $days = $snap->payload['days'] ?? [];
        $this->assertCount(2, $days);
        $this->assertSame(['2026-06-12', '2026-06-13'], array_column($days, 'day'));
    }

    /** @return array{0: User, 1: Event, 2: Discipline} */
    private function seed(): array
    {
        $user = User::factory()->create(['access_level' => 3]);
        $event = Event::create([
            'name' => 'Preview Test Event',
            'location' => 'Lake',
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
        for ($i = 0; $i < 6; $i++) {
            $team = Team::create(['name' => "Team #" . ($i + 1)]);
            Crew::create(['team_id' => $team->id, 'discipline_id' => $discipline->id, 'seed_number' => $i + 1]);
        }
        return [$user, $event, $discipline];
    }
}
