<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\ScheduleBlock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Configures days, blocks, and event-level lane count for the schedule builder.
 * All endpoints are admin-only via the AdminMiddleware applied at the route level.
 */
class ScheduleConfigController extends BaseController
{
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
            'lane_count' => 'required|integer|in:4,6,8',
        ]);

        $event->update($validated);

        return $this->sendResponse($event->only(['id', 'lane_count', 'schedule_status']), 'Schedule config updated.');
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
        $block = ScheduleBlock::find($blockId);
        if (!$block) {
            return $this->sendError('Schedule block not found', [], 404);
        }

        $validated = $this->validateBlock($request, false);
        $block->update($validated);

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
            'gender_filter.*' => 'string|in:M,W,X',
            'distance_filter' => 'nullable|array',
            'distance_filter.*' => 'string',
            'stage_filter' => 'nullable|array',
            'stage_filter.*' => 'string',
            'sort_order' => 'nullable|integer',
        ];
        return $request->validate($rules);
    }
}
