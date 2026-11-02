<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\AllocationException;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateRestriction;
use App\Models\ValidationReport;
use App\Services\Allocation\AllocationValidator;
use App\Services\Allocation\GateAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllocationValidatorTest extends TestCase
{
    use RefreshDatabase;

    private function allocatedArrival(string $landedAt): GateAllocation
    {
        Flight::create([
            'airport_id' => Airport::findByCode('EDDF')->id,
            'direction' => Flight::ARRIVAL,
            'icao24' => substr(md5($landedAt.microtime()), 0, 6),
            'callsign' => 'DLH1',
            'first_seen' => now()->parse($landedAt)->subHour(),
            'last_seen' => $landedAt,
            'observed_at' => now(),
        ]);

        app(GateAllocator::class)->allocatePending();

        return GateAllocation::latest('id')->first();
    }

    public function test_an_allocation_hit_by_a_new_restriction_is_moved_to_another_gate(): void
    {
        $allocation = $this->allocatedArrival('2025-01-10 10:00');
        $originalGate = $allocation->gate_id;

        GateRestriction::create(['gate_id' => $originalGate, 'starts_at' => '2025-01-10', 'ends_at' => '2025-01-12', 'reason' => 'Repairs']);

        $issues = app(AllocationValidator::class)->run();

        $this->assertSame(1, $issues['restriction_conflicts']);
        $this->assertSame(1, $issues['reallocated']);
        $this->assertNotSame($originalGate, $allocation->fresh()->gate_id);
        $this->assertSame(1, AllocationException::where('type', AllocationException::RESTRICTION_CONFLICT)->count());
    }

    public function test_an_overlap_planted_in_the_database_is_detected_and_fixed(): void
    {
        $first = $this->allocatedArrival('2025-01-10 10:00');
        $second = $this->allocatedArrival('2025-01-10 10:30');
        $second->update(['gate_id' => $first->gate_id]);

        $issues = app(AllocationValidator::class)->run();

        $this->assertSame(1, $issues['overlaps']);
        $this->assertNotSame($first->gate_id, $second->fresh()->gate_id);
    }

    public function test_the_allocation_is_dropped_when_no_other_gate_is_free(): void
    {
        $allocation = $this->allocatedArrival('2025-01-10 10:00');

        Airport::findByCode('EDDF')->gates->each(fn (Gate $gate) => GateRestriction::create([
            'gate_id' => $gate->id, 'starts_at' => '2025-01-10', 'ends_at' => '2025-01-11', 'reason' => 'Airport closed',
        ]));

        $issues = app(AllocationValidator::class)->run();

        $this->assertSame(1, $issues['dropped']);
        $this->assertNull($allocation->fresh());
        $this->assertSame(Flight::STATUS_UNALLOCATED, $allocation->flight->fresh()->gate_status);
    }

    public function test_the_command_stores_a_report_with_statistics(): void
    {
        $this->allocatedArrival('2025-01-10 10:00');

        $this->artisan('allocations:validate')->assertSuccessful();

        $stats = ValidationReport::sole()->stats;
        $this->assertSame(1, $stats['totals']['flights']['allocated']);
        $this->assertSame(1, $stats['airports']['EDDF']['flights']['allocated']);
        $this->assertSame(20, $stats['airports']['EDDF']['gates']['total']);
        $this->assertSame(0, $stats['airports']['LROP']['flights']['total']);
        $this->assertSame(0, $stats['issues']['overlaps']);
    }
}
