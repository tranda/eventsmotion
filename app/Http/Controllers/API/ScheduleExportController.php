<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\Event;
use App\Models\RaceResult;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
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

        $entries = $this->loadEntries($event, $day);

        $laneCount = (int) ($event->lane_count ?? 6);
        $eventName = $event->name ?? "event-{$event->id}";
        $slug = $this->slug($eventName);
        $dayTag = $day ? "_{$day}" : '';

        if ($format === 'csv') {
            $body = $this->buildCsv($entries, $laneCount);
            $filename = "schedule_{$slug}{$dayTag}.csv";
            return $this->streamed($body, $filename, 'text/csv; charset=UTF-8');
        }
        if ($format === 'pdf') {
            $body = $this->buildPdf($event, $entries, $day, $laneCount);
            $filename = "schedule_{$slug}{$dayTag}.pdf";
            return $this->streamed($body, $filename, 'application/pdf');
        }
        if ($format === 'xlsx') {
            $body = $this->buildXlsx($entries, $laneCount);
            $filename = "schedule_{$slug}{$dayTag}.xlsx";
            return $this->streamed(
                $body,
                $filename,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            );
        }
        $body = $this->buildTxt($event, $entries, $day, $laneCount);
        $filename = "schedule_{$slug}{$dayTag}.txt";
        return $this->streamed($body, $filename, 'text/plain; charset=UTF-8');
    }

    private function buildXlsx($entries, int $laneCount): string
    {
        $headers = [
            'race_number', 'date', 'time', 'entry_type',
            'boat', 'age', 'gender', 'distance',
            'competition', 'stage', 'label',
        ];
        for ($i = 1; $i <= $laneCount; $i++) {
            $headers[] = "lane{$i}_team";
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
                    for ($i = 1; $i <= $laneCount; $i++) $row[] = '';
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

                $byLane = [];
                foreach ($e->crewResults ?? [] as $cr) {
                    if ($cr->lane !== null) {
                        $byLane[(int) $cr->lane] = $cr;
                    }
                }
                for ($i = 1; $i <= $laneCount; $i++) {
                    $row[] = $byLane[$i]?->crew?->team?->name ?? '';
                }
                $writer->addRow(WriterEntityFactory::createRowFromArray($row));
            }

            $writer->close();
            return file_get_contents($tmp);
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    private function buildPdf(Event $event, $entries, ?string $day, int $laneCount): string
    {
        // Group entries by date for the template. Each race is split into
        // category tokens so the blade can render them as coloured badges
        // (matching the Grid's visual style).
        $colorMap = is_array($event->color_map) ? $event->color_map : [];
        $byDate = [];
        foreach ($entries as $e) {
            $t = $e->race_time ? Carbon::parse($e->race_time) : null;
            $dateStr = $t?->toDateString() ?? '?';

            if ($e->isBreak()) {
                $duration = (int) ($e->duration_seconds ?? 0);
                $byDate[$dateStr][] = [
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

            $byDate[$dateStr][] = [
                'is_break' => false,
                'race_number' => $e->race_number,
                'time' => $t?->format('H:i') ?? '—',
                'tokens' => $tokens,
                'competition' => $d?->competition ?? '',
                'stage' => $e->stage ?? '',
                'stage_bg' => $stageBg,
                'stage_fg' => $stageFg,
            ];
        }

        // PDF shows race rows only — no lanes / crews (operators use the
        // Grid or CSV/XLSX/TXT for the lane breakdown).
        $pdf = Pdf::loadView('exports.schedule', [
            'title' => ($event->name ?? "Event #{$event->id}") . ' — Race Schedule',
            'dayFilter' => $day,
            'generatedAt' => now()->format('Y-m-d H:i'),
            'byDate' => $byDate,
        ])->setPaper('a4', 'portrait');

        return $pdf->output();
    }

    private function loadEntries(Event $event, ?string $day)
    {
        $query = RaceResult::where(function ($q) use ($event) {
            $q->whereHas('discipline', fn($qq) => $qq
                ->where('event_id', $event->id)
                ->where('status', 'active'))
              ->orWhere(function ($qq) use ($event) {
                  $qq->where('event_id', $event->id)
                     ->where('entry_type', 'break');
              });
        })
            ->where('status', 'SCHEDULED')
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

    private function buildCsv($entries, int $laneCount): string
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
        for ($i = 1; $i <= $laneCount; $i++) {
            $headers[] = "lane{$i}_team";
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
                for ($i = 1; $i <= $laneCount; $i++) {
                    $row[] = '';
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

    private function buildTxt(Event $event, $entries, ?string $day, int $laneCount): string
    {
        $lines = [];
        $title = ($event->name ?? "Event #{$event->id}") . ' — Race Schedule';
        $lines[] = $title;
        $lines[] = str_repeat('=', mb_strlen($title));
        $lines[] = $day ? "Day: {$day}" : 'All days';
        $lines[] = 'Generated: ' . now()->format('Y-m-d H:i');
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

            for ($i = 1; $i <= $laneCount; $i++) {
                $cr = $byLane[$i] ?? null;
                $teamName = $cr?->crew?->team?->name ?? '-';
                $lines[] = sprintf('       Lane %d: %s', $i, $teamName);
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
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
