<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Discipline;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DisciplineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that getDisplayName method works correctly
     */
    public function test_get_display_name_generates_correct_format()
    {
        // Create a test event
        $event = Event::create([
            'name' => 'Test Event',
            'start_date' => now()->addDays(30),
            'end_date' => now()->addDays(32),
            'location' => 'Test Location',
            'description' => 'Test Description'
        ]);

        // Test Men's K1 500m
        $discipline = Discipline::create([
            'event_id' => $event->id,
            'distance' => '500m',
            'age_group' => 'Senior',
            'gender_group' => 'M',
            'boat_group' => 'K1',
            'status' => 'active'
        ]);

        $this->assertEquals("Men's K1 500m", $discipline->getDisplayName());

        // Test Women's C2 1000m U23
        $discipline2 = Discipline::create([
            'event_id' => $event->id,
            'distance' => '1000m',
            'age_group' => 'U23',
            'gender_group' => 'W',
            'boat_group' => 'C2',
            'status' => 'active'
        ]);

        $this->assertEquals("Women's C2 1000m U23", $discipline2->getDisplayName());

        // Test Mixed K2 500m Masters
        $discipline3 = Discipline::create([
            'event_id' => $event->id,
            'distance' => '500m',
            'age_group' => 'Masters',
            'gender_group' => 'X',
            'boat_group' => 'K2',
            'status' => 'active'
        ]);

        $this->assertEquals("Mixed K2 500m Masters", $discipline3->getDisplayName());
    }

    /**
     * Test discipline relationships
     */
    public function test_discipline_belongs_to_event()
    {
        $event = Event::create([
            'name' => 'Test Event',
            'start_date' => now()->addDays(30),
            'end_date' => now()->addDays(32),
            'location' => 'Test Location',
            'description' => 'Test Description'
        ]);

        $discipline = Discipline::create([
            'event_id' => $event->id,
            'distance' => '500m',
            'age_group' => 'Senior',
            'gender_group' => 'M',
            'boat_group' => 'K1',
            'status' => 'active'
        ]);

        $this->assertInstanceOf(Event::class, $discipline->event);
        $this->assertEquals($event->id, $discipline->event->id);
    }

    /**
     * Test that getDisplayName handles missing values gracefully
     */
    public function test_get_display_name_handles_null_values()
    {
        $event = Event::create([
            'name' => 'Test Event',
            'start_date' => now()->addDays(30),
            'end_date' => now()->addDays(32),
            'location' => 'Test Location',
            'description' => 'Test Description'
        ]);

        // Test with minimal data
        $discipline = Discipline::create([
            'event_id' => $event->id,
            'distance' => '500m',
            'status' => 'active'
        ]);

        $this->assertEquals("500m", $discipline->getDisplayName());
    }
}