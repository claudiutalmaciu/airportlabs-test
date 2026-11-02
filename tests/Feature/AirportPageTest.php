<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\Flight;
use App\Models\GateRestriction;
use App\Services\Allocation\GateAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AirportPageTest extends TestCase
{
    use RefreshDatabase;

    private Airport $airport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->airport = Airport::findByCode('LROP');

        // An arrival landing now holds gate A1; a departure two days ago is long gone.
        $flights = [
            ['ROT301', Flight::ARRIVAL, now()->subHours(3), now()->subMinutes(10)],
            ['WZZ3001', Flight::DEPARTURE, now()->subDays(2), now()->subDays(2)->addHours(2)],
        ];
        foreach ($flights as [$callsign, $direction, $firstSeen, $lastSeen]) {
            Flight::create([
                'airport_id' => $this->airport->id,
                'direction' => $direction,
                'icao24' => substr(md5($callsign), 0, 6),
                'callsign' => $callsign,
                'departure_airport' => $direction === Flight::ARRIVAL ? 'EGLL' : 'LROP',
                'arrival_airport' => $direction === Flight::ARRIVAL ? 'LROP' : 'EGLL',
                'first_seen' => $firstSeen,
                'last_seen' => $lastSeen,
                'observed_at' => now(),
            ]);
        }

        app(GateAllocator::class)->allocatePending();
    }

    public function test_it_shows_the_airport_with_a_link_to_all_its_flights(): void
    {
        $this->get(route('airports.show', $this->airport))
            ->assertOk()
            ->assertSee('Bucharest Henri Coandă International Airport')
            ->assertSee('Bucharest')
            ->assertSee('href="'.route('flights.index', ['airport' => 'LROP']).'"', false)
            ->assertSee('View all flights')
            ->assertSee('Flights at LROP')
            ->assertSeeInOrder(['fa-plane-arrival', '1', 'arrivals', 'fa-plane-departure', '1', 'departures'], false);
    }

    public function test_it_shows_gates_right_now_grouped_by_terminal(): void
    {
        GateRestriction::create([
            'gate_id' => $this->airport->gates()->where('code', 'B8')->value('id'),
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'reason' => 'Repairs',
        ]);

        $this->get(route('airports.show', $this->airport))
            ->assertOk()
            ->assertSeeInOrder(['Terminal A', '>A1<', '>A10<', 'Terminal B', '>B1<'], false)
            ->assertSee('title="Gate A1: in use"', false)
            ->assertSee('title="Gate B8: closed"', false)
            ->assertSee('title="Gate A2: free"', false);
    }

    public function test_it_lists_the_latest_flights_and_busiest_airlines(): void
    {
        $this->get(route('airports.show', $this->airport))
            ->assertOk()
            ->assertSeeInOrder(['Latest arrivals', 'ROT301', 'Latest departures', 'WZZ3001'])
            ->assertSee('TAROM <span class="muted mono">(ROT)</span>', false)
            ->assertSee('Wizz Air <span class="muted mono">(WZZ)</span>', false);
    }

    public function test_other_pages_link_to_it_and_unknown_codes_are_not_found(): void
    {
        $flight = Flight::where('callsign', 'ROT301')->first();
        $airportUrl = 'href="'.route('airports.show', $this->airport).'"';

        $this->get(route('flights.index', ['airport' => 'LROP']))->assertSee($airportUrl, false);
        $this->get(route('flights.show', $flight))->assertSee($airportUrl, false);
        $this->get(route('gates.show', $flight->allocation->gate))->assertSee($airportUrl, false);

        // Airport pages are addressed by ID; the old code-based URL no longer resolves.
        $this->assertSame(url('/airports/'.$this->airport->id), route('airports.show', $this->airport));
        $this->get('/airports/LROP')->assertNotFound();
        $this->get('/airports/999999')->assertNotFound();
    }
}
