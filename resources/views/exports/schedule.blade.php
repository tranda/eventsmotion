<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        /* DejaVu Sans is bundled with dompdf and supports Latin Extended-A
           (ž, č, š, đ, ć, ö, é, …) plus most European diacritics out of
           the box. */
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9pt;
            color: #1F2937;
            margin: 0;
        }
        h1 {
            font-size: 14pt;
            margin: 0 0 4px 0;
        }
        .meta {
            color: #6B7280;
            font-size: 8pt;
            margin-bottom: 12px;
        }
        h2.day {
            font-size: 11pt;
            background: #1565C0;
            color: white;
            padding: 4px 8px;
            margin: 12px 0 6px 0;
        }
        table.races {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed; /* equal-width lane columns */
            margin-bottom: 8px;
        }
        table.races th {
            background: #E3F2FD;
            color: #0D47A1;
            text-align: left;
            font-size: 8pt;
            padding: 4px 6px;
            border-bottom: 1px solid #BBDEFB;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        table.races td {
            border-bottom: 1px solid #E5E7EB;
            padding: 4px 6px;
            vertical-align: top;
            overflow: hidden;
            word-wrap: break-word;
        }
        td.num, th.num { width: 26pt; font-weight: bold; }
        td.time, th.time { width: 38pt; }
        td.discipline, th.discipline { width: 130pt; font-weight: 600; }
        td.stage, th.stage { width: 70pt; color: #374151; }
        td.lane, th.lane {
            font-size: 8pt;
            color: #1F2937;
            text-align: center;
        }
        th.lane {
            background: #E3F2FD;
        }
        tr.brk td {
            background: #FFF8E1;
            font-style: italic;
        }
        .competition {
            display: inline-block;
            background: #FFE0B2;
            border: 1px solid #FFB74D;
            color: #5D4037;
            padding: 0 4px;
            border-radius: 4px;
            font-size: 7pt;
            font-weight: 600;
            margin-left: 4px;
        }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">
        @if($dayFilter)
            Day: {{ $dayFilter }} ·
        @else
            All days ·
        @endif
        Generated: {{ $generatedAt }}
    </div>

    @foreach($byDate as $date => $entries)
        <h2 class="day">{{ $date }} &nbsp;·&nbsp; {{ count($entries) }} entries</h2>
        <table class="races">
            <thead>
                <tr>
                    <th class="num">#</th>
                    <th class="time">Time</th>
                    <th class="discipline">Discipline</th>
                    <th class="stage">Stage</th>
                    @for($i = 1; $i <= $laneCount; $i++)
                        <th class="lane">L{{ $i }}</th>
                    @endfor
                </tr>
            </thead>
            <tbody>
                @foreach($entries as $e)
                    @if($e['is_break'])
                        <tr class="brk">
                            <td class="num"></td>
                            <td class="time">{{ $e['time'] }}</td>
                            <td colspan="{{ 2 + $laneCount }}">
                                ☕ {{ $e['label'] }}
                                @if($e['duration_label']) ({{ $e['duration_label'] }}) @endif
                                @if(!$e['shift_subsequent']) <em>[parallel]</em> @endif
                            </td>
                        </tr>
                    @else
                        <tr>
                            <td class="num">{{ $e['race_number'] ?: '—' }}</td>
                            <td class="time">{{ $e['time'] }}</td>
                            <td class="discipline">
                                {{ $e['discipline'] }}
                                @if($e['competition'])
                                    <span class="competition">{{ $e['competition'] }}</span>
                                @endif
                            </td>
                            <td class="stage">{{ $e['stage'] }}</td>
                            @for($i = 1; $i <= $laneCount; $i++)
                                <td class="lane">{{ $e['lanes'][$i] ?? '—' }}</td>
                            @endfor
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    @endforeach
</body>
</html>
