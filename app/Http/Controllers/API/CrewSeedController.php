<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Crew;
use App\Models\Discipline;
use Illuminate\Http\Request;

/**
 * View / edit per-crew seed numbers within a discipline. Seed numbers drive
 * IDBF heat lane seeding (1 = top seed → centre lanes).
 */
class CrewSeedController extends BaseController
{
    /** GET /api/disciplines/{id}/crew-seeds */
    public function show($disciplineId)
    {
        $discipline = Discipline::find($disciplineId);
        if (!$discipline) {
            return $this->sendError('Discipline not found', [], 404);
        }

        $crews = $discipline->crews()
            ->with('team')
            ->orderByRaw('seed_number IS NULL')
            ->orderBy('seed_number')
            ->orderBy('id')
            ->get();

        return $this->sendResponse(
            $crews->map(fn(Crew $c) => [
                'crew_id' => $c->id,
                'team_id' => $c->team_id,
                'team_name' => optional($c->team)->name,
                'seed_number' => $c->seed_number,
            ])->values(),
            'Crew seeds.'
        );
    }

    /**
     * PUT /api/disciplines/{id}/crew-seeds
     * Body: { "seeds": [{"crew_id": 1, "seed_number": 3}, ...] }
     * Validates: each crew_id must belong to the discipline, seed_numbers must
     * be unique and in 1..crewCount.
     */
    public function update(Request $request, $disciplineId)
    {
        $discipline = Discipline::find($disciplineId);
        if (!$discipline) {
            return $this->sendError('Discipline not found', [], 404);
        }

        $validated = $request->validate([
            'seeds' => 'required|array',
            'seeds.*.crew_id' => 'required|integer|exists:crews,id',
            'seeds.*.seed_number' => 'nullable|integer|min:1',
        ]);

        $disciplineCrewIds = $discipline->crews()->pluck('id')->all();
        $crewCount = count($disciplineCrewIds);

        $seenSeeds = [];
        foreach ($validated['seeds'] as $entry) {
            if (!in_array($entry['crew_id'], $disciplineCrewIds, true)) {
                return $this->sendError("Crew {$entry['crew_id']} is not in this discipline.", [], 422);
            }
            $seed = $entry['seed_number'] ?? null;
            if ($seed !== null) {
                if ($seed > $crewCount) {
                    return $this->sendError(
                        "seed_number {$seed} exceeds crew count {$crewCount}.",
                        [],
                        422
                    );
                }
                if (isset($seenSeeds[$seed])) {
                    return $this->sendError("seed_number {$seed} appears more than once.", [], 422);
                }
                $seenSeeds[$seed] = true;
            }
        }

        foreach ($validated['seeds'] as $entry) {
            Crew::where('id', $entry['crew_id'])->update([
                'seed_number' => $entry['seed_number'] ?? null,
            ]);
        }

        return $this->sendResponse(null, 'Crew seeds updated.');
    }

    /** POST /api/disciplines/{id}/crew-seeds/reset */
    public function reset($disciplineId)
    {
        $discipline = Discipline::find($disciplineId);
        if (!$discipline) {
            return $this->sendError('Discipline not found', [], 404);
        }

        $discipline->crews()->update(['seed_number' => null]);

        return $this->sendResponse(null, 'Crew seeds reset. Will be regenerated on next schedule generation.');
    }
}
