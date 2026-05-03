<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Discipline;
use App\Models\Event;
use App\Services\Schedule\LaneSeeder;
use App\Services\Schedule\ScheduleGeneratorService;
use Carbon\Carbon;
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

    /** POST /api/events/{id}/schedule/generate */
    public function generate($eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        try {
            $result = $this->generator->generate($event);
        } catch (InvalidArgumentException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        } catch (Throwable $e) {
            \Log::error('Schedule generation failed', ['event_id' => $eventId, 'error' => $e->getMessage()]);
            return $this->sendError('Schedule generation failed.', [$e->getMessage()], 500);
        }

        return $this->sendResponse($result->toArray(), 'Schedule generated.');
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
