<?php

namespace App\Http\Controllers\API;

use App\Models\Athlete;
use App\Models\BoatCheckin;
use App\Models\Crew;
use App\Models\CrewAthlete;
use App\Models\CrewResult;
use App\Models\RaceResult;
use App\Services\AthleteQrCode;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

/**
 * Boat marshal check-in: the marshal picks a race (so the stage is known),
 * then a crew, and scans each athlete who gets on board. Reserves can be
 * scanned too; the gender-mix check runs on who actually boarded.
 */
class BoatCheckinController extends BaseController
{
    /** GET race-results/{race}/crews/{crew}/boat-checkins */
    public function show(RaceResult $race, Crew $crew)
    {
        if ($error = $this->guard($race, $crew)) {
            return $error;
        }

        return response()->json($this->state($race, $crew));
    }

    /** POST race-results/{race}/crews/{crew}/boat-checkins  body: { qr } */
    public function store(Request $request, RaceResult $race, Crew $crew)
    {
        if ($error = $this->guard($race, $crew)) {
            return $error;
        }

        $request->validate(['qr' => 'required|string|max:1000']);

        $athleteId = AthleteQrCode::verify($request->input('qr'));
        if ($athleteId === null) {
            return $this->result('invalid_qr', 'QR code is not valid.', $race, $crew, null, 422);
        }

        $athlete = Athlete::find($athleteId);
        $seat = CrewAthlete::where('crew_id', $crew->id)->where('athlete_id', $athleteId)->first();

        if (!$athlete || !$seat) {
            return $this->result('not_in_crew', 'Athlete is not in this crew.', $race, $crew, $athlete, 422);
        }

        $existing = BoatCheckin::where('race_result_id', $race->id)->where('athlete_id', $athleteId)->first();
        if ($existing && (int) $existing->crew_id !== (int) $crew->id) {
            return $this->result('in_other_crew', 'Athlete is already on board another boat in this race.', $race, $crew, $athlete, 409);
        }
        if ($existing) {
            return $this->result('already_checked_in', 'Athlete is already checked in.', $race, $crew, $athlete);
        }

        try {
            BoatCheckin::create([
                'race_result_id' => $race->id,
                'crew_id' => $crew->id,
                'athlete_id' => $athleteId,
                'stage' => $race->stage,
                'race_number' => $race->race_number,
                'seat_no' => $seat->no,
                'role' => $this->role((int) $seat->no, $this->paddlerSeats($crew)),
                'checked_by' => $request->user()->id,
                'checked_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Same athlete scanned twice at the same moment: unique index wins.
            return $this->result('already_checked_in', 'Athlete is already checked in.', $race, $crew, $athlete);
        }

        return $this->result('checked_in', 'Athlete checked in.', $race, $crew, $athlete);
    }

    /** DELETE race-results/{race}/crews/{crew}/boat-checkins/{athlete} */
    public function destroy(RaceResult $race, Crew $crew, int $athlete)
    {
        if ($error = $this->guard($race, $crew)) {
            return $error;
        }

        BoatCheckin::where('race_result_id', $race->id)
            ->where('crew_id', $crew->id)
            ->where('athlete_id', $athlete)
            ->delete();

        return response()->json($this->state($race, $crew));
    }

    /** GET athletes/{athlete}/qr-token — signed QR string for badges. */
    public function qrToken(Request $request, Athlete $athlete)
    {
        $user = $request->user();
        $canSee = ($user->access_level ?? 0) >= 1 || (int) $user->club_id === (int) $athlete->club_id;
        if (!$canSee) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json(['token' => AthleteQrCode::generate($athlete)]);
    }

    private function guard(RaceResult $race, Crew $crew)
    {
        if ($race->isBreak()) {
            return response()->json(['message' => 'Not a race.'], 422);
        }

        $crew->loadMissing('discipline.event');
        $inRace = CrewResult::where('race_result_id', $race->id)->where('crew_id', $crew->id)->exists()
            || (int) $crew->discipline_id === (int) $race->discipline_id
            || (int) optional($crew->discipline)->combined_with_discipline_id === (int) $race->discipline_id;

        if (!$inRace) {
            return response()->json(['message' => 'Crew is not in this race.'], 422);
        }

        return null;
    }

    private function result(string $status, string $message, RaceResult $race, Crew $crew, ?Athlete $athlete, int $code = 200)
    {
        return response()->json([
            'status' => $status,
            'message' => $message,
            'athlete' => $athlete,
            'checkin' => $this->state($race, $crew),
        ], $code);
    }

    /** Full roster with check-in flags plus the on-board mix summary. */
    private function state(RaceResult $race, Crew $crew): array
    {
        $paddlerSeats = $this->paddlerSeats($crew);
        $checkins = BoatCheckin::where('race_result_id', $race->id)
            ->where('crew_id', $crew->id)
            ->get()
            ->keyBy('athlete_id');

        $roster = CrewAthlete::where('crew_id', $crew->id)
            ->with('athlete')
            ->orderBy('no')
            ->get()
            ->filter(fn ($ca) => $ca->athlete !== null)
            ->map(function ($ca) use ($checkins, $paddlerSeats) {
                $checkin = $checkins->get($ca->athlete_id);
                return [
                    'no' => (int) $ca->no,
                    'role' => $this->role((int) $ca->no, $paddlerSeats),
                    'athlete' => $ca->athlete,
                    'checked_in' => $checkin !== null,
                    'checked_at' => optional(optional($checkin)->checked_at)->toIso8601String(),
                ];
            })
            ->values();

        return [
            'race_result_id' => $race->id,
            'stage' => $race->stage,
            'race_number' => $race->race_number,
            'crew_id' => $crew->id,
            'roster' => $roster,
            'checked_in_count' => $checkins->count(),
            'summary' => $this->summary($roster->where('checked_in', true)->values()->all(), $crew, $paddlerSeats),
        ];
    }

    /**
     * Counts paddlers on board (everyone except the drummer and helm seats;
     * reserves count as paddlers) and warns about the gender mix.
     */
    private function summary(array $onBoard, Crew $crew, int $paddlerSeats): array
    {
        $paddlers = array_values(array_filter($onBoard, fn ($r) => !in_array($r['role'], ['drummer', 'helm'], true)));
        $female = count(array_filter($paddlers, fn ($r) => $r['athlete']->gender === 'Female'));
        $male = count($paddlers) - $female;

        $warnings = [];
        if (count($paddlers) > $paddlerSeats) {
            $warnings[] = "More than {$paddlerSeats} paddlers on board (" . count($paddlers) . ').';
        }

        $boatGroup = optional($crew->discipline)->boat_group;
        $gender = strtolower(trim((string) optional($crew->discipline)->gender_group));

        if (in_array($gender, ['women', 'w', 'female'], true) && $male > 0) {
            $warnings[] = "{$male} male paddler(s) in a Women boat.";
        } elseif (in_array($gender, ['mixed', 'mix', 'x'], true)) {
            // Same rule coaches see when building the crew: the event's
            // Min/Max Gender for this boat size, applied to each gender.
            [$min, $max] = $this->mixRule($crew, $boatGroup);
            if ($max > 0 && count($paddlers) >= $paddlerSeats) {
                foreach (['women' => $female, 'men' => $male] as $label => $n) {
                    if ($n < $min) {
                        $warnings[] = "Only {$n} {$label} paddling, Mixed needs at least {$min}.";
                    } elseif ($n > $max) {
                        $warnings[] = "{$n} {$label} paddling, Mixed allows at most {$max}.";
                    }
                }
            }
        }

        return [
            'paddlers' => count($paddlers),
            'paddler_seats' => $paddlerSeats,
            'female' => $female,
            'male' => $male,
            'warnings' => $warnings,
        ];
    }

    /** Event's Min/Max Gender for the boat size; [0, 0] when not set. */
    private function mixRule(Crew $crew, ?string $boatGroup): array
    {
        $event = optional($crew->discipline)->event;
        if (!$event) {
            return [0, 0];
        }
        $prefix = $boatGroup === 'Small' ? 'small' : 'standard';

        return [(int) $event->{"{$prefix}_min_gender"}, (int) $event->{"{$prefix}_max_gender"}];
    }

    private function paddlerSeats(Crew $crew): int
    {
        $crew->loadMissing('discipline');
        $group = optional($crew->discipline)->boat_group;

        return (int) config("boat_checkin.paddlers.{$group}", 20);
    }

    private function role(int $no, int $paddlerSeats): string
    {
        if ($no === 0) return 'drummer';
        if ($no <= $paddlerSeats) return 'paddler';
        if ($no === $paddlerSeats + 1) return 'helm';
        return 'reserve';
    }
}
