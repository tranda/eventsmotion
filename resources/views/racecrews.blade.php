<!DOCTYPE html>
<html>
<head>
    <title>Race Crews</title>
    <style>
        .page {
            page-break-after: always;
            width: 210mm; /* A4 landscape width */
            height: 297mm; /* A4 landscape height */
            margin: 0;
            padding: 10px;
        /*    transform: rotate(-90deg);*/ /* Rotate the page to landscape orientation */
        /*    transform-origin: left top; */
        }

        table {
            border-collapse: collapse;
            border: 1px solid black;
            width: 100%;
            font-size: 10px; /* Reduced font size */
        }

        th, td {
            border: 1px solid black;
            padding: 2px;
            word-wrap: break-word; /* Ensure text wraps within cells */
        }

        th {
            font-size: 12px; /* Slightly larger font size for headers */
        }

        h1 {
            font-size: 12px; /* Reduced font size for headers */
        }

        @media print {
            body {
                margin: 0;
            }

            .page {
                width: 210mm;
                height: 297mm;
                transform: none; /* Remove rotation for printing */
                transform-origin: initial;
            }

            table {
                table-layout: fixed;
            }

            p {
                margin: 0;
            }
        }
    </style>
</head>
<body>
@foreach ($disciplines as $discipline)
 <div class="page">
    <h1>{{ $discipline['discipline']['boat_group'] }} {{ $discipline['discipline']['age_group'] }} {{ $discipline['discipline']['gender_group'] }} {{ $discipline['discipline']['distance'] }}</h1>

    <table>
            <thead>
                <tr>
                    @foreach ($discipline['teams'] as $team)
                        <th>{{ $team['team']['name'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr>
        @foreach ($discipline['teams'] as $team)
                    <td style="border: 1px solid black; padding: 8px;">
                        @foreach ($team['athletes'] as $athlete)
                            <div style="border: 1px solid black; padding: 4px;">
                                <p>{{ $athlete['name'] }}</p>
                            </div>
                        @endforeach
                    </td>
        @endforeach
                </tr>
            </tbody>
    </table>
 </div>
@endforeach
</body>
</html>