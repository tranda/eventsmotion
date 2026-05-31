<?php

namespace App\Services\Schedule;

use App\Models\Discipline;
use App\Models\Event;
use App\Models\RaceResult;
use Illuminate\Support\Collection;

/**
 * Derives the one-line "where do these crews go next" message for each race.
 *
 * Source of truth — in priority order:
 *   1. RaceResult.progression_note (admin override).
 *   2. Auto-derived from the discipline's IDBF Race Plan + actual race numbers
 *      assigned to the destination stages.
 *
 * The auto rule is built by:
 *   - Mapping the source stage to its IDBF source key ('hts' / 'reps' / 'sf').
 *   - Scanning later stages' lane_seeding tables for refs from that source.
 *   - Grouping destinations by "promotion" (Semi / Grand Final) vs
 *     "consolation" (Repechage / Minor / Tail Final).
 *   - Resolving destination stage names → real race numbers from the
 *     scheduled RaceResults in the same discipline.
 *
 * Rounds plans get a fixed message ("Times summed with other rounds").
 * Terminal stages (last Final / last Round) return "Final standings."
 */
class ProgressionDescriber
{
    public function __construct(
        private IdbfRacePlans $plans,
    ) {}

    /**
     * Compute progression messages for every scheduled race in the event.
     *
     * @return array<int, string> race_id => message (empty string if none)
     */
    public function forEvent(Event $event): array
    {
        $races = RaceResult::whereHas('discipline', fn($q) => $q->where('event_id', $event->id))
            ->where('entry_type', 'race')
            ->with('discipline.progression')
            ->get();

        $byDiscipline = $races->groupBy('discipline_id');
        $laneCount = (int) ($event->lane_count ?? 6);

        $result = [];
        foreach ($byDiscipline as $disciplineRaces) {
            $discipline = $disciplineRaces->first()->discipline;
            if (!$discipline) {
                continue;
            }
            $messages = $this->forDiscipline($discipline, $disciplineRaces, $laneCount);
            foreach ($messages as $raceId => $msg) {
                $result[$raceId] = $msg;
            }
        }
        return $result;
    }

    /**
     * @param Collection<RaceResult> $races all races in this discipline
     * @return array<int, string> race_id => message
     */
    public function forDiscipline(Discipline $discipline, Collection $races, int $laneCount): array
    {
        $crewCount = $races->pluck('id')->count() > 0
            ? $discipline->crews()->count()
            : 0;

        $plan = $this->resolvePlan($discipline, $laneCount, $crewCount);

        $result = [];
        foreach ($races as $race) {
            // Override wins.
            if (!empty($race->progression_note)) {
                $result[$race->id] = (string) $race->progression_note;
                continue;
            }
            $result[$race->id] = $plan
                ? $this->describeStage($plan, (string) $race->stage, $races)
                : '';
        }
        return $result;
    }

    /**
     * Override-only resolver — used when the plan can't be resolved (e.g.
     * crew count out of range). Returns empty string if no override.
     */
    public function noteFor(RaceResult $race): string
    {
        return (string) ($race->progression_note ?? '');
    }

    private function resolvePlan(Discipline $discipline, int $laneCount, int $crewCount): ?RacePlan
    {
        try {
            $override = optional($discipline->progression)->race_plan_code;
            if ($override) {
                return $this->plans->getPlan($override);
            }
            if ($crewCount < 2 || $laneCount < 2) {
                return null;
            }
            return $this->plans->pickPlan($laneCount, $crewCount);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param Collection<RaceResult> $races all races in this discipline
     */
    private function describeStage(RacePlan $plan, string $stage, Collection $races): string
    {
        if ($stage === '') {
            return '';
        }

        $stages = $plan->stages();
        $idx = array_search($stage, $stages, true);
        if ($idx === false) {
            return '';
        }

        // Terminal: last stage in the plan.
        if ($idx === count($stages) - 1) {
            return 'Final standings.';
        }

        // Rounds plan: times sum across all rounds for placings.
        if ($plan->isRoundsPlan() && str_starts_with($stage, 'Round')) {
            return $this->describeRound($stage, $stages, $races);
        }

        $sourceKey = $this->stageToSourceKey($stage);
        if ($sourceKey === null) {
            return '';
        }

        $destinations = $this->collectDestinations($plan, $sourceKey);
        if (empty($destinations)) {
            return '';
        }

        return $this->buildSentence($plan, $sourceKey, $destinations, $races);
    }

    /**
     * @param array<string, int> $destinations stage_name => num_positions_referenced
     * @param Collection<RaceResult> $races
     */
    private function buildSentence(RacePlan $plan, string $sourceKey, array $destinations, Collection $races): string
    {
        $tier = $plan->sourceOrderingTiers($sourceKey);
        $sourceLabel = $this->sourceLabel($sourceKey);

        // Split destinations into promotion vs consolation.
        $promotion = [];
        $consolation = [];
        foreach ($destinations as $destStage => $count) {
            if ($this->isPromotion($destStage)) {
                $promotion[] = $destStage;
            } else {
                $consolation[] = $destStage;
            }
        }

        $promoText = $this->resolveStageList($promotion, $races);
        $consText = $this->resolveStageList($consolation, $races);

        $tierText = $tier === 1
            ? "Winner of each {$sourceLabel}"
            : ($tier === 2 ? "Top 2 of each {$sourceLabel}" : "Top {$tier} of each {$sourceLabel}");

        // Single promotion path, no consolation (or consolation is the same set).
        if ($promoText !== '' && $consText === '') {
            return "{$tierText} → {$promoText}.";
        }
        if ($promoText === '' && $consText !== '') {
            return "All crews → {$consText}.";
        }
        if ($promoText !== '' && $consText !== '') {
            return "{$tierText} → {$promoText}. Others → {$consText}.";
        }
        return '';
    }

    /**
     * @param Collection<RaceResult> $races
     */
    private function describeRound(string $stage, array $stages, Collection $races): string
    {
        $others = array_values(array_filter($stages, fn($s) => $s !== $stage));
        $list = $this->resolveStageList($others, $races);
        if ($list === '') {
            return 'Times will be summed across all rounds for final standings.';
        }
        return "Time sums with {$list} for final standings.";
    }

    /**
     * Resolve a list of stage names (e.g. ['Semi 1', 'Semi 2']) into a
     * comma-joined "Semi 1 (#42), Semi 2 (#43)" string. Skips stages with
     * no scheduled race (number resolution fallback: bare stage name).
     *
     * @param string[] $stageNames
     * @param Collection<RaceResult> $races
     */
    private function resolveStageList(array $stageNames, Collection $races): string
    {
        $parts = [];
        foreach ($stageNames as $stage) {
            $race = $races->firstWhere('stage', $stage);
            if ($race && $race->race_number) {
                $parts[] = "{$stage} (#{$race->race_number})";
            } else {
                $parts[] = $stage;
            }
        }
        return implode(', ', $parts);
    }

    /**
     * @return array<string, int> destination stage name => count of lane refs
     *   from this source key across all later stages.
     */
    private function collectDestinations(RacePlan $plan, string $sourceKey): array
    {
        $destinations = [];
        $stages = $plan->stages();
        // We don't know the actual crew count here for repechageSeeding, but
        // since we're just collecting destination *stage names* (not specific
        // lane assignments), the variant doesn't matter. Pass 0; helper
        // returns the default tables.
        $rep = $plan->repechageSeeding(0);
        $sem = $plan->semiSeeding();
        $gf = $plan->grandFinalSeeding();
        $mf = $plan->minorFinalSeeding();
        $tf = $plan->tailFinalSeeding();

        $tally = function (string $stageName, $lanes) use ($sourceKey, &$destinations) {
            if (!is_array($lanes)) return;
            foreach ($lanes as $lane => $ref) {
                if ($this->refMatchesSource($ref, $sourceKey)) {
                    $destinations[$stageName] = ($destinations[$stageName] ?? 0) + 1;
                }
            }
        };

        if (is_array($rep)) {
            foreach ($rep as $idx => $lanes) {
                $tally($this->repechageName($idx, $rep), $lanes);
            }
        }
        if (is_array($sem)) {
            foreach ($sem as $idx => $lanes) {
                $tally("Semi {$idx}", $lanes);
            }
        }
        if (is_array($gf)) {
            $tally('Grand Final', $gf);
        }
        if (is_array($mf)) {
            $tally('Minor Final', $mf);
        }
        if (is_array($tf)) {
            $tally('Tail Final', $tf);
        }

        // Preserve the stage order from the plan rather than insertion order.
        $ordered = [];
        foreach ($stages as $s) {
            if (isset($destinations[$s])) {
                $ordered[$s] = $destinations[$s];
            }
        }
        return $ordered;
    }

    /**
     * Some plans key repechages by exact crew count; the default we get above
     * is indexed by repechage number (1, 2, ...). Build "Repechage N".
     *
     * @param int|string $idx
     * @param array $rep full repechage map
     */
    private function repechageName($idx, array $rep): string
    {
        if (is_int($idx) && $idx >= 1) {
            return "Repechage {$idx}";
        }
        // Variants keyed by something else → just "Repechage".
        return 'Repechage';
    }

    private function refMatchesSource($ref, string $sourceKey): bool
    {
        if (!is_string($ref)) return false;
        // Refs look like "1st in hts" / "3rd in reps" / "2nd in SF".
        $r = strtolower($ref);
        if ($sourceKey === 'sf') {
            return str_contains($r, ' in sf');
        }
        return str_contains($r, " in {$sourceKey}");
    }

    private function stageToSourceKey(string $stage): ?string
    {
        if (str_starts_with($stage, 'Heat')) return 'hts';
        if (str_starts_with($stage, 'Repechage')) return 'reps';
        if (str_starts_with($stage, 'Semi')) return 'sf';
        return null;
    }

    private function sourceLabel(string $sourceKey): string
    {
        return match ($sourceKey) {
            'hts' => 'heat',
            'reps' => 'repechage',
            'sf' => 'semi-final',
            default => 'race',
        };
    }

    private function isPromotion(string $destStage): bool
    {
        return str_starts_with($destStage, 'Semi')
            || $destStage === 'Grand Final';
    }
}
