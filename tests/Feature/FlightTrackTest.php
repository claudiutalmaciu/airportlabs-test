<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\Flight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlightTrackTest extends TestCase
{
    use RefreshDatabase;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.opensky.client_id' => null]);
        Cache::flush();

        $this->flight = Flight::create([
            'airport_id' => Airport::findByCode('EDDF')->id,
            'direction' => Flight::ARRIVAL,
            'icao24' => '3C5EF8',
            'callsign' => 'EWG2W',
            'departure_airport' => 'LEPA',
            'arrival_airport' => 'EDDF',
            'first_seen' => now()->subHours(2)->startOfMinute(),
            'last_seen' => now()->startOfMinute(),
            'observed_at' => now(),
        ]);
    }

    private function openSkyTrack(): array
    {
        $start = $this->flight->first_seen->getTimestamp();

        return [
            'icao24' => '3c5ef8',
            'callsign' => 'EWG2W   ',
            'startTime' => $start,
            'endTime' => $start + 5400,
            'path' => [
                [$start, 39.60, 2.86, 1219, 58, false],
                [$start + 60, null, null, null, null, false],
                [$start + 2700, 45.00, 6.00, 11887, 30, false],
                [$start + 5400, 50.03, 8.57, 0, 250, true],
            ],
        ];
    }

    public function test_it_returns_the_path_and_the_airports_we_know(): void
    {
        Http::fake(['*/tracks/all*' => Http::response($this->openSkyTrack())]);

        $this->getJson(route('flights.track', $this->flight))
            ->assertOk()
            ->assertJsonCount(3, 'path')
            ->assertJsonPath('path.0.latitude', 39.6)
            ->assertJsonPath('path.2.on_ground', true)
            ->assertJsonPath('max_altitude_m', 11887)
            ->assertJsonPath('message', null)
            // LEPA is not in the airports table, so only Frankfurt is marked.
            ->assertJsonCount(1, 'airports')
            ->assertJsonPath('airports.0.icao_code', 'EDDF')
            ->assertJsonPath('airports.0.role', 'arrival');

        $middle = intdiv($this->flight->first_seen->getTimestamp() + $this->flight->last_seen->getTimestamp(), 2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'icao24=3c5ef8') && str_contains($request->url(), "time={$middle}"));
    }

    public function test_the_track_is_fetched_once_and_then_cached(): void
    {
        Http::fake(['*/tracks/all*' => Http::response($this->openSkyTrack())]);

        $this->getJson(route('flights.track', $this->flight))->assertOk();
        $this->getJson(route('flights.track', $this->flight))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_a_flight_opensky_has_no_track_for_says_so(): void
    {
        Http::fake(['*/tracks/all*' => Http::response(null, 404)]);

        $this->getJson(route('flights.track', $this->flight))
            ->assertOk()
            ->assertJsonCount(0, 'path')
            ->assertJsonPath('message', 'OpenSky has no track for this flight.');
    }

    public function test_past_tracks_without_credentials_explain_what_is_needed(): void
    {
        Http::fake(['*/tracks/all*' => Http::response('Forbidden', 403)]);

        $this->getJson(route('flights.track', $this->flight))
            ->assertOk()
            ->assertJsonPath('message', 'Tracks of past flights need OpenSky credentials (OPENSKY_CLIENT_ID / OPENSKY_CLIENT_SECRET).');
    }

    public function test_flights_older_than_thirty_days_are_not_requested(): void
    {
        Http::fake();
        $this->flight->update(['first_seen' => now()->subDays(40), 'last_seen' => now()->subDays(40)->addHours(2)]);

        $this->getJson(route('flights.track', $this->flight))
            ->assertOk()
            ->assertJsonPath('message', 'OpenSky only keeps tracks for 30 days.');

        Http::assertNothingSent();
    }

    public function test_the_flight_page_loads_the_route_map_without_calling_opensky(): void
    {
        Http::fake();

        $this->get(route('flights.show', $this->flight))
            ->assertOk()
            ->assertSee('Route flown')
            ->assertSee('data-url="'.route('flights.track', $this->flight).'"', false)
            ->assertSee('leaflet.min.js', false);

        Http::assertNothingSent();
    }
}
