<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\Flight;
use App\Services\Allocation\GateAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AirportsPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The query string DataTables sends in server-side mode.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function dataTablesQuery(array $extra = []): array
    {
        $columns = ['name', 'city', 'gates_count', 'flights_count', 'unallocated_count'];

        return array_merge([
            'draw' => 1,
            'start' => 0,
            'length' => 25,
            'columns' => array_map(fn (string $column) => [
                'data' => $column, 'name' => $column, 'orderable' => 'true', 'search' => ['value' => ''],
                'searchable' => in_array($column, ['name', 'city'], true) ? 'true' : 'false',
            ], $columns),
            'order' => [['column' => 0, 'dir' => 'asc']],
            'search' => ['value' => ''],
        ], $extra);
    }

    public function test_the_page_renders_with_navigation(): void
    {
        $this->get(route('airports.index'))
            ->assertOk()
            ->assertSee('id="airports"', false)
            ->assertSee(route('gates.index'), false)
            ->assertSeeInOrder(['<b>13</b>', 'Airports'], false);
    }

    public function test_the_page_shows_stats_across_all_airports(): void
    {
        Flight::create([
            'airport_id' => Airport::findByCode('LOWW')->id,
            'direction' => Flight::ARRIVAL,
            'icao24' => 'abc123',
            'callsign' => 'AUA1',
            'first_seen' => now()->subHours(2),
            'last_seen' => now()->subMinutes(10),
            'observed_at' => now(),
        ]);
        app(GateAllocator::class)->allocatePending();

        // Stored after allocation ran, so it is still waiting for a gate.
        Flight::create([
            'airport_id' => Airport::findByCode('LOWW')->id,
            'direction' => Flight::DEPARTURE,
            'icao24' => 'abc124',
            'callsign' => 'AUA2',
            'first_seen' => now()->addHour(),
            'last_seen' => now()->addHours(3),
            'observed_at' => now(),
        ]);

        $this->get(route('airports.index'))
            ->assertOk()
            ->assertSee('Airports · 260 gates')
            ->assertSee('Flights at all airports')
            ->assertSee('Gates at all airports')
            ->assertSeeInOrder(['fa-plane-arrival', '1', 'arrivals', 'fa-plane-departure', '1', 'departures'], false)
            ->assertSee('No gate available · 1 awaiting gate')
            ->assertDontSee('@if', false)
            ->assertSeeInOrder(['LOWW', 'Most flights · 2'])
            ->assertSeeInOrder(['LOWW', 'Busiest right now · 1 gate in use']);
    }

    public function test_each_airport_links_to_its_page_and_flights_and_counts_them(): void
    {
        foreach ([Flight::STATUS_ALLOCATED, Flight::STATUS_UNALLOCATED] as $i => $status) {
            Flight::create([
                'airport_id' => Airport::findByCode('LROP')->id,
                'direction' => Flight::ARRIVAL,
                'icao24' => "abc00{$i}",
                'callsign' => "ROT10{$i}",
                'first_seen' => now()->subHours(2),
                'last_seen' => now(),
                'observed_at' => now(),
                'gate_status' => $status,
            ]);
        }

        $response = $this->getJson(route('airports.list', $this->dataTablesQuery(['search' => ['value' => 'lrop']])))
            ->assertOk()
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.icao_code', 'LROP')
            ->assertJsonPath('data.0.gates_count', 20)
            ->assertJsonPath('data.0.arrivals_count', 2)
            ->assertJsonPath('data.0.unallocated_count', 1);

        $this->assertStringContainsString('href="'.route('airports.show', Airport::findByCode('LROP')).'"', $response->json('data.0.name'));
        $this->assertSame(route('flights.index', ['airport' => 'LROP']), $response->json('data.0.flights_url'));
        $this->assertSame(route('gates.index', ['airport' => 'LROP']), $response->json('data.0.gates_url'));
    }

    public function test_airports_can_be_sorted_by_their_counts(): void
    {
        Flight::create([
            'airport_id' => Airport::findByCode('LOWW')->id,
            'direction' => Flight::DEPARTURE,
            'icao24' => 'abc123',
            'first_seen' => now(),
            'last_seen' => now()->addHour(),
            'observed_at' => now(),
        ]);

        $this->getJson(route('airports.list', $this->dataTablesQuery(['order' => [['column' => 3, 'dir' => 'desc']]])))
            ->assertOk()
            ->assertJsonPath('data.0.icao_code', 'LOWW');
    }
}
