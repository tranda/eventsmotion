<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class EventControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $normalUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create an admin user (access_level >= 3)
        $this->adminUser = User::factory()->create([
            'access_level' => 3,
            'name' => 'Admin User',
            'email' => 'admin@test.com'
        ]);

        // Create a normal user (access_level < 3)
        $this->normalUser = User::factory()->create([
            'access_level' => 1,
            'name' => 'Normal User',
            'email' => 'user@test.com'
        ]);
    }

    /** @test */
    public function admin_can_create_event()
    {
        Sanctum::actingAs($this->adminUser);

        $eventData = [
            'name' => 'EuroCup 2024',
            'location' => 'Belgrade, Serbia',
            'year' => 2024,
            'status' => 'active'
        ];

        $response = $this->postJson('/api/event', $eventData);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Event created successfully.'
                ]);

        $this->assertDatabaseHas('events', [
            'name' => 'EuroCup 2024',
            'location' => 'Belgrade, Serbia',
            'year' => 2024,
            'status' => 'active'
        ]);
    }

    /** @test */
    public function normal_user_cannot_create_event()
    {
        Sanctum::actingAs($this->normalUser);

        $eventData = [
            'name' => 'EuroCup 2024',
            'location' => 'Belgrade, Serbia',
            'year' => 2024,
        ];

        $response = $this->postJson('/api/event', $eventData);

        $response->assertStatus(403)
                ->assertJson([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ]);
    }

    /** @test */
    public function admin_can_get_single_event()
    {
        Sanctum::actingAs($this->adminUser);

        $event = Event::factory()->create([
            'name' => 'Test Event',
            'location' => 'Test Location',
            'year' => 2024
        ]);

        $response = $this->getJson("/api/event/{$event->id}");

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Event retrieved successfully.',
                    'data' => [
                        'id' => $event->id,
                        'name' => 'Test Event',
                        'location' => 'Test Location',
                        'year' => 2024
                    ]
                ]);
    }

    /** @test */
    public function admin_can_update_event()
    {
        Sanctum::actingAs($this->adminUser);

        $event = Event::factory()->create([
            'name' => 'Original Event',
            'location' => 'Original Location',
            'year' => 2024
        ]);

        $updateData = [
            'name' => 'Updated Event',
            'location' => 'Updated Location',
            'status' => 'inactive'
        ];

        $response = $this->putJson("/api/event/{$event->id}", $updateData);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Event updated successfully.'
                ]);

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'name' => 'Updated Event',
            'location' => 'Updated Location',
            'status' => 'inactive',
            'year' => 2024 // Should remain unchanged
        ]);
    }

    /** @test */
    public function admin_can_delete_event_without_disciplines()
    {
        Sanctum::actingAs($this->adminUser);

        $event = Event::factory()->create();

        $response = $this->deleteJson("/api/event/{$event->id}");

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Event deleted successfully.'
                ]);

        $this->assertSoftDeleted('events', ['id' => $event->id]);
    }

    /** @test */
    public function unauthenticated_user_cannot_access_admin_routes()
    {
        $event = Event::factory()->create();

        $routes = [
            ['method' => 'get', 'url' => "/api/event/{$event->id}"],
            ['method' => 'post', 'url' => '/api/event'],
            ['method' => 'put', 'url' => "/api/event/{$event->id}"],
            ['method' => 'delete', 'url' => "/api/event/{$event->id}"]
        ];

        foreach ($routes as $route) {
            $response = $this->{$route['method'] . 'Json'}($route['url'], []);
            $response->assertStatus(401);
        }
    }

    /** @test */
    public function event_creation_requires_valid_data()
    {
        Sanctum::actingAs($this->adminUser);

        $invalidData = [
            'name' => '', // Required field empty
            'location' => '',
            'year' => 1999 // Invalid year
        ];

        $response = $this->postJson('/api/event', $invalidData);

        $response->assertStatus(422); // Validation error
    }

    /** @test */
    public function get_event_returns_404_for_nonexistent_event()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/event/999999');

        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Event not found.'
                ]);
    }
}