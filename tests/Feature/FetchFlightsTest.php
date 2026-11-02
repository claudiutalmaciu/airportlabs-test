<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\AllocationException;
use App\Models\Flight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FetchFlightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gates.fetch_lookback_hours' => 24, 'services.opensky.client_id' => null]);
    }

    private function openSkyFlight(string $icao24, int $firstSeen, int $lastSeen): array
    {
        return [
            'icao24' => $icao24,
            'firstSeen' => $firstSeen,
            'estDepartureAirport' => 'LROP',
            'lastSeen' => $lastSeen,
            'estArrivalAirport' => 'EDDF',
            'callsign' => 'ROT301  ',
        ];
    }

    public function test_it_stores_and_allocates_flights_without_duplicates(): void
    {
        Http::fake([
            '*/flights/arrival*' => Http::response([$this->openSkyFlight('4B1805', 1736496000, 1736503200)]),
            '*/flights/departure*' => Http::response(null, 404),
        ]);

        $this->artisan('flights:fetch', ['airports' => ['EDDF']])->assertSuccessful();
        $this->artisan('flights:fetch', ['airports' => ['EDDF']])->assertSuccessful();

        $flight = Flight::sole();
        $this->assertSame('EDDF', $flight->airport->icao_code);
        $this->assertSame('4b1805', $flight->icao24);
        $this->assertSame('ROT301', $flight->callsign);
        $this->assertSame('ROT', $flight->airline_code);
        $this->assertSame('TAROM', $flight->airline_name);
        $this->assertSame(Flight::STATUS_ALLOCATED, $flight->gate_status);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'airport=EDDF'));
    }

    public function test_a_refetch_fills_in_the_destination_of_a_departure_that_has_since_landed(): void
    {
        $inTheAir = ['estArrivalAirport' => null] + $this->openSkyFlight('4B1805', 1736496000, 1736497000);
        Http::fake([
            '*/flights/departure*' => Http::sequence()
                ->push([$inTheAir])
                ->push([$this->openSkyFlight('4B1805', 1736496000, 1736503200)]),
            '*/flights/arrival*' => Http::response(null, 404),
        ]);

        $this->artisan('flights:fetch', ['airports' => ['LROP']])->assertSuccessful();
        $flight = Flight::sole();
        $this->assertNull($flight->arrival_airport);
        $gate = $flight->allocation->gate_id;

        $this->artisan('flights:fetch', ['airports' => ['LROP']])
            ->expectsOutputToContain('LROP departures: 1 received, 0 new.')
            ->assertSuccessful();

        $refetched = Flight::sole();
        $this->assertSame('EDDF', $refetched->arrival_airport);
        $this->assertSame('2025-01-10 10:00:00', $refetched->last_seen->toDateTimeString());
        $this->assertEquals($flight->observed_at, $refetched->observed_at);
        $this->assertSame($gate, $refetched->allocation->gate_id);
    }

    public function test_flights_without_timestamps_are_skipped_without_losing_the_rest(): void
    {
        Http::fake([
            '*/flights/arrival*' => Http::response([
                ['lastSeen' => null] + $this->openSkyFlight('4B1805', 1736496000, 1736503200),
                $this->openSkyFlight('4B1806', 1736496000, 1736503200),
            ]),
            '*/flights/departure*' => Http::response(null, 404),
        ]);

        $this->artisan('flights:fetch', ['airports' => ['EDDF']])->assertSuccessful();

        $this->assertSame('4b1806', Flight::sole()->icao24);
        $this->assertSame(0, AllocationException::where('type', AllocationException::FETCH_FAILED)->count());
    }

    public function test_it_fetches_every_airport_by_default(): void
    {
        Http::fake(['*' => Http::response(null, 404)]);

        $this->artisan('flights:fetch')->assertSuccessful();

        foreach (Airport::pluck('icao_code') as $code) {
            Http::assertSent(fn ($request) => str_contains($request->url(), "airport={$code}"));
        }
    }

    public function test_airports_can_be_chosen_on_the_command_line(): void
    {
        Http::fake(['*' => Http::response([$this->openSkyFlight('4B1805', 1736496000, 1736503200)])]);

        $this->artisan('flights:fetch', ['airports' => ['lrop']])->assertSuccessful();

        $this->assertSame(['LROP'], Flight::with('airport')->get()->pluck('airport.icao_code')->unique()->values()->all());
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'airport=EDDF'));

        $this->artisan('flights:fetch', ['airports' => ['XXXX']])->assertFailed();
    }

    public function test_an_api_failure_is_recorded_as_an_exception(): void
    {
        Http::fake(['*' => Http::response('You cannot access historical flights', 403)]);

        $this->artisan('flights:fetch')->assertFailed();

        $this->assertSame(2, AllocationException::where('type', AllocationException::FETCH_FAILED)
            ->where('airport_id', Airport::findByCode('EDDF')->id)->count());
    }
}
