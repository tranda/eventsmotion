<?php

namespace Tests\Unit\Services\Schedule;

use App\Models\Event;
use App\Services\Schedule\FleetConfig;
use PHPUnit\Framework\TestCase;

class FleetConfigTest extends TestCase
{
    public function test_parses_clean_comma_list(): void
    {
        $event = new Event(['hulls_small' => 'D,E,F', 'hulls_standard' => 'A,B,C']);
        $fc = FleetConfig::fromEvent($event);

        $this->assertSame(['D', 'E', 'F'], $fc->small());
        $this->assertSame(['A', 'B', 'C'], $fc->standard());
        $this->assertTrue($fc->isConfigured());
    }

    public function test_trims_and_uppercases(): void
    {
        $event = new Event(['hulls_small' => ' d , e , f ']);
        $this->assertSame(['D', 'E', 'F'], FleetConfig::fromEvent($event)->small());
    }

    public function test_dedupes_preserving_first_occurrence(): void
    {
        $event = new Event(['hulls_small' => 'D,E,D,F,e']);
        $this->assertSame(['D', 'E', 'F'], FleetConfig::fromEvent($event)->small());
    }

    public function test_empty_string_produces_empty_fleet(): void
    {
        $event = new Event(['hulls_small' => '', 'hulls_standard' => null]);
        $fc = FleetConfig::fromEvent($event);
        $this->assertSame([], $fc->small());
        $this->assertSame([], $fc->standard());
        $this->assertFalse($fc->isConfigured());
    }

    public function test_fleet_for_matches_case_insensitively(): void
    {
        $event = new Event(['hulls_small' => 'D,E,F', 'hulls_standard' => 'A,B,C']);
        $fc = FleetConfig::fromEvent($event);

        $this->assertSame(['D', 'E', 'F'], $fc->fleetFor('Small'));
        $this->assertSame(['D', 'E', 'F'], $fc->fleetFor('SMALL'));
        $this->assertSame(['D', 'E', 'F'], $fc->fleetFor('small'));
        $this->assertSame(['A', 'B', 'C'], $fc->fleetFor('Standard'));
    }

    public function test_fleet_for_returns_empty_on_unmapped(): void
    {
        $event = new Event(['hulls_small' => 'D,E,F', 'hulls_standard' => 'A,B,C']);
        $fc = FleetConfig::fromEvent($event);

        $this->assertSame([], $fc->fleetFor('K1'));
        $this->assertSame([], $fc->fleetFor(''));
        $this->assertSame([], $fc->fleetFor(null));
    }

    public function test_is_configured_when_only_one_fleet_set(): void
    {
        $event = new Event(['hulls_small' => 'D,E,F', 'hulls_standard' => null]);
        $this->assertTrue(FleetConfig::fromEvent($event)->isConfigured());
    }
}
