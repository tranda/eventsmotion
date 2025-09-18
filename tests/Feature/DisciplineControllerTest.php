<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Event;
use App\Models\Discipline;
use App\Models\Crew;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class DisciplineControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $adminUser;
    protected $normalUser;
    protected $event;

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

        // Create a test event
        $this->event = Event::factory()->create([
            'name' => 'Test Event',
            'location' => 'Test Location',
            'year' => 2024,
            'status' => 'active'
        ]);
    }

    /** @test */
    public function admin_can_create_discipline()
    {
        Sanctum::actingAs($this->adminUser);

        $disciplineData = [
            'event_id' => $this->event->id,
            'distance' => '1000m',
            'age_group' => 'Senior',
            'gender_group' => 'Mixed',
            'boat_group' => 'K4',
            'status' => 'active'
        ];

        $response = $this->postJson('/api/discipline', $disciplineData);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Discipline created successfully.'
                ]);

        $this->assertDatabaseHas('disciplines', [
            'event_id' => $this->event->id,
            'distance' => '1000m',
            'age_group' => 'Senior',
            'gender_group' => 'Mixed',
            'boat_group' => 'K4',
            'status' => 'active'
        ]);
    }

    /** @test */
    public function normal_user_cannot_create_discipline()
    {
        Sanctum::actingAs($this->normalUser);

        $disciplineData = [
            'event_id' => $this->event->id,
            'distance' => '1000m',
            'age_group' => 'Senior',
            'gender_group' => 'Mixed',
            'boat_group' => 'K4'
        ];

        $response = $this->postJson('/api/discipline', $disciplineData);

        $response->assertStatus(403)
                ->assertJson([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ]);
    }

    /** @test */
    public function admin_can_get_single_discipline()
    {
        Sanctum::actingAs($this->adminUser);

        $discipline = Discipline::factory()->create([
            'event_id' => $this->event->id,
            'distance' => '500m',
            'age_group' => 'U23',
            'gender_group' => 'Women',
            'boat_group' => 'K2'
        ]);

        $response = $this->getJson("/api/discipline/{$discipline->id}");

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Discipline retrieved successfully.',
                    'data' => [
                        'id' => $discipline->id,
                        'distance' => '500m',
                        'age_group' => 'U23',
                        'gender_group' => 'Women',
                        'boat_group' => 'K2'
                    ]
                ]);
    }

    /** @test */
    public function admin_can_update_discipline()
    {
        Sanctum::actingAs($this->adminUser);

        $discipline = Discipline::factory()->create([
            'event_id' => $this->event->id,
            'distance' => '200m',
            'age_group' => 'Junior',
            'gender_group' => 'Men',
            'boat_group' => 'K1'
        ]);

        $updateData = [
            'distance' => '1000m',
            'age_group' => 'Senior',
            'status' => 'inactive'
        ];

        $response = $this->putJson("/api/discipline/{$discipline->id}", $updateData);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Discipline updated successfully.'
                ]);

        $this->assertDatabaseHas('disciplines', [
            'id' => $discipline->id,
            'distance' => '1000m',
            'age_group' => 'Senior',
            'status' => 'inactive',
            'gender_group' => 'Men', // Should remain unchanged
            'boat_group' => 'K1' // Should remain unchanged
        ]);
    }

    /** @test */
    public function admin_can_delete_discipline_without_crews()
    {
        Sanctum::actingAs($this->adminUser);

        $discipline = Discipline::factory()->create([
            'event_id' => $this->event->id
        ]);

        $response = $this->deleteJson("/api/discipline/{$discipline->id}");

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Discipline deleted successfully.'
                ]);

        $this->assertDatabaseMissing('disciplines', ['id' => $discipline->id]);
    }

    /** @test */
    public function admin_cannot_delete_discipline_with_crews()
    {
        Sanctum::actingAs($this->adminUser);

        $discipline = Discipline::factory()->create([
            'event_id' => $this->event->id
        ]);

        // Create a crew for this discipline
        Crew::factory()->create([
            'discipline_id' => $discipline->id
        ]);

        $response = $this->deleteJson("/api/discipline/{$discipline->id}");

        $response->assertStatus(409)
                ->assertJson([
                    'success' => false,
                    'message' => 'Cannot delete discipline.'
                ]);

        $this->assertDatabaseHas('disciplines', ['id' => $discipline->id]);
    }

    /** @test */
    public function discipline_creation_requires_valid_data()
    {
        Sanctum::actingAs($this->adminUser);

        $invalidData = [
            'event_id' => 999999, // Non-existent event
            'distance' => '', // Required field empty
            'age_group' => '',
            'gender_group' => '',
            'boat_group' => ''
        ];

        $response = $this->postJson('/api/discipline', $invalidData);

        $response->assertStatus(422); // Validation error
    }

    /** @test */
    public function get_discipline_returns_404_for_nonexistent_discipline()
    {
        Sanctum::actingAs($this->adminUser);

        $response = $this->getJson('/api/discipline/999999');

        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Discipline not found.'
                ]);
    }

    /** @test */
    public function unauthenticated_user_cannot_access_admin_routes()
    {
        $discipline = Discipline::factory()->create([
            'event_id' => $this->event->id
        ]);

        $routes = [
            ['method' => 'get', 'url' => "/api/discipline/{$discipline->id}"],
            ['method' => 'post', 'url' => '/api/discipline'],
            ['method' => 'put', 'url' => "/api/discipline/{$discipline->id}"],
            ['method' => 'delete', 'url' => "/api/discipline/{$discipline->id}"]
        ];

        foreach ($routes as $route) {
            $response = $this->{$route['method'] . 'Json'}($route['url'], []);
            $response->assertStatus(401);
        }
    }
}