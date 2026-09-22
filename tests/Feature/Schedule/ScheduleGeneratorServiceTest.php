<?php

namespace Tests\Feature\Schedule;

use App\Models\Crew;
use App\Models\Discipline;
use App\Models\DisciplineProgression;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\RaceResult;
use App\Models\ScheduleBlock;
use App\Models\Team;
use App\Services\Schedule\IdbfRacePlans;
use App\Services\Schedule\ScheduleGeneratorService;
use App\Services\Schedule\ScheduleSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Integration tests for ScheduleGeneratorService.
 *
 * These tests exercise the end-to-end pipeline: discipline → IDBF plan →
 * RaceResult/CrewResult rows with lanes, times, and chronological numbering.
 *
 * Asserts the most error-prone behaviors: lane assignment matching IDBF tables,
 * deterministic crew seeding, time placement within block windows, and
 * regenerate scoping.
 */
class ScheduleGeneratorServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScheduleGeneratorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ScheduleGeneratorService(new IdbfRacePlans(), new ScheduleSnapshotService());
    }

    public function test_generates_expected_stage_count_for_rp_2a(): void
    {
        // RP.2A: 6 lanes, 9-12 crews, stages = Heat 1, Heat 2, Repechage 1, Repechage 2, Grand Final = 5 races
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 12);

        $result = $this->service->generate($event);

        $this->assertSame(5, $result->racesCreated);
        $this->assertSame(5, $result->racesPerDiscipline[$discipline->id]);
        $this->assertEmpty($result->warnings);

        $races = RaceResult::where('discipline_id', $discipline->id)->orderBy('id')->get();
        $this->assertSame(
            ['Heat 1', 'Heat 2', 'Repechage 1', 'Repechage 2', 'Grand Final'],
            $races->pluck('stage')->all()
        );
    }

    public function test_assigns_round1_lanes_per_idbf_heat_seeding_table(): void
    {
        // RP.2A Heat 1 lane seeding from PDF p10: [9, 5, 1, 4, 8, 12]
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 12);

        // Pre-set seeds 1..12 in registration order so we can map back.
        foreach ($discipline->crews()->orderBy('id')->get() as $i => $crew) {
            $crew->update(['seed_number' => $i + 1]);
        }

        $this->service->generate($event);

        $heat1 = RaceResult::where('discipline_id', $discipline->id)->where('stage', 'Heat 1')->firstOrFail();
        $laneToSeed = $heat1->crewResults()->get()->mapWithKeys(
            fn($cr) => [$cr->lane => $cr->crew->seed_number]
        )->all();

        $this->assertSame([1 => 9, 2 => 5, 3 => 1, 4 => 4, 5 => 8, 6 => 12], $laneToSeed);
    }

    public function test_later_stages_have_no_crew_results_until_lane_seeded(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 12);

        $this->service->generate($event);

        $final = RaceResult::where('discipline_id', $discipline->id)->where('stage', 'Grand Final')->firstOrFail();
        $this->assertSame(0, $final->crewResults()->count());
    }

    public function test_populates_seed_numbers_when_missing_deterministically(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 6);

        $this->assertNull($discipline->crews()->first()->seed_number);

        $this->service->generate($event);

        $seeds = $discipline->crews()->pluck('seed_number')->sort()->values()->all();
        $this->assertSame([1, 2, 3, 4, 5, 6], $seeds, 'seeds must be a 1..N permutation');

        // Re-running the generator with the same discipline.id seed produces the same shuffle.
        $firstAssignment = $discipline->crews()->orderBy('id')->pluck('seed_number')->all();
        $discipline->crews()->update(['seed_number' => null]);
        $this->service->generate($event->fresh());
        $secondAssignment = $discipline->fresh()->crews()->orderBy('id')->pluck('seed_number')->all();
        $this->assertSame($firstAssignment, $secondAssignment, 'seed shuffle must be deterministic per discipline');
    }

    public function test_preserves_pre_existing_seeds(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 6);

        $crews = $discipline->crews()->orderBy('id')->get();
        $crews[0]->update(['seed_number' => 1]);
        $crews[1]->update(['seed_number' => 6]);

        $this->service->generate($event);

        $this->assertSame(1, $crews[0]->fresh()->seed_number);
        $this->assertSame(6, $crews[1]->fresh()->seed_number);
    }

    public function test_assigns_race_times_within_block_window(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00', gapSeconds: 240);
        $discipline = $this->makeDiscipline($event, 6);  // ROUNDS_6L → 3 round races

        $this->service->generate($event);

        $times = RaceResult::where('discipline_id', $discipline->id)
            ->orderBy('race_time')
            ->pluck('race_time')
            ->map(fn($t) => $t->format('H:i:s'))
            ->all();

        $this->assertSame(['09:00:00', '09:04:00', '09:08:00'], $times);
    }

    public function test_assigns_sequential_race_numbers_chronologically(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $a = $this->makeDiscipline($event, 6, distance: '200m');
        $b = $this->makeDiscipline($event, 6, distance: '500m');

        $this->service->generate($event);

        $races = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->orderBy('race_time')
            ->get();
        $this->assertSame(range(1, 6), $races->pluck('race_number')->all());
    }

    public function test_replaces_existing_scheduled_races_on_regenerate(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 6);

        $this->service->generate($event);
        $firstIds = RaceResult::where('discipline_id', $discipline->id)->pluck('id')->all();

        $this->service->generate($event->fresh());
        $secondIds = RaceResult::where('discipline_id', $discipline->id)->pluck('id')->all();

        $this->assertEmpty(array_intersect($firstIds, $secondIds), 'old race rows should be deleted');
        $this->assertCount(3, $secondIds);
    }

    public function test_warns_and_skips_discipline_with_fewer_than_two_crews(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $this->makeDiscipline($event, 1);

        $result = $this->service->generate($event);

        $this->assertSame(0, $result->racesCreated);
        $this->assertCount(1, $result->warnings);
        $this->assertStringContainsString('at least 2 crews', $result->warnings[0]);
    }

    public function test_throws_when_no_event_days(): void
    {
        $event = $this->makeEvent(laneCount: 6);  // no addBlock call

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no days configured');
        $this->service->generate($event);
    }

    public function test_warns_when_no_block_matches_discipline(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        // Block only matches Mixed gender; discipline below is Men's.
        $this->addBlock($event, 'Mixed only', '09:00:00', genderFilter: ['X']);
        $this->makeDiscipline($event, 6, gender: 'M');

        $result = $this->service->generate($event);

        $this->assertNotEmpty($result->warnings);
        $this->assertStringContainsString('No matching schedule block', $result->warnings[0]);
        $unplaced = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->whereNull('race_time')
            ->count();
        $this->assertSame(3, $unplaced); // ROUNDS_6L creates 3 races, all unplaced
    }

    public function test_uses_progression_override_when_set(): void
    {
        // 12 crews on 6 lanes auto-picks RP.2A. Override to RP.3A (also valid for 6 lanes 13-18) → should reject.
        // Better: override to a valid alternative if any exists; for 12 crews on 6 lanes, only RP.2A fits.
        // So instead: test that an override for an INCOMPATIBLE plan code surfaces a warning.
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 12);
        DisciplineProgression::create([
            'discipline_id' => $discipline->id,
            'race_plan_code' => 'RP.3A',  // RP.3A needs 13-18 crews; 12 doesn't fit
        ]);

        $result = $this->service->generate($event);

        $this->assertNotEmpty($result->warnings);
        $this->assertStringContainsString('RP.3A', $result->warnings[0]);
        $this->assertSame(0, $result->racesCreated);
    }

    public function test_regenerate_discipline_only_affects_that_discipline(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $a = $this->makeDiscipline($event, 6, distance: '200m');
        $b = $this->makeDiscipline($event, 6, distance: '500m');

        $this->service->generate($event);
        $bIdsBefore = RaceResult::where('discipline_id', $b->id)->pluck('id')->all();

        $this->service->regenerateDiscipline($a->fresh());

        $bIdsAfter = RaceResult::where('discipline_id', $b->id)->pluck('id')->all();
        $this->assertSame($bIdsBefore, $bIdsAfter, 'B discipline rows should be untouched');
    }

    public function test_regenerate_refuses_when_races_have_started(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 6);

        $this->service->generate($event);
        // Mark one race as IN_PROGRESS to simulate a started discipline.
        RaceResult::where('discipline_id', $discipline->id)->first()->update(['status' => 'IN_PROGRESS']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IN_PROGRESS');
        $this->service->regenerateDiscipline($discipline->fresh());
    }

    public function test_regenerate_refuses_when_a_different_discipline_is_in_progress(): void
    {
        // Chunk-1 guardrail: even if the target discipline is untouched,
        // any IN_PROGRESS race in the same event blocks a full-event
        // regenerate — snapshotting would clobber the running race's rows.
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $a = $this->makeDiscipline($event, 6, distance: '200m');
        $b = $this->makeDiscipline($event, 6, distance: '500m');

        $this->service->generate($event);
        RaceResult::where('discipline_id', $b->id)->first()->update(['status' => 'IN_PROGRESS']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IN_PROGRESS');
        $this->service->generate($event->fresh());
    }

    public function test_single_round_discipline_is_staged_final(): void
    {
        // crewCount (4) <= laneCount (6) with default_rounds=1 takes the
        // ROUNDS path and produces exactly one race. A lone round decides the
        // discipline, so it must be staged "Final", not "Round 1".
        $event = $this->makeEvent(laneCount: 6);
        $event->update(['default_rounds' => 1]);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 4);

        $result = $this->service->generate($event);

        $this->assertSame(1, $result->racesPerDiscipline[$discipline->id]);
        $races = RaceResult::where('discipline_id', $discipline->id)->get();
        $this->assertSame(['Final'], $races->pluck('stage')->all());
    }

    public function test_multi_round_discipline_keeps_round_stage_names(): void
    {
        // With more than one round the stages stay "Round k" — only the
        // single-round case collapses to "Final".
        $event = $this->makeEvent(laneCount: 6);
        $event->update(['default_rounds' => 3]);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 4);

        $this->service->generate($event);

        $races = RaceResult::where('discipline_id', $discipline->id)->orderBy('id')->get();
        $this->assertSame(['Round 1', 'Round 2', 'Round 3'], $races->pluck('stage')->all());
    }

    public function test_long_distance_over_1000m_is_single_final(): void
    {
        // 2000m: longer than 1000m → always one Final, no rounds/heats, even
        // with default_rounds > 1. Fits on the course here (4 crews / 6 lanes).
        $event = $this->makeEvent(laneCount: 6);
        $event->update(['default_rounds' => 3]);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 4, distance: '2000m');

        $result = $this->service->generate($event);

        $this->assertSame(1, $result->racesPerDiscipline[$discipline->id]);
        $races = RaceResult::where('discipline_id', $discipline->id)->get();
        $this->assertSame(['Final'], $races->pluck('stage')->all());
        // All four crews placed in the one Final.
        $this->assertSame(4, $races->first()->crewResults()->count());
    }

    public function test_long_distance_final_places_all_crews_when_over_lane_count(): void
    {
        // 2000m mass-start final with more crews (8) than lanes (6): every crew
        // still races in the one Final; nobody is dropped.
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 8, distance: '2000m');

        $result = $this->service->generate($event);

        $this->assertSame(1, $result->racesPerDiscipline[$discipline->id]);
        $race = RaceResult::where('discipline_id', $discipline->id)->first();
        $this->assertSame('Final', $race->stage);
        $this->assertSame(8, $race->crewResults()->count());
    }

    public function test_long_distance_seeds_sequentially_from_lane_one(): void
    {
        // Long-distance: no centre-out — fastest seed in lane 1, then 2, 3, 4…
        // (centre-out on 6 lanes for 4 crews would give lanes {2,3,4,5}).
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $d = $this->makeDiscipline($event, 4, distance: '2000m');

        $this->service->generate($event);

        $race = RaceResult::where('discipline_id', $d->id)->first();
        $lanes = $race->crewResults()->pluck('lane')->sort()->values()->all();
        $this->assertSame([1, 2, 3, 4], $lanes);
    }

    public function test_long_distance_splits_into_flights_over_team_limit(): void
    {
        // 2000m Standard, limit 6 boats, 14 crews → ceil(14/6) = 3 flights,
        // staged "Final 1..3", balanced sizes (≤6 each), all 14 crews placed.
        $event = $this->makeEvent(laneCount: 6);
        $event->update(['long_race_max_standard' => 6]);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 14, distance: '2000m');

        $result = $this->service->generate($event);

        $this->assertSame(3, $result->racesPerDiscipline[$discipline->id]);
        $races = RaceResult::where('discipline_id', $discipline->id)->orderBy('stage')->get();
        $this->assertSame(['Final 1', 'Final 2', 'Final 3'], $races->pluck('stage')->sort()->values()->all());

        $sizes = $races->map(fn ($r) => $r->crewResults()->count());
        $this->assertSame(14, $sizes->sum());              // nobody dropped
        $this->assertLessThanOrEqual(1, $sizes->max() - $sizes->min()); // balanced
        $this->assertLessThanOrEqual(6, $sizes->max());    // within the limit
    }

    public function test_long_distance_limit_is_per_boat_group(): void
    {
        // Small limit 4 must apply to a Small discipline even though the
        // Standard limit is large. 10 crews / 4 → 3 flights (not 1).
        $event = $this->makeEvent(laneCount: 6);
        $event->update(['long_race_max_small' => 4, 'long_race_max_standard' => 99]);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 10, distance: '2000m', boatGroup: 'Small');

        $result = $this->service->generate($event);

        $this->assertSame(3, $result->racesPerDiscipline[$discipline->id]);
    }

    public function test_long_distance_single_final_when_within_limit(): void
    {
        // Limit set but the field fits (6 crews ≤ limit 10) → one "Final".
        $event = $this->makeEvent(laneCount: 6);
        $event->update(['long_race_max_standard' => 10]);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 6, distance: '2000m');

        $this->service->generate($event);

        $races = RaceResult::where('discipline_id', $discipline->id)->get();
        $this->assertSame(['Final'], $races->pluck('stage')->all());
        $this->assertSame(6, $races->first()->crewResults()->count());
    }

    public function test_1000m_is_not_forced_to_final(): void
    {
        // Exactly 1000m keeps the normal, configurable behaviour: with
        // default_rounds=3 and crews that fit, it stays a 3-round format.
        $event = $this->makeEvent(laneCount: 6);
        $event->update(['default_rounds' => 3]);
        $this->addBlock($event, 'Morning', '09:00:00');
        $discipline = $this->makeDiscipline($event, 4, distance: '1000m');

        $this->service->generate($event);

        $races = RaceResult::where('discipline_id', $discipline->id)->orderBy('id')->get();
        $this->assertSame(['Round 1', 'Round 2', 'Round 3'], $races->pluck('stage')->all());
    }

    public function test_ordering_spreads_paddler_sharing_races(): void
    {
        // The reported case: Senior A ×4 (Mixed×2, Women, Open) + Senior B ×2
        // (Mixed, Women), all 200m, one Final each (default_rounds=1). Cross-age
        // never conflicts; within an age Mixed shares with everyone but
        // Open↔Women don't. This is fully separable with ZERO rest breaks.
        $event = $this->makeEvent(laneCount: 6);
        $event->update(['default_rounds' => 1]);
        $this->addBlock($event, 'Morning', '09:00:00');

        $this->makeDiscipline($event, 4, 'Mixed', '200m', 'Standard', 'Senior A');
        $this->makeDiscipline($event, 4, 'Mixed', '200m', 'Small', 'Senior A');
        $this->makeDiscipline($event, 4, 'Women', '200m', 'Small', 'Senior A');
        $this->makeDiscipline($event, 4, 'Open', '200m', 'Small', 'Senior A');
        $this->makeDiscipline($event, 4, 'Mixed', '200m', 'Small', 'Senior B');
        $this->makeDiscipline($event, 4, 'Women', '200m', 'Small', 'Senior B');

        $this->service->generate($event);

        // No forced rest breaks were needed.
        $this->assertSame(0, RaceResult::where('event_id', $event->id)
            ->where('entry_type', 'break')
            ->where('label', 'like', 'auto: Rest%')
            ->count());

        $this->assertNoAdjacentPaddlerSharing($event);
    }

    public function test_open_and_women_may_run_consecutively(): void
    {
        // Only Senior A Open + Senior A Women: they don't share paddlers, so
        // they can sit back-to-back with no rest break.
        $event = $this->makeEvent(laneCount: 6);
        $event->update(['default_rounds' => 1]);
        $this->addBlock($event, 'Morning', '09:00:00');
        $this->makeDiscipline($event, 4, 'Open', '200m', 'Small', 'Senior A');
        $this->makeDiscipline($event, 4, 'Women', '200m', 'Small', 'Senior A');

        $this->service->generate($event);

        $this->assertSame(0, RaceResult::where('event_id', $event->id)
            ->where('entry_type', 'break')
            ->where('label', 'like', 'auto: Rest%')
            ->count());
        // Both finals placed, and they're allowed to be consecutive.
        $this->assertSame(2, RaceResult::whereHas('discipline', fn ($q) => $q->where('event_id', $event->id))
            ->where('status', 'SCHEDULED')
            ->whereNotNull('race_time')
            ->count());
    }

    public function test_lopsided_block_inserts_rest_breaks(): void
    {
        // Three Senior A Mixed disciplines (distinct by distance) all share
        // paddlers pairwise — impossible to separate, so two rest breaks are
        // forced: A · rest · A · rest · A.
        $event = $this->makeEvent(laneCount: 6);
        $event->update(['default_rounds' => 1]);
        $this->addBlock($event, 'Morning', '09:00:00');
        $this->makeDiscipline($event, 4, 'Mixed', '200m', 'Small', 'Senior A');
        $this->makeDiscipline($event, 4, 'Mixed', '500m', 'Small', 'Senior A');
        $this->makeDiscipline($event, 4, 'Mixed', '2000m', 'Small', 'Senior A');

        $result = $this->service->generate($event);

        $this->assertSame(2, RaceResult::where('event_id', $event->id)
            ->where('entry_type', 'break')
            ->where('label', 'like', 'auto: Rest%')
            ->count());
        $this->assertNotEmpty($result->warnings);
    }

    /** Assert no two consecutive placed races (by time) share paddlers. */
    private function assertNoAdjacentPaddlerSharing(Event $event): void
    {
        $races = RaceResult::whereHas('discipline', fn ($q) => $q->where('event_id', $event->id))
            ->where('status', 'SCHEDULED')
            ->whereNotNull('race_time')
            ->with('discipline')
            ->orderBy('race_time')
            ->orderBy('id')
            ->get()
            ->filter(fn ($r) => $r->discipline_id !== null)
            ->values();

        for ($i = 1; $i < $races->count(); $i++) {
            $a = $races[$i - 1];
            $b = $races[$i];
            $this->assertFalse(
                $this->testPaddlersShare($a, $b),
                "Races {$a->stage} ({$a->discipline->getDisplayName()}) and "
                . "{$b->stage} ({$b->discipline->getDisplayName()}) share paddlers but are consecutive.",
            );
        }
    }

    private function testPaddlersShare(RaceResult $a, RaceResult $b): bool
    {
        if ($a->discipline_id === $b->discipline_id) return false;
        if (strcasecmp((string) $a->discipline->age_group, (string) $b->discipline->age_group) !== 0) return false;
        $canon = fn (string $g) => match (strtolower(trim($g))) {
            'mixed', 'mix', 'x' => 'mixed',
            'women', 'w' => 'women',
            default => 'open',
        };
        $ga = $canon((string) $a->discipline->gender_group);
        $gb = $canon((string) $b->discipline->gender_group);
        return $ga === 'mixed' || $gb === 'mixed' || $ga === $gb;
    }

    public function test_combined_categories_generate_one_final_with_both(): void
    {
        // Senior C (1 crew) paired to race with Senior A (2 crews). The host
        // produces ONE shared Final with all 3 crews; the secondary makes no
        // race of its own.
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $host = $this->makeDiscipline($event, 2, 'Open', '200m', 'Small', 'Senior A');
        $sec = $this->makeDiscipline($event, 1, 'Open', '200m', 'Small', 'Senior C');
        $sec->update(['combined_with_discipline_id' => $host->id]);

        $this->service->generate($event);

        $this->assertSame(0, RaceResult::where('discipline_id', $sec->id)->count());
        $races = RaceResult::where('discipline_id', $host->id)->get();
        $this->assertCount(1, $races);
        $this->assertSame('Final', $races->first()->stage);
        $this->assertSame(3, $races->first()->crewResults()->count());
    }

    public function test_combined_categories_score_each_category_separately(): void
    {
        $event = $this->makeEvent(laneCount: 6);
        $this->addBlock($event, 'Morning', '09:00:00');
        $host = $this->makeDiscipline($event, 2, 'Open', '200m', 'Small', 'Senior A');
        $sec = $this->makeDiscipline($event, 1, 'Open', '200m', 'Small', 'Senior C');
        $sec->update(['combined_with_discipline_id' => $host->id]);
        $this->service->generate($event);

        $race = RaceResult::with('crewResults.crew')->where('discipline_id', $host->id)->first();
        $t = 100000;
        foreach ($race->crewResults as $cr) {
            $cr->update(['status' => 'FINISHED', 'time_ms' => $t]);
            $t += 1000;
        }
        $race->load('crewResults.crew');

        // Both categories are visible in the one race.
        $this->assertEqualsCanonicalizing([$host->id, $sec->id], $race->categoryDisciplineIds()->all());

        // Each category is scored among ITS OWN crews only.
        $aTimes = $race->getFinalTimesForDiscipline($host->id);
        $cTimes = $race->getFinalTimesForDiscipline($sec->id);
        $this->assertSame(2, $aTimes->count());
        $this->assertSame(1, $cTimes->count());
        $this->assertSame(2, $race->finalStandingCrewResults($host->id)->count());
        $this->assertSame(1, $race->finalStandingCrewResults($sec->id)->count());

        foreach ($aTimes->keys() as $crewId) {
            $this->assertSame($host->id, Crew::find($crewId)->discipline_id);
        }
        foreach ($cTimes->keys() as $crewId) {
            $this->assertSame($sec->id, Crew::find($crewId)->discipline_id);
        }
    }

    public function test_normal_race_reports_single_category(): void
    {
        // Regression guard: a normal race has exactly one category and the
        // unscoped final times equal the host-scoped ones.
        $event = $this->makeEvent(laneCount: 6);
        $event->update(['default_rounds' => 1]);
        $this->addBlock($event, 'Morning', '09:00:00');
        $d = $this->makeDiscipline($event, 4, 'Open', '200m', 'Small', 'Senior A');
        $this->service->generate($event);

        $race = RaceResult::with('crewResults.crew')->where('discipline_id', $d->id)->first();
        foreach ($race->crewResults as $i => $cr) {
            $cr->update(['status' => 'FINISHED', 'time_ms' => 100000 + $i * 1000]);
        }
        $race->load('crewResults.crew');

        $this->assertSame([$d->id], $race->categoryDisciplineIds()->all());
        $this->assertEquals(
            $race->getFinalTimesForDiscipline()->toArray(),
            $race->getFinalTimesForDiscipline($d->id)->toArray(),
        );
    }

    public function test_stage_cooldown_keeps_two_races_between_transitions(): void
    {
        // A heats/rep/final discipline mixed with plenty of single-final
        // fillers: the scheduler must keep >=2 other races between its heats
        // and repechage, and between its repechage and grand final (finals are
        // pulled forward for rest).
        $event = $this->makeEvent(laneCount: 3);
        $event->update(['default_rounds' => 1]);
        $this->addBlock($event, 'Morning', '09:00:00');
        $multi = $this->makeDiscipline($event, 5, 'Mixed', '200m', 'Small', 'Senior B');
        foreach (['Junior A', 'Junior B', 'Master A', 'Master B', 'Master C', 'Master D'] as $age) {
            $this->makeDiscipline($event, 2, 'Open', '200m', 'Small', $age);
        }

        $this->service->generate($event);

        $races = RaceResult::whereHas('discipline', fn ($q) => $q->where('event_id', $event->id))
            ->where('status', 'SCHEDULED')
            ->whereNotNull('race_time')
            ->orderBy('race_time')->orderBy('id')
            ->get()->values();
        $idx = fn ($stage) => $races->search(
            fn ($r) => $r->discipline_id === $multi->id && $r->stage === $stage
        );
        $heat2 = $idx('Heat 2');
        $rep = $idx('Repechage 1');
        $gf = $idx('Grand Final');
        $this->assertNotFalse($heat2);
        $this->assertNotFalse($rep);
        $this->assertNotFalse($gf);
        $this->assertGreaterThanOrEqual(3, $rep - $heat2, '>=2 races between heats and repechage');
        $this->assertGreaterThanOrEqual(3, $gf - $rep, '>=2 races between repechage and grand final');
    }

    public function test_five_crews_on_three_lanes_uses_heats_rep_final(): void
    {
        // 5 crews on 3 lanes: no rounds (5 > 3) and previously no IDBF plan
        // existed for 3 lanes → skipped. RP.2_3L now covers it: Heat 1, Heat 2,
        // Repechage 1, Grand Final.
        $event = $this->makeEvent(laneCount: 3);
        $this->addBlock($event, 'Morning', '09:00:00');
        $d = $this->makeDiscipline($event, 5, 'Mixed', '200m', 'Small', 'Senior B');

        $result = $this->service->generate($event);

        $races = RaceResult::where('discipline_id', $d->id)->orderBy('id')->get();
        $this->assertSame(
            ['Heat 1', 'Heat 2', 'Repechage 1', 'Grand Final'],
            $races->pluck('stage')->all(),
        );
        // Heats seeded up front (3 + 2); rep/final seeded later by LaneSeeder.
        $this->assertSame(3, $races->firstWhere('stage', 'Heat 1')->crewResults()->count());
        $this->assertSame(2, $races->firstWhere('stage', 'Heat 2')->crewResults()->count());
    }

    // ----- helpers -----

    private function makeEvent(int $laneCount): Event
    {
        return Event::create([
            'name' => 'Test Event',
            'location' => 'Lake',
            'year' => 2026,
            'lane_count' => $laneCount,
            'schedule_status' => 'draft',
        ]);
    }

    private function addBlock(
        Event $event,
        string $name,
        string $startTime,
        int $gapSeconds = 240,
        ?array $genderFilter = null,
        ?array $distanceFilter = null,
        ?array $stageFilter = null,
    ): ScheduleBlock {
        $day = EventDay::create([
            'event_id' => $event->id,
            'date' => '2026-06-12',
            'name' => 'Day 1',
            'sort_order' => 0,
        ]);
        return ScheduleBlock::create([
            'event_day_id' => $day->id,
            'name' => $name,
            'start_time' => $startTime,
            'gap_seconds' => $gapSeconds,
            'gender_filter' => $genderFilter,
            'distance_filter' => $distanceFilter,
            'stage_filter' => $stageFilter,
            'sort_order' => 0,
        ]);
    }

    private function makeDiscipline(Event $event, int $crewCount, string $gender = 'M', string $distance = '200m', string $boatGroup = 'Standard', string $ageGroup = 'Senior'): Discipline
    {
        $discipline = Discipline::create([
            'event_id' => $event->id,
            'distance' => $distance,
            'age_group' => $ageGroup,
            'gender_group' => $gender,
            'boat_group' => $boatGroup,
            'status' => 'active',
        ]);
        for ($i = 0; $i < $crewCount; $i++) {
            $team = Team::create(['name' => "Team {$gender}{$distance}#" . ($i + 1)]);
            Crew::create(['team_id' => $team->id, 'discipline_id' => $discipline->id]);
        }
        return $discipline;
    }
}
