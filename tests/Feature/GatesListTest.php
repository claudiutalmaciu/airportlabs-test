<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\Flight;
use App\Models\GateRestriction;
use App\Services\Allocation\GateAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GatesListTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The query string DataTables sends in server-side mode, always ordered by airport first.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function dataTablesQuery(array $extra = []): array
    {
        $columns = [
            ['data' => 'airport_name', 'name' => 'airports.name'],
            ['data' => 'code', 'name' => 'gates.code'],
            ['data' => 'terminal', 'name' => 'gates.terminal'],
        ];

        return array_merge([
            'draw' => 1,
            'start' => 0,
            'length' => 100,
            'columns' => array_map(fn (array $c) => $c + ['searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '']], $columns),
            'order' => [['column' => 0, 'dir' => 'asc'], ['column' => 1, 'dir' => 'asc']],
            'search' => ['value' => ''],
        ], $extra);
    }

    public function test_the_page_lists_airports_to_filter_by(): void
    {
        $this->get(route('gates.index', ['airport' => 'LROP']))
            ->assertOk()
            ->assertSee('260 gates across 13 airports')
            ->assertSee('<option value="LROP" selected>', false)
            ->assertSee('Bucharest Henri Coandă International Airport (LROP) · 20 gates');
    }

    public function test_the_page_shows_gate_stats_for_the_selected_airport(): void
    {
        // Two 90-minute arrivals today, landing an hour apart, so they need gates A1 and A2.
        $airport = Airport::findByCode('EDDF');
        foreach (['DLH1' => 3, 'DLH2' => 2] as $callsign => $hoursAgo) {
            Flight::create([
                'airport_id' => $airport->id,
                'direction' => Flight::ARRIVAL,
                'icao24' => substr(md5($callsign), 0, 6),
                'callsign' => $callsign,
                'first_seen' => now()->startOfDay()->addHours(10 - $hoursAgo - 2),
                'last_seen' => now()->startOfDay()->addHours(10 - $hoursAgo),
                'observed_at' => now(),
            ]);
        }
        app(GateAllocator::class)->allocatePending();

        $this->get(route('gates.index', ['airport' => 'EDDF']))
            ->assertOk()
            ->assertSee('Gates at EDDF')
            ->assertSeeInOrder(['2', 'Flights at a gate'])
            ->assertSee('Time in use · 18 gates unused all day')
            ->assertSeeInOrder(['A1', 'Busiest gate · 1 flights']);

        $this->get(route('gates.index'))->assertOk()->assertSee('Gates at all airports');
    }

    public function test_gates_come_grouped_by_airport_in_natural_order(): void
    {
        $response = $this->getJson(route('gates.list', $this->dataTablesQuery(['airport' => 'EDDF'])))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 20)
            ->assertJsonPath('data.0.airport_label', 'Frankfurt Airport (EDDF)')
            ->assertJsonPath('data.0.airport_url', route('airports.show', Airport::findByCode('EDDF')));

        $this->assertSame(
            ['A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8', 'A9', 'A10', 'B1'],
            array_slice(array_column($response->json('data'), 'code'), 0, 11),
        );
    }

    public function test_it_shows_what_each_gate_is_doing_right_now(): void
    {
        $airport = Airport::findByCode('EDDF');
        $flight = Flight::create([
            'airport_id' => $airport->id,
            'direction' => Flight::ARRIVAL,
            'icao24' => '3c6444',
            'callsign' => 'DLH400',
            'first_seen' => now()->subHours(2),
            'last_seen' => now()->subMinutes(10),
            'observed_at' => now(),
        ]);
        app(GateAllocator::class)->allocatePending();
        GateRestriction::create([
            'gate_id' => $airport->gates()->where('code', 'B8')->value('id'),
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
            'reason' => 'Repairs',
        ]);

        $gates = collect($this->getJson(route('gates.list', $this->dataTablesQuery(['airport' => 'EDDF'])))->json('data'))->keyBy('code');

        $this->assertSame('in_use', $gates['A1']['status']);
        $this->assertSame('DLH400', $gates['A1']['current_callsign']);
        $this->assertSame(route('flights.show', $flight), $gates['A1']['current_flight_url']);
        $this->assertSame('closed', $gates['B8']['status']);
        $this->assertSame('Repairs', $gates['B8']['closed_reason']);
        $this->assertSame('free', $gates['A2']['status']);
    }

    public function test_gates_can_be_filtered_by_their_state_right_now(): void
    {
        $airport = Airport::findByCode('EDDF');
        Flight::create([
            'airport_id' => $airport->id,
            'direction' => Flight::ARRIVAL,
            'icao24' => '3c6444',
            'callsign' => 'DLH400',
            'first_seen' => now()->subHours(2),
            'last_seen' => now()->subMinutes(10),
            'observed_at' => now(),
        ]);
        app(GateAllocator::class)->allocatePending();

        // A1 is in use and also closed: it should only count as closed. B8 is closed and empty.
        foreach (['A1', 'B8'] as $code) {
            GateRestriction::create([
                'gate_id' => $airport->gates()->where('code', $code)->value('id'),
                'starts_at' => now()->subHour(),
                'ends_at' => now()->addHour(),
                'reason' => 'Repairs',
            ]);
        }
        $codes = fn (string $status) => array_column(
            $this->getJson(route('gates.list', $this->dataTablesQuery(['airport' => 'EDDF', 'status' => $status])))->json('data'),
            'code',
        );

        $this->assertSame(['A1', 'B8'], $codes('closed'));
        $this->assertSame([], $codes('in_use'));
        $this->assertCount(18, $codes('free'));

        $this->getJson(route('gates.list', $this->dataTablesQuery(['status' => 'busy'])))->assertUnprocessable();
    }

    public function test_searching_by_airport_code_finds_its_gates(): void
    {
        $this->getJson(route('gates.list', $this->dataTablesQuery(['search' => ['value' => 'LROP']])))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 20);

        $this->getJson(route('gates.list', $this->dataTablesQuery(['airport' => 'ZZZZ'])))->assertUnprocessable();
    }
}
