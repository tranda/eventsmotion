<?php

namespace App\Services\Schedule;

/**
 * Maps a stage name (e.g. "Heat 2", "Round 3", "Grand Final") to a
 * phase integer that drives waves ordering inside a placement block.
 *
 * Placement sorts races by (phase, discipline_id, stage_number) so
 * that all discipline heats run before any repechage, all repechages
 * before any semifinal, and — importantly for the multi-round /
 * rounds-based formats — everyone's Round 1 runs before anyone's
 * Round 2.
 *
 * Phase table (case-insensitive prefix match):
 *
 *   Heat N            → 0          all heats interleave first
 *   Round N           → N − 1      each round is its own wave
 *   Repechage N       → 100        reps together, after all heats/rounds
 *   Semifinal N       → 200        semis together
 *   Final variants    → 300        Grand / Minor / Tail finals last
 *   anything else     → 999        tail bucket, preserves current order
 */
class StagePhase
{
    public static function of(string $stage): int
    {
        $s = strtolower(trim($stage));
        if ($s === '') return 999;

        if (str_starts_with($s, 'heat')) return 0;

        if (str_starts_with($s, 'round')) {
            if (preg_match('/round\s+(\d+)/', $s, $m)) {
                return max(0, (int) $m[1] - 1);
            }
            return 0;
        }

        if (str_starts_with($s, 'repechage')) return 100;
        if (str_starts_with($s, 'semi')) return 200;

        // "Grand Final", "Minor Final", "Tail Final", "Final".
        if (str_contains($s, 'final')) return 300;

        return 999;
    }

    /**
     * Extract the numeric suffix from a stage name for stable ordering
     * within a phase. "Heat 3" → 3, "Repechage 1" → 1, "Grand Final" → 0.
     */
    public static function stageNumber(string $stage): int
    {
        if (preg_match('/(\d+)\s*$/', $stage, $m)) {
            return (int) $m[1];
        }
        return 0;
    }
}
