<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Discipline;
use App\Models\Event;
use App\Models\RaceResult;
use App\Services\Schedule\LaneSeeder;
use App\Services\Schedule\ScheduleGeneratorService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Generates and (un)publishes a draft schedule for an event.
 * All endpoints are admin-only via AdminMiddleware applied at the route level.
 */
class ScheduleGenerationController extends BaseController
{
    public function __construct(
        private ScheduleGeneratorService $generator,
        private LaneSeeder $laneSeeder,
    ) {}

    /**
     * POST /api/events/{id}/schedule/generate
     * Body:
     *   { clean?: bool }            — wipes preserved drag-edits, rebuilds
     *   { day?: "YYYY-MM-DD" }      — only regenerates disciplines whose
     *                                 first matching block lands on that
     *                                 day; other days are left alone.
     */
    public function generate(\Illuminate\Http\Request $request, $eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        $data = $request->validate([
            'clean' => 'sometimes|boolean',
            'day' => 'sometimes|nullable|date_format:Y-m-d',
        ]);
        $clean = (bool) ($data['clean'] ?? false);
        $day = $data['day'] ?? null;

        try {
            $result = $this->generator->generate($event, clean: $clean, day: $day);
        } catch (InvalidArgumentException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        } catch (Throwable $e) {
            \Log::error('Schedule generation failed', ['event_id' => $eventId, 'error' => $e->getMessage()]);
            return $this->sendError('Schedule generation failed.', [$e->getMessage()], 500);
        }

        $message = $day
            ? "Schedule generated for {$day}."
            : 'Schedule generated.';
        return $this->sendResponse($result->toArray(), $message);
    }

    /** POST /api/disciplines/{id}/schedule/regenerate */
    public function regenerateDiscipline($disciplineId)
    {
        $discipline = Discipline::with('event')->find($disciplineId);
        if (!$discipline) {
            return $this->sendError('Discipline not found', [], 404);
        }

        try {
            $result = $this->generator->regenerateDiscipline($discipline);
        } catch (InvalidArgumentException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        } catch (Throwable $e) {
            \Log::error('Discipline regeneration failed', ['discipline_id' => $disciplineId, 'error' => $e->getMessage()]);
            return $this->sendError('Discipline regeneration failed.', [$e->getMessage()], 500);
        }

        return $this->sendResponse($result->toArray(), 'Discipline schedule regenerated.');
    }

    /** POST /api/events/{id}/schedule/publish */
    public function publish($eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        $event->schedule_status = 'published';
        $event->schedule_published_at = Carbon::now();
        $event->save();

        return $this->sendResponse(
            $event->only(['id', 'schedule_status', 'schedule_published_at']),
            'Schedule published.'
        );
    }

    /** POST /api/disciplines/{id}/schedule/seed-next-round */
    public function seedNextRound($disciplineId)
    {
        $discipline = Discipline::with('event')->find($disciplineId);
        if (!$discipline) {
            return $this->sendError('Discipline not found', [], 404);
        }

        try {
            $result = $this->laneSeeder->seedNextRound($discipline);
        } catch (InvalidArgumentException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        } catch (Throwable $e) {
            \Log::error('Lane seeding failed', ['discipline_id' => $disciplineId, 'error' => $e->getMessage()]);
            return $this->sendError('Lane seeding failed.', [$e->getMessage()], 500);
        }

        $warnings = $result->warnings;
        if ($result->skipped && $result->skippedReason !== null) {
            array_unshift($warnings, $result->skippedReason);
        }

        // Use GenerationResult-shaped envelope so the frontend reuses the same parser.
        return $this->sendResponse([
            'races_created' => 0,
            'crew_lanes_assigned' => $result->crewLanesAssigned,
            'races_per_discipline' => [],
            'warnings' => $warnings,
        ], $result->seededStage !== null
            ? "Seeded {$result->seededStage}."
            : ($result->skippedReason ?? 'No stage to seed.'));
    }

    /**
     * POST /api/events/{id}/schedule/shift
     * Shifts all SCHEDULED races at-or-after the pivot race's race_time by
     * N minutes (signed). If same_day_only is true (default), only races on
     * the same calendar day as the pivot are affected.
     */
    public function shift(Request $request, $eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        $validated = $request->validate([
            'from_race_id' => 'required|integer|exists:race_results,id',
            'minutes' => 'required|integer|min:-720|max:720',
            'same_day_only' => 'nullable|boolean',
        ]);

        $pivot = RaceResult::with('discipline')->find($validated['from_race_id']);
        if (!$pivot || $pivot->discipline?->event_id !== $event->id) {
            return $this->sendError('Pivot race does not belong to this event', [], 422);
        }
        if ($pivot->race_time === null) {
            return $this->sendError('Pivot race has no race_time set', [], 422);
        }

        $count = $this->generator->shiftFrom(
            $pivot,
            (int) $validated['minutes'],
            $validated['same_day_only'] ?? true,
        );

        return $this->sendResponse(
            ['races_shifted' => $count, 'minutes' => (int) $validated['minutes']],
            "Shifted {$count} race(s) by {$validated['minutes']} minute(s).",
        );
    }

    /** POST /api/events/{id}/schedule/unpublish */
    public function unpublish($eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        $event->schedule_status = 'draft';
        $event->save();

        return $this->sendResponse(
            $event->only(['id', 'schedule_status', 'schedule_published_at']),
            'Schedule unpublished. Now in draft mode.'
        );
    }
}
