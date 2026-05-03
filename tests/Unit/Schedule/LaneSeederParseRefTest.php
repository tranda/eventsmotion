<?php

namespace Tests\Unit\Schedule;

use App\Services\Schedule\IdbfRacePlans;
use App\Services\Schedule\LaneSeeder;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit test for LaneSeeder's position-reference parser. Runs without
 * Laravel boot since parseRef has no dependencies.
 *
 * Verifies tolerance of the typo variants seen in the IDBF PDF (rsp/rps).
 */
class LaneSeederParseRefTest extends TestCase
{
    private LaneSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();
        $config = require __DIR__ . '/../../../config/idbf_race_plans.php';
        $this->seeder = new LaneSeeder(new IdbfRacePlans($config));
    }

    /** @dataProvider parseRefCases */
    public function test_parses_position_reference(string $input, ?array $expected): void
    {
        $this->assertSame($expected, $this->seeder->parseRef($input));
    }

    public static function parseRefCases(): array
    {
        return [
            ['1st in hts',  ['source' => 'hts',  'position' => 1]],
            ['2nd in hts',  ['source' => 'hts',  'position' => 2]],
            ['3rd in hts',  ['source' => 'hts',  'position' => 3]],
            ['12th in hts', ['source' => 'hts',  'position' => 12]],
            ['1st in reps', ['source' => 'reps', 'position' => 1]],
            ['3rd in rps',  ['source' => 'reps', 'position' => 3]],  // typo seen in PDF
            ['6th in rsp',  ['source' => 'reps', 'position' => 6]],  // typo seen in PDF
            ['1st in SF',   ['source' => 'sf',   'position' => 1]],
            ['5th in sf',   ['source' => 'sf',   'position' => 5]],
            // Unparseable
            ['random',      null],
            ['1st in xyz',  null],
            ['',            null],
        ];
    }
}
