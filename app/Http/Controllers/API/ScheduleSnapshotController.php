<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Event;
use App\Models\ScheduleSnapshot;
use App\Services\Schedule\ScheduleSnapshotService;
use Illuminate\Http\Request;

/**
 * Named, event-scoped snapshots of the schedule builder state.
 *
 * Endpoints:
 *   GET  /api/events/{event}/snapshots                  → list
 *   POST /api/events/{event}/snapshots                  → create
 *        body: { category, day?, name }
 *   DELETE /api/snapshots/{id}                          → delete
 *   POST   /api/snapshots/{id}/restore                  → restore
 */
class ScheduleSnapshotController extends BaseController
{
    public function __construct(private ScheduleSnapshotService $snapshots) {}

    public function index(Request $request, $eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }
        $rows = ScheduleSnapshot::where('event_id', $event->id)
            ->orderByDesc('created_at')
            ->get(['id', 'event_id', 'category', 'day', 'name', 'created_by', 'created_at']);

        return $this->sendResponse(
            ['snapshots' => $rows->map(fn($s) => [
                'id' => $s->id,
                'category' => $s->category,
                'day' => $s->day?->toDateString(),
                'name' => $s->name,
                'created_by' => $s->created_by,
                'created_at' => $s->created_at?->toIso8601String(),
            ])->all()],
            'Snapshots listed.',
        );
    }

    public function store(Request $request, $eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        $data = $request->validate([
            'category' => 'required|string|in:setup,plan_seeds,grid_day',
            'day' => 'nullable|date_format:Y-m-d|required_if:category,grid_day',
            'name' => 'required|string|max:200',
        ]);

        try {
            $payload = $this->snapshots->capture($event, $data['category'], $data['day'] ?? null);
        } catch (\Throwable $e) {
            return $this->sendError('Capture failed.', [$e->getMessage()], 500);
        }

        $snap = ScheduleSnapshot::create([
            'event_id' => $event->id,
            'category' => $data['category'],
            'day' => $data['day'] ?? null,
            'name' => $data['name'],
            'payload' => $payload,
            'created_by' => optional($request->user())->id,
        ]);

        return $this->sendResponse([
            'id' => $snap->id,
            'category' => $snap->category,
            'day' => $snap->day?->toDateString(),
            'name' => $snap->name,
            'created_at' => $snap->created_at?->toIso8601String(),
        ], 'Snapshot saved.');
    }

    public function destroy($snapshotId)
    {
        $snap = ScheduleSnapshot::find($snapshotId);
        if (!$snap) {
            return $this->sendError('Snapshot not found', [], 404);
        }
        $snap->delete();
        return $this->sendResponse([], 'Snapshot deleted.');
    }

    public function restore(Request $request, $snapshotId)
    {
        $snap = ScheduleSnapshot::find($snapshotId);
        if (!$snap) {
            return $this->sendError('Snapshot not found', [], 404);
        }
        $event = Event::find($snap->event_id);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        try {
            $stats = $this->snapshots->restore(
                $event,
                $snap->category,
                is_array($snap->payload) ? $snap->payload : [],
                $snap->day?->toDateString(),
            );
        } catch (\Throwable $e) {
            \Log::error('Snapshot restore failed', [
                'snapshot_id' => $snap->id,
                'error' => $e->getMessage(),
            ]);
            return $this->sendError('Restore failed.', [$e->getMessage()], 500);
        }

        return $this->sendResponse($stats, 'Snapshot restored.');
    }
}
