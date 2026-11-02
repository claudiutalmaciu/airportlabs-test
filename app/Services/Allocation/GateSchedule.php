<?php

namespace App\Services\Allocation;

/**
 * In-memory view of which gates are busy when. Holds no database state,
 * so the allocation rules can be unit tested on their own.
 */
final class GateSchedule
{
    /**
     * Booked windows per gate id.
     *
     * @var array<int, list<TimeWindow>>
     */
    private array $occupied = [];

    /**
     * Closure windows per gate id.
     *
     * @var array<int, list<TimeWindow>>
     */
    private array $restricted = [];

    /**
     * @param  list<int>  $gateIds  in the order gates should be tried
     */
    public function __construct(private readonly array $gateIds)
    {
        foreach ($gateIds as $gateId) {
            $this->occupied[$gateId] = [];
            $this->restricted[$gateId] = [];
        }
    }

    /**
     * Records a booking on a gate.
     */
    public function occupy(int $gateId, TimeWindow $window): void
    {
        $this->occupied[$gateId][] = $window;
    }

    /**
     * Records a closure on a gate.
     */
    public function restrict(int $gateId, TimeWindow $window): void
    {
        $this->restricted[$gateId][] = $window;
    }

    /**
     * Whether the gate has no booking and no closure overlapping the window. Unknown gates are never free.
     */
    public function isFree(int $gateId, TimeWindow $window): bool
    {
        if (! array_key_exists($gateId, $this->occupied)) {
            return false;
        }

        foreach ([...$this->occupied[$gateId], ...$this->restricted[$gateId]] as $busy) {
            if ($busy->overlaps($window)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The first free gate in the order given to the constructor, without booking it.
     */
    public function firstFreeGate(TimeWindow $window): ?int
    {
        foreach ($this->gateIds as $gateId) {
            if ($this->isFree($gateId, $window)) {
                return $gateId;
            }
        }

        return null;
    }

    /**
     * Finds a free gate and marks it busy for the window. Returns null when every gate is taken.
     */
    public function reserve(TimeWindow $window): ?int
    {
        $gateId = $this->firstFreeGate($window);

        if ($gateId !== null) {
            $this->occupy($gateId, $window);
        }

        return $gateId;
    }
}
