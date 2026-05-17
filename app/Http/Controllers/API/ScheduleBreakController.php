<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Event;
use App\Models\RaceResult;
use App\Services\Schedule\ScheduleGeneratorService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Non-race schedule entries (lunch break, medal ceremony, etc.).
 *
 * Stored in race_results with entry_type='break'. Two modes:
 *   - shift_subsequent=true  → inserting/editing/deleting the break shifts
 *                              later same-day SCHEDULED rows by the duration.
 *   - shift_subsequent=false → parallel mode; the break sits in time but does
 *                              not affect race scheduling (e.g. medal ceremony
 *                              while another race runs).
 */
class ScheduleBreakController extends BaseController
{
    public function __construct(private ScheduleGeneratorService $generator) {}

    /** POST /api/events/{event}/schedule/breaks */
    public function store(Request $request, $eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        $data = $request->validate([
            'race_time' => 'required|date',
            'duration_seconds' => 'required|integer|min:1|max:86400',
            'label' => 'required|string|max:200',
            'shift_subsequent' => 'nullable|boolean',
        ]);

        $shift = $data['shift_subsequent'] ?? true;
        $time = Carbon::parse($data['race_time']);

        $break = DB::transaction(function () use ($event, $time, $data, $shift) {
            $break = RaceResult::create([
                'entry_type' => 'break',
                'event_id' => $event->id,
                'discipline_id' => null,
                'race_number' => null,
                'race_time' => $time,
                'duration_seconds' => (int) $data['duration_seconds'],
                'label' => $data['label'],
                'stage' => $data['label'], // mirror into stage so legacy readers see something useful
                'status' => 'SCHEDULED',
                'shift_subsequent' => $shift,
            ]);

            if ($shift) {
                $minutes = $this->secsToMinutes((int) $break->duration_seconds);
                $shifted = $this->shiftLaterRowsByMinutes(
                    $event->id,
                    $time,
                    $minutes,
                    excludeId: $break->id,
                );
                \Log::info('🍴 Break created — shift run', [
                    'event_id' => $event->id,
                    'break_id' => $break->id,
                    'break_time' => $time->toDateTimeString(),
                    'minutes' => $minutes,
                    'rows_shifted' => $shifted,
                ]);
            } else {
                \Log::info('🎉 Break created — parallel mode, no shift', [
                    'event_id' => $event->id,
                    'break_id' => $break->id,
                    'break_time' => $time->toDateTimeString(),
                ]);
            }

            return $break;
        });

        return $this->sendResponse($break->fresh()->toArray(), 'Break created.');
    }

    /** PUT /api/race-results/{id} hits the existing RaceResultController; this is a dedicated break-update endpoint used when duration/mode changes. */
    public function update(Request $request, $id)
    {
        $break = RaceResult::find($id);
        if (!$break || !$break->isBreak()) {
            return $this->sendError('Break not found', [], 404);
        }

        $data = $request->validate([
            'race_time' => 'nullable|date',
            'duration_seconds' => 'nullable|integer|min:1|max:86400',
            'label' => 'nullable|string|max:200',
            'shift_subsequent' => 'nullable|boolean',
        ]);

        DB::transaction(function () use ($break, $data) {
            $oldDuration = (int) ($break->duration_seconds ?? 0);
            $oldShift = (bool) $break->shift_subsequent;
            $oldTime = $break->race_time ? Carbon::parse($break->race_time) : null;

            if (array_key_exists('race_time', $data) && $data['race_time'] !== null) {
                $break->race_time = Carbon::parse($data['race_time']);
            }
            if (array_key_exists('duration_seconds', $data) && $data['duration_seconds'] !== null) {
                $break->duration_seconds = (int) $data['duration_seconds'];
            }
            if (array_key_exists('label', $data) && $data['label'] !== null) {
                $break->label = $data['label'];
                $break->stage = $data['label'];
            }
            if (array_key_exists('shift_subsequent', $data) && $data['shift_subsequent'] !== null) {
                $break->shift_subsequent = (bool) $data['shift_subsequent'];
            }
            $break->save();

            // If the break is still in shift mode and duration changed, apply the delta.
            // Time changes are deliberately not auto-resynced; user can manually shift later races.
            $newDuration = (int) $break->duration_seconds;
            $newShift = (bool) $break->shift_subsequent;
            if ($newShift && $oldShift && $oldTime && $newDuration !== $oldDuration) {
                $delta = $newDuration - $oldDuration;
                if ($delta !== 0) {
                    $this->shiftLaterRowsByMinutes(
                        $break->event_id,
                        $oldTime,
                        $this->secsToMinutes($delta),
                        excludeId: $break->id,
                    );
                }
            }
        });

        return $this->sendResponse($break->fresh()->toArray(), 'Break updated.');
    }

    /** DELETE /api/race-results/{id} handles break deletion via RaceResultController; this version exists for explicit break delete + unshift. */
    public function destroy($id)
    {
        $break = RaceResult::find($id);
        if (!$break || !$break->isBreak()) {
            return $this->sendError('Break not found', [], 404);
        }

        DB::transaction(function () use ($break) {
            $time = $break->race_time ? Carbon::parse($break->race_time) : null;
            $duration = (int) ($break->duration_seconds ?? 0);
            $shift = (bool) $break->shift_subsequent;
            $eventId = $break->event_id;
            $break->delete();
            if ($shift && $time && $duration > 0 && $eventId) {
                $this->shiftLaterRowsByMinutes(
                    $eventId,
                    $time,
                    -$this->secsToMinutes($duration),
                    excludeId: null,
                );
            }
        });

        return $this->sendResponse([], 'Break deleted.');
    }

    /**
     * Shift all SCHEDULED rows for the event at-or-after the given time by
     * N minutes (signed). Same-day only. Optionally exclude a specific row id
     * (the break itself, so it doesn't shift itself on create).
     */
    private function shiftLaterRowsByMinutes(int $eventId, Carbon $fromTime, int $minutes, ?int $excludeId): int
    {
        if ($minutes === 0) {
            return 0;
        }
        $query = RaceResult::forEvent($eventId)
            ->where('status', 'SCHEDULED')
            ->whereNotNull('race_time')
            ->where('race_time', '>=', $fromTime)
            ->whereDate('race_time', $fromTime->toDateString());
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }
        $count = 0;
        foreach ($query->get() as $row) {
            $row->race_time = Carbon::parse($row->race_time)->addMinutes($minutes);
            $row->save();
            $count++;
        }
        return $count;
    }

    private function secsToMinutes(int $seconds): int
    {
        // Round half-up so an odd-second duration still shifts a whole minute.
        return (int) round($seconds / 60);
    }
}
