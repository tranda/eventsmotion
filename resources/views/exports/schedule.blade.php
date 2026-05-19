<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        /* DejaVu Sans covers Latin Extended (ž, č, š, đ, ć, ö, …) — forced
           everywhere to avoid Helvetica fallbacks that drop glyphs. */
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 8pt;
            color: #1F2937;
            margin: 0;
        }
        h1 {
            font-size: 13pt;
            margin: 0 0 4px 0;
        }
        .meta {
            color: #6B7280;
            font-size: 7pt;
            margin-bottom: 10px;
        }
        h2.day {
            font-size: 10pt;
            background: #1565C0;
            color: white;
            padding: 4px 8px;
            margin: 10px 0 4px 0;
        }
        table.races {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-bottom: 6px;
        }
        table.races th {
            background: #E3F2FD;
            color: #0D47A1;
            text-align: left;
            font-size: 7pt;
            padding: 3px 4px;
            border-bottom: 1px solid #BBDEFB;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        table.races td {
            border-bottom: 1px solid #E5E7EB;
            padding: 3px 4px;
            vertical-align: middle;
            overflow: hidden;
            word-wrap: break-word;
            font-size: 7.5pt;
        }
        th.num, td.num { width: 22pt; font-weight: bold; }
        th.time, td.time { width: 30pt; }
        th.discipline, td.discipline { /* fills remaining width */ }
        th.stage, td.stage { width: 70pt; }
        th.lane, td.lane { font-size: 7pt; text-align: center; }
        th.lane { background: #E3F2FD; }
        tr.brk td {
            background: #FFF8E1;
            font-style: italic;
        }
        /* Coloured discipline tokens — mirror the Grid */
        .badge {
            display: inline-block;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 7pt;
            font-weight: 600;
            margin-right: 2px;
            white-space: nowrap;
        }
        .competition {
            display: inline-block;
            background: #FFE0B2;
            border: 1px solid #FFB74D;
            color: #5D4037;
            padding: 0 3px;
            border-radius: 3px;
            font-size: 6.5pt;
            font-weight: 600;
            margin-left: 2px;
        }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">
        @if($dayFilter) Day: {{ $dayFilter }} · @else All days · @endif
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
                                @foreach($e['tokens'] as $tok)
                                    <span class="badge" style="background: {{ $tok['bg'] }}; color: {{ $tok['fg'] }};">{{ $tok['val'] }}</span>
                                @endforeach
                                @if($e['competition'])
                                    <span class="competition">{{ $e['competition'] }}</span>
                                @endif
                            </td>
                            <td class="stage">
                                <span class="badge" style="background: {{ $e['stage_bg'] }}; color: {{ $e['stage_fg'] }};">{{ $e['stage'] }}</span>
                            </td>
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
