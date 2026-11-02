<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\Flight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LiveAircraftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.opensky.client_id' => null, 'gates.live_map_radius_km' => 80]);
        Cache::flush();
    }

    /**
     * An OpenSky state vector, positional as the API returns it.
     */
    private function state(string $icao24, ?string $callsign, float $lat, float $lon, bool $onGround = false): array
    {
        return [$icao24, $callsign, 'Germany', 1793186000, 1793186005, $lon, $lat, 3000.0, $onGround, 150.0, 270.0, -5.0, null, 3050.0, '1000', false, 0];
    }

    private function fakeOpenSky(array $states, int $creditsLeft = 399): void
    {
        Http::fake([
            '*/states/all*' => Http::response(['time' => 1793186010, 'states' => $states], 200, ['X-Rate-Limit-Remaining' => $creditsLeft]),
        ]);
    }

    public function test_it_returns_aircraft_inside_a_box_around_the_airport(): void
    {
        $this->fakeOpenSky([
            $this->state('3c6444', 'DLH400  ', 50.1, 8.6),
            $this->state('4ca1b2', null, 50.0, 8.5, onGround: true),
            ['abcdef', 'NOPOS', 'France', null, 1793186000, null, null, null, false, null, null, null, null, null, null, false, 0],
        ]);

        $response = $this->getJson(route('airports.aircraft', 'EDDF'))->assertOk();

        $response->assertJsonCount(2, 'aircraft')
            ->assertJsonPath('aircraft.0.callsign', 'DLH400')
            ->assertJsonPath('aircraft.0.airline', 'Lufthansa')
            ->assertJsonPath('aircraft.0.altitude_m', 3050)
            ->assertJsonPath('aircraft.1.on_ground', true)
            ->assertJsonPath('credits_remaining', 399)
            ->assertJsonPath('refresh_seconds', config('gates.live_map_refresh_seconds'));

        // Frankfurt is at 50.03 N, so an 80 km radius is about ±0.72° of latitude.
        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/states/all')
                && abs($query['lamin'] - 49.3147) < 0.001
                && abs($query['lamax'] - 50.7519) < 0.001
                && $query['lomin'] < 7.5 && $query['lomax'] > 9.6;
        });
    }

    public function test_aircraft_we_hold_a_flight_for_link_to_it(): void
    {
        $flight = Flight::create([
            'airport_id' => Airport::findByCode('EDDF')->id,
            'direction' => Flight::ARRIVAL,
            'icao24' => '3c6444',
            'callsign' => 'DLH400',
            'first_seen' => now()->subHours(2),
            'last_seen' => now(),
            'observed_at' => now(),
        ]);
        $this->fakeOpenSky([$this->state('3c6444', 'DLH400', 50.1, 8.6), $this->state('ffffff', 'BAW1', 50.2, 8.7)]);

        $this->getJson(route('airports.aircraft', 'EDDF'))
            ->assertJsonPath('aircraft.0.flight_url', route('flights.show', $flight))
            ->assertJsonPath('aircraft.1.flight_url', null);
    }

    public function test_responses_are_cached_to_save_opensky_credits(): void
    {
        $this->fakeOpenSky([$this->state('3c6444', 'DLH400', 50.1, 8.6)]);

        $this->getJson(route('airports.aircraft', 'EDDF'))->assertOk();
        $this->getJson(route('airports.aircraft', 'EDDF'))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_when_rate_limited_it_keeps_the_last_positions_and_says_why(): void
    {
        Http::fake(['*/states/all*' => Http::sequence()
            ->push(['time' => 1793186010, 'states' => [$this->state('3c6444', 'DLH400', 50.1, 8.6)]])
            ->push('Too many requests', 429, ['X-Rate-Limit-Retry-After-Seconds' => 1800]),
        ]);
        $this->getJson(route('airports.aircraft', 'EDDF'))->assertOk();

        // Once the short cache expires, the next call hits the rate limit.
        Cache::forget('live-aircraft:EDDF');

        $this->getJson(route('airports.aircraft', 'EDDF'))
            ->assertOk()
            ->assertJsonCount(1, 'aircraft')
            ->assertJsonPath('stale', true)
            ->assertJsonPath('error', 'OpenSky rate limit reached, more credits in 30 min.');
    }

    public function test_an_airport_without_coordinates_has_no_map(): void
    {
        $airport = Airport::create(['icao_code' => 'LIRF', 'name' => 'Rome Fiumicino Airport']);

        $this->getJson(route('airports.aircraft', 'LIRF'))->assertNotFound();
        $this->get('/flights?airport=LIRF')->assertOk()->assertDontSee('id="live-map"', false);
        $this->get('/flights?airport=EDDF')->assertOk()->assertSee('id="live-map"', false)->assertSee('Live aircraft within 80 km');
    }
}
