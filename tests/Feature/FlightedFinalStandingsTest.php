<?php

namespace Tests\Feature;

use App\Models\Crew;
use App\Models\CrewResult;
use App\Models\Discipline;
use App\Models\Event;
use App\Models\RaceResult;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Standings for long-distance finals split into flights ("Final 1".."Final N").
 * Each crew races exactly once; the discipline standing pools every flight and
 * ranks all crews together by finish time (time-trial).
 */
class FlightedFinalStandingsTest extends TestCase
{
    use RefreshDatabase;

    /** Create a crew (with team) in a discipline. */
    private function makeCrew(Discipline $discipline, string $name): Crew
    {
        $team = Team::factory()->create(['name' => $name]);
        return Crew::factory()->create([
            'team_id' => $team->id,
            'discipline_id' => $discipline->id,
        ]);
    }

    private function makeFlight(Discipline $discipline, string $stage, int $raceNumber): RaceResult
    {
        return RaceResult::factory()->create([
            'discipline_id' => $discipline->id,
            'stage' => $stage,
            'race_number' => $raceNumber,
            'status' => 'FINISHED',
        ]);
    }

    private function addResult(RaceResult $race, Crew $crew, ?int $timeMs, string $status, int $lane): void
    {
        CrewResult::factory()->create([
            'crew_id' => $crew->id,
            'race_result_id' => $race->id,
            'time_ms' => $timeMs,
            'status' => $status,
            'lane' => $lane,
        ]);
    }

    public function test_final_times_pool_across_flights(): void
    {
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);

        $a = $this->makeCrew($discipline, 'A');
        $b = $this->makeCrew($discipline, 'B');
        $c = $this->makeCrew($discipline, 'C');
        $d = $this->makeCrew($discipline, 'D');

        $flight1 = $this->makeFlight($discipline, 'Final 1', 1);
        $flight2 = $this->makeFlight($discipline, 'Final 2', 2);

        $this->addResult($flight1, $a, 300000, 'FINISHED', 1);
        $this->addResult($flight1, $b, 310000, 'FINISHED', 2);
        $this->addResult($flight2, $c, 305000, 'FINISHED', 1);
        $this->addResult($flight2, $d, 320000, 'FINISHED', 2);

        // Computed from the final flight — must span every flight's crews.
        $finalTimes = $flight2->getFinalTimesForDiscipline();

        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id, $c->id, $d->id],
            $finalTimes->keys()->all(),
        );
        $this->assertSame(300000, $finalTimes[$a->id]['final_time_ms']);
        $this->assertSame(310000, $finalTimes[$b->id]['final_time_ms']);
        $this->assertSame(305000, $finalTimes[$c->id]['final_time_ms']);
        $this->assertSame(320000, $finalTimes[$d->id]['final_time_ms']);

        // Ranked across flights by time: A(300) < C(305) < B(310) < D(320).
        $ranked = $finalTimes->sortBy(fn ($v) => $v['final_time_ms'])->keys()->all();
        $this->assertSame([$a->id, $c->id, $b->id, $d->id], $ranked);
    }

    public function test_flighted_final_flags_and_detection(): void
    {
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        $flight1 = $this->makeFlight($discipline, 'Final 1', 1);
        $flight2 = $this->makeFlight($discipline, 'Final 2', 2);

        // Both are flighted; only the last (highest race number) is the final round.
        $this->assertTrue($flight1->isFlightedFinal());
        $this->assertTrue($flight2->isFlightedFinal());
        $this->assertFalse($flight1->isFinalRound());
        $this->assertTrue($flight2->isFinalRound());
    }

    public function test_final_standing_crew_results_pools_all_flights(): void
    {
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        $a = $this->makeCrew($discipline, 'A');
        $b = $this->makeCrew($discipline, 'B');
        $c = $this->makeCrew($discipline, 'C');

        $flight1 = $this->makeFlight($discipline, 'Final 1', 1);
        $flight2 = $this->makeFlight($discipline, 'Final 2', 2);
        $this->addResult($flight1, $a, 300000, 'FINISHED', 1);
        $this->addResult($flight1, $b, 310000, 'FINISHED', 2);
        $this->addResult($flight2, $c, 305000, 'FINISHED', 1);

        $pooled = $flight2->finalStandingCrewResults();
        $this->assertSame(3, $pooled->count());
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id, $c->id],
            $pooled->pluck('crew_id')->all(),
        );
    }

    public function test_dsq_in_a_flight_yields_no_time(): void
    {
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        $a = $this->makeCrew($discipline, 'A');
        $b = $this->makeCrew($discipline, 'B');

        $flight1 = $this->makeFlight($discipline, 'Final 1', 1);
        $flight2 = $this->makeFlight($discipline, 'Final 2', 2);
        $this->addResult($flight1, $a, null, 'DSQ', 1);
        $this->addResult($flight2, $b, 305000, 'FINISHED', 1);

        $finalTimes = $flight2->getFinalTimesForDiscipline();
        $this->assertNull($finalTimes[$a->id]['final_time_ms']);
        $this->assertSame('DSQ', $finalTimes[$a->id]['final_status']);
        $this->assertSame(305000, $finalTimes[$b->id]['final_time_ms']);
    }

    public function test_single_final_is_not_flighted(): void
    {
        $event = Event::factory()->create();
        $discipline = Discipline::factory()->create(['event_id' => $event->id]);
        $a = $this->makeCrew($discipline, 'A');
        $b = $this->makeCrew($discipline, 'B');

        $final = $this->makeFlight($discipline, 'Final', 1);
        $this->addResult($final, $a, 300000, 'FINISHED', 1);
        $this->addResult($final, $b, 310000, 'FINISHED', 2);

        $this->assertFalse($final->isFlightedFinal());
        $this->assertTrue($final->isFinalRound());

        // Single final still ranks its own crews by time.
        $finalTimes = $final->getFinalTimesForDiscipline();
        $this->assertSame(300000, $finalTimes[$a->id]['final_time_ms']);
        $this->assertSame(310000, $finalTimes[$b->id]['final_time_ms']);
    }
}
