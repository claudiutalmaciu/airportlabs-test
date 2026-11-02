<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\AllocationException;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateRestriction;
use App\Services\Allocation\GateAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GateRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private Airport $airport;

    private Gate $a1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->airport = Airport::findByCode('EDDF');
        $this->a1 = $this->airport->gates()->where('code', 'A1')->first();
    }

    private function arrival(string $callsign, string $landedAt): Flight
    {
        return Flight::create([
            'airport_id' => $this->airport->id,
            'direction' => Flight::ARRIVAL,
            'icao24' => substr(md5($callsign), 0, 6),
            'callsign' => $callsign,
            'first_seen' => now()->parse($landedAt)->subHours(2),
            'last_seen' => $landedAt,
            'observed_at' => now(),
        ]);
    }

    public function test_closing_a_gate_moves_flights_already_booked_on_it(): void
    {
        // Both land before the closure is added and get gate A1 (10:00-11:30 and 12:00-13:30).
        $first = $this->arrival('DLH1', '2025-01-10 10:00');
        $second = $this->arrival('DLH2', '2025-01-10 12:00');
        app(GateAllocator::class)->allocatePending();
        $this->assertSame('A1', $first->fresh()->allocation->gate->code);

        $this->post(route('gates.restrictions.create', $this->a1), [
            'starts_at' => '2025-01-10T09:00',
            'ends_at' => '2025-01-10T11:00',
            'reason' => 'Jet bridge repairs',
        ])->assertRedirect(route('gates.show', [$this->a1, 'date' => '2025-01-10']))
            ->assertSessionHas('restriction_result', fn (array $result) => $result['moved'][0]['callsign'] === 'DLH1'
                && $result['moved'][0]['gate'] === 'A2'
                && $result['dropped'] === []);

        $this->assertSame(1, $this->a1->restrictions()->where('reason', 'Jet bridge repairs')->count());
        // Only the flight overlapping the closure moves; the later one stays on A1.
        $this->assertSame('A2', $first->fresh()->allocation->gate->code);
        $this->assertSame('A1', $second->fresh()->allocation->gate->code);
        $this->assertSame(1, AllocationException::where('type', AllocationException::RESTRICTION_CONFLICT)->count());
    }

    public function test_a_flight_loses_its_gate_when_no_other_gate_is_free(): void
    {
        $flight = $this->arrival('DLH1', '2025-01-10 10:00');
        app(GateAllocator::class)->allocatePending();

        // Every other gate is already closed for that window.
        $this->airport->gates()->whereKeyNot($this->a1->id)->each(fn (Gate $gate) => GateRestriction::create([
            'gate_id' => $gate->id, 'starts_at' => '2025-01-10 00:00', 'ends_at' => '2025-01-11 00:00', 'reason' => 'Closed',
        ]));

        $this->post(route('gates.restrictions.create', $this->a1), [
            'starts_at' => '2025-01-10T09:00',
            'ends_at' => '2025-01-10T12:00',
            'reason' => 'Repairs',
        ])->assertSessionHas('restriction_result', fn (array $result) => $result['dropped'][0]['callsign'] === 'DLH1');

        $this->assertNull($flight->fresh()->allocation);
        $this->assertSame(Flight::STATUS_UNALLOCATED, $flight->fresh()->gate_status);
    }

    public function test_the_gate_page_shows_the_form_and_what_happened(): void
    {
        $this->arrival('DLH1', '2025-01-10 10:00');
        app(GateAllocator::class)->allocatePending();

        $this->get(route('gates.show', $this->a1))
            ->assertOk()
            ->assertSee('action="'.route('gates.restrictions.create', $this->a1).'"', false)
            ->assertSee('name="_token"', false);

        $this->followingRedirects()->post(route('gates.restrictions.create', $this->a1), [
            'starts_at' => '2025-01-10T09:00',
            'ends_at' => '2025-01-10T11:00',
            'reason' => 'Repairs',
        ])->assertOk()
            ->assertSee('Gate A1 closed from 10 Jan 09:00 until 10 Jan 11:00 UTC.')
            ->assertSeeInOrder(['DLH1', 'moved to gate', 'A2']);
    }

    public function test_invalid_closures_are_rejected(): void
    {
        $this->post(route('gates.restrictions.create', $this->a1), [
            'starts_at' => '2025-01-10T12:00',
            'ends_at' => '2025-01-10T11:00',
            'reason' => '',
        ])->assertSessionHasErrors(['ends_at', 'reason']);

        $this->assertSame(0, $this->a1->restrictions()->count());
    }

    public function test_the_command_uses_the_same_logic(): void
    {
        $flight = $this->arrival('DLH1', '2025-01-10 10:00');
        app(GateAllocator::class)->allocatePending();

        $this->artisan('gates:restrict', ['gate' => 'A1', 'starts' => '2025-01-10 09:00', 'ends' => '2025-01-10 11:00', '--airport' => 'EDDF'])
            ->expectsOutputToContain('DLH1 moved to gate A2')
            ->assertSuccessful();

        $this->assertSame('A2', $flight->fresh()->allocation->gate->code);
    }
}
