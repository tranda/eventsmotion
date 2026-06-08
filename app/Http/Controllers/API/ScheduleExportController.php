<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Event;
use App\Models\RaceResult;
use App\Services\Schedule\ProgressionDescriber;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Mpdf\Mpdf;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /api/events/{event}/schedule/export?format=txt|csv&day=YYYY-MM-DD
 *
 * Streams a downloadable race schedule. day= filters to a single date;
 * omit it for the whole event. Races and breaks are interleaved in
 * chronological order. Lanes are emitted per crew, padded out to the
 * event's lane_count so CSV columns stay uniform.
 */
class ScheduleExportController extends BaseController
{
    public function __construct(
        private ProgressionDescriber $progressionDescriber,
    ) {}

    public function export(Request $request, $eventId)
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->sendError('Event not found', [], 404);
        }

        $format = strtolower((string) $request->query('format', 'csv'));
        if (!in_array($format, ['txt', 'csv', 'pdf', 'xlsx'], true)) {
            return $this->sendError('Unsupported format. Use txt, csv, pdf or xlsx.', [], 422);
        }

        $day = $request->query('day'); // YYYY-MM-DD or null
        $includeCrews = filter_var($request->query('include_crews'), FILTER_VALIDATE_BOOLEAN);

        $entries = $this->loadEntries($event, $day);

        $laneCount = (int) ($event->lane_count ?? 6);
        $eventName = $event->name ?? "event-{$event->id}";
        $slug = $this->slug($eventName);
        $dayTag = $day ? "_{$day}" : '';
        // Race plan vs start list: when crews are included, the document is
        // the printable handout for teams and should be named accordingly.
        $kind = $includeCrews ? 'start-list' : 'race-plan';

        if ($format === 'csv') {
            $body = $this->buildCsv($entries, $laneCount, $includeCrews);
            $filename = "{$kind}_{$slug}{$dayTag}.csv";
            return $this->streamed($body, $filename, 'text/csv; charset=UTF-8');
        }
        if ($format === 'pdf') {
            $body = $this->buildPdf($event, $entries, $day, $laneCount, $includeCrews);
            $filename = "{$kind}_{$slug}{$dayTag}.pdf";
            return $this->streamed($body, $filename, 'application/pdf');
        }
        if ($format === 'xlsx') {
            $body = $this->buildXlsx($entries, $laneCount, $includeCrews);
            $filename = "{$kind}_{$slug}{$dayTag}.xlsx";
            return $this->streamed(
                $body,
                $filename,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );
        }
        $body = $this->buildTxt($event, $entries, $day, $laneCount, $includeCrews);
        $filename = "{$kind}_{$slug}{$dayTag}.txt";
        return $this->streamed($body, $filename, 'text/plain; charset=UTF-8');
    }

    private function buildXlsx($entries, int $laneCount, bool $includeCrews = true): string
    {
        $headers = [
            'race_number', 'date', 'time', 'entry_type',
            'boat', 'age', 'gender', 'distance',
            'competition', 'stage', 'label',
        ];
        if ($includeCrews) {
            for ($i = 1; $i <= $laneCount; $i++) {
                $headers[] = "lane{$i}_team";
            }
        }

        $tmp = tempnam(sys_get_temp_dir(), 'sched_') . '.xlsx';
        try {
            // openspout 3.x: use the legacy factory; constructing the Writer
            // directly requires internal services.
            $writer = WriterEntityFactory::createXLSXWriter();
            $writer->openToFile($tmp);
            $writer->addRow(WriterEntityFactory::createRowFromArray($headers));

            foreach ($entries as $e) {
                $t = $e->race_time ? Carbon::parse($e->race_time) : null;
                if ($e->isBreak()) {
                    $row = [
                        '',
                        $t?->toDateString() ?? '',
                        $t?->format('H:i') ?? '',
                        'break',
                        '', '', '', '', '',
                        $e->stage ?? '',
                        $e->label ?? '',
                    ];
                    if ($includeCrews) {
                        for ($i = 1; $i <= $laneCount; $i++) $row[] = '';
                    }
                    $writer->addRow(WriterEntityFactory::createRowFromArray($row));
                    continue;
                }

                $d = $e->discipline;
                $row = [
                    $e->race_number ?? '',
                    $t?->toDateString() ?? '',
                    $t?->format('H:i') ?? '',
                    'race',
                    $d?->boat_group ?? '',
                    $d?->age_group ?? '',
                    $d?->gender_group ?? '',
                    $d?->distance ?? '',
                    $d?->competition ?? '',
                    $e->stage ?? '',
                    '',
                ];

                if ($includeCrews) {
                    $byLane = [];
                    foreach ($e->crewResults ?? [] as $cr) {
                        if ($cr->lane !== null) {
                            $byLane[(int) $cr->lane] = $cr;
                        }
                    }
                    for ($i = 1; $i <= $laneCount; $i++) {
                        $row[] = $byLane[$i]?->crew?->team?->name ?? '';
                    }
                }
                $writer->addRow(WriterEntityFactory::createRowFromArray($row));
            }

            $writer->close();
            return file_get_contents($tmp);
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    private function buildPdf(Event $event, $entries, ?string $day, int $laneCount, bool $includeCrews = false): string
    {
        // PDF is grouped by block (one block per page). Each race is split
        // into category tokens so the blade can render them as coloured
        // badges (matching the Grid's visual style).
        $colorMap = is_array($event->color_map) ? $event->color_map : [];

        // Auto-derived progression lines, keyed by race_id. We compute over
        // the full event (not just the day filter) so cross-day refs like
        // "→ Grand Final (#42)" still resolve when day= is set. Override
        // notes on each race short-circuit the auto rule inside the service.
        $progressionByRace = [];
        try {
            $progressionByRace = $this->progressionDescriber->forEvent($event);
        } catch (\Throwable $e) {
            \Log::warning('PDF progression derivation failed: ' . $e->getMessage());
        }

        // Load every block with its day so we can pin each entry to one.
        $orderedBlocks = [];
        foreach ($event->eventDays()->with('blocks')->get()->sortBy('sort_order') as $eday) {
            foreach ($eday->blocks->sortBy('sort_order') as $blk) {
                $orderedBlocks[] = ['day' => $eday, 'block' => $blk];
            }
        }

        $byBlock = [];
        $orphanKey = '__orphan__';
        foreach ($entries as $e) {
            $t = $e->race_time ? Carbon::parse($e->race_time) : null;
            $dateStr = $t?->toDateString() ?? '?';

            // Pin this entry to a block. For races we use filter matching;
            // for breaks we use "latest block on same day whose start ≤
            // break time". Falls back to an "Unassigned" bucket if nothing
            // matches (e.g. event has no blocks configured).
            $bd = $e->isBreak()
                ? $this->findBlockForBreakByTime($t, $orderedBlocks)
                : $this->findBlockForRace($e, $orderedBlocks);
            if ($bd === null) {
                $blockKey = $orphanKey;
                $blockMeta = ['name' => 'Unassigned', 'date' => $dateStr, 'start_time' => '', 'gap_seconds' => 0];
            } else {
                $blockKey = $bd['block']->id;
                $blockMeta = [
                    'name' => $bd['block']->name ?: 'Block',
                    'date' => $dateStr,
                    'start_time' => $bd['block']->start_time,
                    'gap_seconds' => (int) $bd['block']->gap_seconds,
                ];
            }
            if (!isset($byBlock[$blockKey])) {
                $byBlock[$blockKey] = ['meta' => $blockMeta, 'entries' => []];
            }

            if ($e->isBreak()) {
                $duration = (int) ($e->duration_seconds ?? 0);
                $byBlock[$blockKey]['entries'][] = [
                    'is_break' => true,
                    'time' => $t?->format('H:i') ?? '—',
                    'label' => $e->label ?? $e->stage ?? 'Break',
                    'duration_label' => $duration > 0 ? $this->durationLabel($duration) : null,
                    'shift_subsequent' => (bool) $e->shift_subsequent,
                ];
                continue;
            }

            $d = $e->discipline;
            $tokens = [];
            if (!empty($d?->boat_group))   $tokens[] = ['cat' => 'boat', 'val' => $d->boat_group];
            if (!empty($d?->age_group))    $tokens[] = ['cat' => 'age', 'val' => $d->age_group];
            if (!empty($d?->gender_group)) $tokens[] = ['cat' => 'gender', 'val' => $d->gender_group];
            if (!empty($d?->distance))     $tokens[] = ['cat' => 'distance', 'val' => "{$d->distance}m"];
            // Resolve colours server-side so the blade stays simple.
            foreach ($tokens as &$tok) {
                $tok['bg'] = $this->resolveColor($colorMap, $tok['cat'], $tok['val']);
                $tok['fg'] = $this->contrastingFg($tok['bg']);
            }
            unset($tok);

            $stageType = $this->stageType($e->stage ?? '');
            $stageBg = $stageType !== ''
                ? $this->resolveColor($colorMap, 'stage', $stageType)
                : '#E5E7EB';
            $stageFg = $this->contrastingFg($stageBg);

            $competition = $d?->competition ?? '';
            $compColors = $competition !== ''
                ? $this->competitionColor($competition)
                : ['bg' => '#FFE0B2', 'fg' => '#5D4037', 'border' => '#FFB74D'];

            // Mirror the Grid / Race Results flags so the blade can render
            // a MEDALS badge on the race that decides medals and a
            // CANCELLED badge on dropped races. isFinalRound already
            // returns false for cancelled rows on the backend, so the two
            // are mutually exclusive.
            $isCancelled = $e->status === 'CANCELLED';
            $isFinalRound = !$isCancelled && $e->isFinalRound();
            // For Rounds-plan final standings the per-race position is by
            // single-race time and is NOT the medal rank — medals are by
            // sum across rounds. Pull final times and sort them so the
            // position circle shows the actual medal rank, matching what
            // the Flutter Grid does in _calculateFinalPositions.
            $finalRankByCrewId = [];
            if ($isFinalRound && $e->shouldShowAccumulatedTime()) {
                try {
                    $finalTimes = $e->getFinalTimesForDiscipline()
                        ->filter(fn($v) => ($v['final_status'] ?? null) === 'FINISHED' && ($v['final_time_ms'] ?? null) !== null)
                        ->sortBy(fn($v) => $v['final_time_ms'])
                        ->values();
                    foreach ($finalTimes as $idx => $row) {
                        // collection key is crew_id when it came from getFinalTimesForDiscipline
                    }
                    // Re-derive ranks keyed by crew_id (sortBy on a keyed
                    // collection preserves keys — we want crew_id => rank).
                    $sorted = $e->getFinalTimesForDiscipline()
                        ->filter(fn($v) => ($v['final_status'] ?? null) === 'FINISHED' && ($v['final_time_ms'] ?? null) !== null)
                        ->sortBy(fn($v) => $v['final_time_ms']);
                    $rank = 1;
                    foreach ($sorted as $crewId => $_) {
                        $finalRankByCrewId[$crewId] = $rank++;
                    }
                } catch (\Throwable $err) {
                    \Log::warning('PDF final-rank computation failed: ' . $err->getMessage());
                }
            }

            $crews = [];
            if ($includeCrews) {
                $byLane = [];
                foreach ($e->crewResults ?? [] as $cr) {
                    if ($cr->lane !== null) {
                        $byLane[(int) $cr->lane] = $cr;
                    }
                }
                for ($i = 1; $i <= $laneCount; $i++) {
                    $cr = $byLane[$i] ?? null;
                    $position = $cr?->position;
                    // For Rounds-plan medal races, overwrite per-race position
                    // with the accumulated-time rank so the PDF awards medals
                    // by the sum, not by this round's time alone.
                    if ($cr && !empty($finalRankByCrewId) && isset($finalRankByCrewId[$cr->crew_id])) {
                        $position = $finalRankByCrewId[$cr->crew_id];
                    }
                    $crews[] = [
                        'lane' => $i,
                        'name' => $cr?->crew?->team?->name ?? '—',
                        'position' => $position,
                        'status' => $cr?->status,
                    ];
                }
            }

            $byBlock[$blockKey]['entries'][] = [
                'is_break' => false,
                'race_number' => $e->race_number,
                'time' => $t?->format('H:i') ?? '—',
                'tokens' => $tokens,
                'competition' => $competition,
                'comp_bg' => $compColors['bg'],
                'comp_fg' => $compColors['fg'],
                'comp_border' => $compColors['border'],
                'stage' => $e->stage ?? '',
                'stage_bg' => $stageBg,
                'stage_fg' => $stageFg,
                'is_cancelled' => $isCancelled,
                'is_final_round' => $isFinalRound,
                'crews' => $crews,
                'progression' => $progressionByRace[$e->id] ?? '',
            ];
        }

        // PDF shows race rows only — one block per page. Rendered via mpdf
        // (dompdf wouldn't honour th/col widths reliably).
        $html = view('exports.schedule', [
            'title' => ($event->name ?? "Event #{$event->id}") . ' — Race Schedule',
            'dayFilter' => $day,
            'generatedAt' => now()->setTimezone('Europe/Belgrade')->format('Y-m-d H:i'),
            'byBlock' => $byBlock,
        ])->render();

        $tempDir = storage_path('app/mpdf');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'tempDir' => $tempDir,
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 12,
        ]);
        $mpdf->WriteHTML($html);
        return $mpdf->Output('', 'S');
    }

    private function loadEntries(Event $event, ?string $day)
    {
        // Mirror the Grid (RaceResultController@index): show every race for an
        // active discipline plus all breaks, regardless of the race's own
        // status. We deliberately do NOT filter on status='SCHEDULED' — races
        // that have already run (or sit in any non-SCHEDULED state) still
        // appear in the on-screen Grid, so the export must include them too,
        // otherwise the first races of a day go missing from the document.
        // whereNotNull('race_time') stays: this is a time-ordered schedule and
        // an un-timed race can't be slotted onto the timeline.
        $query = RaceResult::where(function ($q) use ($event) {
            $q->whereHas('discipline', fn($qq) => $qq
                ->where('event_id', $event->id)
                ->where('status', 'active'))
              ->orWhere(function ($qq) use ($event) {
                  $qq->where('event_id', $event->id)
                     ->where('entry_type', 'break');
              });
        })
            ->whereNotNull('race_time')
            ->with(['discipline', 'crewResults.crew.team.club']);

        if ($day) {
            $query->whereDate('race_time', $day);
        }

        return $query
            ->orderBy('race_time')
            ->orderBy('race_number')
            ->orderBy('id')
            ->get();
    }

    private function buildCsv($entries, int $laneCount, bool $includeCrews = true): string
    {
        $headers = [
            'race_number',
            'date',
            'time',
            'entry_type',
            'boat',
            'age',
            'gender',
            'distance',
            'competition',
            'stage',
            'label',
        ];
        if ($includeCrews) {
            for ($i = 1; $i <= $laneCount; $i++) {
                $headers[] = "lane{$i}_team";
            }
        }

        $rows = [$headers];
        foreach ($entries as $e) {
            $t = $e->race_time ? Carbon::parse($e->race_time) : null;
            if ($e->isBreak()) {
                $row = [
                    '',                                       // race_number
                    $t?->toDateString() ?? '',
                    $t?->format('H:i') ?? '',
                    'break',
                    '', '', '', '', '',
                    $e->stage ?? '',
                    $e->label ?? '',
                ];
                if ($includeCrews) {
                    for ($i = 1; $i <= $laneCount; $i++) {
                        $row[] = '';
                    }
                }
                $rows[] = $row;
                continue;
            }

            $d = $e->discipline;
            $row = [
                $e->race_number ?? '',
                $t?->toDateString() ?? '',
                $t?->format('H:i') ?? '',
                'race',
                $d?->boat_group ?? '',
                $d?->age_group ?? '',
                $d?->gender_group ?? '',
                $d?->distance ?? '',
                $d?->competition ?? '',
                $e->stage ?? '',
                '', // label only used for breaks
            ];

            if ($includeCrews) {
                $byLane = [];
                foreach ($e->crewResults ?? [] as $cr) {
                    if ($cr->lane !== null) {
                        $byLane[(int) $cr->lane] = $cr;
                    }
                }
                for ($i = 1; $i <= $laneCount; $i++) {
                    $cr = $byLane[$i] ?? null;
                    $row[] = $cr?->crew?->team?->name ?? '';
                }
            }
            $rows[] = $row;
        }

        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);
        return $out;
    }

    private function buildTxt(Event $event, $entries, ?string $day, int $laneCount, bool $includeCrews = true): string
    {
        $lines = [];
        $title = ($event->name ?? "Event #{$event->id}") . ' — Race Schedule';
        $lines[] = $title;
        $lines[] = str_repeat('=', mb_strlen($title));
        $lines[] = $day ? "Day: {$day}" : 'All days';
        $lines[] = 'Generated: ' . now()->setTimezone('Europe/Belgrade')->format('Y-m-d H:i');
        $lines[] = '';

        $lastDate = null;
        foreach ($entries as $e) {
            $t = $e->race_time ? Carbon::parse($e->race_time) : null;
            $dateStr = $t?->toDateString() ?? '?';
            if ($dateStr !== $lastDate) {
                $lines[] = '';
                $lines[] = "--- {$dateStr} ---";
                $lastDate = $dateStr;
            }

            if ($e->isBreak()) {
                $duration = (int) ($e->duration_seconds ?? 0);
                $durLabel = $duration > 0 ? ' (' . $this->durationLabel($duration) . ')' : '';
                $shift = $e->shift_subsequent ? '' : ' [parallel]';
                $lines[] = sprintf(
                    '  %s  ☕ %s%s%s',
                    $t?->format('H:i') ?? '—',
                    $e->label ?? $e->stage ?? 'Break',
                    $durLabel,
                    $shift,
                );
                continue;
            }

            $d = $e->discipline;
            $discName = trim(implode(' ', array_filter([
                $d?->boat_group,
                $d?->age_group,
                $d?->gender_group,
                $d?->distance ? "{$d->distance}m" : null,
            ])));
            $competition = $d?->competition ? " [{$d->competition}]" : '';

            $byLane = [];
            foreach ($e->crewResults ?? [] as $cr) {
                if ($cr->lane !== null) {
                    $byLane[(int) $cr->lane] = $cr;
                }
            }
            $filled = count($byLane);

            $lines[] = sprintf(
                '  #%-3s %s  %s%s   %s   %d/%d',
                $e->race_number ?? '—',
                $t?->format('H:i') ?? '—',
                $discName,
                $competition,
                $e->stage ?? '',
                $filled,
                $laneCount,
            );

            if ($includeCrews) {
                for ($i = 1; $i <= $laneCount; $i++) {
                    $cr = $byLane[$i] ?? null;
                    $teamName = $cr?->crew?->team?->name ?? '-';
                    $lines[] = sprintf('       Lane %d: %s', $i, $teamName);
                }
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Pick the first block in order that matches the race's discipline
     * filters. Returns ['day' => EventDay, 'block' => ScheduleBlock] or null.
     */
    private function findBlockForRace(RaceResult $race, array $orderedBlocks): ?array
    {
        $d = $race->discipline;
        if (!$d) return null;
        foreach ($orderedBlocks as $bd) {
            if ($this->raceMatchesBlock($d, $race->stage, $bd['block'])) {
                return $bd;
            }
        }
        return null;
    }

    /** Latest block on the same day whose start_time ≤ time. */
    private function findBlockForBreakByTime(?Carbon $t, array $orderedBlocks): ?array
    {
        if (!$t) return null;
        $date = $t->toDateString();
        $candidate = null;
        foreach ($orderedBlocks as $bd) {
            $dayDate = $bd['day']->date instanceof Carbon
                ? $bd['day']->date->toDateString()
                : (string) $bd['day']->date;
            if ($dayDate !== $date) continue;
            $bs = Carbon::parse($dayDate . ' ' . $bd['block']->start_time);
            if ($bs->lte($t)) {
                $candidate = $bd;
            }
        }
        return $candidate;
    }

    private function raceMatchesBlock($discipline, ?string $stage, $block): bool
    {
        $gf = $block->gender_filter;
        if (is_array($gf) && !empty($gf)) {
            $map = ['M' => 'Open', 'W' => 'Women', 'X' => 'Mixed'];
            $needles = array_map(fn($v) => $map[strtoupper((string) $v)] ?? $v, $gf);
            if (!in_array($discipline->gender_group, $needles, true)) return false;
        }
        $df = $block->distance_filter;
        if (is_array($df) && !empty($df)) {
            $needles = array_values(array_filter(
                array_map(fn($v) => preg_replace('/\D/', '', (string) $v), $df),
                fn($v) => $v !== ''
            ));
            if (!in_array((string) $discipline->distance, $needles, true)) return false;
        }
        $sf = $block->stage_filter;
        if (is_array($sf) && !empty($sf)) {
            $s = strtolower((string) $stage);
            $matched = false;
            foreach ($sf as $needle) {
                if (str_contains($s, strtolower((string) $needle))) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) return false;
        }
        $cf = $block->competition_filter;
        if (is_array($cf) && !empty($cf)) {
            $c = strtolower((string) ($discipline->competition ?? ''));
            $needles = array_map(fn($v) => strtolower((string) $v), $cf);
            if (!in_array($c, $needles, true)) return false;
        }
        return true;
    }

    /**
     * Default colour palette mirroring the frontend's RaceColorPalette so
     * un-overridden values get a sensible chip colour in the PDF.
     */
    private const DEFAULT_COLORS = [
        'boat' => [
            'Standard' => '#1976D2', 'Small' => '#7B1FA2',
        ],
        'age' => [
            'Junior' => '#388E3C', 'Junior A' => '#66BB6A', 'Junior B' => '#AED581',
            'U24' => '#00ACC1', 'Premier' => '#D32F2F', 'Senior A' => '#F57C00',
            'Senior B' => '#FBC02D', 'Senior C' => '#FFA726', 'Senior D' => '#FFCC80',
            'BCP' => '#5D4037', 'ACP' => '#455A64',
        ],
        'gender' => [
            'Open' => '#1565C0', 'Women' => '#C2185B', 'Mixed' => '#6D4C41',
        ],
        'distance' => [
            '100m' => '#B39DDB', '200m' => '#7E57C2', '250m' => '#26A69A',
            '500m' => '#42A5F5', '1000m' => '#66BB6A', '2000m' => '#EF6C00',
        ],
        'stage' => [
            'Round' => '#1976D2', 'Heat' => '#388E3C', 'Repechage' => '#F57C00',
            'Semi' => '#7B1FA2', 'Grand Final' => '#FFC107',
            'Minor Final' => '#9E9E9E', 'Tail Final' => '#607D8B',
        ],
    ];

    private function resolveColor(array $colorMap, string $category, string $value): string
    {
        $override = $colorMap[$category][$value] ?? null;
        if (is_string($override) && preg_match('/^#?[0-9a-fA-F]{6,8}$/', $override)) {
            return str_starts_with($override, '#') ? $override : "#{$override}";
        }
        return self::DEFAULT_COLORS[$category][$value] ?? '#BDBDBD';
    }

    /** "Round 1" / "Round 2" → "Round"; "Repechage 2" → "Repechage". */
    private function stageType(string $stage): string
    {
        $trimmed = trim($stage);
        if ($trimmed === '') return '';
        if (preg_match('/^(.*?)\s+\d+$/', $trimmed, $m)) return $m[1];
        return $trimmed;
    }

    /**
     * Distinct light-tint background per competition string (Club, Corporate,
     * Highschool, University, …). Stable: same string → same colour every
     * time, so an event keeps its colour-coding consistent across re-exports.
     *
     * Mirrors the frontend `competitionBadgeColor()` in lib/src/common.dart so
     * the on-screen Grid and the exported PDF agree on which colour belongs to
     * each competition. Palette = Material purple/teal/indigo/deepOrange/pink/
     * brown, shaded 50/200/700. Hash = sum of lowercase UTF-16 code units mod 6
     * (matches Dart's `String.toLowerCase().codeUnits.fold((a,b)=>a+b)`).
     */
    private function competitionColor(string $name): array
    {
        $palette = [
            ['bg' => '#F3E5F5', 'border' => '#CE93D8', 'fg' => '#7B1FA2'], // purple
            ['bg' => '#E0F2F1', 'border' => '#80CBC4', 'fg' => '#00796B'], // teal
            ['bg' => '#E8EAF6', 'border' => '#9FA8DA', 'fg' => '#303F9F'], // indigo
            ['bg' => '#FBE9E7', 'border' => '#FFAB91', 'fg' => '#E64A19'], // deepOrange
            ['bg' => '#FCE4EC', 'border' => '#F48FB1', 'fg' => '#C2185B'], // pink
            ['bg' => '#EFEBE9', 'border' => '#BCAAA4', 'fg' => '#5D4037'], // brown
        ];
        $lower = mb_strtolower($name, 'UTF-8');
        $sum = 0;
        // mb_str_split → array of characters; mb_ord gives the Unicode code
        // point. For ASCII-only competition names ("Club", "Corporate") this
        // matches Dart's `codeUnits` exactly; for BMP non-ASCII it also
        // matches because a single UTF-16 code unit == the code point there.
        foreach (mb_str_split($lower, 1, 'UTF-8') as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            $sum += ($cp !== false ? $cp : 0);
        }
        $idx = $sum % count($palette);
        return $palette[$idx];
    }

    /** Black or white text for readable contrast on the given hex background. */
    private function contrastingFg(string $hex): string
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 6) {
            $r = hexdec(substr($h, 0, 2));
            $g = hexdec(substr($h, 2, 2));
            $b = hexdec(substr($h, 4, 2));
            // Relative luminance, simplified.
            $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
            return $lum > 0.55 ? '#1F2937' : '#FFFFFF';
        }
        return '#FFFFFF';
    }

    private function durationLabel(int $seconds): string
    {
        $m = (int) round($seconds / 60);
        if ($m < 60) return "{$m} min";
        $h = intdiv($m, 60);
        $rem = $m % 60;
        return $rem === 0 ? "{$h}h" : "{$h}h {$rem}m";
    }

    private function streamed(string $body, string $filename, string $contentType): StreamedResponse
    {
        return response()->stream(
            function () use ($body) {
                echo $body;
            },
            200,
            [
                'Content-Type' => $contentType,
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'no-store',
            ],
        );
    }

    private function slug(string $name): string
    {
        $s = strtolower($name);
        $s = preg_replace('/[^a-z0-9]+/i', '_', $s) ?? $s;
        return trim($s, '_') ?: 'event';
    }
}
