<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\AllocationException;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateRestriction;
use App\Services\Allocation\GateAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GateAllocatorTest extends TestCase
{
    use RefreshDatabase;

    private function arrival(string $landedAt, string $callsign = 'DLH100', string $airport = 'EDDF'): Flight
    {
        $landed = CarbonImmutable::parse($landedAt);

        return Flight::create([
            'airport_id' => Airport::findByCode($airport)->id,
            'direction' => Flight::ARRIVAL,
            'icao24' => substr(md5($callsign.$landedAt), 0, 6),
            'callsign' => $callsign,
            'first_seen' => $landed->subHours(2),
            'last_seen' => $landed,
            'observed_at' => now(),
        ]);
    }

    public function test_the_migrations_create_airports_with_twenty_gates_each(): void
    {
        $eddf = Airport::findByCode('EDDF');

        $this->assertSame('Frankfurt Airport', $eddf->name);
        $this->assertSame(20, $eddf->gates()->count());
        $this->assertTrue($eddf->gates()->where('code', 'B8')->exists());
        $this->assertSame(Airport::count() * 20, Gate::count());
    }

    public function test_each_airport_uses_its_own_gates(): void
    {
        $frankfurt = $this->arrival('2025-01-09 10:00', 'DLH1', 'EDDF');
        $bucharest = $this->arrival('2025-01-09 10:00', 'ROT1', 'LROP');

        app(GateAllocator::class)->allocatePending();

        $this->assertSame('EDDF', $frankfurt->fresh()->allocation->gate->airport->icao_code);
        $this->assertSame('LROP', $bucharest->fresh()->allocation->gate->airport->icao_code);
        // Same window, but no conflict: both get gate A1 of their own airport.
        $this->assertSame('A1', $frankfurt->fresh()->allocation->gate->code);
        $this->assertSame('A1', $bucharest->fresh()->allocation->gate->code);
    }

    public function test_it_can_allocate_a_single_airport(): void
    {
        $this->arrival('2025-01-09 10:00', 'DLH1', 'EDDF');
        $bucharest = $this->arrival('2025-01-09 10:00', 'ROT1', 'LROP');

        $result = app(GateAllocator::class)->allocatePending(Airport::findByCode('EDDF'));

        $this->assertSame(['allocated' => 1, 'unallocated' => 0], $result);
        $this->assertSame(Flight::STATUS_PENDING, $bucharest->fresh()->gate_status);
    }

    public function test_pending_flights_get_a_ninety_minute_gate_window(): void
    {
        $flight = $this->arrival('2025-01-09 10:00');

        $result = app(GateAllocator::class)->allocatePending();

        $this->assertSame(['allocated' => 1, 'unallocated' => 0], $result);
        $allocation = $flight->fresh()->allocation;
        $this->assertSame(Flight::STATUS_ALLOCATED, $flight->fresh()->gate_status);
        $this->assertEquals(CarbonImmutable::parse('2025-01-09 10:00'), $allocation->starts_at);
        $this->assertEquals(CarbonImmutable::parse('2025-01-09 11:30'), $allocation->ends_at);
    }

    public function test_overlapping_flights_never_share_a_gate(): void
    {
        foreach (range(0, 9) as $i) {
            $this->arrival(CarbonImmutable::parse('2025-01-09 10:00')->addMinutes($i * 5)->toDateTimeString(), "DLH{$i}");
        }

        app(GateAllocator::class)->allocatePending();

        $this->assertSame(10, GateAllocation::distinct()->count('gate_id'));
    }

    public function test_a_flight_is_not_placed_on_a_gate_under_repair(): void
    {
        $b8 = Airport::findByCode('EDDF')->gates()->where('code', 'B8')->first();
        GateRestriction::create(['gate_id' => $b8->id, 'starts_at' => '2025-01-10', 'ends_at' => '2025-01-12', 'reason' => 'Repairs']);

        // Fill all other gates so B8 would be the only candidate.
        foreach (range(1, 20) as $i) {
            $this->arrival('2025-01-10 12:00', "DLH{$i}");
        }

        $result = app(GateAllocator::class)->allocatePending();

        $this->assertSame(['allocated' => 19, 'unallocated' => 1], $result);
        $this->assertFalse(GateAllocation::where('gate_id', $b8->id)->exists());
        $this->assertSame(1, AllocationException::where('type', AllocationException::NO_GATE_AVAILABLE)->count());
    }

    public function test_running_twice_does_not_allocate_a_flight_again(): void
    {
        $this->arrival('2025-01-09 10:00');

        app(GateAllocator::class)->allocatePending();
        $second = app(GateAllocator::class)->allocatePending();

        $this->assertSame(['allocated' => 0, 'unallocated' => 0], $second);
        $this->assertSame(1, GateAllocation::count());
    }
}
