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
        table.crews {
            margin-top: 4px;
            border-collapse: collapse;
            font-size: 8pt;
            color: #1F2937;
        }
        table.crews td {
            padding: 1px 6px 1px 0;
            border: none;
            vertical-align: top;
        }
        table.crews td.lane {
            color: #6B7280;
            font-weight: 600;
            width: 22pt;
            white-space: nowrap;
        }
        /* Position circle — same style as the on-screen Grid / Race Results.
           Hollow (coloured border + coloured text) for non-medal races so
           the lane number reads as just "running order". Filled with the
           position colour (gold/silver/bronze/blue) for the medal race. */
        table.crews td.pos {
            width: 22pt;
            white-space: nowrap;
            vertical-align: middle;
        }
        .poscircle {
            display: inline-block;
            width: 16pt;
            height: 16pt;
            line-height: 14pt;
            border-radius: 8pt;
            text-align: center;
            font-weight: bold;
            font-size: 9pt;
            border-width: 1.5pt;
            border-style: solid;
        }
        table.crews td.team {
            white-space: nowrap;
        }
        table.crews tr.empty td.team {
            color: #9CA3AF;
        }
        /* MEDALS / CANCELLED race-row badges */
        .race-flag {
            display: inline-block;
            padding: 0 5px;
            border-radius: 3px;
            font-size: 7pt;
            font-weight: bold;
            margin-right: 4px;
            vertical-align: middle;
            white-space: nowrap;
        }
        .race-flag-medals {
            background: #FBBF24;
            color: #5D4037;
        }
        .race-flag-cancelled {
            background: #7F1D1D;
            color: #FFFFFF;
        }
        tr.race-cancelled td {
            color: #9CA3AF;
        }
        .progression {
            margin-top: 4px;
            padding: 2px 6px;
            background: #F3F4F6;
            border-left: 3px solid #1565C0;
            font-size: 7.5pt;
            color: #1F2937;
            line-height: 1.3;
        }
        .competition {
            display: inline-block;
            padding: 0 4px;
            border-radius: 3px;
            font-size: 7pt;
            font-weight: 600;
            margin-left: 3px;
            border-style: solid;
            border-width: 1px;
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
                <thead>
                    <tr>
                        <th class="num" style="width: 22pt;">#</th>
                        <th class="time" style="width: 34pt;">Time</th>
                        <th class="discipline" style="width: auto;">Discipline</th>
                        <th class="stage" style="width: 70pt;">Stage</th>
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
                            @php
                                // Position colour palette — matches the Flutter UI:
                                //   1 gold, 2 silver-grey, 3 bronze, 4+ blue.
                                $posColor = function ($pos) {
                                    return match ((int) $pos) {
                                        1 => '#FBBF24',   // amber-400 — gold
                                        2 => '#9CA3AF',   // grey-400 — silver
                                        3 => '#92400E',   // amber-900 — bronze
                                        default => '#2563EB', // blue-600
                                    };
                                };
                            @endphp
                            <tr class="{{ $e['is_cancelled'] ? 'race-cancelled' : '' }}">
                                <td class="num">{{ $e['race_number'] ?: '—' }}</td>
                                <td class="time">{{ $e['time'] }}</td>
                                <td class="discipline">
                                    @foreach($e['tokens'] as $tok)
                                        <span class="badge" style="background: {{ $tok['bg'] }}; color: {{ $tok['fg'] }};">{{ $tok['val'] }}</span>
                                    @endforeach
                                    @if($e['competition'])
                                        <span class="competition" style="background: {{ $e['comp_bg'] }}; color: {{ $e['comp_fg'] }}; border-color: {{ $e['comp_border'] }};">{{ $e['competition'] }}</span>
                                    @endif
                                    @if(!empty($e['crews']))
                                        <table class="crews">
                                            @foreach($e['crews'] as $c)
                                                @php
                                                    $hasResult = !empty($c['position']);
                                                    if ($hasResult) {
                                                        $color = $posColor($c['position']);
                                                        if ($e['is_final_round']) {
                                                            // Medal race: filled circle.
                                                            $circleStyle = "background: {$color}; border-color: {$color}; color: #FFFFFF;";
                                                            $circleLabel = $c['position'];
                                                        } else {
                                                            // Non-medal race: hollow circle, coloured number.
                                                            $circleStyle = "background: #FFFFFF; border-color: {$color}; color: {$color};";
                                                            $circleLabel = $c['position'];
                                                        }
                                                    } else {
                                                        // No result yet — show lane number, neutral grey hollow.
                                                        $circleStyle = "background: #FFFFFF; border-color: #D1D5DB; color: #6B7280;";
                                                        $circleLabel = $c['lane'];
                                                    }
                                                @endphp
                                                <tr class="{{ $c['name'] === '—' ? 'empty' : '' }}">
                                                    <td class="pos"><span class="poscircle" style="{{ $circleStyle }}">{{ $circleLabel }}</span></td>
                                                    <td class="lane">L{{ $c['lane'] }}</td>
                                                    <td class="team">{{ $c['name'] }}</td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    @endif
                                    @if(!empty($e['progression']))
                                        <div class="progression">{{ $e['progression'] }}</div>
                                    @endif
                                </td>
                                <td class="stage">
                                    @if($e['is_cancelled'])
                                        <span class="race-flag race-flag-cancelled">CANCELLED</span>
                                    @elseif($e['is_final_round'])
                                        <span class="race-flag race-flag-medals">MEDALS</span>
                                    @endif
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
