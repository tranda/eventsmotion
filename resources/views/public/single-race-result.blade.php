<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $event->name }} - Race {{ $race['race_number'] }} Results</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            line-height: 1.6;
            color: #333;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            text-align: center;
            backdrop-filter: blur(10px);
        }

        .header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .header h2 {
            font-size: 1.6rem;
            font-weight: 600;
            color: #667eea;
            margin-bottom: 15px;
        }

        .race-meta {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .meta-item {
            background: #f7fafc;
            padding: 10px 20px;
            border-radius: 8px;
            border: 2px solid #e2e8f0;
            font-size: 0.9rem;
            color: #718096;
        }

        .back-button {
            display: inline-block;
            background: #667eea;
            color: white;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .back-button:hover {
            background: #5a67d8;
            transform: translateY(-2px);
        }

        .race-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
        }

        .race-header {
            background: #667eea;
            color: white;
            padding: 25px;
            text-align: center;
        }

        .race-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .race-status {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-completed {
            background: rgba(72, 187, 120, 0.3);
            color: #2f855a;
        }

        .status-pending {
            background: rgba(237, 137, 54, 0.3);
            color: #c05621;
        }

        .results-table {
            padding: 0;
        }

        .results-header {
            background: #f7fafc;
            padding: 20px 30px;
            border-bottom: 2px solid #e2e8f0;
            font-weight: 700;
            color: #4a5568;
            display: grid;
            grid-template-columns: 80px 1fr 100px 120px 120px;
            gap: 20px;
            align-items: center;
            font-size: 1rem;
        }

        .result-row {
            padding: 20px 30px;
            border-bottom: 1px solid #f1f5f9;
            display: grid;
            grid-template-columns: 80px 1fr 100px 120px 120px;
            gap: 20px;
            align-items: center;
            transition: background-color 0.2s ease;
        }

        .result-row:hover {
            background: #f8fafc;
        }

        .result-row:last-child {
            border-bottom: none;
        }

        .position-circle {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.3rem;
            color: white;
            margin: 0 auto;
            border: 3px solid rgba(255, 255, 255, 0.3);
        }

        .position-1 { 
            background: linear-gradient(45deg, #ffd700, #ffed4e);
            color: #8b7355;
            box-shadow: 0 4px 15px rgba(255, 215, 0, 0.4);
        }
        .position-2 { 
            background: linear-gradient(45deg, #c0c0c0, #e2e8f0);
            color: #5a5a5a;
            box-shadow: 0 4px 15px rgba(192, 192, 192, 0.4);
        }
        .position-3 { 
            background: linear-gradient(45deg, #cd7f32, #d69e2e);
            color: #fff;
            box-shadow: 0 4px 15px rgba(205, 127, 50, 0.4);
        }
        .position-other { 
            background: linear-gradient(45deg, #667eea, #764ba2);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        .position-none { 
            background: #e2e8f0;
            color: #a0aec0;
        }

        .team-info {
            display: flex;
            flex-direction: column;
        }

        .team-name {
            font-weight: 700;
            font-size: 1.3rem;
            color: #2d3748;
            margin-bottom: 5px;
        }

        .team-details {
            font-size: 1rem;
            color: #718096;
        }

        .lane-number {
            text-align: center;
            font-weight: 700;
            font-size: 1.2rem;
            color: #4a5568;
            background: #f7fafc;
            padding: 10px;
            border-radius: 8px;
        }

        .race-time {
            text-align: center;
            font-family: 'Courier New', monospace;
            font-weight: 700;
            font-size: 1.2rem;
            color: #2d3748;
            background: #f7fafc;
            padding: 10px;
            border-radius: 8px;
        }

        .time-delay {
            text-align: center;
            font-family: 'Courier New', monospace;
            font-size: 1rem;
            color: #e53e3e;
            font-weight: 600;
        }

        .no-results {
            padding: 60px;
            text-align: center;
            color: #718096;
            font-style: italic;
            font-size: 1.2rem;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 16px;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            margin-top: 5px;
        }

        .status-finished { background: #c6f6d5; color: #22543d; }
        .status-dns { background: #fed7d7; color: #742a2a; }
        .status-dnf { background: #fbb6ce; color: #702459; }
        .status-dsq { background: #fbb6ce; color: #702459; }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }

            .header h1 {
                font-size: 1.8rem;
            }

            .header h2 {
                font-size: 1.3rem;
            }

            .race-meta {
                flex-direction: column;
                gap: 10px;
            }

            .results-header,
            .result-row {
                grid-template-columns: 60px 1fr 80px;
                gap: 15px;
                padding: 15px 20px;
            }

            .results-header .time-col,
            .results-header .delay-col,
            .result-row .race-time,
            .result-row .time-delay {
                display: none;
            }

            .position-circle {
                width: 40px;
                height: 40px;
                font-size: 1.1rem;
            }

            .team-name {
                font-size: 1.1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header Section -->
        <div class="header">
            <h1>{{ $event->name }} {{ $event->year }}</h1>
            <h2>Race {{ $race['race_number'] }}: {{ $race['title'] }}</h2>
            
            <div class="race-meta">
                @if($race['race_time'])
                    <div class="meta-item">
                        📅 {{ \Carbon\Carbon::parse($race['race_time'])->format('M j, Y • H:i') }}
                    </div>
                @endif
                @if($race['stage'])
                    <div class="meta-item">
                        🏁 {{ ucfirst($race['stage']) }}
                    </div>
                @endif
                <div class="meta-item">
                    <span class="race-status {{ $race['has_results'] ? 'status-completed' : 'status-pending' }}">
                        {{ $race['has_results'] ? 'Results Available' : 'Pending Results' }}
                    </span>
                </div>
            </div>

            <a href="{{ route('public.race-results', $event->id) }}" class="back-button">
                ← Back to All Results
            </a>
        </div>

        <!-- Race Results -->
        <div class="race-card">
            <div class="race-header">
                <div class="race-title">{{ $race['title'] }}</div>
            </div>
            
            @if($race['crew_results']->isEmpty())
                <div class="no-results">
                    <h3>No crews registered for this race</h3>
                    <p>Check back later for updates.</p>
                </div>
            @else
                <div class="results-table">
                    <div class="results-header">
                        <div>Position</div>
                        <div>Team</div>
                        <div>Lane</div>
                        <div class="time-col">Time</div>
                        <div class="delay-col">+Delay</div>
                    </div>
                    
                    @foreach($race['crew_results'] as $result)
                        <div class="result-row">
                            <div>
                                @if($result->position)
                                    <div class="position-circle position-{{ $result->position <= 3 ? $result->position : 'other' }}">
                                        {{ $result->position }}
                                    </div>
                                @else
                                    <div class="position-circle position-none">
                                        —
                                    </div>
                                @endif
                            </div>
                            
                            <div class="team-info">
                                <div class="team-name">{{ $result->crew->team->name ?? 'Unknown Team' }}</div>
                                <div class="team-details">
                                    @if($result->status && $result->status !== 'FINISHED')
                                        <span class="status-badge status-{{ strtolower($result->status) }}">
                                            {{ $result->status }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                            
                            <div class="lane-number">
                                {{ $result->lane ?? '—' }}
                            </div>
                            
                            <div class="race-time">
                                @if($result->time_ms)
                                    {{ $result->formatted_time }}
                                @else
                                    —
                                @endif
                            </div>
                            
                            <div class="time-delay">
                                @if($result->delay_after_first)
                                    +{{ number_format($result->delay_after_first / 1000, 3) }}s
                                @else
                                    —
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <script>
        // Auto-refresh every 30 seconds for live updates
        setInterval(() => {
            window.location.reload();
        }, 30000);
    </script>
</body>
</html>