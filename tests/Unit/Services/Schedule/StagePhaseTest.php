<?php

namespace Tests\Unit\Services\Schedule;

use App\Services\Schedule\StagePhase;
use PHPUnit\Framework\TestCase;

class StagePhaseTest extends TestCase
{
    public function test_heats_land_in_phase_zero(): void
    {
        $this->assertSame(0, StagePhase::of('Heat 1'));
        $this->assertSame(0, StagePhase::of('Heat 5'));
        $this->assertSame(0, StagePhase::of('heat'));
    }

    public function test_rounds_map_to_phase_by_round_number(): void
    {
        $this->assertSame(0, StagePhase::of('Round 1'));
        $this->assertSame(1, StagePhase::of('Round 2'));
        $this->assertSame(2, StagePhase::of('Round 3'));
        $this->assertSame(4, StagePhase::of('Round 5'));
    }

    public function test_repechages_semis_finals_land_in_ordered_phases(): void
    {
        $this->assertSame(100, StagePhase::of('Repechage'));
        $this->assertSame(100, StagePhase::of('Repechage 2'));
        $this->assertSame(200, StagePhase::of('Semifinal'));
        $this->assertSame(200, StagePhase::of('Semifinal 1'));
        $this->assertSame(300, StagePhase::of('Grand Final'));
        $this->assertSame(300, StagePhase::of('Minor Final'));
        $this->assertSame(300, StagePhase::of('Tail Final'));
        $this->assertSame(300, StagePhase::of('Final'));
    }

    public function test_unknown_stages_land_in_tail_bucket(): void
    {
        $this->assertSame(999, StagePhase::of('Warmup'));
        $this->assertSame(999, StagePhase::of(''));
    }

    public function test_stage_number_extracts_trailing_digits(): void
    {
        $this->assertSame(1, StagePhase::stageNumber('Heat 1'));
        $this->assertSame(3, StagePhase::stageNumber('Round 3'));
        $this->assertSame(0, StagePhase::stageNumber('Grand Final'));
    }
}
