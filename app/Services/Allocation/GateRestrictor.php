<?php

namespace App\Services\Allocation;

use App\Models\AllocationException;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateRestriction;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Closes a gate for a period, then re-runs allocation for the flights booked on it during that period, so no flight
 * stays on a closed gate even if it was allocated before the closure was added.
 */
final class GateRestrictor
{
    /**
     * @param  GateAllocator  $allocator  moves affected flights with the same rules as normal allocation
     */
    public function __construct(private readonly GateAllocator $allocator) {}

    /**
     * Closes the gate and moves every flight booked on it during the closure. Used by the "Close gate" form
     * and by gates:restrict, so both behave the same.
     *
     * @return array{restriction: GateRestriction, moved: list<array{flight: Flight, gate: Gate}>, dropped: list<Flight>}
     *                                                                                                                    the new closure, flights moved to another gate, and flights left without a gate
     */
    public function restrict(Gate $gate, DateTimeInterface $startsAt, DateTimeInterface $endsAt, string $reason): array
    {
        return DB::transaction(function () use ($gate, $startsAt, $endsAt, $reason) {
            // Same lock as an allocation run, so a scheduled run cannot book this gate while flights are moved off it.
            Gate::where('airport_id', $gate->airport_id)->lockForUpdate()->get(['id']);

            $restriction = $gate->restrictions()->create([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reason' => $reason,
            ]);

            $result = ['restriction' => $restriction, 'moved' => [], 'dropped' => []];

            $affected = $gate->allocations()->with(['flight', 'gate'])
                ->overlapping($startsAt, $endsAt)
                ->orderBy('starts_at')
                ->get();

            /** @var GateAllocation $allocation */
            foreach ($affected as $allocation) {
                $flight = $allocation->flight;
                $newGate = $this->allocator->relocate($allocation, AllocationException::RESTRICTION_CONFLICT);

                if ($newGate) {
                    $result['moved'][] = ['flight' => $flight, 'gate' => $newGate];
                } else {
                    $result['dropped'][] = $flight->refresh();
                }
            }

            return $result;
        });
    }
}
