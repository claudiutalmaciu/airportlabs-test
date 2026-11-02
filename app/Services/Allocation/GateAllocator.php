<?php

namespace App\Services\Allocation;

use App\Models\Airport;
use App\Models\AllocationException;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateRestriction;
use Illuminate\Support\Facades\DB;

/**
 * Books gates for flights. Every change to bookings goes through here: the scheduled allocation run,
 * gate closures (via GateRestrictor) and the validator's repairs, so they all follow the same rules.
 *
 * Allocation is greedy: flights are handled in order of their window start, and each takes the first
 * gate (in gate order: A1, A2 …) that has no overlapping booking or closure during its window.
 */
final class GateAllocator
{
    /**
     * @param  GateWindowCalculator  $windows  decides each flight's moment T and how long it holds a gate
     */
    public function __construct(private readonly GateWindowCalculator $windows) {}

    /**
     * Assigns every pending ("Awaiting gate") flight to a free gate of its own airport.
     *
     * Each airport is handled in its own transaction, so airports never wait on each other's locks.
     *
     * @param  Airport|null  $airport  only this airport, or every airport that has pending flights
     * @return array{allocated: int, unallocated: int} how many flights got a gate and how many found none free
     */
    public function allocatePending(?Airport $airport = null): array
    {
        $airportIds = $airport ? [$airport->id] : Flight::where('gate_status', Flight::STATUS_PENDING)->distinct()->pluck('airport_id')->all();

        $total = ['allocated' => 0, 'unallocated' => 0];

        foreach ($airportIds as $airportId) {
            $result = $this->allocateAirport($airportId);
            $total['allocated'] += $result['allocated'];
            $total['unallocated'] += $result['unallocated'];
        }

        return $total;
    }

    /**
     * Allocates one airport's pending flights in a single transaction.
     *
     * Steps: lock the airport's gates, work out each flight's window, sort by window start, load the bookings
     * and closures already in that time range, then give each flight the first free gate or mark it unallocated.
     *
     * @return array{allocated: int, unallocated: int}
     */
    private function allocateAirport(int $airportId): array
    {
        return DB::transaction(function () use ($airportId) {
            // Locking the airport's gate rows serialises allocation runs, so two runs can never book the same gate.
            $gateIds = Gate::where('airport_id', $airportId)->orderBy('id')->lockForUpdate()->pluck('id')->all();

            // Pair each flight with its gate window, earliest window first, so earlier flights choose first.
            $flights = Flight::where('airport_id', $airportId)->where('gate_status', Flight::STATUS_PENDING)->get()
                ->map(fn (Flight $flight) => [$flight, $this->windows->windowFor($flight)])
                ->sortBy(fn (array $pair) => $pair[1]->start->getTimestamp())
                ->values();

            if ($flights->isEmpty()) {
                return ['allocated' => 0, 'unallocated' => 0];
            }

            // Only bookings and closures between the earliest start and the latest end can clash with these flights.
            $schedule = $this->scheduleCovering($gateIds, new TimeWindow($flights->first()[1]->start, $flights->max(fn (array $pair) => $pair[1]->end)));

            $result = ['allocated' => 0, 'unallocated' => 0];

            foreach ($flights as [$flight, $window]) {
                // reserve() also marks the gate busy in memory, so later flights in this run see it as taken.
                $gateId = $schedule->reserve($window);

                if ($gateId === null) {
                    $this->markUnallocated($flight, $window);
                    $result['unallocated']++;

                    continue;
                }

                GateAllocation::create([
                    'flight_id' => $flight->id,
                    'gate_id' => $gateId,
                    'starts_at' => $window->start,
                    'ends_at' => $window->end,
                ]);
                $flight->update(['gate_status' => Flight::STATUS_ALLOCATED]);
                $result['allocated']++;
            }

            return $result;
        });
    }

    /**
     * Takes an allocation off its gate (because the gate closed or clashes) and records why: the flight moves to
     * another free gate of the same airport, or loses its gate when none is free.
     *
     * Must run inside a transaction that holds the airport's gate locks.
     *
     * @param  string  $reason  the AllocationException type to log, e.g. restriction_conflict or overlap
     * @return Gate|null the gate the flight moved to, or null when it lost its gate
     */
    public function relocate(GateAllocation $allocation, string $reason): ?Gate
    {
        $fromGate = $allocation->gate;
        $flight = $allocation->flight;
        $callsign = $flight->callsign ?? $flight->icao24;
        $newGate = ($newGateId = $this->reallocate($allocation)) ? Gate::find($newGateId) : null;

        AllocationException::create([
            'airport_id' => $flight->airport_id,
            'flight_id' => $flight->id,
            'gate_id' => $fromGate->id,
            'type' => $reason,
            'message' => "{$callsign} at gate {$fromGate->code}: ".($newGate ? "moved to gate {$newGate->code}" : 'no other gate was free, allocation dropped').'.',
            'context' => ['from_gate' => $fromGate->code, 'to_gate_id' => $newGate?->id],
        ]);

        return $newGate;
    }

    /**
     * Moves an allocation to another free gate, or drops it when none is free. Keeps the flight's window:
     * only the gate changes, never the time.
     *
     * Must run inside a transaction that holds the airport's gate locks.
     *
     * @return int|null the new gate's id, or null when the allocation was dropped
     */
    public function reallocate(GateAllocation $allocation): ?int
    {
        $window = new TimeWindow($allocation->starts_at, $allocation->ends_at);
        $gateIds = Gate::where('airport_id', $allocation->gate->airport_id)->orderBy('id')->pluck('id')->all();

        // Leave this allocation out of the picture, otherwise it would block itself. Its current gate is still a
        // candidate in principle, but the closure or clash that triggered the move keeps it from being free.
        $schedule = $this->scheduleCovering($gateIds, $window, exceptAllocationId: $allocation->id);

        $gateId = $schedule->firstFreeGate($window);

        if ($gateId === null) {
            $flight = $allocation->flight;
            $allocation->delete();
            $this->markUnallocated($flight, $window);

            return null;
        }

        $allocation->update(['gate_id' => $gateId]);

        return $gateId;
    }

    /**
     * Builds the in-memory schedule for some gates: every booking and closure on them that overlaps the range.
     *
     * @param  list<int>  $gateIds  the gates to consider, in the order they should be tried
     * @param  TimeWindow  $range  only bookings and closures overlapping this range are loaded
     * @param  int|null  $exceptAllocationId  a booking to leave out (the one being moved)
     */
    private function scheduleCovering(array $gateIds, TimeWindow $range, ?int $exceptAllocationId = null): GateSchedule
    {
        $schedule = new GateSchedule($gateIds);

        GateAllocation::overlapping($range->start, $range->end)
            ->whereIn('gate_id', $gateIds)
            ->when($exceptAllocationId, fn ($query) => $query->whereKeyNot($exceptAllocationId))
            ->get(['gate_id', 'starts_at', 'ends_at'])
            ->each(fn (GateAllocation $a) => $schedule->occupy($a->gate_id, new TimeWindow($a->starts_at, $a->ends_at)));

        GateRestriction::overlapping($range->start, $range->end)
            ->whereIn('gate_id', $gateIds)
            ->get(['gate_id', 'starts_at', 'ends_at'])
            ->each(fn (GateRestriction $r) => $schedule->restrict($r->gate_id, new TimeWindow($r->starts_at, $r->ends_at)));

        return $schedule;
    }

    /**
     * Marks a flight "No gate available" and logs a no_gate_available event for the Reports page.
     */
    private function markUnallocated(Flight $flight, TimeWindow $window): void
    {
        $flight->update(['gate_status' => Flight::STATUS_UNALLOCATED]);

        AllocationException::create([
            'airport_id' => $flight->airport_id,
            'flight_id' => $flight->id,
            'type' => AllocationException::NO_GATE_AVAILABLE,
            'message' => "No free gate for {$flight->callsign} between {$window->start} and {$window->end}.",
            'context' => ['starts_at' => $window->start->toIso8601String(), 'ends_at' => $window->end->toIso8601String()],
        ]);
    }
}
