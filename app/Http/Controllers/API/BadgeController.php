<?php

namespace App\Http\Controllers\API;

use App\Models\BadgePerson;
use App\Models\BadgeTemplate;
use App\Models\Event;
use App\Services\Badges\BadgeRenderer;
use App\Services\Badges\BadgeService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use ZipArchive;

/**
 * Event accreditation badges (Event Managers and up, see routes/api.php).
 *
 * GET    events/{event}/badges                     template + everyone who gets a badge
 * POST   events/{event}/badges/template            save settings (+ logo/background upload)
 * POST   events/{event}/badges/people              add a non-crew person
 * POST   events/{event}/badges/people/{person}     update (POST so multipart photo works)
 * DELETE events/{event}/badges/people/{person}
 * GET    events/{event}/badges/files/{name}        uploaded logo/background/photo
 * GET    events/{event}/badges/download            PDF (split=single) or ZIP per club (split=club);
 *                                                   sides=duplex: pages alternate front, back
 *                                                   sides=spread: front and back side by side on one page
 */
class BadgeController extends BaseController
{
    private const IMAGE_RULE = 'image|mimes:jpg,jpeg,png|max:8192';

    public function __construct(private BadgeService $service) {}

    public function index($eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        $people = BadgePerson::where('event_id', $event->id)->orderBy('last_name')->get();

        return response()->json([
            'template' => BadgeTemplate::forEvent($event)->toApi($event),
            'entries' => array_map([BadgeService::class, 'forApi'], $this->service->entries($event)),
            'people' => $people,
        ]);
    }

    public function saveTemplate(Request $request, $eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        $data = Validator::make($request->all(), [
            'size' => ['required', Rule::in(BadgeTemplate::SIZES)],
            'layout' => ['nullable', Rule::in(BadgeTemplate::LAYOUTS)],
            'banner_text' => 'nullable|string|max:60',
            'banner_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'footer_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'bleed_mm' => 'required|integer|min:0|max:5',
            'fields' => 'nullable|string',   // JSON object (multipart)
            'types' => 'nullable|string',    // JSON object (multipart)
            'areas' => 'nullable|string',    // JSON list (multipart)
            'logo' => 'nullable|' . self::IMAGE_RULE,
            'background' => 'nullable|' . self::IMAGE_RULE,
            'back_background' => 'nullable|' . self::IMAGE_RULE,
            'back_text' => 'nullable|string|max:1000',
            'remove_logo' => 'nullable|boolean',
            'remove_background' => 'nullable|boolean',
            'remove_back_background' => 'nullable|boolean',
        ])->validate();

        $tpl = BadgeTemplate::forEvent($event);
        $tpl->fill([
            'size' => $data['size'],
            'banner_text' => $data['banner_text'] ?? null,
            'banner_color' => strtoupper($data['banner_color']),
            'footer_color' => strtoupper($data['footer_color']),
            'bleed_mm' => (int) $data['bleed_mm'],
            'back_text' => isset($data['back_text']) && trim($data['back_text']) !== '' ? trim($data['back_text']) : null,
            'fields' => $this->cleanFields($data['fields'] ?? null),
            'types' => $this->cleanTypes($data['types'] ?? null),
            'areas' => $this->cleanAreas($data['areas'] ?? null),
        ]);

        // Guarded so saving keeps working until the layout migration has run.
        if (\Illuminate\Support\Facades\Schema::hasColumn('badge_templates', 'layout')) {
            $tpl->layout = $data['layout'] ?? ($tpl->layout ?: 'classic');
        }

        foreach (['logo', 'background', 'back_background'] as $slot) {
            if ($request->hasFile($slot)) {
                $this->deleteFile($event->id, $tpl->$slot);
                $tpl->$slot = $this->storeImage($event->id, $request->file($slot), str_replace('_', '', $slot));
            } elseif ($request->boolean('remove_' . $slot)) {
                $this->deleteFile($event->id, $tpl->$slot);
                $tpl->$slot = null;
            }
        }

        $tpl->save();

        return response()->json(['template' => $tpl->toApi($event)]);
    }

    public function storePerson(Request $request, $eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }
        $person = new BadgePerson(['event_id' => $event->id]);
        return $this->savePerson($request, $person);
    }

    public function updatePerson(Request $request, $eventId, $personId)
    {
        $person = BadgePerson::where('event_id', $eventId)->find($personId);
        if (!$person) {
            return $this->sendError('Person not found', [], 404);
        }
        return $this->savePerson($request, $person);
    }

    public function deletePerson($eventId, $personId)
    {
        $person = BadgePerson::where('event_id', $eventId)->find($personId);
        if (!$person) {
            return $this->sendError('Person not found', [], 404);
        }
        $this->deleteFile((int) $eventId, $person->photo);
        $person->delete();
        return response()->json(['deleted' => true]);
    }

    public function file($eventId, $name)
    {
        if (!preg_match('/^[a-z]+_[a-f0-9]{16}\.(jpg|png)$/', $name)) {
            return $this->sendError('Invalid file name', [], 400);
        }
        $path = BadgeService::storageDir((int) $eventId) . '/' . $name;
        if (!is_file($path)) {
            return $this->sendError('File not found', [], 404);
        }
        return response()->file($path, ['Cache-Control' => 'private, max-age=3600']);
    }

    public function download(Request $request, $eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        $data = Validator::make($request->query(), [
            'size' => ['nullable', Rule::in(BadgeTemplate::SIZES)],
            'split' => ['nullable', Rule::in(['single', 'club'])],
            'sides' => ['nullable', Rule::in(['duplex', 'spread'])],
            'trim_lines' => 'nullable|boolean', // previews: dashed trim line
            'keys' => 'nullable|string|max:20000',   // comma separated a12,p3
            'types' => 'nullable|string|max:200',    // comma separated
        ])->validate();

        @set_time_limit(600);
        @ini_set('memory_limit', '1024M');

        $tpl = BadgeTemplate::forEvent($event);
        $size = $data['size'] ?? ($tpl->size ?: 'small');
        $split = $data['split'] ?? 'single';

        $entries = $this->service->entries($event);
        if (!empty($data['keys'])) {
            $keys = array_flip(array_filter(explode(',', $data['keys'])));
            $entries = array_values(array_filter($entries, fn ($e) => isset($keys[$e['key']])));
        }
        if (!empty($data['types'])) {
            $types = array_flip(array_filter(explode(',', $data['types'])));
            $entries = array_values(array_filter($entries, fn ($e) => isset($types[$e['type']])));
        }
        if (empty($entries)) {
            return $this->sendError('No badges match the selection.', [], 422);
        }

        $sides = $data['sides'] ?? 'duplex';
        $renderer = new BadgeRenderer($tpl, (string) $event->name, $size, $sides === 'spread',
            filter_var($data['trim_lines'] ?? false, FILTER_VALIDATE_BOOLEAN));
        $base = 'badges_' . $this->slug($event->name ?: 'event-' . $event->id) . '_' . $size . ($sides === 'spread' ? '_spread' : '');

        if ($split === 'single') {
            return $this->send($renderer->pdf($entries), $base . '.pdf', 'application/pdf');
        }

        if (!class_exists(ZipArchive::class)) {
            return $this->sendError('ZIP is not available on this server. Download a single PDF instead.', [], 500);
        }

        $groups = [];
        foreach ($entries as $e) {
            $groups[$e['group']][] = $e;
        }

        $zipPath = storage_path('app/mpdf/' . $base . '_' . bin2hex(random_bytes(4)) . '.zip');
        File::ensureDirectoryExists(dirname($zipPath));
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $used = [];
        foreach ($groups as $group => $list) {
            $name = $this->slug($group) ?: 'group';
            $n = $name;
            for ($i = 2; isset($used[$n]); $i++) {
                $n = $name . '-' . $i;
            }
            $used[$n] = true;
            $zip->addFromString($n . '_' . count($list) . '.pdf', $renderer->pdf($list));
        }
        $zip->close();

        $body = (string) file_get_contents($zipPath);
        @unlink($zipPath);

        return $this->send($body, $base . '_by_club.zip', 'application/zip');
    }

    // ---------------------------------------------------------------- helpers

    private function savePerson(Request $request, BadgePerson $person)
    {
        $data = Validator::make($request->all(), [
            'type' => ['required', Rule::in(BadgePerson::TYPES)],
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'organisation' => 'nullable|string|max:150',
            'country' => 'nullable|string|max:100',
            'photo' => 'nullable|' . self::IMAGE_RULE,
            'remove_photo' => 'nullable|boolean',
        ])->validate();

        $person->fill([
            'type' => $data['type'],
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'organisation' => $data['organisation'] ?? null,
            'country' => $data['country'] ?? null,
        ]);

        if ($request->hasFile('photo')) {
            $this->deleteFile($person->event_id, $person->photo);
            $person->photo = $this->storeImage($person->event_id, $request->file('photo'), 'photo');
        } elseif ($request->boolean('remove_photo')) {
            $this->deleteFile($person->event_id, $person->photo);
            $person->photo = null;
        }

        $person->save();

        return response()->json(['person' => $person]);
    }

    /** Store under our own random name and extension; never the client's. */
    private function storeImage(int $eventId, UploadedFile $file, string $prefix): string
    {
        $ext = $file->getMimeType() === 'image/png' ? 'png' : 'jpg';
        $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dir = BadgeService::storageDir($eventId);
        File::ensureDirectoryExists($dir);
        $file->move($dir, $name);
        return $name;
    }

    private function deleteFile(int $eventId, ?string $name): void
    {
        if ($name) {
            @unlink(BadgeService::storageDir($eventId) . '/' . basename($name));
        }
    }

    private function cleanFields(?string $json): array
    {
        $in = json_decode((string) $json, true);
        $out = [];
        foreach (BadgeTemplate::FIELDS as $f) {
            $out[$f] = is_array($in) && array_key_exists($f, $in) ? (bool) $in[$f] : true;
        }
        return $out;
    }

    private function cleanTypes(?string $json): array
    {
        $in = json_decode((string) $json, true);
        $out = [];
        foreach (BadgeTemplate::DEFAULT_TYPES as $type => $def) {
            $t = is_array($in) && is_array($in[$type] ?? null) ? $in[$type] : [];
            $label = mb_substr(trim((string) ($t['label'] ?? '')), 0, 20);
            $color = (string) ($t['color'] ?? '');
            $areas = array_values(array_unique(array_filter(
                array_map('intval', is_array($t['areas'] ?? null) ? $t['areas'] : []),
                fn ($n) => $n >= 1 && $n <= BadgeTemplate::MAX_AREAS
            )));
            sort($areas);
            $out[$type] = [
                'label' => $label !== '' ? $label : $def['label'],
                'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? strtoupper($color) : $def['color'],
                'areas' => $areas,
            ];
        }
        return $out;
    }

    private function cleanAreas(?string $json): array
    {
        $in = json_decode((string) $json, true);
        $out = [];
        foreach (is_array($in) ? $in : [] as $a) {
            $no = (int) ($a['no'] ?? 0);
            $name = mb_substr(trim((string) ($a['name'] ?? '')), 0, 40);
            if ($no >= 1 && $no <= BadgeTemplate::MAX_AREAS && $name !== '') {
                $out[$no] = ['no' => $no, 'name' => $name];
            }
        }
        ksort($out);
        return array_values($out);
    }

    private function send(string $body, string $filename, string $type)
    {
        return response($body, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function slug(string $s): string
    {
        $s = \Illuminate\Support\Str::ascii($s);
        $s = preg_replace('/[^A-Za-z0-9]+/', '-', $s);
        return trim(strtolower((string) $s), '-');
    }
}
