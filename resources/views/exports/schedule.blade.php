<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        /* DejaVu Sans covers Latin Extended (ž, č, š, đ, ć, ö, …). */
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9pt;
            color: #1F2937;
            margin: 0;
        }
        .block-section {
            page-break-after: always;
        }
        .block-section:last-child {
            page-break-after: auto;
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
        .block-header {
            background: #1565C0;
            color: white;
            padding: 6px 10px;
            margin: 0 0 6px 0;
        }
        .block-header .name {
            font-size: 12pt;
            font-weight: 700;
        }
        .block-header .sub {
            font-size: 8pt;
            opacity: 0.9;
        }
        table.races {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
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
        }
        table.races td {
            border-bottom: 1px solid #E5E7EB;
            padding: 4px 6px;
            vertical-align: middle;
            font-size: 9pt;
            word-wrap: break-word;
        }
        td.num, th.num { font-weight: bold; text-align: right; }
        td.stage, th.stage { text-align: right; }
        tr.brk td {
            background: #FFF8E1;
            font-style: italic;
        }
        .badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 8pt;
            font-weight: 600;
            margin-right: 3px;
            white-space: nowrap;
        }
        .competition {
            display: inline-block;
            background: #FFE0B2;
            border: 1px solid #FFB74D;
            color: #5D4037;
            padding: 0 4px;
            border-radius: 3px;
            font-size: 7pt;
            font-weight: 600;
            margin-left: 3px;
        }
    </style>
</head>
<body>
    @foreach($byBlock as $blockKey => $section)
        <div class="block-section">
            <h1>{{ $title }}</h1>
            <div class="meta">
                @if($dayFilter) Day: {{ $dayFilter }} · @else All days · @endif
                Generated: {{ $generatedAt }}
            </div>

            <div class="block-header">
                <div class="name">{{ $section['meta']['name'] }}</div>
                <div class="sub">
                    {{ $section['meta']['date'] }}
                    @if($section['meta']['start_time'])
                        &nbsp;·&nbsp; starts {{ $section['meta']['start_time'] }}
                    @endif
                    @if($section['meta']['gap_seconds'] > 0)
                        &nbsp;·&nbsp; gap {{ intval($section['meta']['gap_seconds'] / 60) }} min
                    @endif
                    &nbsp;·&nbsp; {{ count($section['entries']) }} entries
                </div>
            </div>

            <table class="races">
                <colgroup>
                    <col width="4%">
                    <col width="8%">
                    <col width="72%">
                    <col width="16%">
                </colgroup>
                <thead>
                    <tr>
                        <th class="num">#</th>
                        <th class="time">Time</th>
                        <th class="discipline">Discipline</th>
                        <th class="stage">Stage</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($section['entries'] as $e)
                        @if($e['is_break'])
                            <tr class="brk">
                                <td class="num"></td>
                                <td class="time">{{ $e['time'] }}</td>
                                <td colspan="2">
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
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
</body>
</html>
