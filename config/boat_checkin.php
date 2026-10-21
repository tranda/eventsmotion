<?php

return [
    /*
     * Secret used to sign athlete QR codes. Falls back to APP_KEY.
     * Never ship this to the app — only the backend signs and verifies.
     */
    'qr_secret' => env('ATHLETE_QR_SECRET', env('APP_KEY')),

    /*
     * Paddler seats per boat group. Seat layout in crew_athletes.no:
     * 0 = drummer, 1..paddlers = paddlers, paddlers+1 = helm, above = reserves.
     */
    'paddlers' => [
        'Standard' => 20,
        'Small' => 10,
    ],
];
