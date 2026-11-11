<?php

namespace App\Services\Badges;

use App\Models\Athlete;
use App\Models\BadgePerson;
use App\Models\Club;
use App\Models\Discipline;
use App\Models\Event;
use App\Services\AthleteQrCode;
use Illuminate\Support\Facades\DB;

/**
 * Builds the list of people who get a badge for an event:
 *  - every athlete on a crew in the event (type "athlete"),
 *  - staff flagged on athletes of participating clubs (coach / official /
 *    media / supporter) who are not on a crew,
 *  - people added by hand to badge_people (volunteers, VIPs, media, ...).
 *
 * Each entry is a flat array used by the API list and by BadgeRenderer.
 */
class BadgeService
{
    public const GENDER_CODES = ['Female' => 'FEMALE', 'Male' => 'MALE'];

    /** Folder for this event's uploaded badge files (logo, background, photos). */
    public static function storageDir(int $eventId): string
    {
        return storage_path('app/badges/' . $eventId);
    }

    public function entries(Event $event): array
    {
        $disciplineIds = Discipline::where('event_id', $event->id)->pluck('id');

        $crewRows = DB::table('crew_athletes')
            ->join('crews', 'crews.id', '=', 'crew_athletes.crew_id')
            ->join('teams', 'teams.id', '=', 'crews.team_id')
            ->whereIn('crews.discipline_id', $disciplineIds)
            ->whereNotNull('crew_athletes.athlete_id')
            ->where(function ($q) {
                $q->whereNull('teams.active')->orWhere('teams.active', '!=', 0);
            })
            ->select('crew_athletes.athlete_id', 'teams.club_id')
            ->get();

        $athleteIds = $crewRows->pluck('athlete_id')->unique()->values();
        $clubIds = $crewRows->pluck('club_id')->filter()->unique()->values();

        $crewAthletes = Athlete::whereIn('id', $athleteIds)->get();

        $staff = Athlete::whereIn('club_id', $clubIds)
            ->whereNotIn('id', $athleteIds)
            ->where(function ($q) {
                $q->where('official', 1)->orWhere('coach', 1)
                    ->orWhere('media', 1)->orWhere('supporter', 1);
            })
            ->get();

        $clubs = Club::whereIn('id', $crewAthletes->pluck('club_id')->merge($staff->pluck('club_id'))->unique())
            ->get()->keyBy('id');

        $entries = [];
        foreach ($crewAthletes as $a) {
            $entries[] = $this->fromAthlete($a, 'athlete', $clubs->get($a->club_id));
        }
        foreach ($staff as $a) {
            $entries[] = $this->fromAthlete($a, $this->staffType($a), $clubs->get($a->club_id));
        }
        foreach (BadgePerson::where('event_id', $event->id)->get() as $p) {
            $entries[] = $this->fromPerson($p);
        }

        usort($entries, fn ($x, $y) => [$x['group'], $x['last_name'], $x['first_name']]
            <=> [$y['group'], $y['last_name'], $y['first_name']]);

        return $entries;
    }

    private function staffType(Athlete $a): string
    {
        if ($a->official) return 'official';
        if ($a->coach) return 'coach';
        if ($a->media) return 'media';
        return 'support';
    }

    private function fromAthlete(Athlete $a, string $type, ?Club $club): array
    {
        $photo = $a->photo ? public_path('photos/' . basename($a->photo)) : null;
        $clubName = $club->name ?? $a->club_name ?? '';

        return [
            'key' => 'a' . $a->id,
            'source' => 'athlete',
            'id' => (int) $a->id,
            'type' => $type,
            'first_name' => (string) $a->first_name,
            'last_name' => (string) $a->last_name,
            'club' => $clubName,
            'country' => $club->country ?? '',
            'group' => $clubName !== '' ? $clubName : 'No club',
            'gender' => $type === 'athlete' ? (self::GENDER_CODES[$a->gender] ?? null) : null,
            'category' => $type === 'athlete' ? (string) ($a->category ?? '') : '',
            'edbf_id' => (string) ($a->edbf_id ?? ''),
            'photo_path' => $photo && is_file($photo) ? $photo : null,
            'photo_file' => $photo && is_file($photo) ? basename($photo) : null,
            'qr' => $type === 'athlete' ? fn () => AthleteQrCode::generate($a) : null,
        ];
    }

    private function fromPerson(BadgePerson $p): array
    {
        $photo = $p->photo ? self::storageDir($p->event_id) . '/' . basename($p->photo) : null;
        $org = (string) ($p->organisation ?? '');

        return [
            'key' => 'p' . $p->id,
            'source' => 'person',
            'id' => (int) $p->id,
            'type' => $p->type,
            'first_name' => (string) $p->first_name,
            'last_name' => (string) $p->last_name,
            'club' => $org,
            'country' => (string) ($p->country ?? ''),
            'group' => $org !== '' ? $org : 'Guests and staff',
            'gender' => null,
            'category' => '',
            'edbf_id' => '',
            'photo_path' => $photo && is_file($photo) ? $photo : null,
            'photo_file' => $photo && is_file($photo) ? basename($photo) : null,
            'qr' => null,
        ];
    }

    /** Entry without closures/paths, for JSON. */
    public static function forApi(array $e): array
    {
        return [
            'key' => $e['key'],
            'source' => $e['source'],
            'id' => $e['id'],
            'type' => $e['type'],
            'first_name' => $e['first_name'],
            'last_name' => $e['last_name'],
            'club' => $e['club'],
            'country' => $e['country'],
            'group' => $e['group'],
            'gender' => $e['gender'],
            'category' => $e['category'],
            'edbf_id' => $e['edbf_id'],
            'has_photo' => $e['photo_path'] !== null,
            // athletes: public /photos/{file}; people: badges/files/{file} (auth)
            'photo_file' => $e['photo_file'] ?? null,
        ];
    }
}
