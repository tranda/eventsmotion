<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\CrewResult;
use App\Models\RaceResult;
use App\Models\Crew;
use App\Models\Team;
use App\Models\Discipline;
use Illuminate\Support\Facades\DB;

class InvestigateCrewResultIssue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crew-results:investigate {--race-number=2 : Race number to investigate} {--team-pattern=Motion : Team name pattern to search for} {--lane=3 : Lane number to investigate}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Investigate specific crew result issues like Race #2 Motion 2 Lane 3';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $raceNumber = $this->option('race-number');
        $teamPattern = $this->option('team-pattern');
        $lane = $this->option('lane');

        $this->info("Investigating crew result issue:");
        $this->info("  - Race Number: {$raceNumber}");
        $this->info("  - Team Pattern: {$teamPattern}");
        $this->info("  - Lane: {$lane}");
        $this->newLine();

        // Step 1: Find all races with this race number
        $this->info("Step 1: Finding races with number {$raceNumber}...");
        $races = RaceResult::with(['discipline'])
            ->where('race_number', $raceNumber)
            ->get();

        if ($races->isEmpty()) {
            $this->error("No races found with race number {$raceNumber}");
            return Command::FAILURE;
        }

        $this->info("Found {$races->count()} race(s) with number {$raceNumber}:");
        foreach ($races as $race) {
            $this->info("  - Race ID: {$race->id} | Discipline: {$race->discipline->getDisplayName()} | Status: {$race->status}");
        }
        $this->newLine();

        // Step 2: Find teams matching the pattern
        $this->info("Step 2: Finding teams matching pattern '{$teamPattern}'...");
        $teams = Team::where('name', 'like', "%{$teamPattern}%")->get();

        if ($teams->isEmpty()) {
            $this->error("No teams found matching pattern '{$teamPattern}'");
            return Command::FAILURE;
        }

        $this->info("Found {$teams->count()} team(s) matching pattern:");
        foreach ($teams as $team) {
            $this->info("  - Team ID: {$team->id} | Name: {$team->name}");
        }
        $this->newLine();

        // Step 3: Find crew results for all combinations
        $this->info("Step 3: Finding crew results for these combinations...");
        $crewResults = CrewResult::with(['crew.team', 'raceResult.discipline'])
            ->whereHas('raceResult', function($q) use ($raceNumber) {
                $q->where('race_number', $raceNumber);
            })
            ->whereHas('crew.team', function($q) use ($teamPattern) {
                $q->where('name', 'like', "%{$teamPattern}%");
            })
            ->when($lane, function($q) use ($lane) {
                $q->where('lane', $lane);
            })
            ->get();

        if ($crewResults->isEmpty()) {
            $this->error("No crew results found for the specified criteria");
            return Command::FAILURE;
        }

        $this->info("Found {$crewResults->count()} crew result(s):");
        $this->newLine();

        // Step 4: Display detailed analysis of each crew result
        foreach ($crewResults as $crewResult) {
            $this->displayCrewResultAnalysis($crewResult);
            $this->newLine();
        }

        // Step 5: Check for inconsistencies
        $this->info("Step 5: Checking for data inconsistencies...");
        $this->checkInconsistencies($crewResults);

        return Command::SUCCESS;
    }

    /**
     * Display detailed analysis of a crew result.
     *
     * @param CrewResult $crewResult
     */
    private function displayCrewResultAnalysis($crewResult)
    {
        $race = $crewResult->raceResult;
        $crew = $crewResult->crew;
        $team = $crew->team;

        $this->info("=== CREW RESULT ANALYSIS ===");
        $this->info("Crew Result ID: {$crewResult->id}");
        $this->info("Race: #{$race->race_number} - {$race->discipline->getDisplayName()}");
        $this->info("Race Status: {$race->status}");
        $this->info("Team: {$team->name}");
        $this->info("Lane: " . ($crewResult->lane ?? 'Not assigned'));
        $this->info("Position: " . ($crewResult->position ?? 'Not assigned'));
        $this->info("Time (ms): " . ($crewResult->time_ms ?? 'No time'));
        $this->info("Formatted Time: " . ($crewResult->formatted_time ?? 'No time'));
        $this->info("Status: {$crewResult->status}");
        $this->info("Created: {$crewResult->created_at}");
        $this->info("Updated: {$crewResult->updated_at}");

        // Analyze potential issues
        $issues = $this->analyzeCrewResultIssues($crewResult);
        if (!empty($issues)) {
            $this->error("POTENTIAL ISSUES DETECTED:");
            foreach ($issues as $issue) {
                $this->error("  - {$issue}");
            }
        } else {
            $this->info("No obvious issues detected");
        }
    }

    /**
     * Analyze a crew result for potential issues.
     *
     * @param CrewResult $crewResult
     * @return array
     */
    private function analyzeCrewResultIssues($crewResult)
    {
        $issues = [];
        $race = $crewResult->raceResult;

        // Check if race status doesn't match having a time
        if ($race->status === 'SCHEDULED' && $crewResult->time_ms) {
            $issues[] = "Race is only SCHEDULED but crew has time ({$crewResult->formatted_time})";
        }

        if ($race->status === 'CANCELLED' && $crewResult->time_ms) {
            $issues[] = "Race is CANCELLED but crew has time ({$crewResult->formatted_time})";
        }

        // Check if crew status doesn't match having a time
        if (in_array($crewResult->status, ['DNS', 'DNF', 'DSQ']) && $crewResult->time_ms) {
            $issues[] = "Crew status is {$crewResult->status} but has time ({$crewResult->formatted_time})";
        }

        // Check for suspicious times
        if ($crewResult->time_ms && $crewResult->time_ms < 30000) {
            $issues[] = "Time is suspiciously fast (< 30 seconds): {$crewResult->formatted_time}";
        }

        if ($crewResult->time_ms && $crewResult->time_ms > 600000) {
            $issues[] = "Time is suspiciously slow (> 10 minutes): {$crewResult->formatted_time}";
        }

        // Check position consistency
        if ($crewResult->position && !$crewResult->time_ms) {
            $issues[] = "Has position ({$crewResult->position}) but no time";
        }

        if ($crewResult->time_ms && $crewResult->status === 'FINISHED' && !$crewResult->position) {
            $issues[] = "Has time and is FINISHED but no position assigned";
        }

        return $issues;
    }

    /**
     * Check for inconsistencies across multiple crew results.
     *
     * @param \Illuminate\Database\Eloquent\Collection $crewResults
     */
    private function checkInconsistencies($crewResults)
    {
        $inconsistencies = [];

        // Group by race to check for lane conflicts
        $byRace = $crewResults->groupBy('race_result_id');

        foreach ($byRace as $raceId => $raceCrewResults) {
            $race = $raceCrewResults->first()->raceResult;

            // Check for duplicate lanes
            $lanes = $raceCrewResults->pluck('lane')->filter()->toArray();
            $duplicateLanes = array_diff_assoc($lanes, array_unique($lanes));

            if (!empty($duplicateLanes)) {
                $inconsistencies[] = "Race #{$race->race_number}: Duplicate lanes detected: " . implode(', ', array_unique($duplicateLanes));
            }

            // Check for position conflicts
            $positions = $raceCrewResults->pluck('position')->filter()->toArray();
            $duplicatePositions = array_diff_assoc($positions, array_unique($positions));

            if (!empty($duplicatePositions)) {
                $inconsistencies[] = "Race #{$race->race_number}: Duplicate positions detected: " . implode(', ', array_unique($duplicatePositions));
            }

            // Check if finished crews without times have positions
            $finishedWithoutTime = $raceCrewResults->filter(function($cr) {
                return $cr->status === 'FINISHED' && !$cr->time_ms && $cr->position;
            });

            if ($finishedWithoutTime->isNotEmpty()) {
                $inconsistencies[] = "Race #{$race->race_number}: " . $finishedWithoutTime->count() . " crews marked FINISHED with positions but no times";
            }
        }

        if (!empty($inconsistencies)) {
            $this->error("DATA INCONSISTENCIES FOUND:");
            foreach ($inconsistencies as $inconsistency) {
                $this->error("  - {$inconsistency}");
            }
        } else {
            $this->info("No data inconsistencies detected");
        }

        // Show recommendations
        $this->newLine();
        $this->info("RECOMMENDATIONS:");

        $staleCrewResults = $crewResults->filter(function($crewResult) {
            $issues = $this->analyzeCrewResultIssues($crewResult);
            return !empty($issues);
        });

        if ($staleCrewResults->isNotEmpty()) {
            $this->info("Run the cleanup command to fix stale data:");
            $this->info("  php artisan crew-results:cleanup --dry-run");
            $this->info("Or target specific race/team:");
            $this->info("  php artisan crew-results:cleanup --race-number={$this->option('race-number')} --team={$this->option('team-pattern')} --dry-run");
        } else {
            $this->info("No cleanup needed based on current analysis");
        }
    }
}