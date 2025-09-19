<?php

/**
 * Multi-Round Race Results Demo
 *
 * This script demonstrates how the multi-round functionality works in the EuroCup platform.
 * It shows example API responses for different scenarios involving multiple rounds.
 */

// Example 1: Single Round Race (Traditional Finals)
echo "=== EXAMPLE 1: Single Round Race ===\n";
echo "GET /api/race-results/123\n\n";

$singleRoundResponse = [
    'success' => true,
    'data' => [
        'id' => 123,
        'race_number' => 5,
        'stage' => 'Finals',
        'status' => 'FINISHED',
        'is_final_round' => true, // Single round is always final
        'discipline' => [
            'id' => 1,
            'distance' => 500,
            'age_group' => 'Senior',
            'gender_group' => 'M',
            'boat_group' => 'K1',
            'display_name' => "Men's K1 500m"
        ],
        'crew_results' => [
            [
                'id' => 301,
                'crew_id' => 101,
                'lane' => 1,
                'position' => 1,
                'time_ms' => 125000,
                'formatted_time' => '02:05.000',
                'status' => 'FINISHED',
                'final_time_ms' => null, // No final time needed for single round
                'final_position' => null,
                'formatted_final_time' => null,
                'final_status' => null,
                'is_final_round' => true,
                'crew' => [
                    'id' => 101,
                    'team' => ['id' => 201, 'name' => 'Team Austria']
                ]
            ],
            [
                'id' => 302,
                'crew_id' => 102,
                'lane' => 2,
                'position' => 2,
                'time_ms' => 127500,
                'formatted_time' => '02:07.500',
                'status' => 'FINISHED',
                'final_time_ms' => null,
                'final_position' => null,
                'formatted_final_time' => null,
                'final_status' => null,
                'is_final_round' => true,
                'crew' => [
                    'id' => 102,
                    'team' => ['id' => 202, 'name' => 'Team Germany']
                ]
            ]
        ]
    ],
    'message' => 'Race result retrieved successfully'
];

echo json_encode($singleRoundResponse, JSON_PRETTY_PRINT) . "\n\n";

// Example 2: Multi-Round Race - Non-Final Round
echo "=== EXAMPLE 2: Multi-Round Race - Round 1 (Non-Final) ===\n";
echo "GET /api/race-results/124\n\n";

$round1Response = [
    'success' => true,
    'data' => [
        'id' => 124,
        'race_number' => 6,
        'stage' => 'Round 1',
        'status' => 'FINISHED',
        'is_final_round' => false, // Not the final round
        'discipline' => [
            'id' => 2,
            'distance' => 500,
            'age_group' => 'Senior',
            'gender_group' => 'W',
            'boat_group' => 'K1',
            'display_name' => "Women's K1 500m"
        ],
        'crew_results' => [
            [
                'id' => 401,
                'crew_id' => 201,
                'lane' => 1,
                'position' => 1,
                'time_ms' => 130000,
                'formatted_time' => '02:10.000',
                'status' => 'FINISHED',
                'final_time_ms' => null, // No final times for non-final rounds
                'final_position' => null,
                'formatted_final_time' => null,
                'final_status' => null,
                'is_final_round' => false,
                'crew' => [
                    'id' => 201,
                    'team' => ['id' => 301, 'name' => 'Team Hungary']
                ]
            ],
            [
                'id' => 402,
                'crew_id' => 202,
                'lane' => 2,
                'position' => 2,
                'time_ms' => 132000,
                'formatted_time' => '02:12.000',
                'status' => 'FINISHED',
                'final_time_ms' => null,
                'final_position' => null,
                'formatted_final_time' => null,
                'final_status' => null,
                'is_final_round' => false,
                'crew' => [
                    'id' => 202,
                    'team' => ['id' => 302, 'name' => 'Team Poland']
                ]
            ]
        ]
    ],
    'message' => 'Race result retrieved successfully'
];

echo json_encode($round1Response, JSON_PRETTY_PRINT) . "\n\n";

// Example 3: Multi-Round Race - Final Round with Accumulated Times
echo "=== EXAMPLE 3: Multi-Round Race - Round 3 (Final Round) ===\n";
echo "GET /api/race-results/126\n\n";

$round3Response = [
    'success' => true,
    'data' => [
        'id' => 126,
        'race_number' => 8,
        'stage' => 'Round 3',
        'status' => 'FINISHED',
        'is_final_round' => true, // This is the final round
        'discipline' => [
            'id' => 2,
            'distance' => 500,
            'age_group' => 'Senior',
            'gender_group' => 'W',
            'boat_group' => 'K1',
            'display_name' => "Women's K1 500m"
        ],
        'crew_results' => [
            [
                'id' => 601,
                'crew_id' => 202, // Team Poland
                'lane' => 1,
                'position' => 1, // Current round position
                'time_ms' => 128000, // Current round time: 2:08.000
                'formatted_time' => '02:08.000',
                'status' => 'FINISHED',
                // Final accumulated data:
                'final_time_ms' => 390000, // Sum of all 3 rounds: 6:30.000
                'final_position' => 1, // Best overall position based on total time
                'formatted_final_time' => '06:30.000',
                'final_status' => 'FINISHED',
                'is_final_round' => true,
                'crew' => [
                    'id' => 202,
                    'team' => ['id' => 302, 'name' => 'Team Poland']
                ]
            ],
            [
                'id' => 602,
                'crew_id' => 201, // Team Hungary
                'lane' => 2,
                'position' => 2, // Current round position
                'time_ms' => 129000, // Current round time: 2:09.000
                'formatted_time' => '02:09.000',
                'status' => 'FINISHED',
                // Final accumulated data:
                'final_time_ms' => 391000, // Sum of all 3 rounds: 6:31.000
                'final_position' => 2, // Second overall position
                'formatted_final_time' => '06:31.000',
                'final_status' => 'FINISHED',
                'is_final_round' => true,
                'crew' => [
                    'id' => 201,
                    'team' => ['id' => 301, 'name' => 'Team Hungary']
                ]
            ],
            [
                'id' => 603,
                'crew_id' => 203, // Team Czech
                'lane' => 3,
                'position' => null, // No position due to DSQ
                'time_ms' => null,
                'formatted_time' => null,
                'status' => 'DSQ', // Disqualified in this round
                // Final accumulated data:
                'final_time_ms' => null, // DSQ overrides any accumulated time
                'final_position' => 'DSQ',
                'formatted_final_time' => null,
                'final_status' => 'DSQ', // DSQ in any round = overall DSQ
                'is_final_round' => true,
                'crew' => [
                    'id' => 203,
                    'team' => ['id' => 303, 'name' => 'Team Czech']
                ]
            ]
        ]
    ],
    'message' => 'Race result retrieved successfully'
];

echo json_encode($round3Response, JSON_PRETTY_PRINT) . "\n\n";

// Example 4: Crew Results Endpoint for Final Round
echo "=== EXAMPLE 4: Crew Results Endpoint - Final Round ===\n";
echo "GET /api/race-results/126/crew-results\n\n";

$crewResultsResponse = [
    'success' => true,
    'is_final_round' => true,
    'data' => [
        [
            'id' => 601,
            'crew_id' => 202,
            'lane' => 1,
            'position' => 1,
            'time_ms' => 128000,
            'formatted_time' => '02:08.000',
            'status' => 'FINISHED',
            'final_time_ms' => 390000, // 6:30.000 total
            'final_position' => 1,
            'formatted_final_time' => '06:30.000',
            'final_status' => 'FINISHED',
            'is_final_round' => true,
            'crew' => [
                'id' => 202,
                'team' => ['id' => 302, 'name' => 'Team Poland']
            ]
        ],
        [
            'id' => 602,
            'crew_id' => 201,
            'lane' => 2,
            'position' => 2,
            'time_ms' => 129000,
            'formatted_time' => '02:09.000',
            'status' => 'FINISHED',
            'final_time_ms' => 391000, // 6:31.000 total
            'final_position' => 2,
            'formatted_final_time' => '06:31.000',
            'final_status' => 'FINISHED',
            'is_final_round' => true,
            'crew' => [
                'id' => 201,
                'team' => ['id' => 301, 'name' => 'Team Hungary']
            ]
        ],
        [
            'id' => 603,
            'crew_id' => 203,
            'lane' => 3,
            'position' => null,
            'time_ms' => null,
            'formatted_time' => null,
            'status' => 'DSQ',
            'final_time_ms' => null,
            'final_position' => 'DSQ',
            'formatted_final_time' => null,
            'final_status' => 'DSQ',
            'is_final_round' => true,
            'crew' => [
                'id' => 203,
                'team' => ['id' => 303, 'name' => 'Team Czech']
            ]
        ]
    ],
    'message' => 'Crew results retrieved successfully'
];

echo json_encode($crewResultsResponse, JSON_PRETTY_PRINT) . "\n\n";

// Summary of Time Calculation Example
echo "=== TIME CALCULATION EXAMPLE ===\n";
echo "Team Poland (Winner):\n";
echo "  Round 1: 2:12.000 (132,000 ms)\n";
echo "  Round 2: 2:10.000 (130,000 ms)\n";
echo "  Round 3: 2:08.000 (128,000 ms)\n";
echo "  TOTAL:   6:30.000 (390,000 ms) <- WINNER\n\n";

echo "Team Hungary (Runner-up):\n";
echo "  Round 1: 2:10.000 (130,000 ms)\n";
echo "  Round 2: 2:12.000 (132,000 ms)\n";
echo "  Round 3: 2:09.000 (129,000 ms)\n";
echo "  TOTAL:   6:31.000 (391,000 ms)\n\n";

echo "Team Czech (DSQ):\n";
echo "  Round 1: 2:11.000 (131,000 ms)\n";
echo "  Round 2: 2:09.000 (129,000 ms)\n";
echo "  Round 3: DSQ\n";
echo "  TOTAL:   DSQ (any DSQ = overall DSQ)\n\n";

?>