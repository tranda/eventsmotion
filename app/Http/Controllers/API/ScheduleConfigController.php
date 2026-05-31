<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\ScheduleBlock;
use App\Services\Schedule\ScheduleGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Configures days, blocks, and event-level lane count for the schedule builder.
 * All endpoints are admin-only via the AdminMiddleware applied at the route level.
 */
class ScheduleConfigController extends BaseController
{
    public function __construct(
        private ScheduleGeneratorService $generator,
    ) {}

    /**
     * GET /api/events/{id}/schedule-config
     * Returns lane_count, schedule_status, and the nested days+blocks tree.
     */
    public function show($eventId)
    {
        $event = Event::with(['eventDays.blocks' => function ($q) {
            $q->orderBy('sort_order');
        }])->find($eventId);

        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'event_id' => $event->id,
                'lane_count' => $event->lane_count,
                'default_rounds' => $event->default_rounds ?? 3,
                'min_crews_per_race' => $event->min_crews_per_race ?? 3,
                'color_map' => $event->color_map,
                'schedule_status' => $event->schedule_status,
                'schedule_published_at' => $event->schedule_published_at,
                'days' => $event->eventDays->map(fn(EventDay $day) => [
                    'id' => $day->id,
                    'date' => $day->date->toDateString(),
                    'name' => $day->name,
                    'sort_order' => $day->sort_order,
                    'blocks' => $day->blocks->map(fn(ScheduleBlock $b) => [
                        'id' => $b->id,
                        'name' => $b->name,
                        'start_time' => $b->start_time,
                        'gap_seconds' => $b->gap_seconds,
                        'gender_filter' => $b->gender_filter,
                        'distance_filter' => $b->distance_filter,
                        'stage_filter' => $b->stage_filter,
                        'competition_filter' => $b->competition_filter,
                        'sort_order' => $b->sort_order,
                    ]),
                ]),
            ],
        ]);
    }

    /**
     * PUT /api/events/{id}/schedule-config
     * Updates only the event-level fields (lane_count). Day/block CRUD use their
     * own endpoints to keep payloads small.
     */
    public function update(Request $request, $eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        $validated = $request->validate([
            'lane_count' => 'sometimes|integer|in:3,4,6,8,9',
            'default_rounds' => 'sometimes|integer|min:1|max:10',
            'min_crews_per_race' => 'sometimes|integer|min:1|max:20',
            // Free-form JSON map { category => { value => hex_color } }.
            // Validated shallowly here; the frontend owns the schema.
            'color_map' => 'sometimes|nullable|array',
        ]);

        $event->update($validated);

        return $this->sendResponse(
            $event->only(['id', 'lane_count', 'default_rounds', 'min_crews_per_race', 'color_map', 'schedule_status']),
            'Schedule config updated.',
        );
    }

    /** POST /api/events/{id}/event-days */
    public function storeDay(Request $request, $eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        $validated = $request->validate([
            'date' => 'required|date',
            'name' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer',
        ]);

        $validated['sort_order'] = $validated['sort_order']
            ?? ($event->eventDays()->max('sort_order') + 1);
        $validated['event_id'] = $event->id;

        $day = EventDay::create($validated);

        return $this->sendResponse($day, 'Event day created.');
    }

    /** PUT /api/event-days/{id} */
    public function updateDay(Request $request, $dayId)
    {
        $day = EventDay::find($dayId);
        if (!$day) {
            return $this->sendError('Event day not found', [], 404);
        }

        $validated = $request->validate([
            'date' => 'sometimes|date',
            'name' => 'nullable|string|max:255',
            'sort_order' => 'sometimes|integer',
        ]);

        $day->update($validated);

        return $this->sendResponse($day, 'Event day updated.');
    }

    /**
     * POST /api/event-days/{id}/copy-blocks
     * Body: { from_day_id: int, replace?: bool }
     *
     * Copies every block from from_day_id to the target day. With
     * replace=true (default), existing blocks on the target day are
     * deleted first — clean clone. Useful to make day 2's schedule
     * structurally identical to day 1's.
     */
    public function copyBlocks(Request $request, $targetDayId)
    {
        $target = EventDay::find($targetDayId);
        if (!$target) {
            return $this->sendError('Target day not found', [], 404);
        }
        $data = $request->validate([
            'from_day_id' => 'required|integer|different:target',
            'replace' => 'sometimes|boolean',
        ]);
        $source = EventDay::find($data['from_day_id']);
        if (!$source) {
            return $this->sendError('Source day not found', [], 404);
        }
        if ($source->event_id !== $target->event_id) {
            return $this->sendError('Source and target days must belong to the same event', [], 422);
        }

        $replace = (bool) ($data['replace'] ?? true);
        $copied = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($source, $target, $replace, &$copied) {
            if ($replace) {
                ScheduleBlock::where('event_day_id', $target->id)->delete();
            }
            foreach ($source->blocks()->orderBy('sort_order')->get() as $b) {
                ScheduleBlock::create([
                    'event_day_id' => $target->id,
                    'name' => $b->name,
                    'start_time' => $b->start_time,
                    'gap_seconds' => $b->gap_seconds,
                    'gender_filter' => $b->gender_filter,
                    'distance_filter' => $b->distance_filter,
                    'stage_filter' => $b->stage_filter,
                    'competition_filter' => $b->competition_filter,
                    'sort_order' => $b->sort_order,
                ]);
                $copied++;
            }
        });

        return $this->sendResponse(
            ['blocks_copied' => $copied],
            "Copied {$copied} block(s) from day {$source->date} to day {$target->date}.",
        );
    }

    /** DELETE /api/event-days/{id} */
    public function destroyDay($dayId)
    {
        $day = EventDay::find($dayId);
        if (!$day) {
            return $this->sendError('Event day not found', [], 404);
        }

        $day->delete();

        return $this->sendResponse(null, 'Event day deleted.');
    }

    /** POST /api/event-days/{id}/blocks */
    public function storeBlock(Request $request, $dayId)
    {
        $day = EventDay::find($dayId);
        if (!$day) {
            return $this->sendError('Event day not found', [], 404);
        }

        $validated = $this->validateBlock($request, true);
        $validated['sort_order'] = $validated['sort_order']
            ?? ($day->blocks()->max('sort_order') + 1);
        $validated['event_day_id'] = $day->id;

        $block = ScheduleBlock::create($validated);

        return $this->sendResponse($block, 'Schedule block created.');
    }

    /** PUT /api/schedule-blocks/{id} */
    public function updateBlock(Request $request, $blockId)
    {
        $block = ScheduleBlock::with('eventDay.event')->find($blockId);
        if (!$block) {
            return $this->sendError('Schedule block not found', [], 404);
        }

        $validated = $this->validateBlock($request, false);

        // If timing inputs change, the existing races in this block (and any
        // downstream blocks on the same day) need their race_time recomputed
        // from the new block.start + N*gap. Order is preserved — entries are
        // re-sorted by current race_time, so manual reorderings stick.
        $timingChanged =
            (array_key_exists('start_time', $validated)
                && (string) $validated['start_time'] !== (string) $block->start_time)
            || (array_key_exists('gap_seconds', $validated)
                && (int) $validated['gap_seconds'] !== (int) $block->gap_seconds);

        $block->update($validated);

        if ($timingChanged) {
            $event = $block->eventDay?->event;
            if ($event) {
                $this->generator->recomputeAllBlockTimes($event);
            }
        }

        return $this->sendResponse($block, 'Schedule block updated.');
    }

    /** DELETE /api/schedule-blocks/{id} */
    public function destroyBlock($blockId)
    {
        $block = ScheduleBlock::find($blockId);
        if (!$block) {
            return $this->sendError('Schedule block not found', [], 404);
        }

        $block->delete();

        return $this->sendResponse(null, 'Schedule block deleted.');
    }

    private function validateBlock(Request $request, bool $isCreate): array
    {
        $rules = [
            'name' => ($isCreate ? 'required' : 'sometimes') . '|string|max:255',
            'start_time' => ($isCreate ? 'required' : 'sometimes') . '|date_format:H:i,H:i:s',
            'gap_seconds' => ($isCreate ? 'required' : 'sometimes') . '|integer|min:30|max:7200',
            'gender_filter' => 'nullable|array',
            'gender_filter.*' => 'string|in:Open,Women,Mixed,M,W,X',
            'distance_filter' => 'nullable|array',
            'distance_filter.*' => 'string',
            'stage_filter' => 'nullable|array',
            'stage_filter.*' => 'string',
            'competition_filter' => 'nullable|array',
            'competition_filter.*' => 'string',
            'sort_order' => 'nullable|integer',
        ];
        return $request->validate($rules);
    }
}
