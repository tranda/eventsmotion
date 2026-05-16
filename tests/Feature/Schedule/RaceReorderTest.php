<?php

namespace Tests\Feature\Schedule;

use App\Models\Crew;
use App\Models\Discipline;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\RaceResult;
use App\Models\ScheduleBlock;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for POST /api/race-results/reorder.
 *
 * The endpoint accepts a batch of (race_id, race_time) pairs, applies
 * them atomically, and renumbers the event's races chronologically.
 * Intended use: drag-reorder in the Grid tab.
 */
class RaceReorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reorder_endpoint_updates_race_times_and_renumbers(): void
    {
        [$user, $races] = $this->seedThreeScheduledRaces();
        Sanctum::actingAs($user);

        // races initially at 09:00, 09:15, 09:30 (race_number 1, 2, 3).
        // Permute to put race[2] first, race[0] second, race[1] third.
        $updates = [
            ['race_id' => $races[2]->id, 'race_time' => '2026-06-07 09:00:00'],
            ['race_id' => $races[0]->id, 'race_time' => '2026-06-07 09:15:00'],
            ['race_id' => $races[1]->id, 'race_time' => '2026-06-07 09:30:00'],
        ];

        $response = $this->postJson('/api/race-results/reorder', ['updates' => $updates]);

        $response->assertOk();

        $fresh = RaceResult::orderBy('race_time')->get();
        $this->assertSame(
            [$races[2]->id, $races[0]->id, $races[1]->id],
            $fresh->pluck('id')->all(),
            'race_time values should be permuted to match new order'
        );
        $this->assertSame([1, 2, 3], $fresh->pluck('race_number')->all(),
            'race_number should be re-run chronologically');
    }

    public function test_reorder_endpoint_rejects_non_scheduled_race(): void
    {
        [$user, $races] = $this->seedThreeScheduledRaces();
        $races[1]->update(['status' => 'IN_PROGRESS']);
        Sanctum::actingAs($user);

        $updates = [
            ['race_id' => $races[1]->id, 'race_time' => '2026-06-07 09:00:00'],
            ['race_id' => $races[0]->id, 'race_time' => '2026-06-07 09:15:00'],
        ];

        $response = $this->postJson('/api/race-results/reorder', ['updates' => $updates]);

        $response->assertStatus(422);
        // Times must NOT have been written.
        $this->assertSame('SCHEDULED', $races[0]->fresh()->status);
        $this->assertNotEquals('2026-06-07 09:15:00', $races[0]->fresh()->race_time?->format('Y-m-d H:i:s'));
    }

    public function test_reorder_endpoint_rejects_empty_updates(): void
    {
        [$user] = $this->seedThreeScheduledRaces();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/race-results/reorder', ['updates' => []]);

        $response->assertStatus(422);
    }

    public function test_reorder_endpoint_requires_auth(): void
    {
        [, $races] = $this->seedThreeScheduledRaces();

        $response = $this->postJson('/api/race-results/reorder', [
            'updates' => [
                ['race_id' => $races[0]->id, 'race_time' => '2026-06-07 09:00:00'],
            ],
        ]);

        $response->assertStatus(401);
    }

    /**
     * Matches the fixture style in ScheduleGeneratorServiceTest: plain Model::create
     * rather than factories. User uses the standard factory (admin level).
     *
     * @return array{0: User, 1: array<int, RaceResult>}
     */
    private function seedThreeScheduledRaces(): array
    {
        $user = User::factory()->create(['access_level' => 3]);

        $event = Event::create([
            'name' => 'Test Event',
            'location' => 'Lake',
            'year' => 2026,
            'lane_count' => 6,
            'schedule_status' => 'draft',
        ]);
        $day = EventDay::create([
            'event_id' => $event->id,
            'date' => '2026-06-07',
            'name' => 'Day 1',
            'sort_order' => 0,
        ]);
        ScheduleBlock::create([
            'event_day_id' => $day->id,
            'name' => 'Morning',
            'start_time' => '09:00:00',
            'gap_seconds' => 900,
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
            $team = Team::create(['name' => 'Team ' . ($i + 1)]);
            Crew::create([
                'team_id' => $team->id,
                'discipline_id' => $discipline->id,
                'seed_number' => $i + 1,
            ]);
        }

        $races = [];
        foreach (['09:00:00', '09:15:00', '09:30:00'] as $i => $time) {
            $races[] = RaceResult::create([
                'race_number' => $i + 1,
                'discipline_id' => $discipline->id,
                'race_time' => "2026-06-07 $time",
                'stage' => 'Heat ' . ($i + 1),
                'status' => 'SCHEDULED',
            ]);
        }

        return [$user, $races];
    }
}
