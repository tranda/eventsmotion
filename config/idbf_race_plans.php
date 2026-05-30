<?php

/*
|--------------------------------------------------------------------------
| IDBF Race Plans (v2, August 2020)
|--------------------------------------------------------------------------
|
| Verbatim transcription of the lookup tables in:
|   docs/idbf/Race-Plans-v2-2020-08.pdf
|
| Each plan is keyed by its IDBF code. The structure:
|   lane_count        — number of racing lanes (4, 6, or 8)
|   crew_count_range  — [min, max] inclusive
|   stages            — ordered list of stage names that this plan generates
|   heat_lane_seeding — list of heats, each [lane => seedNumber|null]
|   repechage_lane_seeding — list of reps, each [lane => positionRef|null]
|                            keyed by exact crew_count when it varies
|   semi_lane_seeding      — list of semis, each [lane => positionRef|null]
|   grand_final_lane_seeding / minor_final_lane_seeding / tail_final_lane_seeding
|                          — [lane => positionRef|null]
|   round_lane_seeding — for ROUNDS plans only; list of rounds, each [lane => seedNumber|null]
|   advancement       — instruction strings copied from the PDF; LaneSeeder uses them
|                       (Phase 5) to compute structured progression rules
|
| Position references are strings like "1st in hts", "3rd in reps", "5th in SF".
| Seeds and positions are 1-indexed. Lanes are 1-indexed.
| For Rounds plans, the 4-lane tables in the PDF print "L2..L5"; we normalize to
| "L1..L4" because the seeding rule (intro p3) confirms 4 racing lanes are 1-4.
| Heat compositions for variable crew counts are derived at runtime from the
| heat_lane_seeding tables (a seed > crew_count means that lane is empty).
|
*/

return [

    // -------------------------------------------------------------------
    // 3-LANE PLANS  (custom — not in the IDBF v2 PDF; designed to guarantee
    // every crew at least 2 races on a 3-lane course)
    // -------------------------------------------------------------------

    // 4 crews: 2 heats (2 vs 2) → repechage of heat-2nds → grand final of
    // heat-1sts + rep winner. Every crew gets at least 2 races.
    'RP.1_3L' => [
        'lane_count' => 3,
        'crew_count_range' => [4, 4],
        'stages' => ['Heat 1', 'Heat 2', 'Repechage 1', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 4, 2 => 1, 3 => null],
            2 => [1 => 3, 2 => 2, 3 => null],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => '4th in hts', 2 => '3rd in hts', 3 => null],
        ],
        'grand_final_lane_seeding' => [
            1 => '2nd in hts', 2 => '1st in hts', 3 => '1st in reps',
        ],
        'source_orderings' => ['hts' => 1, 'reps' => 1],
        'advancement' => [
            '4 crews split into 2 heats (2 vs 2).',
            '1st of each heat → Grand Final.',
            '2nd of each heat → Repechage.',
            'Winner of the Repechage → Grand Final (3 crews total).',
            'Every crew races at least twice.',
        ],
    ],

    // -------------------------------------------------------------------
    // 4-LANE PLANS
    // -------------------------------------------------------------------

    'ROUNDS_4L' => [
        'lane_count' => 4,
        'crew_count_range' => [2, 4],
        'stages' => ['Round 1', 'Round 2', 'Round 3'],
        'round_lane_seeding' => [
            1 => [1 => 3, 2 => 1, 3 => 2, 4 => 4],
            2 => [1 => 2, 2 => 3, 3 => 4, 4 => 1],
            3 => [1 => 4, 2 => 2, 3 => 1, 4 => 3],
        ],
        'advancement' => [
            'If time permits use 3 rounds (sum all 3 times for placings); otherwise 2 rounds (sum both).',
        ],
    ],

    'RP.1' => [
        'lane_count' => 4,
        'crew_count_range' => [5, 8],
        'stages' => ['Heat 1', 'Heat 2', 'Repechage 1', 'Repechage 2', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 5, 2 => 1, 3 => 4, 4 => 8],
            2 => [1 => 6, 2 => 2, 3 => 3, 4 => 7],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => '7th in hts', 2 => '3rd in hts', 3 => '6th in hts', 4 => null],
            2 => [1 => '8th in hts', 2 => '4th in hts', 3 => '5th in hts', 4 => null],
        ],
        'grand_final_lane_seeding' => [
            1 => '1st in reps', 2 => '1st in hts', 3 => '2nd in hts', 4 => '2nd in reps',
        ],
        'source_orderings' => ['hts' => 1, 'reps' => 1],
        'advancement' => [
            'Crews seeded in heats based on previous results.',
            'Winner of each heat → Grand Final (2 crews).',
            'Remainder → repechages (3 to 8).',
            '5-6 crews: one repechage only — 1st & 2nd → Grand Final.',
            '7-8 crews: winner of each repechage → Grand Final.',
            'Remainder drop out (5 to 8).',
        ],
    ],

    // Non-IDBF variant. Same heat structure as RP.1 (4 crews + 3 crews) but
    // a single repechage instead of two, and one extra crew skips the rep via
    // "best result" alongside the two heat winners. Listed after RP.1 so
    // pickPlan() keeps choosing the standard plan; this only appears in the
    // override dropdown for operators who want a shorter day.
    //
    // Final lineup (4 crews): 2 heat winners + best heat non-winner + rep winner.
    // Total races: 2 heats + 1 rep + 1 final = 4 (vs RP.1's 5).
    'RP.1_COMPACT' => [
        'lane_count' => 4,
        'crew_count_range' => [7, 7],
        'stages' => ['Heat 1', 'Heat 2', 'Repechage 1', 'Grand Final'],
        // Same heat-lane numbers as RP.1; the resolveHeatPlacements() rebalance
        // turns the 7-crew case into H1=4 (lanes 5,1,4,7), H2=3 (lanes 6,2,3).
        'heat_lane_seeding' => [
            1 => [1 => 5, 2 => 1, 3 => 4, 4 => 8],
            2 => [1 => 6, 2 => 2, 3 => 3, 4 => 7],
        ],
        // 4 crews into the rep, centre-out by overall heat ranking.
        'repechage_lane_seeding' => [
            1 => [1 => '6th in hts', 2 => '4th in hts', 3 => '5th in hts', 4 => '7th in hts'],
        ],
        'grand_final_lane_seeding' => [
            1 => '3rd in hts', 2 => '1st in hts', 3 => '2nd in hts', 4 => '1st in reps',
        ],
        'source_orderings' => ['hts' => 1, 'reps' => 1],
        'advancement' => [
            'Variant of RP.1 — not in the IDBF Race Plans document.',
            '2 heat winners + next fastest overall → Grand Final (3 crews).',
            'Remaining 4 crews → repechage.',
            'Winner of repechage → Grand Final (4 crews total).',
        ],
    ],

    'RP.2' => [
        'lane_count' => 4,
        'crew_count_range' => [9, 12],
        'stages' => ['Heat 1', 'Heat 2', 'Heat 3', 'Repechage 1', 'Repechage 2', 'Semi 1', 'Semi 2', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 7,  2 => 1, 3 => 6, 4 => 12],
            2 => [1 => 8,  2 => 2, 3 => 5, 4 => 11],
            3 => [1 => 9,  2 => 3, 3 => 4, 4 => 10],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => '9th in hts',  2 => '5th in hts', 3 => '8th in hts',  4 => '12th in hts'],
            2 => [1 => '10th in hts', 2 => '6th in hts', 3 => '7th in hts',  4 => '11th in hts'],
        ],
        'semi_lane_seeding' => [
            1 => [1 => '1st in reps', 2 => '1st in hts', 3 => '4th in hts', 4 => '4th in reps'],
            2 => [1 => '2nd in reps', 2 => '2nd in hts', 3 => '3rd in hts', 4 => '3rd in reps'],
        ],
        'grand_final_lane_seeding' => [
            1 => '3rd in SF', 2 => '1st in SF', 3 => '2nd in SF', 4 => '4th in SF',
        ],
        'source_orderings' => ['hts' => 1, 'reps' => 1, 'sf' => 1],
        'advancement' => [
            'Winner of each heat plus next fastest overall → semi-finals (4 crews).',
            'Remainder → repechages (5 to 12).',
            'Winner of each repechage plus next 2 fastest overall → semi-finals (4 crews).',
            'Winner of each semi plus next 2 fastest overall → Grand Final (4 crews).',
        ],
    ],

    'RP.3' => [
        'lane_count' => 4,
        'crew_count_range' => [13, 16],
        'stages' => ['Heat 1', 'Heat 2', 'Heat 3', 'Heat 4', 'Repechage 1', 'Repechage 2', 'Semi 1', 'Semi 2', 'Semi 3', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 9,  2 => 1, 3 => 8,  4 => 16],
            2 => [1 => 10, 2 => 2, 3 => 7,  4 => 15],
            3 => [1 => 11, 2 => 3, 3 => 6,  4 => 14],
            4 => [1 => 12, 2 => 4, 3 => 5,  4 => 13],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => '13th in hts', 2 => '9th in hts',  3 => '12th in hts', 4 => '16th in hts'],
            2 => [1 => '14th in hts', 2 => '10th in hts', 3 => '11th in hts', 4 => '15th in hts'],
        ],
        'semi_lane_seeding' => [
            1 => [1 => '7th in reps', 2 => '1st in hts', 3 => '6th in hts', 4 => '4th in reps'],
            2 => [1 => '8th in reps', 2 => '2nd in hts', 3 => '5th in hts', 4 => '3rd in reps'],
            3 => [1 => '1st in reps', 2 => '3rd in hts', 3 => '4th in hts', 4 => '2nd in reps'],
        ],
        'grand_final_lane_seeding' => [
            1 => '3rd in SF', 2 => '1st in SF', 3 => '2nd in SF', 4 => '4th in SF',
        ],
        'source_orderings' => ['hts' => 2, 'reps' => 2, 'sf' => 1],
        'advancement' => [
            '1st and 2nd in each heat → semi-finals (8 crews).',
            'Remainder → repechages (9 to 16).',
            '1st and 2nd in each repechage → semi-finals (4 crews).',
            'Winner of each semi plus next fastest overall → Grand Final (4 crews).',
        ],
    ],

    // -------------------------------------------------------------------
    // 6-LANE PLANS
    // -------------------------------------------------------------------

    'ROUNDS_6L' => [
        'lane_count' => 6,
        'crew_count_range' => [3, 6],
        'stages' => ['Round 1', 'Round 2', 'Round 3'],
        'round_lane_seeding' => [
            1 => [1 => 5, 2 => 3, 3 => 1, 4 => 2, 5 => 4, 6 => 6],
            2 => [1 => 3, 2 => 2, 3 => 6, 4 => 5, 5 => 1, 6 => 4],
            3 => [1 => 1, 2 => 5, 3 => 4, 4 => 3, 5 => 6, 6 => 2],
        ],
        'advancement' => [
            'If time permits use 3 rounds (sum all 3 times); otherwise 2 rounds (sum both).',
        ],
    ],

    'RP.1A' => [
        'lane_count' => 6,
        'crew_count_range' => [7, 8],
        'stages' => ['Heat 1', 'Heat 2', 'Repechage 1', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => null, 2 => 5, 3 => 1, 4 => 4, 5 => 8, 6 => null],
            2 => [1 => null, 2 => 6, 3 => 2, 4 => 3, 5 => 7, 6 => null],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => '8th in hts', 2 => '6th in hts', 3 => '4th in hts', 4 => '5th in hts', 5 => '7th in hts', 6 => null],
        ],
        'grand_final_lane_seeding' => [
            1 => '2nd in reps', 2 => '3rd in hts', 3 => '1st in hts', 4 => '2nd in hts', 5 => '1st in reps', 6 => '3rd in reps',
        ],
        'source_orderings' => ['hts' => 1, 'reps' => 3],
        'advancement' => [
            'Winner of each heat plus next fastest overall in both heats → Grand Final (3 crews).',
            'Remainder → repechage (4 to 8).',
            '1st, 2nd and 3rd in repechage → Grand Final (3 crews).',
            'Remainder drop out (7 to 8).',
        ],
    ],

    'RP.2A' => [
        'lane_count' => 6,
        'crew_count_range' => [9, 12],
        'stages' => ['Heat 1', 'Heat 2', 'Repechage 1', 'Repechage 2', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 9,  2 => 5, 3 => 1, 4 => 4, 5 => 8,  6 => 12],
            2 => [1 => 10, 2 => 6, 3 => 2, 4 => 3, 5 => 7,  6 => 11],
        ],
        'repechage_lane_seeding_by_crew_count' => [
            // 9 crews: single repechage
            9 => [
                1 => [1 => '8th in hts', 2 => '6th in hts', 3 => '4th in hts', 4 => '5th in hts', 5 => '7th in hts', 6 => '9th in hts'],
            ],
            // 10-12 crews: two repechages
            10 => [
                1 => [1 => '12th in hts', 2 => '8th in hts', 3 => '4th in hts', 4 => '7th in hts', 5 => '11th in hts', 6 => null],
                2 => [1 => '9th in hts',  2 => '5th in hts', 3 => '6th in hts', 4 => '10th in hts', 5 => null,         6 => null],
            ],
            11 => [
                1 => [1 => '12th in hts', 2 => '8th in hts', 3 => '4th in hts', 4 => '7th in hts', 5 => '11th in hts', 6 => null],
                2 => [1 => '9th in hts',  2 => '5th in hts', 3 => '6th in hts', 4 => '10th in hts', 5 => null,         6 => null],
            ],
            12 => [
                1 => [1 => '12th in hts', 2 => '8th in hts', 3 => '4th in hts', 4 => '7th in hts', 5 => '11th in hts', 6 => null],
                2 => [1 => '9th in hts',  2 => '5th in hts', 3 => '6th in hts', 4 => '10th in hts', 5 => null,         6 => null],
            ],
        ],
        'grand_final_lane_seeding' => [
            1 => '2nd in reps', 2 => '3rd in hts', 3 => '1st in hts', 4 => '2nd in hts', 5 => '1st in reps', 6 => '3rd in reps',
        ],
        'source_orderings' => ['hts' => 1, 'reps' => 3],
        'advancement' => [
            'Winner of each heat plus next fastest overall in both heats → Grand Final (3 crews).',
            'Remainder → repechage (4 to 12).',
            '9 crews: 1st, 2nd and 3rd in single rep → Grand Final.',
            '10-12 crews: 1st in each rep plus next fastest overall → Grand Final.',
            'Remainder drop out (7 to 12).',
        ],
    ],

    'RP.3A' => [
        'lane_count' => 6,
        'crew_count_range' => [13, 18],
        'stages' => ['Heat 1', 'Heat 2', 'Heat 3', 'Repechage 1', 'Repechage 2', 'Semi 1', 'Semi 2', 'Minor Final', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 13, 2 => 7, 3 => 1, 4 => 6,  5 => 12, 6 => 18],
            2 => [1 => 14, 2 => 8, 3 => 2, 4 => 5,  5 => 11, 6 => 17],
            3 => [1 => 15, 2 => 9, 3 => 3, 4 => 4,  5 => 10, 6 => 16],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => '15th in hts', 2 => '11th in hts', 3 => '7th in hts', 4 => '10th in hts', 5 => '14th in hts', 6 => '18th in hts'],
            2 => [1 => '16th in hts', 2 => '12th in hts', 3 => '8th in hts', 4 => '9th in hts',  5 => '13th in hts', 6 => '17th in hts'],
        ],
        'semi_lane_seeding' => [
            1 => [1 => '3rd in reps', 2 => '5th in hts', 3 => '1st in hts', 4 => '4th in hts', 5 => '2nd in reps', 6 => '6th in reps'],
            2 => [1 => '4th in reps', 2 => '6th in hts', 3 => '2nd in hts', 4 => '3rd in hts', 5 => '1st in reps', 6 => '5th in reps'],
        ],
        'minor_final_lane_seeding' => [
            1 => '11th in SF', 2 => '9th in SF', 3 => '7th in SF', 4 => '8th in SF', 5 => '10th in SF', 6 => '12th in SF',
        ],
        'grand_final_lane_seeding' => [
            1 => '5th in SF', 2 => '3rd in SF', 3 => '1st in SF', 4 => '2nd in SF', 5 => '4th in SF', 6 => '6th in SF',
        ],
        'source_orderings' => ['hts' => 2, 'reps' => 2, 'sf' => 2],
        'advancement' => [
            '1st and 2nd in each heat → semi-finals (6 crews).',
            'Remainder → repechages (13 to 18).',
            '13 crews: 1st & 2nd in each rep → semi-finals (3 crews drop out).',
            '14 crews: 1st & 2nd in each rep plus next fastest → semi-finals (4 crews drop out).',
            '15-18 crews: 1st & 2nd in each rep plus next 2 fastest → semi-finals (3 to 6 crews drop out).',
            '1st & 2nd in each semi plus next 2 fastest → Grand Final (6 crews).',
            'Crews placed 7 to 12 in semis → Minor Final.',
        ],
    ],

    'RP.4A' => [
        'lane_count' => 6,
        'crew_count_range' => [19, 24],
        'stages' => ['Heat 1', 'Heat 2', 'Heat 3', 'Heat 4', 'Repechage 1', 'Repechage 2', 'Semi 1', 'Semi 2', 'Semi 3', 'Minor Final', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 17, 2 => 9,  3 => 1, 4 => 8, 5 => 16, 6 => 24],
            2 => [1 => 18, 2 => 10, 3 => 2, 4 => 7, 5 => 15, 6 => 23],
            3 => [1 => 19, 2 => 11, 3 => 3, 4 => 6, 5 => 14, 6 => 22],
            4 => [1 => 20, 2 => 12, 3 => 4, 4 => 5, 5 => 13, 6 => 21],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => '21st in hts', 2 => '17th in hts', 3 => '13th in hts', 4 => '16th in hts', 5 => '20th in hts', 6 => '24th in hts'],
            2 => [1 => '22nd in hts', 2 => '18th in hts', 3 => '14th in hts', 4 => '15th in hts', 5 => '19th in hts', 6 => '23rd in hts'],
        ],
        'semi_lane_seeding' => [
            1 => [1 => '1st in reps', 2 => '7th in hts',  3 => '1st in hts', 4 => '6th in hts',  5 => '12th in hts', 6 => '6th in reps'],
            2 => [1 => '2nd in reps', 2 => '8th in hts',  3 => '2nd in hts', 4 => '5th in hts',  5 => '11th in hts', 6 => '5th in reps'],
            3 => [1 => '3rd in reps', 2 => '9th in hts',  3 => '3rd in hts', 4 => '4th in hts',  5 => '10th in hts', 6 => '4th in reps'],
        ],
        'minor_final_lane_seeding' => [
            1 => '11th in SF', 2 => '9th in SF', 3 => '7th in SF', 4 => '8th in SF', 5 => '10th in SF', 6 => '12th in SF',
        ],
        'grand_final_lane_seeding' => [
            1 => '5th in SF', 2 => '3rd in SF', 3 => '1st in SF', 4 => '2nd in SF', 5 => '4th in SF', 6 => '6th in SF',
        ],
        'source_orderings' => ['hts' => 3, 'reps' => 3, 'sf' => 1],
        'advancement' => [
            '1st, 2nd and 3rd in each heat → semi-finals (12 crews).',
            'Remainder → repechages (13 to 24).',
            '19-20 crews: 1st & 2nd in each rep → semi-finals (3 drop).',
            '21 crews: 1st & 2nd in each rep plus next fastest → semi-finals (4 drop).',
            '22-24 crews: 1st, 2nd and 3rd in each rep → semi-finals (remainder drop).',
            '1st in each semi plus next 3 fastest → Grand Final (6 crews).',
            'Next 6 fastest → Minor Final.',
        ],
    ],

    'RP.5A' => [
        'lane_count' => 6,
        'crew_count_range' => [25, 30],
        'stages' => ['Heat 1', 'Heat 2', 'Heat 3', 'Heat 4', 'Heat 5', 'Repechage 1', 'Repechage 2', 'Repechage 3', 'Semi 1', 'Semi 2', 'Semi 3', 'Minor Final', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 21, 2 => 11, 3 => 1, 4 => 10, 5 => 20, 6 => 30],
            2 => [1 => 22, 2 => 12, 3 => 2, 4 => 9,  5 => 19, 6 => 29],
            3 => [1 => 23, 2 => 13, 3 => 3, 4 => 8,  5 => 18, 6 => 28],
            4 => [1 => 24, 2 => 14, 3 => 4, 4 => 7,  5 => 17, 6 => 27],
            5 => [1 => 25, 2 => 15, 3 => 5, 4 => 6,  5 => 16, 6 => 26],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => '26th in hts', 2 => '20th in hts', 3 => '14th in hts', 4 => '19th in hts', 5 => '25th in hts', 6 => null],
            2 => [1 => '27th in hts', 2 => '21st in hts', 3 => '15th in hts', 4 => '18th in hts', 5 => '24th in hts', 6 => '30th in hts'],
            3 => [1 => '28th in hts', 2 => '22nd in hts', 3 => '16th in hts', 4 => '17th in hts', 5 => '23rd in hts', 6 => '29th in hts'],
        ],
        'semi_lane_seeding' => [
            1 => [1 => '13th in hts', 2 => '7th in hts', 3 => '1st in hts', 4 => '6th in hts', 5 => '12th in hts', 6 => '5th in reps'],
            2 => [1 => '1st in reps', 2 => '8th in hts', 3 => '2nd in hts', 4 => '5th in hts', 5 => '11th in hts', 6 => '4th in reps'],
            3 => [1 => '2nd in reps', 2 => '9th in hts', 3 => '3rd in hts', 4 => '4th in hts', 5 => '10th in hts', 6 => '3rd in reps'],
        ],
        'minor_final_lane_seeding' => [
            1 => '11th in SF', 2 => '9th in SF', 3 => '7th in SF', 4 => '8th in SF', 5 => '10th in SF', 6 => '12th in SF',
        ],
        'grand_final_lane_seeding' => [
            1 => '5th in SF', 2 => '3rd in SF', 3 => '1st in SF', 4 => '2nd in SF', 5 => '4th in SF', 6 => '6th in SF',
        ],
        'source_orderings' => ['hts' => 2, 'reps' => 1, 'sf' => 1],
        'advancement' => [
            '1st and 2nd in each heat plus next 3 fastest overall → semi-finals (13 crews).',
            'Remainder → repechages (14 to 30).',
            '1st in each repechage plus next 2 fastest overall → semi-finals (5 crews).',
            'Remainder drop out (19 to 30).',
            '1st in each semi plus next 3 fastest → Grand Final (6 crews).',
            'Next 6 fastest → Minor Final.',
        ],
    ],

    'RP.6A' => [
        'lane_count' => 6,
        'crew_count_range' => [31, 36],
        'stages' => ['Heat 1', 'Heat 2', 'Heat 3', 'Heat 4', 'Heat 5', 'Heat 6', 'Repechage 1', 'Repechage 2', 'Repechage 3', 'Semi 1', 'Semi 2', 'Semi 3', 'Semi 4', 'Minor Final', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 25, 2 => 13, 3 => 1, 4 => 12, 5 => 24, 6 => 36],
            2 => [1 => 26, 2 => 14, 3 => 2, 4 => 11, 5 => 23, 6 => 35],
            3 => [1 => 27, 2 => 15, 3 => 3, 4 => 10, 5 => 22, 6 => 34],
            4 => [1 => 28, 2 => 16, 3 => 4, 4 => 9,  5 => 21, 6 => 33],
            5 => [1 => 29, 2 => 17, 3 => 5, 4 => 8,  5 => 20, 6 => 32],
            6 => [1 => 30, 2 => 18, 3 => 6, 4 => 7,  5 => 19, 6 => 31],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => '31st in hts', 2 => '25th in hts', 3 => '19th in hts', 4 => '24th in hts', 5 => '30th in hts', 6 => '36th in hts'],
            2 => [1 => '32nd in hts', 2 => '26th in hts', 3 => '20th in hts', 4 => '23rd in hts', 5 => '29th in hts', 6 => '35th in hts'],
            3 => [1 => '33rd in hts', 2 => '27th in hts', 3 => '21st in hts', 4 => '22nd in hts', 5 => '28th in hts', 6 => '34th in hts'],
        ],
        'semi_lane_seeding' => [
            1 => [1 => '17th in hts', 2 => '9th in hts',  3 => '1st in hts', 4 => '8th in hts', 5 => '16th in hts', 6 => '6th in reps'],
            2 => [1 => '18th in hts', 2 => '10th in hts', 3 => '2nd in hts', 4 => '7th in hts', 5 => '15th in hts', 6 => '5th in reps'],
            3 => [1 => '1st in reps',  2 => '11th in hts', 3 => '3rd in hts', 4 => '6th in hts', 5 => '14th in hts', 6 => '4th in reps'],
            4 => [1 => '2nd in reps',  2 => '12th in hts', 3 => '4th in hts', 4 => '5th in hts', 5 => '13th in hts', 6 => '3rd in reps'],
        ],
        'minor_final_lane_seeding' => [
            1 => '11th in SF', 2 => '9th in SF', 3 => '7th in SF', 4 => '8th in SF', 5 => '10th in SF', 6 => '12th in SF',
        ],
        'grand_final_lane_seeding' => [
            1 => '5th in SF', 2 => '3rd in SF', 3 => '1st in SF', 4 => '2nd in SF', 5 => '4th in SF', 6 => '6th in SF',
        ],
        'source_orderings' => ['hts' => 3, 'reps' => 1, 'sf' => 1],
        'advancement' => [
            '1st, 2nd and 3rd in each heat → semi-finals (18 crews).',
            'Remainder → repechages (19 to 36).',
            '1st in each repechage plus next 3 fastest overall → semi-finals (6 crews).',
            'Remainder drop out (24 to 36).',
            '1st in each semi plus next 2 fastest → Grand Final (6 crews).',
            'Next 6 fastest → Minor Final.',
        ],
    ],

    'RP.7A' => [
        'lane_count' => 6,
        'crew_count_range' => [37, 42],
        'stages' => ['Heat 1', 'Heat 2', 'Heat 3', 'Heat 4', 'Heat 5', 'Heat 6', 'Heat 7', 'Repechage 1', 'Repechage 2', 'Repechage 3', 'Repechage 4', 'Semi 1', 'Semi 2', 'Semi 3', 'Semi 4', 'Semi 5', 'Tail Final', 'Minor Final', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 29, 2 => 15, 3 => 1, 4 => 14, 5 => 28, 6 => 42],
            2 => [1 => 30, 2 => 16, 3 => 2, 4 => 13, 5 => 27, 6 => 41],
            3 => [1 => 31, 2 => 17, 3 => 3, 4 => 12, 5 => 26, 6 => 40],
            4 => [1 => 32, 2 => 18, 3 => 4, 4 => 11, 5 => 25, 6 => 39],
            5 => [1 => 33, 2 => 19, 3 => 5, 4 => 10, 5 => 24, 6 => 38],
            6 => [1 => 34, 2 => 20, 3 => 6, 4 => 9,  5 => 23, 6 => 37],
            7 => [1 => 35, 2 => 21, 3 => 7, 4 => 8,  5 => 22, 6 => 36],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => '38th in hts', 2 => '30th in hts', 3 => '22nd in hts', 4 => '29th in hts', 5 => '37th in hts', 6 => null],
            2 => [1 => '39th in hts', 2 => '31st in hts', 3 => '23rd in hts', 4 => '28th in hts', 5 => '36th in hts', 6 => null],
            3 => [1 => '40th in hts', 2 => '32nd in hts', 3 => '24th in hts', 4 => '27th in hts', 5 => '35th in hts', 6 => null],
            4 => [1 => '41st in hts', 2 => '33rd in hts', 3 => '25th in hts', 4 => '26th in hts', 5 => '34th in hts', 6 => '42nd in hts'],
        ],
        'semi_lane_seeding' => [
            1 => [1 => '21st in hts', 2 => '11th in hts', 3 => '1st in hts', 4 => '10th in hts', 5 => '20th in hts', 6 => '9th in reps'],
            2 => [1 => '1st in reps',  2 => '12th in hts', 3 => '2nd in hts', 4 => '9th in hts',  5 => '19th in hts', 6 => '8th in reps'],
            3 => [1 => '2nd in reps',  2 => '13th in hts', 3 => '3rd in hts', 4 => '8th in hts',  5 => '18th in hts', 6 => '7th in reps'],
            4 => [1 => '3rd in reps',  2 => '14th in hts', 3 => '4th in hts', 4 => '7th in hts',  5 => '17th in hts', 6 => '6th in reps'],
            5 => [1 => '4th in reps',  2 => '15th in hts', 3 => '5th in hts', 4 => '6th in hts',  5 => '16th in hts', 6 => '5th in reps'],
        ],
        'tail_final_lane_seeding' => [
            1 => '17th in SF', 2 => '15th in SF', 3 => '13th in SF', 4 => '14th in SF', 5 => '16th in SF', 6 => '18th in SF',
        ],
        'minor_final_lane_seeding' => [
            1 => '11th in SF', 2 => '9th in SF', 3 => '7th in SF', 4 => '8th in SF', 5 => '10th in SF', 6 => '12th in SF',
        ],
        'grand_final_lane_seeding' => [
            1 => '5th in SF', 2 => '3rd in SF', 3 => '1st in SF', 4 => '2nd in SF', 5 => '4th in SF', 6 => '6th in SF',
        ],
        'source_orderings' => ['hts' => 3, 'reps' => 3, 'sf' => 1],
        'advancement' => [
            '1st, 2nd and 3rd in each heat → semi-finals (21 crews).',
            'Remainder → repechages (22 to 42).',
            '1st and 2nd plus fastest overall in each repechage → semi-finals (9 crews).',
            'Remainder drop out (34 to 42).',
            '1st in each semi plus next fastest → Grand Final (6 crews).',
            'Next 6 fastest → Minor Final.',
            'Next 6 fastest → Tail Final.',
        ],
    ],

    // -------------------------------------------------------------------
    // 8-LANE PLANS
    // -------------------------------------------------------------------

    'RP.1B' => [
        'lane_count' => 8,
        'crew_count_range' => [7, 8],
        'stages' => ['Round 1', 'Round 2', 'Round 3'],
        'round_lane_seeding' => [
            1 => [1 => 7, 2 => 5, 3 => 3, 4 => 1, 5 => 2, 6 => 4, 7 => 6, 8 => 8],
            2 => [1 => 3, 2 => 2, 3 => 7, 4 => 6, 5 => 5, 6 => 8, 7 => 1, 8 => 4],
            3 => [1 => 6, 2 => 4, 3 => 2, 4 => 8, 5 => 7, 6 => 1, 7 => 3, 8 => 5],
        ],
        'advancement' => [
            'If time permits use 3 rounds (sum all 3 times); otherwise 2 rounds (sum both).',
        ],
    ],

    'RP.2B' => [
        'lane_count' => 8,
        'crew_count_range' => [9, 12],
        'stages' => ['Heat 1', 'Heat 2', 'Repechage 1', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 13, 2 => 9,  3 => 5, 4 => 1, 5 => 4, 6 => 8,  7 => 12, 8 => null],
            2 => [1 => 14, 2 => 10, 3 => 6, 4 => 2, 5 => 3, 6 => 7,  7 => 11, 8 => null],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => 11, 2 => 9, 3 => 7, 4 => 5, 5 => 6, 6 => 8, 7 => 10, 8 => 12],
        ],
        'grand_final_lane_seeding' => [
            1 => '3rd in reps', 2 => '1st in reps', 3 => '3rd in hts', 4 => '1st in hts', 5 => '2nd in hts', 6 => '4th in hts', 7 => '2nd in reps', 8 => '4th in reps',
        ],
        'source_orderings' => ['hts' => 2, 'reps' => 4],
        'advancement' => [
            '1st and 2nd in each heat → Grand Final (4 crews).',
            'Remainder → repechage (5 to 12).',
            '1st, 2nd, 3rd and 4th in repechage → Grand Final (4 crews).',
            'Remainder drop out (5 to 12).',
        ],
    ],

    'RP.3B' => [
        'lane_count' => 8,
        'crew_count_range' => [13, 16],
        'stages' => ['Heat 1', 'Heat 2', 'Repechage 1', 'Repechage 2', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 13, 2 => 9,  3 => 5, 4 => 1, 5 => 4, 6 => 8, 7 => 12, 8 => 16],
            2 => [1 => 14, 2 => 10, 3 => 6, 4 => 2, 5 => 3, 6 => 7, 7 => 11, 8 => 15],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => null,         2 => '13th in hts', 3 => '9th in hts',  4 => '5th in hts', 5 => '8th in hts',  6 => '12th in hts', 7 => '16th in hts', 8 => null],
            2 => [1 => null,         2 => '14th in hts', 3 => '10th in hts', 4 => '6th in hts', 5 => '7th in hts',  6 => '11th in hts', 7 => '15th in hts', 8 => null],
        ],
        'grand_final_lane_seeding' => [
            1 => '3rd in reps', 2 => '1st in reps', 3 => '3rd in hts', 4 => '1st in hts', 5 => '2nd in hts', 6 => '4th in hts', 7 => '2nd in reps', 8 => '4th in reps',
        ],
        'source_orderings' => ['hts' => 2, 'reps' => 2],
        'advancement' => [
            '1st and 2nd in each heat → Grand Final (4 crews).',
            'Remainder → repechage (5 to 16).',
            '1st and 2nd in each repechage → Grand Final (4 crews).',
            'Remainder drop out (12 to 16).',
        ],
    ],

    'RP.4B' => [
        'lane_count' => 8,
        'crew_count_range' => [17, 24],
        'stages' => ['Heat 1', 'Heat 2', 'Heat 3', 'Repechage 1', 'Repechage 2', 'Semi 1', 'Semi 2', 'Minor Final', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 19, 2 => 13, 3 => 7, 4 => 1, 5 => 6, 6 => 12, 7 => 18, 8 => 24],
            2 => [1 => 20, 2 => 14, 3 => 8, 4 => 2, 5 => 5, 6 => 11, 7 => 17, 8 => 23],
            3 => [1 => 21, 2 => 15, 3 => 9, 4 => 3, 5 => 4, 6 => 10, 7 => 16, 8 => 22],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => 21, 2 => 17, 3 => 13, 4 => 9,  5 => 12, 6 => 16, 7 => 20, 8 => 24],
            2 => [1 => 22, 2 => 18, 3 => 14, 4 => 10, 5 => 11, 6 => 15, 7 => 19, 8 => 23],
        ],
        'semi_lane_seeding' => [
            1 => [1 => '5th in rps', 2 => '1st in rps', 3 => '5th in hts', 4 => '1st in hts', 5 => '4th in hts', 6 => '8th in hts', 7 => '4th in rps', 8 => '8th in rps'],
            2 => [1 => '6th in rps', 2 => '2nd in rps', 3 => '6th in hts', 4 => '2nd in hts', 5 => '3rd in hts', 6 => '7th in hts', 7 => '3rd in rps', 8 => '7th in rps'],
        ],
        'minor_final_lane_seeding' => null,
        'grand_final_lane_seeding' => [
            1 => '7th in SF', 2 => '5th in SF', 3 => '3rd in SF', 4 => '1st in SF', 5 => '2nd in SF', 6 => '4th in SF', 7 => '6th in SF', 8 => '8th in SF',
        ],
        'source_orderings' => ['hts' => 2, 'reps' => 3, 'sf' => 3],
        'advancement' => [
            '1st and 2nd in each heat plus next 2 fastest overall → semi-finals (8 crews).',
            'Remainder → repechage (9 to 24).',
            '1st, 2nd and 3rd in each repechage plus next 2 fastest overall → semi-finals (8 crews).',
            'Remainder drop out (17 to 24).',
            '1st, 2nd and 3rd in each semi plus next 2 fastest overall → Grand Final (8 crews).',
            'Remainder drop out (9 to 16).',
        ],
    ],

    'RP.5B' => [
        'lane_count' => 8,
        'crew_count_range' => [25, 32],
        'stages' => ['Heat 1', 'Heat 2', 'Heat 3', 'Heat 4', 'Repechage 1', 'Repechage 2', 'Semi 1', 'Semi 2', 'Semi 3', 'Minor Final', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 25, 2 => 17, 3 => 9,  4 => 1, 5 => 8, 6 => 16, 7 => 24, 8 => 32],
            2 => [1 => 26, 2 => 18, 3 => 10, 4 => 2, 5 => 7, 6 => 15, 7 => 23, 8 => 31],
            3 => [1 => 27, 2 => 19, 3 => 11, 4 => 3, 5 => 6, 6 => 14, 7 => 22, 8 => 30],
            4 => [1 => 28, 2 => 20, 3 => 12, 4 => 4, 5 => 5, 6 => 13, 7 => 21, 8 => 29],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => 29, 2 => 25, 3 => 21, 4 => 17, 5 => 20, 6 => 24, 7 => 28, 8 => 32],
            2 => [1 => 30, 2 => 26, 3 => 22, 4 => 18, 5 => 19, 6 => 23, 7 => 27, 8 => 31],
        ],
        'semi_lane_seeding' => [
            1 => [1 => '3rd in rps', 2 => '13th in hts', 3 => '7th in hts', 4 => '1st in hts', 5 => '6th in hts',  6 => '12th in hts', 7 => '2nd in rps', 8 => '8th in rps'],
            2 => [1 => '4th in rps', 2 => '14th in hts', 3 => '8th in hts', 4 => '2nd in hts', 5 => '5th in hts',  6 => '11th in hts', 7 => '1st in rps', 8 => '7th in rps'],
            3 => [1 => '5th in rps', 2 => '15th in hts', 3 => '9th in hts', 4 => '3rd in hts', 5 => '4th in hts',  6 => '10th in hts', 7 => '16th in hts', 8 => '6th in rps'],
        ],
        'minor_final_lane_seeding' => [
            1 => '15th in SF', 2 => '13th in SF', 3 => '11th in SF', 4 => '9th in SF', 5 => '10th in SF', 6 => '12th in SF', 7 => '14th in SF', 8 => '16th in SF',
        ],
        'grand_final_lane_seeding' => [
            1 => '7th in SF', 2 => '5th in SF', 3 => '3rd in SF', 4 => '1st in SF', 5 => '2nd in SF', 6 => '4th in SF', 7 => '6th in SF', 8 => '8th in SF',
        ],
        'source_orderings' => ['hts' => 4, 'reps' => 3, 'sf' => 2],
        'advancement' => [
            '1st, 2nd, 3rd and 4th in each heat → semi-finals (16 crews).',
            'Remainder → repechage (17 to 32).',
            '1st, 2nd and 3rd in each repechage plus next 2 fastest overall → semi-finals (8 crews).',
            'Remainder drop out (25 to 32).',
            '1st and 2nd in each semi plus next 2 fastest overall → Grand Final (8 crews).',
            'Next 8 fastest → Minor Final.',
        ],
    ],

    'RP.6B' => [
        'lane_count' => 8,
        'crew_count_range' => [33, 40],
        'stages' => ['Heat 1', 'Heat 2', 'Heat 3', 'Heat 4', 'Heat 5', 'Repechage 1', 'Repechage 2', 'Repechage 3', 'Semi 1', 'Semi 2', 'Semi 3', 'Minor Final', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 31, 2 => 21, 3 => 11, 4 => 1, 5 => 10, 6 => 20, 7 => 30, 8 => 40],
            2 => [1 => 32, 2 => 22, 3 => 12, 4 => 2, 5 => 9,  6 => 19, 7 => 29, 8 => 39],
            3 => [1 => 33, 2 => 23, 3 => 13, 4 => 3, 5 => 8,  6 => 18, 7 => 28, 8 => 38],
            4 => [1 => 34, 2 => 24, 3 => 14, 4 => 4, 5 => 7,  6 => 17, 7 => 27, 8 => 37],
            5 => [1 => 35, 2 => 25, 3 => 15, 4 => 5, 5 => 6,  6 => 16, 7 => 26, 8 => 36],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => 35, 2 => 29, 3 => 23, 4 => 17, 5 => 22, 6 => 28, 7 => 34, 8 => 40],
            2 => [1 => 36, 2 => 30, 3 => 24, 4 => 18, 5 => 21, 6 => 27, 7 => 33, 8 => 39],
            3 => [1 => 37, 2 => 31, 3 => 25, 4 => 19, 5 => 20, 6 => 26, 7 => 32, 8 => 38],
        ],
        'semi_lane_seeding' => [
            1 => [1 => '3rd in rsp', 2 => '13th in hts', 3 => '7th in hts', 4 => '1st in hts', 5 => '6th in hts',  6 => '12th in hts', 7 => '2nd in rps', 8 => '8th in rps'],
            2 => [1 => '4th in rps', 2 => '14th in hts', 3 => '8th in hts', 4 => '2nd in hts', 5 => '5th in hts',  6 => '11th in hts', 7 => '1st in rps', 8 => '7th in rps'],
            3 => [1 => '5th in rps', 2 => '15th in hts', 3 => '9th in hts', 4 => '3rd in hts', 5 => '4th in hts',  6 => '10th in hts', 7 => '16th in hts', 8 => '6th in rsp'],
        ],
        'minor_final_lane_seeding' => [
            1 => '15th in SF', 2 => '13th in SF', 3 => '11th in SF', 4 => '9th in SF', 5 => '10th in SF', 6 => '12th in SF', 7 => '14th in SF', 8 => '16th in SF',
        ],
        'grand_final_lane_seeding' => [
            1 => '7th in SF', 2 => '5th in SF', 3 => '3rd in SF', 4 => '1st in SF', 5 => '2nd in SF', 6 => '4th in SF', 7 => '6th in SF', 8 => '8th in SF',
        ],
        'source_orderings' => ['hts' => 3, 'reps' => 2, 'sf' => 2],
        'advancement' => [
            '1st, 2nd and 3rd in each heat plus next fastest overall → semi-finals (16 crews).',
            'Remainder → repechage (17 to 40).',
            '1st and 2nd in each repechage plus next 2 fastest → semi-finals (8 crews).',
            'Remainder drop out (25 to 40).',
            '1st and 2nd in each semi plus next 2 fastest → Grand Final (8 crews).',
            'Next 8 fastest → Minor Final.',
        ],
    ],

    'RP.7B' => [
        'lane_count' => 8,
        'crew_count_range' => [41, 48],
        'stages' => ['Heat 1', 'Heat 2', 'Heat 3', 'Heat 4', 'Heat 5', 'Heat 6', 'Repechage 1', 'Repechage 2', 'Repechage 3', 'Repechage 4', 'Semi 1', 'Semi 2', 'Semi 3', 'Semi 4', 'Tail Final', 'Minor Final', 'Grand Final'],
        'heat_lane_seeding' => [
            1 => [1 => 37, 2 => 25, 3 => 13, 4 => 1, 5 => 12, 6 => 24, 7 => 36, 8 => 48],
            2 => [1 => 38, 2 => 26, 3 => 14, 4 => 2, 5 => 11, 6 => 23, 7 => 35, 8 => 47],
            3 => [1 => 39, 2 => 27, 3 => 15, 4 => 3, 5 => 10, 6 => 22, 7 => 34, 8 => 46],
            4 => [1 => 40, 2 => 28, 3 => 16, 4 => 4, 5 => 9,  6 => 21, 7 => 33, 8 => 45],
            5 => [1 => 41, 2 => 29, 3 => 17, 4 => 5, 5 => 8,  6 => 20, 7 => 32, 8 => 44],
            6 => [1 => 42, 2 => 30, 3 => 18, 4 => 6, 5 => 7,  6 => 19, 7 => 31, 8 => 43],
        ],
        'repechage_lane_seeding' => [
            1 => [1 => 47, 2 => 39, 3 => 31, 4 => 23, 5 => 30, 6 => 38, 7 => 46, 8 => null],
            2 => [1 => 48, 2 => 40, 3 => 32, 4 => 24, 5 => 29, 6 => 37, 7 => 45, 8 => null],
            3 => [1 => null, 2 => 41, 3 => 33, 4 => 25, 5 => 28, 6 => 36, 7 => 44, 8 => null],
            4 => [1 => null, 2 => 42, 3 => 34, 4 => 26, 5 => 27, 6 => 35, 7 => 43, 8 => null],
        ],
        'semi_lane_seeding' => [
            1 => [1 => '3rd in rps', 2 => '17th in hts', 3 => '9th in hts',  4 => '1st in hts', 5 => '8th in hts',  6 => '16th in hts', 7 => '2nd in rps', 8 => '10th in rsp'],
            2 => [1 => '4th in rps', 2 => '18th in hts', 3 => '10th in hts', 4 => '2nd in hts', 5 => '7th in hts',  6 => '15th in hts', 7 => '1st in rps', 8 => '9th in rps'],
            3 => [1 => '5th in rps', 2 => '19th in hts', 3 => '11th in hts', 4 => '3rd in hts', 5 => '6th in hts',  6 => '14th in hts', 7 => '22nd in hts', 8 => '8th in rps'],
            4 => [1 => '6th in rsp', 2 => '20th in hts', 3 => '12th in hts', 4 => '4th in hts', 5 => '5th in hts',  6 => '13th in hts', 7 => '21st in hts', 8 => '7th in rps'],
        ],
        'tail_final_lane_seeding' => [
            1 => '23rd in SF', 2 => '21st in SF', 3 => '19th in SF', 4 => '17th in SF', 5 => '18th in SF', 6 => '20th in SF', 7 => '22nd in SF', 8 => '24th in SF',
        ],
        'minor_final_lane_seeding' => [
            1 => '15th in SF', 2 => '13th in SF', 3 => '11th in SF', 4 => '9th in SF', 5 => '10th in SF', 6 => '12th in SF', 7 => '14th in SF', 8 => '16th in SF',
        ],
        'grand_final_lane_seeding' => [
            1 => '7th in SF', 2 => '5th in SF', 3 => '3rd in SF', 4 => '1st in SF', 5 => '2nd in SF', 6 => '4th in SF', 7 => '6th in SF', 8 => '8th in SF',
        ],
        'source_orderings' => ['hts' => 3, 'reps' => 2, 'sf' => 1],
        'advancement' => [
            '1st, 2nd and 3rd in each heat plus next 4 fastest overall → semi-finals (22 crews).',
            'Remainder → repechage (23 to 48).',
            '1st and 2nd in each repechage plus next 2 fastest → semi-finals (10 crews).',
            'Remainder drop out (33 to 48).',
            '1st in each semi plus next 4 fastest → Grand Final (8 crews).',
            'Next 8 fastest → Minor Final.',
            'Next 8 fastest → Tail Final.',
        ],
    ],

];
