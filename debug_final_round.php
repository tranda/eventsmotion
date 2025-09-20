<?php

/**
 * Debug script to test final round detection for existing data.
 *
 * Usage: php debug_final_round.php
 *
 * This script will:
 * 1. Load all race results from the database
 * 2. Call the debug version of isFinalRound() for each
 * 3. Output the results for manual verification
 */

require_once __DIR__ . '/vendor/autoload.php';

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\RaceResult;
use Illuminate\Support\Facades\Log;

echo "=== Debug Final Round Detection ===\n\n";

try {
    // Get all race results grouped by discipline
    $raceResults = RaceResult::with('discipline')
        ->orderBy('discipline_id')
        ->orderBy('created_at')
        ->get()
        ->groupBy('discipline_id');

    if ($raceResults->isEmpty()) {
        echo "No race results found in database.\n";
        exit(0);
    }

    foreach ($raceResults as $disciplineId => $races) {
        echo "=== Discipline ID: $disciplineId ===\n";

        $disciplineName = $races->first()->discipline ?
            $races->first()->discipline->getDisplayName() :
            'Unknown Discipline';
        echo "Discipline: $disciplineName\n";
        echo "Total rounds: " . $races->count() . "\n\n";

        foreach ($races as $race) {
            echo "Race ID: {$race->id}\n";
            echo "Stage: {$race->stage}\n";
            echo "Created: {$race->created_at}\n";

            // Call the debug version to get logging output
            $isFinal = $race->isFinalRoundDebug();

            echo "Is Final Round: " . ($isFinal ? 'YES' : 'NO') . "\n";
            echo "---\n";
        }

        echo "\n";
    }

    echo "=== Debug completed ===\n";
    echo "Check the Laravel log file for detailed debug information.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}