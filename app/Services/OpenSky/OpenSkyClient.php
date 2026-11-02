<?php

namespace App\Services\OpenSky;

use App\Models\Flight;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The only class that talks to the OpenSky REST API (https://openskynetwork.github.io/opensky-api/rest.html).
 *
 * Without credentials, the live endpoints (states, live tracks) work within a small anonymous allowance, while
 * the flight endpoints return 403. With OPENSKY_CLIENT_ID / OPENSKY_CLIENT_SECRET set, every request carries an
 * OAuth2 bearer token.
 */
final class OpenSkyClient
{
    /**
     * Longest time range per flights call. OpenSky rejects ranges over two days; one day keeps responses small.
     */
    private const MAX_INTERVAL_SECONDS = 86400;

    /**
     * Cache key for the OAuth2 access token.
     */
    private const TOKEN_CACHE_KEY = 'opensky.access_token';

    /**
     * Arrivals or departures for an airport between two moments, newest data being yesterday's: OpenSky only
     * publishes flights after its overnight batch. Longer ranges are split into one-day calls.
     *
     * @param  string  $direction  Flight::ARRIVAL or Flight::DEPARTURE
     * @param  string  $airport  ICAO code, e.g. LRCL
     * @return list<array<string, mixed>> rows ready for the flights table (airport, status and timestamps added by the caller)
     *
     * @throws RequestException when OpenSky refuses or fails, e.g. 403 without credentials
     */
    public function flights(string $direction, string $airport, CarbonImmutable $begin, CarbonImmutable $end): array
    {
        $endpoint = $direction === Flight::ARRIVAL ? 'flights/arrival' : 'flights/departure';
        $rows = [];

        for ($from = $begin; $from < $end; $from = $from->addSeconds(self::MAX_INTERVAL_SECONDS)) {
            $to = $from->addSeconds(self::MAX_INTERVAL_SECONDS)->min($end);

            $response = $this->request()->get($endpoint, [
                'airport' => $airport,
                'begin' => $from->getTimestamp(),
                'end' => $to->getTimestamp(),
            ]);

            // OpenSky answers 404 with an empty body when there are no flights in the interval.
            if ($response->notFound()) {
                continue;
            }

            $response->throw();

            foreach ($response->json() ?? [] as $flight) {
                // OpenSky occasionally sends a flight without an aircraft or a timestamp; it cannot be placed in time, so skip it.
                if (empty($flight['icao24']) || ! isset($flight['firstSeen'], $flight['lastSeen'])) {
                    continue;
                }

                $rows[] = $this->toRow($direction, $flight);
            }
        }

        return $rows;
    }

    /**
     * Live state vectors (position, altitude, speed…) for every aircraft inside a lat/lon box. Small boxes
     * cost 1 credit per call.
     *
     * @return array{time: ?int, states: list<array<int, mixed>>, credits_remaining: ?int}
     *                                                                                     states are positional arrays as OpenSky sends them; credits_remaining comes from X-Rate-Limit-Remaining
     *
     * @throws RequestException when OpenSky refuses or fails, e.g. 429 when credits run out
     */
    public function states(float $minLat, float $minLon, float $maxLat, float $maxLon): array
    {
        $response = $this->request()->get('states/all', [
            'lamin' => round($minLat, 4),
            'lomin' => round($minLon, 4),
            'lamax' => round($maxLat, 4),
            'lomax' => round($maxLon, 4),
        ])->throw();

        $remaining = $response->header('X-Rate-Limit-Remaining');

        return [
            'time' => $response->json('time'),
            'states' => $response->json('states') ?? [],
            'credits_remaining' => $remaining === '' ? null : (int) $remaining,
        ];
    }

    /**
     * The trajectory of one aircraft's flight around a given moment, or null when OpenSky has none.
     * Past flights need credentials; OpenSky keeps tracks for 30 days.
     *
     * @param  int  $time  any Unix time during the flight; 0 means the aircraft's current flight
     * @return array{icao24: string, callsign: ?string, startTime: int, endTime: int, path: list<array<int, mixed>>}|null
     *
     * @throws RequestException when OpenSky refuses or fails, e.g. 403 for past flights without credentials
     */
    public function track(string $icao24, int $time): ?array
    {
        $response = $this->request()->get('tracks/all', [
            'icao24' => strtolower($icao24),
            'time' => $time,
        ]);

        if ($response->notFound()) {
            return null;
        }

        return $response->throw()->json();
    }

    /**
     * A request with the base URL, timeout and token set. Connection failures are retried twice, a second apart;
     * HTTP errors are left to the caller.
     */
    private function request(): PendingRequest
    {
        $request = Http::baseUrl(config('services.opensky.base_url'))
            ->acceptJson()
            ->timeout(config('services.opensky.timeout'))
            ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException, throw: false);

        $token = $this->accessToken();

        return $token ? $request->withToken($token) : $request;
    }

    /**
     * OAuth2 client-credentials token, cached until shortly before it expires (tokens last 30 minutes).
     *
     * @return string|null null when no credentials are configured, so requests go out anonymously
     */
    private function accessToken(): ?string
    {
        $clientId = config('services.opensky.client_id');
        $secret = config('services.opensky.client_secret');

        if (! $clientId || ! $secret) {
            return null;
        }

        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(25), function () use ($clientId, $secret) {
            return Http::asForm()
                ->timeout(config('services.opensky.timeout'))
                ->post(config('services.opensky.token_url'), [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $secret,
                ])
                ->throw()
                ->json('access_token');
        });
    }

    /**
     * Turns one OpenSky flight into a flights row: trims the padded callsign, derives the airline from it and
     * converts Unix timestamps to UTC dates.
     *
     * @param  array<string, mixed>  $flight  as OpenSky sends it (icao24, callsign, firstSeen, lastSeen, est…Airport)
     * @return array<string, mixed>
     */
    private function toRow(string $direction, array $flight): array
    {
        $callsign = isset($flight['callsign']) ? (trim($flight['callsign']) ?: null) : null;
        $airlineCode = Flight::airlineCodeFrom($callsign);

        return [
            'direction' => $direction,
            'icao24' => strtolower($flight['icao24']),
            'callsign' => $callsign,
            'airline_code' => $airlineCode,
            'airline_name' => Flight::airlineName($airlineCode),
            'departure_airport' => $flight['estDepartureAirport'] ?? null,
            'arrival_airport' => $flight['estArrivalAirport'] ?? null,
            'first_seen' => CarbonImmutable::createFromTimestampUTC($flight['firstSeen']),
            'last_seen' => CarbonImmutable::createFromTimestampUTC($flight['lastSeen']),
        ];
    }
}
