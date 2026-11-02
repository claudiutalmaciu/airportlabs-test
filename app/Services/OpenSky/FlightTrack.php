<?php

namespace App\Services\OpenSky;

use App\Models\Airport;
use App\Models\Flight;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The path a flight flew, for the route map on its page. Fetched only when the page asks for it,
 * and cached per flight: a finished flight's track never changes, and each call costs OpenSky credits.
 */
final class FlightTrack
{
    /**
     * OpenSky keeps tracks for this many days; older flights aren't requested at all.
     */
    private const KEEP_DAYS = 30;

    public function __construct(private readonly OpenSkyClient $openSky) {}

    /**
     * The flight's path plus the airports to mark on the map.
     *
     * @return array<string, mixed> path, start_time, end_time, max_altitude_m and message (why there is no path, if
     *                              so), plus airports
     */
    public function for(Flight $flight): array
    {
        $result = $this->track($flight);

        return $result + ['airports' => $this->airports($flight)];
    }

    /**
     * The path from cache or OpenSky. Results are cached for different lengths: 7 days for a track (it never
     * changes), 1 hour when OpenSky has none, 10 minutes after an error so it can be retried.
     *
     * @return array<string, mixed>
     */
    private function track(Flight $flight): array
    {
        if ($flight->last_seen->lt(now()->subDays(self::KEEP_DAYS))) {
            return ['path' => [], 'message' => 'OpenSky only keeps tracks for '.self::KEEP_DAYS.' days.'];
        }

        $key = "flight-track:{$flight->id}";

        if ($cached = Cache::get($key)) {
            return $cached;
        }

        // Any moment during the flight identifies it; the middle is safest.
        $time = intdiv($flight->first_seen->getTimestamp() + $flight->last_seen->getTimestamp(), 2);

        try {
            $track = $this->openSky->track($flight->icao24, $time);
        } catch (Throwable $e) {
            Log::warning("Track for flight {$flight->id} ({$flight->icao24}) failed: {$e->getMessage()}");
            $result = ['path' => [], 'message' => $this->describe($e)];
            Cache::put($key, $result, now()->addMinutes(10));

            return $result;
        }

        if ($track === null || empty($track['path'])) {
            $result = ['path' => [], 'message' => 'OpenSky has no track for this flight.'];
            Cache::put($key, $result, now()->addHour());

            return $result;
        }

        // Waypoints are positional: [time, latitude, longitude, baro_altitude, true_track, on_ground].
        $path = collect($track['path'])
            ->filter(fn (array $p) => $p[1] !== null && $p[2] !== null)
            ->map(fn (array $p) => [
                'time' => (int) $p[0],
                'latitude' => $p[1],
                'longitude' => $p[2],
                'altitude_m' => $p[3],
                'heading' => $p[4],
                'on_ground' => (bool) $p[5],
            ])
            ->values()
            ->all();

        $result = [
            'path' => $path,
            'start_time' => (int) $track['startTime'],
            'end_time' => (int) $track['endTime'],
            'max_altitude_m' => collect($path)->max('altitude_m'),
            'message' => null,
        ];

        Cache::put($key, $result, now()->addDays(7));

        return $result;
    }

    /**
     * Departure and arrival airports we know the position of, to mark on the map.
     *
     * @return list<array{icao_code: string, label: string, latitude: float, longitude: float, role: string}>
     */
    private function airports(Flight $flight): array
    {
        $known = Airport::whereIn('icao_code', array_filter([$flight->departure_airport, $flight->arrival_airport]))
            ->whereNotNull('latitude')
            ->get()
            ->keyBy('icao_code');

        $airports = [];
        foreach (['departure' => $flight->departure_airport, 'arrival' => $flight->arrival_airport] as $role => $code) {
            if ($code && ($airport = $known->get($code))) {
                $airports[] = [
                    'icao_code' => $code,
                    'label' => $airport->label,
                    'latitude' => $airport->latitude,
                    'longitude' => $airport->longitude,
                    'role' => $role,
                ];
            }
        }

        return $airports;
    }

    /**
     * A short message for the route map explaining why there is no track.
     */
    private function describe(Throwable $e): string
    {
        if (! $e instanceof RequestException) {
            return 'Could not reach OpenSky right now.';
        }

        return match ($e->response->status()) {
            401, 403 => config('services.opensky.client_id')
                ? 'OpenSky refused the request for this track.'
                : 'Tracks of past flights need OpenSky credentials (OPENSKY_CLIENT_ID / OPENSKY_CLIENT_SECRET).',
            429 => 'OpenSky rate limit reached, try again later.',
            default => 'OpenSky could not return this track right now.',
        };
    }
}
