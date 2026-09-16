<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Discipline;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the event edit lock: club users cannot change data on events that
 * are inactive or past their correction grace window; admins always can.
 */
class EventEditLockTest extends TestCase
{
    use RefreshDatabase;

    protected User $adminUser;
    protected User $clubUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = User::factory()->create(['access_level' => 3]);
        $this->clubUser = User::factory()->create(['access_level' => 1]);
    }

    /**
     * Build an event + discipline + team graph. $lastDay is a date string for
     * the event's single day, or null for an event with no days set.
     */
    private function makeGraph(string $status, ?string $lastDay = null): array
    {
        $event = Event::factory()->create(['status' => $status]);

        if ($lastDay !== null) {
            EventDay::create([
                'event_id' => $event->id,
                'date' => $lastDay,
                'name' => 'Day 1',
                'sort_order' => 1,
            ]);
        }

        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        $club = Club::create(['name' => 'Test Club', 'country' => 'SRB', 'active' => 1]);
        $team = Team::create(['name' => 'Test Team', 'club_id' => $club->id]);

        return [$event, $discipline, $team];
    }

    private function register(Team $team, Discipline $discipline)
    {
        return $this->postJson('/api/registercrew', [
            'team_id' => $team->id,
            'discipline_id' => $discipline->id,
        ]);
    }

    /** @test */
    public function club_user_can_register_for_active_event_with_no_days()
    {
        [, $discipline, $team] = $this->makeGraph('active');
        Sanctum::actingAs($this->clubUser);

        $this->register($team, $discipline)->assertStatus(201);
    }

    /** @test */
    public function club_user_cannot_register_for_inactive_event()
    {
        [, $discipline, $team] = $this->makeGraph('inactive');
        Sanctum::actingAs($this->clubUser);

        $this->register($team, $discipline)->assertStatus(403);
        $this->assertDatabaseMissing('crews', ['discipline_id' => $discipline->id]);
    }

    /** @test */
    public function admin_can_register_for_inactive_event()
    {
        [, $discipline, $team] = $this->makeGraph('inactive');
        Sanctum::actingAs($this->adminUser);

        $this->register($team, $discipline)->assertStatus(201);
    }

    /** @test */
    public function club_user_can_register_within_grace_window()
    {
        // Event ended 3 days ago — still inside the 14-day correction window.
        [, $discipline, $team] = $this->makeGraph('active', Carbon::now()->subDays(3)->toDateString());
        Sanctum::actingAs($this->clubUser);

        $this->register($team, $discipline)->assertStatus(201);
    }

    /** @test */
    public function club_user_cannot_register_past_grace_window()
    {
        // Event ended 20 days ago — past the 14-day window.
        [, $discipline, $team] = $this->makeGraph('active', Carbon::now()->subDays(20)->toDateString());
        Sanctum::actingAs($this->clubUser);

        $this->register($team, $discipline)->assertStatus(403);
        $this->assertDatabaseMissing('crews', ['discipline_id' => $discipline->id]);
    }

    /** @test */
    public function admin_can_register_past_grace_window()
    {
        [, $discipline, $team] = $this->makeGraph('active', Carbon::now()->subDays(20)->toDateString());
        Sanctum::actingAs($this->adminUser);

        $this->register($team, $discipline)->assertStatus(201);
    }

    /** @test */
    public function event_is_locked_reflects_status_and_grace_window()
    {
        $active = Event::factory()->create(['status' => 'active']);
        $this->assertFalse($active->isLocked());

        $inactive = Event::factory()->create(['status' => 'inactive']);
        $this->assertTrue($inactive->isLocked());

        $withinGrace = Event::factory()->create(['status' => 'active']);
        EventDay::create([
            'event_id' => $withinGrace->id,
            'date' => Carbon::now()->subDays(3)->toDateString(),
            'name' => 'Day 1',
            'sort_order' => 1,
        ]);
        $this->assertFalse($withinGrace->isLocked());

        $pastGrace = Event::factory()->create(['status' => 'active']);
        EventDay::create([
            'event_id' => $pastGrace->id,
            'date' => Carbon::now()->subDays(20)->toDateString(),
            'name' => 'Day 1',
            'sort_order' => 1,
        ]);
        $this->assertTrue($pastGrace->isLocked());
    }
}
