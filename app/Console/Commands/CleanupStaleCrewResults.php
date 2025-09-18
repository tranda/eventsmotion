<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CrewResult;
use App\Models\RaceResult;
use App\Models\Crew;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

class CleanupStaleCrewResults extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crew-results:cleanup {--dry-run : Show what would be cleaned without making changes} {--race-number= : Clean specific race number only} {--team= : Clean specific team only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up stale crew_results that have time values but should not according to current race plan';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $raceNumber = $this->option('race-number');
        $teamName = $this->option('team');

        $this->info('Starting crew results cleanup...');
        $this->info($dryRun ? 'DRY RUN MODE - No changes will be made' : 'LIVE MODE - Changes will be applied');
        $this->newLine();

        // Step 1: Find crew_results with time_ms that might be stale
        $query = CrewResult::with(['crew.team', 'raceResult.discipline'])
            ->whereNotNull('time_ms')
            ->where('time_ms', '>', 0);

        // Apply filters if specified
        if ($raceNumber) {
            $query->whereHas('raceResult', function($q) use ($raceNumber) {
                $q->where('race_number', $raceNumber);
            });
        }

        if ($teamName) {
            $query->whereHas('crew.team', function($q) use ($teamName) {
                $q->where('name', 'like', "%{$teamName}%");
            });
        }

        $crewResultsWithTimes = $query->get();

        $this->info("Found {$crewResultsWithTimes->count()} crew results with time values");
        $this->newLine();

        $staleResults = [];
        $validResults = [];

        // Step 2: Analyze each crew result to determine if it should have a time
        foreach ($crewResultsWithTimes as $crewResult) {
            $raceResult = $crewResult->raceResult;
            $crew = $crewResult->crew;
            $team = $crew->team ?? null;

            $analysis = $this->analyzeCrewResult($crewResult);

            if ($analysis['is_stale']) {
                $staleResults[] = [
                    'crew_result' => $crewResult,
                    'reason' => $analysis['reason'],
                    'race_info' => "Race #{$raceResult->race_number} - {$raceResult->discipline->getDisplayName()}",
                    'team_name' => $team->name ?? 'Unknown Team',
                    'lane' => $crewResult->lane,
                    'time_ms' => $crewResult->time_ms,
                    'formatted_time' => $crewResult->formatted_time
                ];
            } else {
                $validResults[] = $crewResult;
            }
        }

        // Step 3: Display findings
        $this->displayFindings($staleResults, $validResults, $raceNumber, $teamName);

        // Step 4: Perform cleanup if not dry run
        if (!$dryRun && count($staleResults) > 0) {
            if ($this->confirm('Do you want to clean up the stale crew results listed above?')) {
                $this->performCleanup($staleResults);
            } else {
                $this->info('Cleanup cancelled by user.');
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Analyze a crew result to determine if it should have a time value.
     *
     * @param CrewResult $crewResult
     * @return array
     */
    private function analyzeCrewResult($crewResult)
    {
        $raceResult = $crewResult->raceResult;

        // Check if race is cancelled or not scheduled for results
        if ($raceResult->status === 'CANCELLED') {
            return [
                'is_stale' => true,
                'reason' => 'Race is cancelled'
            ];
        }

        if ($raceResult->status === 'SCHEDULED') {
            return [
                'is_stale' => true,
                'reason' => 'Race is only scheduled, not yet run'
            ];
        }

        // Check if crew status indicates they shouldn't have a time
        if (in_array($crewResult->status, ['DNS', 'DNF', 'DSQ'])) {
            return [
                'is_stale' => true,
                'reason' => "Crew status is {$crewResult->status}"
            ];
        }

        // Check for suspicious very fast times (likely test data)
        if ($crewResult->time_ms < 30000) { // Less than 30 seconds
            return [
                'is_stale' => true,
                'reason' => 'Suspiciously fast time (< 30 seconds)'
            ];
        }

        // Check for very slow times (likely invalid)
        if ($crewResult->time_ms > 600000) { // More than 10 minutes
            return [
                'is_stale' => true,
                'reason' => 'Suspiciously slow time (> 10 minutes)'
            ];
        }

        // For now, consider all other times as valid
        // In the future, we could add more sophisticated checks
        return [
            'is_stale' => false,
            'reason' => 'Appears valid'
        ];
    }

    /**
     * Display the findings from the analysis.
     *
     * @param array $staleResults
     * @param array $validResults
     * @param string|null $raceNumber
     * @param string|null $teamName
     */
    private function displayFindings($staleResults, $validResults, $raceNumber = null, $teamName = null)
    {
        // Display filters applied
        if ($raceNumber || $teamName) {
            $this->info('Filters applied:');
            if ($raceNumber) $this->info("  - Race Number: {$raceNumber}");
            if ($teamName) $this->info("  - Team: {$teamName}");
            $this->newLine();
        }

        // Display stale results
        if (count($staleResults) > 0) {
            $this->error("Found " . count($staleResults) . " STALE crew results that should be cleaned up:");
            $this->newLine();

            $headers = ['Race', 'Team', 'Lane', 'Time', 'Reason'];
            $rows = [];

            foreach ($staleResults as $result) {
                $rows[] = [
                    $result['race_info'],
                    $result['team_name'],
                    $result['lane'] ?? 'N/A',
                    $result['formatted_time'] ?? 'N/A',
                    $result['reason']
                ];
            }

            $this->table($headers, $rows);
            $this->newLine();
        } else {
            $this->info('No stale crew results found!');
        }

        // Display valid results summary
        if (count($validResults) > 0) {
            $this->info("Found " . count($validResults) . " valid crew results with times");
        }

        $this->newLine();
    }

    /**
     * Perform the actual cleanup of stale crew results.
     *
     * @param array $staleResults
     */
    private function performCleanup($staleResults)
    {
        $this->info('Performing cleanup...');

        $cleanedCount = 0;
        $errorCount = 0;

        foreach ($staleResults as $result) {
            try {
                $crewResult = $result['crew_result'];

                // Store original values for logging
                $originalTime = $crewResult->time_ms;
                $originalPosition = $crewResult->position;

                // Clear the stale data
                $crewResult->time_ms = null;
                $crewResult->position = null;

                // Update status if needed
                if ($crewResult->status === 'FINISHED' && in_array($result['reason'], [
                    'Race is cancelled',
                    'Race is only scheduled, not yet run'
                ])) {
                    $crewResult->status = 'DNS'; // Did not start
                }

                $crewResult->save();

                $this->info("✓ Cleaned crew result ID {$crewResult->id}: {$result['race_info']} - {$result['team_name']} (was: {$result['formatted_time']})");
                $cleanedCount++;

            } catch (\Exception $e) {
                $this->error("✗ Failed to clean crew result ID {$crewResult->id}: " . $e->getMessage());
                $errorCount++;
            }
        }

        $this->newLine();
        $this->info("Cleanup completed:");
        $this->info("  - Successfully cleaned: {$cleanedCount} records");
        if ($errorCount > 0) {
            $this->error("  - Errors: {$errorCount} records");
        }

        // Recalculate positions for affected races
        $this->info('Recalculating positions for affected races...');
        $affectedRaceIds = collect($staleResults)->pluck('crew_result.race_result_id')->unique();

        foreach ($affectedRaceIds as $raceId) {
            $this->recalculatePositions($raceId);
        }

        $this->info("Recalculated positions for " . $affectedRaceIds->count() . " races");
    }

    /**
     * Recalculate positions for a specific race.
     *
     * @param int $raceResultId
     */
    private function recalculatePositions($raceResultId)
    {
        // Get all finished crew results for this race, ordered by time (fastest first)
        $finishedResults = CrewResult::where('race_result_id', $raceResultId)
            ->where('status', 'FINISHED')
            ->whereNotNull('time_ms')
            ->where('time_ms', '>', 0)
            ->orderBy('time_ms', 'asc')
            ->get();

        // Assign positions starting from 1
        $position = 1;
        foreach ($finishedResults as $crewResult) {
            $crewResult->position = $position;
            $crewResult->save();
            $position++;
        }

        // Clear positions for non-finished or no-time results
        CrewResult::where('race_result_id', $raceResultId)
            ->where(function ($query) {
                $query->where('status', '!=', 'FINISHED')
                    ->orWhereNull('time_ms')
                    ->orWhere('time_ms', '<=', 0);
            })
            ->update(['position' => null]);
    }

    /**
     * Search for specific race/team combination.
     *
     * @param int $raceNumber
     * @param string $teamPattern
     * @return void
     */
    public function searchSpecific($raceNumber, $teamPattern)
    {
        $this->info("Searching for Race #{$raceNumber} with team pattern '{$teamPattern}'...");

        $results = CrewResult::with(['crew.team', 'raceResult.discipline'])
            ->whereHas('raceResult', function($q) use ($raceNumber) {
                $q->where('race_number', $raceNumber);
            })
            ->whereHas('crew.team', function($q) use ($teamPattern) {
                $q->where('name', 'like', "%{$teamPattern}%");
            })
            ->get();

        if ($results->count() > 0) {
            $this->info("Found {$results->count()} results:");
            foreach ($results as $result) {
                $this->info("  - {$result->raceResult->discipline->getDisplayName()} | {$result->crew->team->name} | Lane {$result->lane} | Time: {$result->formatted_time} | Status: {$result->status}");
            }
        } else {
            $this->info("No results found matching the criteria.");
        }
    }
}