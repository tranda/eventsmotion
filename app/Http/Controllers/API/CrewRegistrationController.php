<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Event;
use App\Services\Schedule\CrewRegistrationImporter;
use Illuminate\Http\Request;

class CrewRegistrationController extends BaseController
{
    public function __construct(private CrewRegistrationImporter $importer) {}

    /**
     * POST /api/events/{event}/registrations/import
     * Body: { csv: string, dry_run?: bool }
     * Returns: { matched_count, crews_created, crews_skipped_existing,
     *            disciplines_created, unmatched_teams[], warnings[] }
     */
    public function import(Request $request, $eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        $data = $request->validate([
            'csv' => 'required|string|max:1048576', // up to 1 MiB
            'dry_run' => 'sometimes|boolean',
        ]);

        try {
            $result = $this->importer->importForEvent(
                $event,
                $data['csv'],
                (bool) ($data['dry_run'] ?? false),
            );
        } catch (\Throwable $e) {
            \Log::error('Crew registration import failed', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
            return $this->sendError('Import failed.', [$e->getMessage()], 500);
        }

        return $this->sendResponse($result, ($data['dry_run'] ?? false)
            ? 'Preview only — nothing committed.'
            : 'Registrations imported.');
    }
}
