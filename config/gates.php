<?php

return [
    // Default airport (LRCL = Cluj): the one pages and commands use when none is chosen.
    'airport_icao' => strtoupper(env('AIRPORT_ICAO', 'LRCL')),

    // Number of gates created by the gates migration.
    'count' => (int) env('GATE_COUNT', 20),

    // How long a flight holds its gate, starting at moment T.
    'occupancy_minutes' => (int) env('GATE_OCCUPANCY_MINUTES', 90),

    // How moment T is chosen:
    //  - "flight_time": arrivals start at landing (lastSeen), departures end at take-off (firstSeen)
    //  - "observed":    T is when the fetch cron first saw the flight
    'window_strategy' => env('GATE_WINDOW_STRATEGY', 'flight_time'),

    // OpenSky only serves flights from the previous day or earlier, so look back far enough.
    'fetch_lookback_hours' => (int) env('FETCH_LOOKBACK_HOURS', 48),

    // Live aircraft map: how far around the airport to look, and how often to refresh.
    // Anonymous OpenSky users get 400 /states credits a day and each refresh costs 1, so keep the refresh slow.
    'live_map_radius_km' => (int) env('LIVE_MAP_RADIUS_KM', 80),
    'live_map_refresh_seconds' => (int) env('LIVE_MAP_REFRESH_SECONDS', 60),

    'fetch_cron' => env('FETCH_CRON', '*/10 * * * *'),
    'validate_cron' => env('VALIDATE_CRON', '*/15 * * * *'),
];
