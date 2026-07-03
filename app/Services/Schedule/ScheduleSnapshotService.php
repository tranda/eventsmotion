<?php

namespace App\Services\Schedule;

use App\Models\Crew;
use App\Models\CrewResult;
use App\Models\Discipline;
use App\Models\DisciplineProgression;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\RaceResult;
use App\Models\ScheduleBlock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Capture/restore named snapshots of the schedule builder state.
 *
 * Four orthogonal categories:
 *   - setup      → event params (lane_count, default_rounds,
 *                  min_crews_per_race, color_map) + every day + every block.
 *   - plan_seeds → each discipline's progression (override_code,
 *                  custom_stages) + each crew's seed_number.
 *   - grid_day   → races + crew_results + breaks for one specific day.
 *   - event_grid → grid_day capture for EVERY day in the event, bundled.
 *                  Used by auto-snapshots taken before a regenerate /
 *                  recompute so the whole event can be rolled back with
 *                  one call.
 *
 * Categories are independent; restoring one doesn't touch the others.
 *
 * Identity strategy on restore:
 *   - Days matched by date (created if absent).
 *   - Blocks matched by (day, name) (created if absent).
 *   - Disciplines matched by (boat, age, gender, distance, competition).
 *   - Crews matched by (discipline, team_id).
 *   - Races matched by (discipline, stage).
 *   - Snapshot is meant for the same event; cross-event restore not
 *     supported.
 */
class ScheduleSnapshotService
{
    public function __construct() {}

    public function capture(Event $event, string $category, ?string $day = null): array
    {
        return match ($category) {
            'setup' => $this->captureSetup($event),
            'plan_seeds' => $this->capturePlanSeeds($event),
            'grid_day' => $this->captureGridDay($event, $this->requireDay($day)),
            'event_grid' => $this->captureEventGrid($event),
            default => throw new InvalidArgumentException("Unknown category: {$category}"),
        };
    }

    public function restore(Event $event, string $category, array $payload, ?string $day = null): array
    {
        return match ($category) {
            'setup' => $this->restoreSetup($event, $payload),
            'plan_seeds' => $this->restorePlanSeeds($event, $payload),
            'grid_day' => $this->restoreGridDay($event, $this->requireDay($day), $payload),
            'event_grid' => $this->restoreEventGrid($event, $payload),
            default => throw new InvalidArgumentException("Unknown category: {$category}"),
        };
    }

    // ---------------- SETUP ----------------

    private function captureSetup(Event $event): array
    {
        $event->loadMissing('eventDays.blocks');
        return [
            'lane_count' => $event->lane_count,
            'default_rounds' => $event->default_rounds,
            'min_crews_per_race' => $event->min_crews_per_race,
            'color_map' => $event->color_map,
            'days' => $event->eventDays->sortBy('sort_order')->values()->map(function ($d) {
                return [
                    'date' => $d->date instanceof Carbon ? $d->date->toDateString() : (string) $d->date,
                    'name' => $d->name,
                    'sort_order' => $d->sort_order,
                    'blocks' => $d->blocks->sortBy('sort_order')->values()->map(function ($b) {
                        return [
                            'name' => $b->name,
                            'start_time' => $b->start_time,
                            'gap_seconds' => $b->gap_seconds,
                            'gender_filter' => $b->gender_filter,
                            'distance_filter' => $b->distance_filter,
                            'stage_filter' => $b->stage_filter,
                            'competition_filter' => $b->competition_filter,
                            'sort_order' => $b->sort_order,
                        ];
                    })->all(),
                ];
            })->all(),
        ];
    }

    private function restoreSetup(Event $event, array $payload): array
    {
        $stats = ['days_restored' => 0, 'blocks_restored' => 0, 'warnings' => []];

        DB::transaction(function () use ($event, $payload, &$stats) {
            $event->update([
                'lane_count' => $payload['lane_count'] ?? $event->lane_count,
                'default_rounds' => $payload['default_rounds'] ?? $event->default_rounds,
                'min_crews_per_race' => $payload['min_crews_per_race'] ?? $event->min_crews_per_race,
                'color_map' => $payload['color_map'] ?? null,
            ]);

            // Wipe existing days+blocks then recreate. Simple and reliable —
            // means race times that depended on these will be recomputed by
            // the generator on next regen.
            ScheduleBlock::whereIn('event_day_id',
                EventDay::where('event_id', $event->id)->pluck('id'),
            )->delete();
            EventDay::where('event_id', $event->id)->delete();

            foreach ($payload['days'] ?? [] as $dayPayload) {
                $day = EventDay::create([
                    'event_id' => $event->id,
                    'date' => $dayPayload['date'],
                    'name' => $dayPayload['name'] ?? null,
                    'sort_order' => $dayPayload['sort_order'] ?? 0,
                ]);
                $stats['days_restored']++;
                foreach ($dayPayload['blocks'] ?? [] as $blockPayload) {
                    ScheduleBlock::create([
                        'event_day_id' => $day->id,
                        'name' => $blockPayload['name'] ?? null,
                        'start_time' => $blockPayload['start_time'] ?? '09:00:00',
                        'gap_seconds' => $blockPayload['gap_seconds'] ?? 240,
                        'gender_filter' => $blockPayload['gender_filter'] ?? null,
                        'distance_filter' => $blockPayload['distance_filter'] ?? null,
                        'stage_filter' => $blockPayload['stage_filter'] ?? null,
                        'competition_filter' => $blockPayload['competition_filter'] ?? null,
                        'sort_order' => $blockPayload['sort_order'] ?? 0,
                    ]);
                    $stats['blocks_restored']++;
                }
            }
        });

        return $stats;
    }

    // ---------------- PLAN & SEEDS ----------------

    private function capturePlanSeeds(Event $event): array
    {
        $disciplines = Discipline::where('event_id', $event->id)
            ->with(['progression', 'crews.team'])
            ->orderBy('id')
            ->get();

        return [
            'disciplines' => $disciplines->map(function ($d) {
                return [
                    'key' => $this->disciplineKey($d),
                    'status' => $d->status,
                    'progression' => [
                        'race_plan_code' => optional($d->progression)->race_plan_code,
                        'custom_stages' => optional($d->progression)->custom_stages,
                    ],
                    'crews' => $d->crews->map(fn($c) => [
                        'team_id' => $c->team_id,
                        'seed_number' => $c->seed_number,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    private function restorePlanSeeds(Event $event, array $payload): array
    {
        $stats = ['progressions_updated' => 0, 'seeds_updated' => 0, 'unmatched' => []];

        DB::transaction(function () use ($event, $payload, &$stats) {
            // Index current disciplines by their natural key.
            $current = Discipline::where('event_id', $event->id)->with('crews')->get();
            $byKey = $current->keyBy(fn($d) => $this->disciplineKey($d));

            foreach ($payload['disciplines'] ?? [] as $dp) {
                $key = $dp['key'] ?? '';
                $d = $byKey->get($key);
                if (!$d) {
                    $stats['unmatched'][] = $key;
                    continue;
                }
                // Status (active/inactive) is part of plan&seeds — operator may
                // have flipped it via the planner.
                if (isset($dp['status']) && $dp['status'] !== $d->status) {
                    $d->update(['status' => $dp['status']]);
                }
                // Progression upsert.
                $prog = $dp['progression'] ?? [];
                DisciplineProgression::updateOrCreate(
                    ['discipline_id' => $d->id],
                    [
                        'race_plan_code' => $prog['race_plan_code'] ?? null,
                        'custom_stages' => $prog['custom_stages'] ?? null,
                    ],
                );
                $stats['progressions_updated']++;
                // Crew seed numbers.
                $crewsByTeam = $d->crews->keyBy('team_id');
                foreach ($dp['crews'] ?? [] as $cp) {
                    $crew = $crewsByTeam->get($cp['team_id'] ?? 0);
                    if (!$crew) continue;
                    $crew->update(['seed_number' => $cp['seed_number'] ?? null]);
                    $stats['seeds_updated']++;
                }
            }
        });

        return $stats;
    }

    // ---------------- GRID (per day) ----------------

    private function captureGridDay(Event $event, string $day): array
    {
        $start = Carbon::parse("{$day} 00:00:00");
        $end = $start->copy()->addDay();

        $races = RaceResult::where(function ($q) use ($event) {
            $q->whereHas('discipline', fn($qq) => $qq->where('event_id', $event->id))
              ->orWhere(function ($qq) use ($event) {
                  $qq->where('event_id', $event->id)->where('entry_type', 'break');
              });
        })
            ->whereBetween('race_time', [$start, $end])
            ->with(['discipline', 'crewResults.crew'])
            ->orderBy('race_time')
            ->orderBy('id')
            ->get();

        return [
            'day' => $day,
            'races' => $races->map(function ($r) {
                $disc = $r->discipline;
                return [
                    'entry_type' => $r->entry_type,
                    'discipline_key' => $disc ? $this->disciplineKey($disc) : null,
                    'stage' => $r->stage,
                    'race_number' => $r->race_number,
                    'race_time' => $r->race_time ? Carbon::parse($r->race_time)->toDateTimeString() : null,
                    'status' => $r->status,
                    'label' => $r->label,
                    'duration_seconds' => $r->duration_seconds,
                    'shift_subsequent' => $r->shift_subsequent,
                    'crew_results' => $r->crewResults->map(fn($cr) => [
                        'team_id' => optional(optional($cr->crew)->team_id ? $cr->crew : null)->team_id,
                        'lane' => $cr->lane,
                        'status' => $cr->status,
                        'time_ms' => $cr->time_ms,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    private function restoreGridDay(Event $event, string $day, array $payload): array
    {
        $stats = ['races_restored' => 0, 'crew_results_restored' => 0, 'warnings' => []];

        // Map discipline natural keys → current Discipline objects (with crews).
        $disciplinesByKey = Discipline::where('event_id', $event->id)
            ->with('crews')
            ->get()
            ->keyBy(fn($d) => $this->disciplineKey($d));

        DB::transaction(function () use ($event, $day, $payload, $disciplinesByKey, &$stats) {
            // Wipe existing races on this day for this event.
            $start = Carbon::parse("{$day} 00:00:00");
            $end = $start->copy()->addDay();
            $toDelete = RaceResult::where(function ($q) use ($event) {
                $q->whereHas('discipline', fn($qq) => $qq->where('event_id', $event->id))
                  ->orWhere(function ($qq) use ($event) {
                      $qq->where('event_id', $event->id)->where('entry_type', 'break');
                  });
            })
                ->whereBetween('race_time', [$start, $end])
                ->pluck('id');
            CrewResult::whereIn('race_result_id', $toDelete)->delete();
            RaceResult::whereIn('id', $toDelete)->delete();

            foreach ($payload['races'] ?? [] as $racePayload) {
                $isBreak = ($racePayload['entry_type'] ?? null) === 'break';
                $discipline = null;
                if (!$isBreak) {
                    $key = $racePayload['discipline_key'] ?? null;
                    $discipline = $key ? $disciplinesByKey->get($key) : null;
                    if (!$discipline) {
                        $stats['warnings'][] = "Skipped race: unmatched discipline '{$key}' for stage '{$racePayload['stage']}'.";
                        continue;
                    }
                }
                $race = RaceResult::create([
                    'discipline_id' => $discipline?->id,
                    'event_id' => $isBreak ? $event->id : null,
                    'entry_type' => $isBreak ? 'break' : null,
                    'stage' => $racePayload['stage'] ?? null,
                    'race_number' => $racePayload['race_number'] ?? 0,
                    'race_time' => $racePayload['race_time'] ?? null,
                    'status' => $racePayload['status'] ?? 'SCHEDULED',
                    'label' => $racePayload['label'] ?? null,
                    'duration_seconds' => $racePayload['duration_seconds'] ?? null,
                    'shift_subsequent' => $racePayload['shift_subsequent'] ?? null,
                ]);
                $stats['races_restored']++;

                if ($isBreak) continue;

                $crewsByTeam = $discipline->crews->keyBy('team_id');
                foreach ($racePayload['crew_results'] ?? [] as $crP) {
                    $teamId = $crP['team_id'] ?? null;
                    $crew = $teamId ? $crewsByTeam->get($teamId) : null;
                    if (!$crew) continue;
                    CrewResult::create([
                        'race_result_id' => $race->id,
                        'crew_id' => $crew->id,
                        'lane' => $crP['lane'] ?? null,
                        'status' => $crP['status'] ?? null,
                        'time_ms' => $crP['time_ms'] ?? null,
                    ]);
                    $stats['crew_results_restored']++;
                }
            }
        });

        return $stats;
    }

    // ---------------- EVENT GRID (all days) ----------------

    /**
     * Bundle a grid_day capture for every event day into one payload.
     * Days without any races still appear so restore can safely wipe them.
     *
     * Payload shape:
     *   { days: [ { day: 'YYYY-MM-DD', races: [...] }, … ] }
     *
     * Used by the auto-snapshot pass in ScheduleGeneratorService: takes one
     * snapshot before every regenerate / recompute so the whole event can
     * be rolled back with one restore call.
     */
    private function captureEventGrid(Event $event): array
    {
        $event->loadMissing('eventDays');

        $days = [];
        foreach ($event->eventDays->sortBy('sort_order') as $ed) {
            $day = $ed->date instanceof Carbon ? $ed->date->toDateString() : (string) $ed->date;
            $dayPayload = $this->captureGridDay($event, $day);
            $days[] = ['day' => $day, 'races' => $dayPayload['races'] ?? []];
        }

        return ['days' => $days];
    }

    /**
     * Restore every day in a captured event_grid payload. Refuses if any
     * race in the event is currently IN_PROGRESS — restoring across a
     * running race would delete its lane assignments and results.
     *
     * @throws InvalidArgumentException when any race in the event is IN_PROGRESS
     */
    private function restoreEventGrid(Event $event, array $payload): array
    {
        $this->refuseIfAnyRaceInProgress($event, null);

        $stats = [
            'days_restored' => 0,
            'races_restored' => 0,
            'crew_results_restored' => 0,
            'warnings' => [],
        ];

        foreach ($payload['days'] ?? [] as $dp) {
            $day = $dp['day'] ?? null;
            if (!is_string($day) || $day === '') {
                $stats['warnings'][] = 'Skipped a day entry with no date.';
                continue;
            }
            $dayStats = $this->restoreGridDay($event, $day, ['races' => $dp['races'] ?? []]);
            $stats['days_restored']++;
            $stats['races_restored'] += $dayStats['races_restored'] ?? 0;
            $stats['crew_results_restored'] += $dayStats['crew_results_restored'] ?? 0;
            foreach ($dayStats['warnings'] ?? [] as $w) {
                $stats['warnings'][] = "{$day}: {$w}";
            }
        }

        return $stats;
    }

    /**
     * Refuse a restore that would clobber a running race. Scope is
     * 'event' (any IN_PROGRESS race in the event) or 'discipline'
     * (only within the given discipline_id).
     */
    private function refuseIfAnyRaceInProgress(Event $event, ?int $disciplineId): void
    {
        $query = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->where('status', 'IN_PROGRESS');
        if ($disciplineId !== null) {
            $query->where('discipline_id', $disciplineId);
        }
        $running = $query->first();
        if ($running) {
            throw new InvalidArgumentException(
                "Cannot restore: race {$running->race_number} ({$running->stage}) is currently IN_PROGRESS."
            );
        }
    }

    private function disciplineKey($discipline): string
    {
        return strtolower(implode('|', [
            (string) $discipline->boat_group,
            (string) $discipline->age_group,
            (string) $discipline->gender_group,
            (string) $discipline->distance,
            (string) ($discipline->competition ?? ''),
        ]));
    }

    private function requireDay(?string $day): string
    {
        if ($day === null || $day === '') {
            throw new InvalidArgumentException('grid_day category requires a "day" (YYYY-MM-DD).');
        }
        return $day;
    }
}
