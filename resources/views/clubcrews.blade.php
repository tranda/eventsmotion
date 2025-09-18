@foreach ($disciplines as $discipline)
 <div class="page">
    <h1>{{ $discipline['discipline']['boat_group'] }} {{ $discipline['discipline']['age_group'] }} {{ $discipline['discipline']['gender_group'] }} {{ $discipline['discipline']['distance'] }}</h1>

    <table style="border-collapse: collapse; border: 1px solid black;">
            <thead>
                <tr>
                    @foreach ($discipline['teams'] as $team)
                        <th style="border: 1px solid black; padding: 8px;">{{ $team['team']['name'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr>
        @foreach ($discipline['teams'] as $team)
                    <td style="border: 1px solid black; padding: 1px;">
                        @foreach ($team['athletes'] as $athlete)
                            {{-- <div style="border: 1px solid black; padding: 1px;"> --}}
                                <p>{{ $athlete['name'] }}</p>
                            {{-- </div> --}}
                        @endforeach
                    </td>
        @endforeach
                </tr>
            </tbody>
    </table>
 </div>
@endforeach

<style>
    .page {
        page-break-after: always;
        width: 210mm; /* A4 landscape width */
        height: 297mm; /* A4 landscape height */
        margin: 0;
        padding: 20px;
        /* transform: rotate(-90deg); Rotate the page to landscape orientation */
        transform-origin: left top;
    }

    @media print {
        body {
            margin: 0;
        }
        
        table {
            table-layout: fixed;
        }

        p {
            margin: 0;
        }
    }
</style>