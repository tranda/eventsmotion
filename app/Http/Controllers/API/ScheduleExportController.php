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
        // Group entries by date for the template; lanes pre-rendered as
        // (lane => "Team — Club (Country)") for the per-row "Lanes" cell.
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
            $discName = trim(implode(' ', array_filter([
                $d?->boat_group,
                $d?->age_group,
                $d?->gender_group,
                $d?->distance ? "{$d->distance}m" : null,
            ])));

            $byLane = [];
            foreach ($e->crewResults ?? [] as $cr) {
                if ($cr->lane !== null) {
                    $byLane[(int) $cr->lane] = $cr;
                }
            }

            $lanes = [];
            for ($i = 1; $i <= $laneCount; $i++) {
                $cr = $byLane[$i] ?? null;
                $lanes[$i] = $cr?->crew?->team?->name;
            }

            $byDate[$dateStr][] = [
                'is_break' => false,
                'race_number' => $e->race_number,
                'time' => $t?->format('H:i') ?? '—',
                'discipline' => $discName,
                'competition' => $d?->competition ?? '',
                'stage' => $e->stage ?? '',
                'lanes' => $lanes,
            ];
        }

        $pdf = Pdf::loadView('exports.schedule', [
            'title' => ($event->name ?? "Event #{$event->id}") . ' — Race Schedule',
            'dayFilter' => $day,
            'generatedAt' => now()->format('Y-m-d H:i'),
            'byDate' => $byDate,
            'laneCount' => $laneCount,
        ])->setPaper('a4', 'landscape');

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
