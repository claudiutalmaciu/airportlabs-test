<?php

namespace App\Services\Allocation;

use App\Models\AllocationException;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateRestriction;
use Illuminate\Support\Facades\DB;

/**
 * Re-checks stored allocations against the rules and repairs what it can. Run every 15 minutes by
 * allocations:validate, as a safety net behind the allocator and gate closures.
 */
final class AllocationValidator
{
    /**
     * @param  GateAllocator  $allocator  used to move a flight off a gate, with the same rules as normal allocation
     */
    public function __construct(private readonly GateAllocator $allocator) {}

    /**
     * Finds and repairs bookings that break the rules, holding every gate lock while it works.
     *
     * @return array{overlaps: int, restriction_conflicts: int, reallocated: int, dropped: int, wrong_duration: int}
     *                                                                                                               what was found and what happened to it; wrong_duration is only counted, never changed
     */
    public function run(): array
    {
        return DB::transaction(function () {
            // Lock every gate so no allocation run or gate closure changes bookings while they are checked.
            Gate::lockForUpdate()->get(['id']);

            $issues = ['overlaps' => 0, 'restriction_conflicts' => 0, 'reallocated' => 0, 'dropped' => 0, 'wrong_duration' => 0];

            // Restrictions can be added after flights were allocated (e.g. a gate suddenly closes for repairs).
            foreach ($this->restrictionConflicts() as $allocation) {
                $issues['restriction_conflicts']++;
                $this->repair($allocation, AllocationException::RESTRICTION_CONFLICT, $issues);
            }

            // Should never happen thanks to the gate locks; kept as a safety net.
            foreach ($this->overlappingAllocations() as $allocation) {
                $issues['overlaps']++;
                $this->repair($allocation, AllocationException::OVERLAP, $issues);
            }

            // Bookings whose length differs from the configured occupancy, e.g. after GATE_OCCUPANCY_MINUTES changed.
            $issues['wrong_duration'] = GateAllocation::query()
                ->whereRaw('TIMESTAMPDIFF(MINUTE, starts_at, ends_at) <> ?', [config('gates.occupancy_minutes')])
                ->count();

            return $issues;
        });
    }

    /**
     * Bookings whose window overlaps a closure on the same gate.
     *
     * @return iterable<GateAllocation>
     */
    private function restrictionConflicts(): iterable
    {
        return GateAllocation::query()
            ->whereExists(fn ($query) => $query->from((new GateRestriction)->getTable(), 'r')
                ->whereColumn('r.gate_id', 'gate_allocations.gate_id')
                ->whereColumn('r.starts_at', '<', 'gate_allocations.ends_at')
                ->whereColumn('r.ends_at', '>', 'gate_allocations.starts_at'))
            ->with(['flight', 'gate'])
            ->get();
    }

    /**
     * The later allocation of every overlapping pair on the same gate.
     *
     * @return iterable<GateAllocation>
     */
    private function overlappingAllocations(): iterable
    {
        return GateAllocation::query()
            ->whereExists(fn ($query) => $query->from('gate_allocations', 'other')
                ->whereColumn('other.gate_id', 'gate_allocations.gate_id')
                ->whereColumn('other.id', '<', 'gate_allocations.id')
                ->whereColumn('other.starts_at', '<', 'gate_allocations.ends_at')
                ->whereColumn('other.ends_at', '>', 'gate_allocations.starts_at'))
            ->with(['flight', 'gate'])
            ->get();
    }

    /**
     * Moves one bad booking to another gate (or drops it) and counts the outcome.
     *
     * @param  string  $type  the AllocationException type to log
     * @param  array{overlaps: int, restriction_conflicts: int, reallocated: int, dropped: int, wrong_duration: int}  $issues
     */
    private function repair(GateAllocation $allocation, string $type, array &$issues): void
    {
        $this->allocator->relocate($allocation, $type) ? $issues['reallocated']++ : $issues['dropped']++;
    }
}
