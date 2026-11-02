<?php

namespace App\Services\OpenSky;

use App\Models\Airport;
use App\Models\Flight;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aircraft currently flying (or taxiing) around an airport, for the live map.
 * Responses are cached per airport so every open page shares one OpenSky call per refresh.
 */
final class LiveAircraft
{
    /**
     * Kilometres per degree of latitude (and of longitude at the equator).
     */
    private const KM_PER_DEGREE = 111.32;

    public function __construct(private readonly OpenSkyClient $openSky) {}

    /**
     * Aircraft inside the box around the airport, served from cache for LIVE_MAP_REFRESH_SECONDS.
     *
     * When OpenSky fails (e.g. credits run out), returns the last positions it had with an error message and
     * stale = true, instead of an empty map.
     *
     * @return array<string, mixed> box, fetched_at, credits_remaining and aircraft; plus error and stale on failure
     */
    public function around(Airport $airport): array
    {
        $key = "live-aircraft:{$airport->icao_code}";

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        $box = $this->boundingBox($airport);

        try {
            $result = $this->fetch($airport, $box);
        } catch (Throwable $e) {
            Log::warning("Live aircraft for {$airport->icao_code} failed: {$e->getMessage()}");

            // Keep showing the last positions we had rather than an empty map.
            $last = Cache::get("{$key}:last");

            return ($last ?? ['box' => $box, 'aircraft' => [], 'fetched_at' => null, 'credits_remaining' => null])
                + ['error' => $this->describe($e), 'stale' => $last !== null];
        }

        // The short-lived copy limits OpenSky calls; the day-long copy is the fallback shown when a call fails.
        Cache::put($key, $result, now()->addSeconds(config('gates.live_map_refresh_seconds')));
        Cache::put("{$key}:last", $result, now()->addDay());

        return $result;
    }

    /**
     * A square-ish box of the configured radius around the airport (longitude degrees shrink towards the poles).
     *
     * @return array{min_lat: float, min_lon: float, max_lat: float, max_lon: float}
     */
    public function boundingBox(Airport $airport): array
    {
        $radius = config('gates.live_map_radius_km');
        $dLat = $radius / self::KM_PER_DEGREE;
        $dLon = $radius / (self::KM_PER_DEGREE * max(cos(deg2rad($airport->latitude)), 0.01));

        return [
            'min_lat' => $airport->latitude - $dLat,
            'min_lon' => $airport->longitude - $dLon,
            'max_lat' => $airport->latitude + $dLat,
            'max_lon' => $airport->longitude + $dLon,
        ];
    }

    /**
     * Calls OpenSky and turns its positional state vectors into named fields for the map, dropping aircraft
     * without a position and linking aircraft that match one of this airport's flights.
     *
     * @param  array{min_lat: float, min_lon: float, max_lat: float, max_lon: float}  $box
     * @return array<string, mixed>
     */
    private function fetch(Airport $airport, array $box): array
    {
        $response = $this->openSky->states($box['min_lat'], $box['min_lon'], $box['max_lat'], $box['max_lon']);

        // State vector fields are positional, see https://openskynetwork.github.io/opensky-api/rest.html#all-state-vectors
        $aircraft = collect($response['states'])
            ->filter(fn (array $s) => $s[5] !== null && $s[6] !== null)
            ->map(function (array $s) {
                $callsign = trim((string) $s[1]) ?: null;

                return [
                    'icao24' => $s[0],
                    'callsign' => $callsign,
                    'airline' => Flight::airlineName(Flight::airlineCodeFrom($callsign)),
                    'origin_country' => $s[2],
                    'last_contact' => $s[4],
                    'longitude' => $s[5],
                    'latitude' => $s[6],
                    // GPS altitude when available, otherwise barometric.
                    'altitude_m' => $s[13] ?? $s[7],
                    'on_ground' => (bool) $s[8],
                    'velocity_ms' => $s[9],
                    'heading' => $s[10],
                    'vertical_rate_ms' => $s[11],
                ];
            })
            ->values();

        // Link aircraft we also hold a flight for at this airport. Ordered by last_seen, so pluck() keeps the
        // most recent flight per aircraft.
        $flightIds = Flight::where('airport_id', $airport->id)
            ->whereIn('icao24', $aircraft->pluck('icao24'))
            ->orderBy('last_seen')
            ->pluck('id', 'icao24');

        return [
            'box' => $box,
            'fetched_at' => $response['time'] ?? now()->getTimestamp(),
            'credits_remaining' => $response['credits_remaining'],
            'aircraft' => $aircraft->map(fn (array $a) => $a + [
                'flight_url' => isset($flightIds[$a['icao24']]) ? route('flights.show', $flightIds[$a['icao24']]) : null,
            ])->all(),
        ];
    }

    /**
     * A short message for the map's status line explaining why the refresh failed.
     */
    private function describe(Throwable $e): string
    {
        if ($e instanceof RequestException && $e->response->status() === 429) {
            $wait = (int) $e->response->header('X-Rate-Limit-Retry-After-Seconds');

            return 'OpenSky rate limit reached'.($wait ? ', more credits in '.ceil($wait / 60).' min' : '').'.';
        }

        return 'Could not reach OpenSky right now.';
    }
}
