<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateRestriction;
use App\Services\Allocation\GateAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GatePageTest extends TestCase
{
    use RefreshDatabase;

    private Gate $gate;

    protected function setUp(): void
    {
        parent::setUp();

        $airport = Airport::findByCode('EDDF');
        $this->gate = $airport->gates()->where('code', 'A1')->first();

        // Two arrivals on 9 January 2025: 10:00-11:30 and 12:00-13:30, both on gate A1.
        foreach (['DLH400' => '2025-01-09 10:00', 'BAW900' => '2025-01-09 12:00'] as $callsign => $landed) {
            Flight::create([
                'airport_id' => $airport->id,
                'direction' => Flight::ARRIVAL,
                'icao24' => substr(md5($callsign), 0, 6),
                'callsign' => $callsign,
                'departure_airport' => 'EGLL',
                'arrival_airport' => 'EDDF',
                'first_seen' => now()->parse($landed)->subHours(2),
                'last_seen' => $landed,
                'observed_at' => now(),
            ]);
        }

        app(GateAllocator::class)->allocatePending();
    }

    public function test_it_shows_the_gate_schedule_for_a_day(): void
    {
        $this->get(route('gates.show', [$this->gate, 'date' => '2025-01-09']))
            ->assertOk()
            ->assertSeeInOrder(['Terminal A', 'Frankfurt Airport (EDDF)'])
            ->assertSee('Thursday, 9 January 2025')
            ->assertSeeInOrder(['DLH400', 'BAW900'])
            ->assertSee('London Heathrow Airport <span class="muted mono">(EGLL)</span>', false)
            // Two 90-minute windows are 3 hours, 12.5% of the day; 10:00 starts 41.667% into it.
            ->assertSee('3h 0m of the day')
            ->assertSee('13%')
            ->assertSee('left: 41.667%; width: 6.25%', false);
    }

    public function test_other_days_and_other_gates_are_left_out(): void
    {
        $this->get(route('gates.show', [$this->gate, 'date' => '2025-01-10']))
            ->assertOk()
            ->assertSee('No flights at this gate on this day.')
            ->assertDontSee('DLH400');

        $otherGate = Airport::findByCode('EDDF')->gates()->where('code', 'A2')->first();
        $this->get(route('gates.show', [$otherGate, 'date' => '2025-01-09']))->assertDontSee('DLH400');
    }

    public function test_closures_are_listed_and_drawn_on_the_timeline(): void
    {
        GateRestriction::create([
            'gate_id' => $this->gate->id,
            'starts_at' => '2025-01-10 00:00',
            'ends_at' => '2025-01-12 00:00',
            'reason' => 'Repairs',
        ]);

        $this->get(route('gates.show', [$this->gate, 'date' => '2025-01-10']))
            ->assertOk()
            ->assertSee('timeline-bar closed', false)
            ->assertSee('Closed: Repairs')
            ->assertSee('2025-01-12 00:00');
    }

    public function test_the_flights_list_and_flight_page_link_to_the_gate(): void
    {
        $flight = Flight::where('callsign', 'DLH400')->first();

        $this->get(route('flights.show', $flight))
            ->assertSee(route('gates.show', [$this->gate, 'date' => '2025-01-09']), false);

        $this->getJson(route('flights.list', [
            'airport' => 'EDDF', 'direction' => 'arrival', 'draw' => 1, 'start' => 0, 'length' => 10,
            'columns' => [['data' => 'gate_code', 'name' => 'g.code', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '']]],
            'order' => [['column' => 0, 'dir' => 'asc']], 'search' => ['value' => ''],
        ]))->assertJsonPath('data.0.gate_id', $this->gate->id);
    }

    public function test_an_invalid_date_is_rejected(): void
    {
        $this->get(route('gates.show', [$this->gate, 'date' => 'nonsense']))->assertSessionHasErrors('date');
    }
}
