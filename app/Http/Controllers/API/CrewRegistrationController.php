<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Crew;
use App\Models\Event;
use App\Services\Schedule\CrewRegistrationImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CrewRegistrationController extends BaseController
{
    public function __construct(private CrewRegistrationImporter $importer) {}

    /**
     * POST /api/events/{event}/registrations/import
     * Body: { csv: string, dry_run?: bool, sync?: bool,
     *         team_mappings?: [{csv_team_name, csv_club_name, team_id}, ...] }
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
            'sync' => 'sometimes|boolean',
            'team_mappings' => 'sometimes|array',
            'team_mappings.*.csv_team_name' => 'required_with:team_mappings|string',
            'team_mappings.*.csv_club_name' => 'required_with:team_mappings|string',
            'team_mappings.*.team_id' => 'required_with:team_mappings|integer',
        ]);

        try {
            $result = $this->importer->importForEvent(
                $event,
                $data['csv'],
                (bool) ($data['dry_run'] ?? false),
                (bool) ($data['sync'] ?? false),
                $data['team_mappings'] ?? [],
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

    /**
     * DELETE /api/events/{event}/registrations
     * Deletes every Crew row tied to a discipline of this event. Destructive,
     * irreversible. Used to wipe and re-import from scratch.
     */
    public function clearAll(Request $request, $eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        $disciplineIds = $event->disciplines()->pluck('id');

        try {
            $deleted = DB::transaction(function () use ($disciplineIds) {
                return Crew::whereIn('discipline_id', $disciplineIds)->delete();
            });
        } catch (\Throwable $e) {
            \Log::error('Clear registrations failed', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
            return $this->sendError('Clear failed.', [$e->getMessage()], 500);
        }

        return $this->sendResponse(
            ['crews_deleted' => $deleted],
            "Cleared {$deleted} registrations.",
        );
    }
}
