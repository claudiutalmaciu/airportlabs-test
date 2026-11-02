<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\AllocationException;
use App\Models\Flight;
use App\Services\Allocation\GateAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlightPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $eddf = Airport::findByCode('EDDF');
        $lrop = Airport::findByCode('LROP');

        foreach ([[$eddf, Flight::ARRIVAL, 'DLH400'], [$eddf, Flight::DEPARTURE, 'BAW900'], [$lrop, Flight::ARRIVAL, 'ROT100'], [$lrop, Flight::ARRIVAL, 'XYZ123']] as [$airport, $direction, $callsign]) {
            Flight::create([
                'airport_id' => $airport->id,
                'direction' => $direction,
                'icao24' => substr(md5($callsign), 0, 6),
                'callsign' => $callsign,
                'departure_airport' => $direction === Flight::ARRIVAL ? 'EGLL' : $airport->icao_code,
                'arrival_airport' => $direction === Flight::ARRIVAL ? $airport->icao_code : 'EGLL',
                'first_seen' => '2025-01-09 08:00',
                'last_seen' => '2025-01-09 10:00',
                'observed_at' => now(),
            ]);
        }

        app(GateAllocator::class)->allocatePending();
    }

    /**
     * The query string DataTables sends in server-side mode.
     */
    private function dataTablesQuery(string $direction, array $extra = [], string $airport = 'EDDF'): array
    {
        $columns = [
            ['data' => 'callsign', 'name' => 'flights.callsign'],
            ['data' => 'airline_name', 'name' => 'flights.airline_name'],
            ['data' => 'icao24', 'name' => 'flights.icao24'],
            $direction === 'arrival'
                ? ['data' => 'departure_airport', 'name' => 'departure_airport']
                : ['data' => 'arrival_airport', 'name' => 'arrival_airport'],
            ['data' => 'gate_code', 'name' => 'g.code'],
            ['data' => 'gate_status', 'name' => 'flights.gate_status'],
        ];

        return array_merge([
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'airport' => $airport,
            'direction' => $direction,
            'columns' => array_map(fn ($c) => $c + ['searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '']], $columns),
            'order' => [['column' => 0, 'dir' => 'asc']],
            'search' => ['value' => ''],
        ], $extra);
    }

    public function test_the_list_page_renders_the_table_shell(): void
    {
        $this->get('/flights')->assertOk()->assertSee('id="flights"', false)->assertSee('Landed at')->assertSee('fa-plane-arrival', false);
        $this->get('/flights?direction=departure')->assertOk()->assertSee('Departed at');
    }

    public function test_the_stats_only_count_the_selected_airport(): void
    {
        $this->get('/flights?airport=EDDF')
            ->assertOk()
            ->assertSee('Flights at EDDF')
            ->assertSeeInOrder(['fa-plane-arrival', '1', 'arrivals', 'fa-plane-departure', '1', 'departures'], false)
            ->assertSee('Gates at EDDF');

        $this->get('/flights?airport=LROP')
            ->assertOk()
            ->assertSee('Flights at LROP')
            ->assertSeeInOrder(['fa-plane-arrival', '2', 'arrivals', 'fa-plane-departure', '0', 'departures'], false);
    }

    public function test_without_a_chosen_airport_the_default_one_is_shown(): void
    {
        config(['gates.airport_icao' => 'LRCL']);

        $this->get('/')->assertRedirect('/flights');
        $this->get('/flights')
            ->assertOk()
            ->assertSeeInOrder(['<h1>', 'Avram Iancu Cluj International Airport</a>'], false)
            ->assertSee('<option value="'.e(route('flights.index', ['airport' => 'LRCL', 'direction' => 'arrival'])).'" selected>', false);
    }

    public function test_the_airport_dropdown_lists_codes_with_names(): void
    {
        $this->get('/flights')
            ->assertOk()
            ->assertSee('Frankfurt Airport')
            ->assertSee('Frankfurt Airport (EDDF)')
            ->assertSee('Bucharest Henri Coandă International Airport (LROP)')
            ->assertDontSee('<optgroup', false);

        $this->get('/flights?airport=lrop')->assertOk()->assertSeeInOrder(['<h1>', 'Bucharest Henri Coandă International Airport</a>'], false);
    }

    public function test_the_data_endpoint_only_returns_the_selected_airport(): void
    {
        $response = $this->getJson(route('flights.list', $this->dataTablesQuery('arrival', [], 'LROP')))->assertOk();

        $response->assertJsonPath('recordsTotal', 2);
        $this->assertStringContainsString('ROT100', $response->json('data.0.callsign'));
        $this->assertSame('London Heathrow Airport <span class="muted mono">(EGLL)</span>', $response->json('data.0.departure_airport'));

        $this->getJson(route('flights.list', $this->dataTablesQuery('arrival', [], 'ZZZZ')))->assertUnprocessable();
    }

    public function test_the_data_endpoint_returns_one_direction_with_gate_and_link(): void
    {
        $response = $this->getJson(route('flights.list', $this->dataTablesQuery('arrival')))->assertOk();

        $response->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('data.0.gate_code', 'A1')
            ->assertJsonPath('data.0.airline_name', 'Lufthansa');
        $this->assertStringContainsString(route('flights.show', Flight::where('callsign', 'DLH400')->first()), $response->json('data.0.callsign'));
        $this->assertStringNotContainsString('BAW900', $response->getContent());
    }

    public function test_the_data_endpoint_searches_and_filters_on_the_server(): void
    {
        $this->getJson(route('flights.list', $this->dataTablesQuery('departure', ['search' => ['value' => 'BAW9']])))
            ->assertJsonPath('recordsFiltered', 1);

        // Statuses show and search by their readable names.
        $allocated = $this->getJson(route('flights.list', $this->dataTablesQuery('arrival', ['search' => ['value' => 'gate allocated']])));
        $allocated->assertJsonPath('recordsFiltered', 1);
        $this->assertStringContainsString('Gate allocated', $allocated->json('data.0.gate_status'));
        $this->getJson(route('flights.list', $this->dataTablesQuery('arrival', ['search' => ['value' => 'no gate available']])))
            ->assertJsonPath('recordsFiltered', 0);

        // Searching by an airline's name.
        $this->getJson(route('flights.list', $this->dataTablesQuery('arrival', ['search' => ['value' => 'lufthansa']])))
            ->assertJsonPath('recordsFiltered', 1);

        // An airline we have no name for still comes back with its code.
        $unknown = $this->getJson(route('flights.list', $this->dataTablesQuery('arrival', ['search' => ['value' => 'XYZ1']], 'LROP')));
        $unknown->assertJsonPath('recordsFiltered', 1)->assertJsonPath('data.0.airline_name', null)->assertJsonPath('data.0.airline_code', 'XYZ');

        // Searching by an airport's commercial name.
        $this->getJson(route('flights.list', $this->dataTablesQuery('arrival', ['search' => ['value' => 'Heathrow']])))
            ->assertJsonPath('recordsFiltered', 1);

        $this->getJson(route('flights.list', $this->dataTablesQuery('departure', ['status' => 'unallocated'])))
            ->assertJsonPath('recordsFiltered', 0);

        $this->getJson(route('flights.list', $this->dataTablesQuery('sideways')))->assertUnprocessable();
    }

    public function test_the_flight_page_shows_its_gate_and_exceptions(): void
    {
        $flight = Flight::where('callsign', 'DLH400')->first();
        AllocationException::create([
            'flight_id' => $flight->id,
            'type' => AllocationException::RESTRICTION_CONFLICT,
            'message' => 'DLH400 at gate B8: moved to gate A1.',
        ]);

        $this->get(route('flights.show', $flight))
            ->assertOk()
            ->assertSee('DLH400')
            ->assertSee('EGLL → EDDF')
            ->assertSee('direction-badge large arrival', false)
            ->assertSee('<span class="badge allocated">Gate allocated</span>', false)
            ->assertSeeInOrder(['fa-plane-arrival', 'Arrival'], false)
            ->assertSee('London Heathrow Airport')
            ->assertSee('Lufthansa <span class="muted mono">(DLH)</span>', false)
            // The gate table lists the departure sharing gate A1, with its airline, and highlights this flight.
            ->assertSeeInOrder(['Gate', '>A1</a>', 'around this flight'], false)
            ->assertSee('British Airways <span class="muted mono">(BAW)</span>', false)
            ->assertSee('aria-current="true"', false)
            ->assertSee('London Heathrow Airport <span class="muted mono">(EGLL)</span>', false)
            // Gate table rows show only the other end of the route: this arrival came from EGLL, the departure goes to EGLL.
            ->assertSeeInOrder(['fa-plane-departure', '>to<', 'London Heathrow Airport', '<span class="muted mono">(EGLL)</span>'], false)
            ->assertSee('This flight')
            ->assertSee('A1')
            ->assertSee('90 min')
            ->assertSee('moved to gate A1');

        $this->get('/flights/999999')->assertNotFound();
    }
}
