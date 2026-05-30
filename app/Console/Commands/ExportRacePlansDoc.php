<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * php artisan idbf:export-plans
 *
 * Generates docs/idbf/race-plans-summary.md from config/idbf_race_plans.php.
 * Re-run after any config change so the doc stays in sync.
 */
class ExportRacePlansDoc extends Command
{
    protected $signature = 'idbf:export-plans {--out=docs/idbf/race-plans-summary.md}';
    protected $description = 'Generate a markdown summary of every race plan from config/idbf_race_plans.php';

    public function handle(): int
    {
        $plans = config('idbf_race_plans');
        if (!is_array($plans) || empty($plans)) {
            $this->error('config/idbf_race_plans.php is empty or missing.');
            return self::FAILURE;
        }

        $out = $this->buildMarkdown($plans);

        $path = base_path((string) $this->option('out'));
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $out);

        $this->info('Wrote ' . count($plans) . ' plan(s) to ' . $path);
        return self::SUCCESS;
    }

    private function buildMarkdown(array $plans): string
    {
        $lines = [];
        $lines[] = '# Race Plans Summary';
        $lines[] = '';
        $lines[] = '_Auto-generated from `config/idbf_race_plans.php` by `php artisan idbf:export-plans`._';
        $lines[] = '_Source of truth for IDBF plans: `docs/idbf/Race-Plans-v2-2020-08.pdf`._';
        $lines[] = '';
        $lines[] = '## Position-reference convention';
        $lines[] = '';
        $lines[] = 'Refs like `1st in hts` / `3rd in reps` / `5th in SF` resolve as:';
        $lines[] = '';
        $lines[] = '1. Positions 1..N (N = number of races in the source stage) → literal **winner of each race**, in race order.';
        $lines[] = '2. Positions N+1..M → remaining crews ranked by combined time across the stage (lucky losers).';
        $lines[] = '';
        $lines[] = 'Example: for RP.1 with 2 heats, `1st in hts` = winner of Heat 1, `2nd in hts` = winner of Heat 2, `3rd in hts` = fastest non-winner overall.';
        $lines[] = '';

        // Group plans by lane count.
        $byLanes = [];
        foreach ($plans as $code => $data) {
            $byLanes[(int) ($data['lane_count'] ?? 0)][$code] = $data;
        }
        ksort($byLanes);

        $lines[] = '## Index';
        $lines[] = '';
        foreach ($byLanes as $lc => $group) {
            $lines[] = "**{$lc} lanes**: " . implode(', ', array_map(
                fn($c) => "[{$c}](#" . $this->slug($c) . ')',
                array_keys($group),
            ));
        }
        $lines[] = '';

        foreach ($byLanes as $laneCount => $group) {
            $lines[] = '---';
            $lines[] = '';
            $lines[] = "# {$laneCount}-lane plans";
            $lines[] = '';
            foreach ($group as $code => $data) {
                $lines[] = $this->renderPlan($code, $data);
                $lines[] = '';
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function renderPlan(string $code, array $data): string
    {
        $lines = [];
        $isVariant = str_contains($code, '_'); // our naming for non-IDBF variants
        $variantTag = $isVariant ? ' _(non-IDBF variant)_' : '';

        $range = $data['crew_count_range'] ?? [null, null];
        $crewRange = ($range[0] === $range[1])
            ? "{$range[0]} crews"
            : "{$range[0]}–{$range[1]} crews";

        $lines[] = "## `{$code}`{$variantTag}";
        $lines[] = '';
        $lines[] = "- **Lanes**: {$data['lane_count']}";
        $lines[] = "- **Crew count**: {$crewRange}";
        $lines[] = '- **Stages**: ' . implode(' → ', $data['stages'] ?? []);
        $lines[] = '- **Total races**: ' . count($data['stages'] ?? []);
        $lines[] = '';

        // Heat / Round lane seeding.
        $isRounds = isset($data['round_lane_seeding']);
        $heatKey = $isRounds ? 'round_lane_seeding' : 'heat_lane_seeding';
        $heatLabel = $isRounds ? 'Round' : 'Heat';
        if (!empty($data[$heatKey])) {
            $lines[] = "### {$heatLabel} lane seeding (seed numbers)";
            $lines[] = '';
            $lines[] = $this->renderLaneTable($data[$heatKey], $heatLabel, $data['lane_count']);
            $lines[] = '';
        }

        if (!empty($data['repechage_lane_seeding'])) {
            $lines[] = '### Repechage lane seeding';
            $lines[] = '';
            $lines[] = $this->renderLaneTable($data['repechage_lane_seeding'], 'Rep', $data['lane_count']);
            $lines[] = '';
        }

        if (!empty($data['repechage_lane_seeding_by_crew_count'])) {
            $lines[] = '### Repechage lane seeding (varies by exact crew count)';
            $lines[] = '';
            foreach ($data['repechage_lane_seeding_by_crew_count'] as $cc => $map) {
                $lines[] = "**{$cc} crews:**";
                $lines[] = '';
                $lines[] = $this->renderLaneTable($map, 'Rep', $data['lane_count']);
                $lines[] = '';
            }
        }

        if (!empty($data['semi_lane_seeding'])) {
            $lines[] = '### Semi-final lane seeding';
            $lines[] = '';
            $lines[] = $this->renderLaneTable($data['semi_lane_seeding'], 'Semi', $data['lane_count']);
            $lines[] = '';
        }

        if (!empty($data['grand_final_lane_seeding'])) {
            $lines[] = '### Grand Final lane seeding';
            $lines[] = '';
            $lines[] = $this->renderLaneTable([1 => $data['grand_final_lane_seeding']], 'Final', $data['lane_count']);
            $lines[] = '';
        }
        if (!empty($data['minor_final_lane_seeding'])) {
            $lines[] = '### Minor Final lane seeding';
            $lines[] = '';
            $lines[] = $this->renderLaneTable([1 => $data['minor_final_lane_seeding']], 'Minor', $data['lane_count']);
            $lines[] = '';
        }
        if (!empty($data['tail_final_lane_seeding'])) {
            $lines[] = '### Tail Final lane seeding';
            $lines[] = '';
            $lines[] = $this->renderLaneTable([1 => $data['tail_final_lane_seeding']], 'Tail', $data['lane_count']);
            $lines[] = '';
        }

        if (!empty($data['advancement'])) {
            $lines[] = '### Advancement';
            $lines[] = '';
            foreach ($data['advancement'] as $note) {
                $lines[] = "- {$note}";
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<int, int|string|null>> $rows  e.g. [1 => [1 => 5, 2 => 1, ...], 2 => [...]]
     */
    private function renderLaneTable(array $rows, string $rowLabel, int $laneCount): string
    {
        $lines = [];
        $header = ['Lane →'];
        for ($l = 1; $l <= $laneCount; $l++) {
            $header[] = "L{$l}";
        }
        $lines[] = '| ' . implode(' | ', $header) . ' |';
        $lines[] = '|' . str_repeat('---|', count($header));

        $i = 1;
        foreach ($rows as $rowKey => $lanes) {
            $row = ["**{$rowLabel} " . (is_int($rowKey) ? $rowKey : $i) . '**'];
            for ($l = 1; $l <= $laneCount; $l++) {
                $v = $lanes[$l] ?? null;
                $row[] = $v === null ? '—' : (string) $v;
            }
            $lines[] = '| ' . implode(' | ', $row) . ' |';
            $i++;
        }
        return implode("\n", $lines);
    }

    private function slug(string $code): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $code) ?? $code);
    }
}
