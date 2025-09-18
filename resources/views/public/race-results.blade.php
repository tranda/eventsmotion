<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $event->name }} - Race Results</title>
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
            max-width: 1200px;
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
            font-size: 2.5rem;
            font-weight: 700;
            color: #2d3748;
            margin-bottom: 10px;
        }

        .header p {
            font-size: 1.1rem;
            color: #718096;
            margin-bottom: 20px;
        }

        .stats {
            display: flex;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
        }

        .stat-item {
            background: #f7fafc;
            padding: 15px 25px;
            border-radius: 12px;
            text-align: center;
            border: 2px solid #e2e8f0;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: #667eea;
            display: block;
        }

        .stat-label {
            font-size: 0.9rem;
            color: #718096;
            margin-top: 5px;
        }

        .controls {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
            backdrop-filter: blur(10px);
        }

        .btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn:hover {
            background: #5a67d8;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        .btn-secondary {
            background: #718096;
        }

        .btn-secondary:hover {
            background: #4a5568;
        }

        .race-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .race-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
        }

        .race-header {
            background: #667eea;
            color: white;
            padding: 20px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            overflow: hidden;
        }

        .race-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.5s;
        }

        .race-header:hover::before {
            left: 100%;
        }

        .race-info h3 {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .race-meta {
            display: flex;
            gap: 20px;
            font-size: 0.9rem;
            opacity: 0.9;
            flex-wrap: wrap;
        }

        .race-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-completed {
            background: rgba(72, 187, 120, 0.2);
            color: #38a169;
        }

        .status-pending {
            background: rgba(237, 137, 54, 0.2);
            color: #dd6b20;
        }

        .expand-icon {
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }

        .race-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }

        .race-content.expanded {
            max-height: 2000px;
        }

        .results-table {
            padding: 0;
        }

        .results-header {
            background: #f7fafc;
            padding: 15px 25px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
            color: #4a5568;
            display: grid;
            grid-template-columns: 60px 1fr 80px 100px 100px;
            gap: 15px;
            align-items: center;
        }

        .result-row {
            padding: 15px 25px;
            border-bottom: 1px solid #f1f5f9;
            display: grid;
            grid-template-columns: 60px 1fr 80px 100px 100px;
            gap: 15px;
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
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            color: white;
            margin: 0 auto;
        }

        .position-1 { background: #ffd700; color: #8b7355; }
        .position-2 { background: #c0c0c0; color: #5a5a5a; }
        .position-3 { background: #cd7f32; color: #fff; }
        .position-other { background: #667eea; }
        .position-none { background: #e2e8f0; color: #a0aec0; }

        .team-info {
            display: flex;
            flex-direction: column;
        }

        .team-name {
            font-weight: 600;
            font-size: 1.1rem;
            color: #2d3748;
            margin-bottom: 2px;
        }

        .team-details {
            font-size: 0.9rem;
            color: #718096;
        }

        .lane-number {
            text-align: center;
            font-weight: 600;
            font-size: 1rem;
            color: #4a5568;
        }

        .race-time {
            text-align: center;
            font-family: 'Courier New', monospace;
            font-weight: 600;
            font-size: 1rem;
            color: #2d3748;
        }

        .time-delay {
            text-align: center;
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            color: #e53e3e;
        }

        .no-results {
            padding: 40px;
            text-align: center;
            color: #718096;
            font-style: italic;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
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
                font-size: 2rem;
            }

            .stats {
                gap: 15px;
            }

            .controls {
                flex-direction: column;
                align-items: center;
            }

            .race-header {
                padding: 15px;
            }

            .race-info h3 {
                font-size: 1.2rem;
            }

            .race-meta {
                flex-direction: column;
                gap: 5px;
            }

            .results-header,
            .result-row {
                grid-template-columns: 50px 1fr 70px;
                gap: 10px;
                padding: 15px;
            }

            .results-header .time-col,
            .results-header .delay-col,
            .result-row .race-time,
            .result-row .time-delay {
                display: none;
            }

            .position-circle {
                width: 35px;
                height: 35px;
                font-size: 1rem;
            }
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #718096;
        }

        .error {
            background: #fed7d7;
            color: #742a2a;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header Section -->
        <div class="header">
            <h1>{{ $event->name }} {{ $event->year }}</h1>
            <p>{{ $event->location }} • Race Results</p>
            <div class="stats">
                <div class="stat-item">
                    <span class="stat-number">{{ $totalRaces }}</span>
                    <div class="stat-label">Total Races</div>
                </div>
                <div class="stat-item">
                    <span class="stat-number">{{ $racesWithResults }}</span>
                    <div class="stat-label">Completed</div>
                </div>
                <div class="stat-item">
                    <span class="stat-number">{{ $totalRaces - $racesWithResults }}</span>
                    <div class="stat-label">Pending</div>
                </div>
            </div>
        </div>

        <!-- Controls -->
        <div class="controls">
            <button class="btn" onclick="expandAll()">Expand All</button>
            <button class="btn btn-secondary" onclick="collapseAll()">Collapse All</button>
            <button class="btn btn-secondary" onclick="window.location.reload()">Refresh Results</button>
        </div>

        <!-- Race Results -->
        <div class="races-container">
            @if($raceResults->isEmpty())
                <div class="no-results">
                    <h3>No race results available yet</h3>
                    <p>Results will appear here as races are completed.</p>
                </div>
            @else
                @foreach($raceResults as $race)
                    <div class="race-card">
                        <div class="race-header" onclick="toggleRace({{ $race['id'] }})">
                            <div class="race-info">
                                <h3>Race {{ $race['race_number'] }}: {{ $race['title'] }}</h3>
                                <div class="race-meta">
                                    @if($race['race_time'])
                                        <span>📅 {{ \Carbon\Carbon::parse($race['race_time'])->format('M j, Y • H:i') }}</span>
                                    @endif
                                    @if($race['stage'])
                                        <span>🏁 {{ ucfirst($race['stage']) }}</span>
                                    @endif
                                    <span class="race-status {{ $race['has_results'] ? 'status-completed' : 'status-pending' }}">
                                        {{ $race['has_results'] ? 'Results Available' : 'Pending Results' }}
                                    </span>
                                </div>
                            </div>
                            <span class="expand-icon" id="icon-{{ $race['id'] }}">▼</span>
                        </div>
                        
                        <div class="race-content" id="content-{{ $race['id'] }}">
                            @if($race['crew_results']->isEmpty())
                                <div class="no-results">
                                    <p>No crews registered for this race.</p>
                                </div>
                            @else
                                <div class="results-table">
                                    <div class="results-header">
                                        <div>Pos</div>
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
                @endforeach
            @endif
        </div>
    </div>

    <script>
        function toggleRace(raceId) {
            const content = document.getElementById(`content-${raceId}`);
            const icon = document.getElementById(`icon-${raceId}`);
            
            if (content.classList.contains('expanded')) {
                content.classList.remove('expanded');
                icon.style.transform = 'rotate(0deg)';
            } else {
                content.classList.add('expanded');
                icon.style.transform = 'rotate(180deg)';
            }
        }

        function expandAll() {
            const contents = document.querySelectorAll('.race-content');
            const icons = document.querySelectorAll('.expand-icon');
            
            contents.forEach(content => content.classList.add('expanded'));
            icons.forEach(icon => icon.style.transform = 'rotate(180deg)');
        }

        function collapseAll() {
            const contents = document.querySelectorAll('.race-content');
            const icons = document.querySelectorAll('.expand-icon');
            
            contents.forEach(content => content.classList.remove('expanded'));
            icons.forEach(icon => icon.style.transform = 'rotate(0deg)');
        }

        // Auto-refresh every 30 seconds for live updates
        setInterval(() => {
            // Only refresh if we're on the main results page (not a specific race)
            if (window.location.pathname.includes('/race-results') && !window.location.pathname.includes('/races/')) {
                window.location.reload();
            }
        }, 30000);
    </script>
</body>
</html>