<?php

namespace App\Services\Schedule;

use App\Models\Crew;
use App\Models\Discipline;
use App\Models\Event;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Parses the entry-matrix CSV format (rows = teams, columns = disciplines,
 * grouped into Standard/Small boat sections) and registers Crew rows.
 *
 * Per-cell mark "x" (case-insensitive) = "this team is registered for this
 * discipline". Other accepted truthy marks: "1", "yes", "y", "✓".
 *
 * Rules:
 *   - Missing teams/clubs → skip, surface as warning. Operator must add them
 *     in Admin first.
 *   - Missing disciplines → auto-create (event_id + boat/age/gender/distance,
 *     status='active'). Caller can review the diff afterwards.
 *   - Duplicate registration (crew row already exists for team+discipline) →
 *     skip silently, count toward 'crews_skipped_existing'. Re-runs are
 *     idempotent.
 */
class CrewRegistrationImporter
{
    /**
     * Run the importer.
     *
     * @param Event  $event   the event whose disciplines/teams to match against
     * @param string $csv     raw CSV text
     * @param bool   $dryRun  true → rollback transaction (preview only)
     * @param bool   $sync    true → also unregister teams whose cell is empty
     *                        in the CSV (only for team/discipline pairs that
     *                        are in-scope of the CSV). Destructive.
     */
    public function importForEvent(Event $event, string $csv, bool $dryRun, bool $sync = false): array
    {
        $sections = $this->parseCsv($csv);

        $result = [
            'sections_parsed' => count($sections),
            'matched_count' => 0,
            'crews_created' => 0,
            'crews_skipped_existing' => 0,
            'crews_unregistered' => 0,
            'unregistered_pairs' => [], // ["team_name — discipline_name", ...]
            'disciplines_created' => 0,
            'unmatched_teams' => [],
            'warnings' => [],
            'sync_mode' => $sync,
        ];

        DB::beginTransaction();
        try {
            // Pre-load existing disciplines keyed by normalized signature.
            $disciplinesByKey = [];
            foreach ($event->disciplines()->get() as $d) {
                $disciplinesByKey[$this->disciplineKey(
                    $d->boat_group,
                    $d->age_group,
                    $d->gender_group,
                    (int) $d->distance,
                )] = $d;
            }

            // Pre-load existing crews to dedupe / unregister.
            $existingCrews = Crew::with(['team', 'discipline'])->whereHas(
                'discipline',
                fn($q) => $q->where('event_id', $event->id),
            )->get()->keyBy(fn($c) => "{$c->team_id}-{$c->discipline_id}");

            // Track (team_id, discipline_id) pairs that are marked in the CSV,
            // and the in-scope team/discipline IDs (everything that appears as
            // a row or column header in the CSV). Sync mode uses these to
            // decide what to unregister.
            $markedPairs = [];        // "team_id-discipline_id" => true
            $inScopeTeamIds = [];     // team_id => true
            $inScopeDisciplineIds = []; // discipline_id => true

            foreach ($sections as $section) {
                $boat = $this->normalizeBoat($section['boat_group']);
                if (!$boat) {
                    $result['warnings'][] = "Unknown boat group '{$section['boat_group']}', section skipped.";
                    continue;
                }

                // First pass: resolve column headers to existing disciplines
                // (only existing — auto-create happens later only for marked
                // cells, so an empty header column doesn't spawn a discipline).
                $colToDiscipline = [];
                foreach ($section['columns'] as $colIdx => $col) {
                    $age = $this->normalizeAge($col['age_group']);
                    $gender = $this->normalizeGender($col['gender_group']);
                    $distance = $this->normalizeDistance($col['distance']);
                    if (!$age || !$gender || !$distance) {
                        continue;
                    }
                    $key = $this->disciplineKey($boat, $age, $gender, (int) $distance);
                    $d = $disciplinesByKey[$key] ?? null;
                    if ($d) {
                        $colToDiscipline[$colIdx] = $d;
                        $inScopeDisciplineIds[$d->id] = true;
                    }
                }

                foreach ($section['team_rows'] as $teamRow) {
                    if ($teamRow['team_name'] === '') {
                        continue;
                    }

                    $team = $this->findTeam($teamRow['team_name'], $teamRow['club_name']);
                    if (!$team) {
                        $key = trim("{$teamRow['team_name']} ({$teamRow['club_name']})");
                        if (!in_array($key, $result['unmatched_teams'], true)) {
                            $result['unmatched_teams'][] = $key;
                        }
                        continue;
                    }
                    $inScopeTeamIds[$team->id] = true;

                    foreach ($section['columns'] as $colIdx => $col) {
                        $markIdx = $colIdx - 5;
                        $mark = trim($teamRow['marks'][$markIdx] ?? '');
                        if (!$this->isRegisteredMark($mark)) {
                            continue;
                        }

                        $age = $this->normalizeAge($col['age_group']);
                        $gender = $this->normalizeGender($col['gender_group']);
                        $distance = $this->normalizeDistance($col['distance']);
                        if (!$age || !$gender || !$distance) {
                            $result['warnings'][] = "Cell mark at column {$colIdx} skipped: "
                                . "incomplete header (age='{$col['age_group']}', "
                                . "gender='{$col['gender_group']}', distance='{$col['distance']}').";
                            continue;
                        }

                        // Use cached resolution, or auto-create on first mark.
                        $discipline = $colToDiscipline[$colIdx] ?? null;
                        if (!$discipline) {
                            $key = $this->disciplineKey($boat, $age, $gender, (int) $distance);
                            $discipline = Discipline::create([
                                'event_id' => $event->id,
                                'boat_group' => $boat,
                                'age_group' => $age,
                                'gender_group' => $gender,
                                'distance' => (int) $distance,
                                'status' => 'active',
                            ]);
                            $disciplinesByKey[$key] = $discipline;
                            $colToDiscipline[$colIdx] = $discipline;
                            $inScopeDisciplineIds[$discipline->id] = true;
                            $result['disciplines_created']++;
                        }

                        $result['matched_count']++;
                        $crewKey = "{$team->id}-{$discipline->id}";
                        $markedPairs[$crewKey] = true;

                        if ($existingCrews->has($crewKey)) {
                            $result['crews_skipped_existing']++;
                            continue;
                        }

                        $crew = Crew::create([
                            'team_id' => $team->id,
                            'discipline_id' => $discipline->id,
                        ]);
                        $existingCrews->put($crewKey, $crew);
                        $result['crews_created']++;
                    }
                }
            }

            // Sync mode: delete crews where both team and discipline are
            // in-scope of the CSV but the cell is empty. Only touches pairs
            // the CSV actually covers — crews for teams/disciplines outside
            // the CSV are left alone.
            if ($sync) {
                foreach ($existingCrews as $crewKey => $crew) {
                    if (isset($markedPairs[$crewKey])) {
                        continue;
                    }
                    if (!isset($inScopeTeamIds[$crew->team_id])) {
                        continue;
                    }
                    if (!isset($inScopeDisciplineIds[$crew->discipline_id])) {
                        continue;
                    }
                    $teamName = $crew->team?->name ?? "team {$crew->team_id}";
                    $discName = $crew->discipline?->getDisplayName() ?? "discipline {$crew->discipline_id}";
                    $result['unregistered_pairs'][] = "{$teamName} — {$discName}";
                    $result['crews_unregistered']++;
                    $crew->delete();
                }
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $result;
    }

    /**
     * Parse the raw CSV into one or more "sections" (Standard, Small, …).
     * Each section: boat_group, columns (colIdx => header), team_rows.
     */
    private function parseCsv(string $csv): array
    {
        $rows = $this->parseCsvRows($csv);
        $sections = [];
        $i = 0;
        $n = count($rows);

        while ($i < $n) {
            if ($this->isBoatGroupHeader($rows[$i])) {
                $section = $this->parseSection($rows, $i);
                $sections[] = $section;
                $i = $section['next_row'];
            } else {
                $i++;
            }
        }
        return $sections;
    }

    private function parseSection(array $rows, int $start): array
    {
        $boatRow = $rows[$start] ?? [];
        $ageRow = $rows[$start + 1] ?? [];
        $genderRow = $rows[$start + 2] ?? [];
        $labelsRow = $rows[$start + 3] ?? [];

        $boat = trim($boatRow[5] ?? '');

        $columns = [];
        $colCount = max(count($labelsRow), count($boatRow));
        for ($c = 5; $c < $colCount; $c++) {
            $columns[$c] = [
                'boat_group' => trim($boatRow[$c] ?? ''),
                'age_group' => trim($ageRow[$c] ?? ''),
                'gender_group' => trim($genderRow[$c] ?? ''),
                'distance' => trim($labelsRow[$c] ?? ''),
            ];
        }

        // Skip the counts row at start + 4. Team rows start at start + 5.
        $teamRows = [];
        $i = $start + 5;
        $n = count($rows);
        while ($i < $n) {
            $row = $rows[$i];
            $firstCol = trim($row[0] ?? '');
            if (strtoupper($firstCol) === 'TOTAL') {
                break;
            }
            // Stop if we hit the next section header (empty leading cols + boat group at col 5).
            if ($this->isBoatGroupHeader($row)) {
                break;
            }
            $teamRows[] = [
                'team_name' => trim($row[1] ?? ''),
                'club_name' => trim($row[2] ?? ''),
                'country' => trim($row[3] ?? ''),
                'marks' => array_slice($row, 5),
            ];
            $i++;
        }

        return [
            'boat_group' => $boat,
            'columns' => $columns,
            'team_rows' => $teamRows,
            'next_row' => $i + 1, // skip past TOTAL/section break
        ];
    }

    private function isBoatGroupHeader(array $row): bool
    {
        for ($i = 0; $i < 5; $i++) {
            if (trim($row[$i] ?? '') !== '') {
                return false;
            }
        }
        $boat = strtolower(trim($row[5] ?? ''));
        return $boat === 'standard' || $boat === 'small';
    }

    private function parseCsvRows(string $csv): array
    {
        // Strip UTF-8 BOM if present.
        if (strncmp($csv, "\xEF\xBB\xBF", 3) === 0) {
            $csv = substr($csv, 3);
        }
        $lines = preg_split('/\r?\n/', $csv);
        $rows = [];
        foreach ($lines as $line) {
            // Keep blank lines so row indices align with the source file.
            $rows[] = str_getcsv($line);
        }
        return $rows;
    }

    private function findTeam(string $teamName, string $clubName): ?Team
    {
        $teamNeedle = strtolower(trim($teamName));
        $clubNeedle = strtolower(trim($clubName));
        if ($teamNeedle === '' || $clubNeedle === '') {
            return null;
        }
        return Team::whereRaw('LOWER(TRIM(name)) = ?', [$teamNeedle])
            ->whereHas('club', fn($q) => $q->whereRaw('LOWER(TRIM(name)) = ?', [$clubNeedle]))
            ->first();
    }

    private function normalizeBoat(?string $s): ?string
    {
        return match (strtolower(trim((string) $s))) {
            'standard' => 'Standard',
            'small' => 'Small',
            default => null,
        };
    }

    private function normalizeAge(?string $s): ?string
    {
        $key = strtolower(trim((string) $s));
        // Spreadsheet autofill often turns U24 into 'under 24', 'under 25', …
        // through 'under 29'. Treat any 'under N' label as U24.
        if (preg_match('/^under\s+\d+$/', $key)) {
            return 'U24';
        }
        return match ($key) {
            'premier' => 'Premier',
            'senior a' => 'Senior A',
            'senior b' => 'Senior B',
            'senior c' => 'Senior C',
            'senior d' => 'Senior D',
            'junior' => 'Junior',
            'junior a' => 'Junior A',
            'junior b' => 'Junior B',
            'u24' => 'U24',
            'bcp' => 'BCP',
            'acp' => 'ACP',
            default => null,
        };
    }

    private function normalizeGender(?string $s): ?string
    {
        return match (strtolower(trim((string) $s))) {
            'open', 'o' => 'Open',
            'women', 'w' => 'Women',
            'mixed', 'm', 'x' => 'Mixed',
            default => null,
        };
    }

    private function normalizeDistance(?string $s): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $s);
        return $digits === '' ? null : $digits;
    }

    private function disciplineKey(string $boat, string $age, string $gender, int $distance): string
    {
        return strtolower("$boat|$age|$gender|$distance");
    }

    private function isRegisteredMark(string $cell): bool
    {
        return in_array(strtolower(trim($cell)), ['x', '✓', '1', 'yes', 'y'], true);
    }
}
