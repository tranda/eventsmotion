<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Discipline;
use App\Models\DisciplineProgression;
use App\Services\Schedule\IdbfRacePlans;
use Illuminate\Http\Request;
use OutOfRangeException;
use InvalidArgumentException;

/**
 * View / override which IDBF race plan applies to a discipline.
 */
class DisciplineProgressionController extends BaseController
{
    public function __construct(private IdbfRacePlans $plans) {}

    /** GET /api/disciplines/{id}/progression */
    public function show($disciplineId)
    {
        $discipline = Discipline::with(['event', 'progression'])->find($disciplineId);
        if (!$discipline) {
            return $this->sendError('Discipline not found', [], 404);
        }

        $crewCount = $discipline->crews()->count();
        $laneCount = optional($discipline->event)->lane_count;

        $autoPick = null;
        if ($laneCount && $crewCount >= 2) {
            try {
                $autoPick = $this->plans->pickPlan($laneCount, $crewCount)->code;
            } catch (OutOfRangeException $e) {
                $autoPick = null;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'discipline_id' => $discipline->id,
                'crew_count' => $crewCount,
                'lane_count' => $laneCount,
                'auto_pick_code' => $autoPick,
                'override_code' => optional($discipline->progression)->race_plan_code,
                'effective_code' => optional($discipline->progression)->race_plan_code ?? $autoPick,
                'custom_stages' => optional($discipline->progression)->custom_stages,
            ],
        ]);
    }

    /**
     * PUT /api/disciplines/{id}/progression
     * Body:
     *   { "race_plan_code": "RP.3A" }                          // override
     *   { "race_plan_code": null }                              // clear override
     *   { "race_plan_code": "CUSTOM",
     *     "custom_stages": ["Round 1","Round 2","Final"] }      // custom stages
     */
    public function update(Request $request, $disciplineId)
    {
        $discipline = Discipline::with(['event'])->find($disciplineId);
        if (!$discipline) {
            return $this->sendError('Discipline not found', [], 404);
        }

        $validated = $request->validate([
            'race_plan_code' => 'present|nullable|string',
            'custom_stages' => 'nullable|array',
            'custom_stages.*' => 'string|max:255',
        ]);

        $code = $validated['race_plan_code'];
        $customStages = $validated['custom_stages'] ?? null;

        if ($code === 'CUSTOM') {
            if (!is_array($customStages) || empty($customStages)) {
                return $this->sendError('CUSTOM plan requires a non-empty custom_stages list.', [], 422);
            }
        } elseif ($code !== null) {
            try {
                $plan = $this->plans->getPlan($code);
            } catch (InvalidArgumentException $e) {
                return $this->sendError("Unknown race plan code: {$code}", [], 422);
            }
            $laneCount = optional($discipline->event)->lane_count;
            if ($laneCount && $plan->laneCount() !== $laneCount) {
                return $this->sendError(
                    "Plan {$code} is for {$plan->laneCount()} lanes, event uses {$laneCount}.",
                    [],
                    422
                );
            }
            $customStages = null; // non-custom plans don't store stages
        } else {
            $customStages = null;
        }

        DisciplineProgression::updateOrCreate(
            ['discipline_id' => $discipline->id],
            ['race_plan_code' => $code, 'custom_stages' => $customStages]
        );

        return $this->sendResponse(
            ['race_plan_code' => $code, 'custom_stages' => $customStages],
            'Progression updated.'
        );
    }

    /**
     * GET /api/events/{event}/plan-and-seeds
     * Bulk endpoint for the Plan & Seeds tab: returns active disciplines
     * with their progression + race-plan options in a single payload, so the
     * UI doesn't have to fire 2N+1 calls.
     */
    public function bulkForEvent($eventId)
    {
        $event = \App\Models\Event::with([
            'disciplines' => function ($q) {
                $q->where('status', 'active')->orderBy('id');
            },
            'disciplines.progression',
            'disciplines.crews',
            'eventDays.blocks',
        ])->find($eventId);

        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        // Pre-compute ordered blocks (day.sort_order, then block.sort_order)
        // so we can predict which day each discipline lands on without
        // touching the existing schedule.
        $orderedBlocks = [];
        foreach ($event->eventDays->sortBy('sort_order') as $day) {
            foreach ($day->blocks->sortBy('sort_order') as $b) {
                $orderedBlocks[] = ['day' => $day, 'block' => $b];
            }
        }

        $laneCount = (int) ($event->lane_count ?? 0);
        $disciplines = $event->disciplines->map(function ($d) use ($laneCount, $orderedBlocks) {
            $crewCount = $d->crews->count();

            // Auto-pick (best-fit IDBF plan).
            $autoPick = null;
            if ($laneCount && $crewCount >= 2) {
                try {
                    $autoPick = $this->plans->pickPlan($laneCount, $crewCount)->code;
                } catch (OutOfRangeException $e) {
                    $autoPick = null;
                }
            }

            // All valid options + CUSTOM. Mirrors the single-discipline
            // /race-plan-options endpoint.
            $options = ($laneCount && $crewCount >= 2)
                ? $this->plans->planOptions($laneCount, $crewCount)
                : [];
            $options[] = 'CUSTOM';

            // Predict which day this discipline's races land on by finding
            // the first ordered block whose filters match. Returns null if
            // no block matches (orphan / no scheduling rules cover it).
            $predictedDay = null;
            foreach ($orderedBlocks as $bd) {
                if ($this->disciplineMatchesBlock($d, $bd['block'])) {
                    $dayDate = $bd['day']->date;
                    $predictedDay = $dayDate instanceof \Carbon\Carbon
                        ? $dayDate->toDateString()
                        : (string) $dayDate;
                    break;
                }
            }

            return [
                'id' => $d->id,
                'name' => method_exists($d, 'getDisplayName')
                    ? $d->getDisplayName()
                    : (string) ($d->name ?? "Discipline {$d->id}"),
                'boat_group' => $d->boat_group,
                'age_group' => $d->age_group,
                'gender_group' => $d->gender_group,
                'distance' => $d->distance,
                'competition' => $d->competition,
                'status' => $d->status,
                'predicted_day' => $predictedDay,
                'progression' => [
                    'discipline_id' => $d->id,
                    'crew_count' => $crewCount,
                    'lane_count' => $laneCount,
                    'auto_pick_code' => $autoPick,
                    'override_code' => optional($d->progression)->race_plan_code,
                    'effective_code' => optional($d->progression)->race_plan_code ?? $autoPick,
                    'custom_stages' => optional($d->progression)->custom_stages,
                ],
                'race_plan_options' => $options,
            ];
        })->values();

        return $this->sendResponse([
            'event_id' => $event->id,
            'lane_count' => $laneCount,
            'disciplines' => $disciplines,
        ], 'Plan & Seeds bulk fetch.');
    }

    /**
     * Pre-generation filter match: does this discipline qualify for the
     * given block? Mirrors the logic in ScheduleGeneratorService::matchesBlock
     * but checks only the discipline (no stage / no race row). Stage filter
     * is ignored because pre-generation we don't know which stage names a
     * future race will have.
     */
    private function disciplineMatchesBlock($discipline, $block): bool
    {
        $gf = $block->gender_filter;
        if (is_array($gf) && !empty($gf)) {
            $map = ['M' => 'Open', 'W' => 'Women', 'X' => 'Mixed'];
            $needles = array_map(fn($v) => $map[strtoupper((string) $v)] ?? $v, $gf);
            if (!in_array($discipline->gender_group, $needles, true)) return false;
        }
        $df = $block->distance_filter;
        if (is_array($df) && !empty($df)) {
            $needles = array_values(array_filter(
                array_map(fn($v) => preg_replace('/\D/', '', (string) $v), $df),
                fn($v) => $v !== '',
            ));
            if (!in_array((string) $discipline->distance, $needles, true)) return false;
        }
        $cf = $block->competition_filter;
        if (is_array($cf) && !empty($cf)) {
            $c = strtolower((string) ($discipline->competition ?? ''));
            $needles = array_map(fn($v) => strtolower((string) $v), $cf);
            if (!in_array($c, $needles, true)) return false;
        }
        return true;
    }

    /** GET /api/disciplines/{id}/race-plan-options */
    public function options($disciplineId)
    {
        $discipline = Discipline::with('event')->find($disciplineId);
        if (!$discipline) {
            return $this->sendError('Discipline not found', [], 404);
        }

        $crewCount = $discipline->crews()->count();
        $laneCount = optional($discipline->event)->lane_count;

        if (!$laneCount || $crewCount < 2) {
            // Even with no IDBF plans available, allow CUSTOM so organizers
            // can still define their own stage list.
            return $this->sendResponse(['options' => ['CUSTOM']], 'Only CUSTOM available.');
        }

        $options = $this->plans->planOptions($laneCount, $crewCount);
        // CUSTOM is always available — lets organizers define their own stage list.
        $options[] = 'CUSTOM';

        return $this->sendResponse(
            ['options' => $options],
            'Race plan options.'
        );
    }
}
