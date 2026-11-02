<?php

namespace App\Services\Allocation;

use App\Models\Airport;
use App\Models\AllocationException;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateRestriction;
use App\Models\ValidationReport;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The numbers behind the stat tiles and the validation reports.
 *
 * Two time frames: flight counts cover every flight stored, while gate counts describe what the gates are
 * doing at one moment ("right now" by default).
 */
final class AllocationStats
{
    /**
     * Statistics for one airport, or for all airports together when none is given.
     *
     * @param  Airport|null  $airport  one airport, or null for every airport
     * @param  CarbonInterface|null  $at  the moment the gate counts describe; defaults to now
     * @return array{
     *     at: string,
     *     airport: ?string,
     *     flights: array<string, mixed>,
     *     gates: array{total: int, occupied: int, restricted: int, free: int},
     *     exceptions_since_last_report: array<string, int>
     * }
     */
    public function snapshot(?Airport $airport = null, ?CarbonInterface $at = null): array
    {
        $at ??= now();
        $since = ValidationReport::latest('id')->value('created_at');

        // Scopes a query to the airport when one is given; with no airport it covers everything.
        $forAirport = fn (Builder $query, string $column = 'airport_id') => $query->when($airport, fn ($q) => $q->where($column, $airport->id));
        $airportGates = Gate::query()->when($airport, fn ($q) => $q->where('airport_id', $airport->id))->select('id');

        $totalGates = $forAirport(Gate::query())->count();
        $occupiedNow = GateAllocation::whereIn('gate_id', $airportGates)->where('starts_at', '<=', $at)->where('ends_at', '>', $at);
        $occupied = (clone $occupiedNow)->distinct()->count('gate_id');
        // A gate that is closed but still has a booking counts once, as occupied, so the three numbers add up to the total.
        $restricted = GateRestriction::whereIn('gate_id', $airportGates)->where('starts_at', '<=', $at)->where('ends_at', '>', $at)
            ->whereNotIn('gate_id', (clone $occupiedNow)->select('gate_id'))
            ->distinct()->count('gate_id');

        $byStatus = $forAirport(Flight::query())->selectRaw('direction, gate_status, COUNT(*) as total')
            ->groupBy('direction', 'gate_status')->get();

        // Every direction/status pair, with 0 for combinations that have no flights.
        $flights = [];
        foreach ([Flight::ARRIVAL, Flight::DEPARTURE] as $direction) {
            foreach ([Flight::STATUS_ALLOCATED, Flight::STATUS_UNALLOCATED, Flight::STATUS_PENDING] as $status) {
                $flights[$direction][$status] = (int) ($byStatus->first(
                    fn ($row) => $row->direction === $direction && $row->gate_status === $status
                )?->total ?? 0);
            }
        }

        return [
            'at' => $at->toIso8601String(),
            'airport' => $airport?->icao_code,
            'flights' => $flights + [
                'total' => array_sum(array_map('array_sum', $flights)),
                'allocated' => $flights[Flight::ARRIVAL][Flight::STATUS_ALLOCATED] + $flights[Flight::DEPARTURE][Flight::STATUS_ALLOCATED],
                'unallocated' => $flights[Flight::ARRIVAL][Flight::STATUS_UNALLOCATED] + $flights[Flight::DEPARTURE][Flight::STATUS_UNALLOCATED],
                'pending' => $flights[Flight::ARRIVAL][Flight::STATUS_PENDING] + $flights[Flight::DEPARTURE][Flight::STATUS_PENDING],
            ],
            'gates' => [
                'total' => $totalGates,
                'occupied' => $occupied,
                'restricted' => $restricted,
                'free' => $totalGates - $occupied - $restricted,
            ],
            'exceptions_since_last_report' => $forAirport(AllocationException::query())
                ->when($since, fn ($query) => $query->where('created_at', '>', $since))
                ->selectRaw('type, COUNT(*) as total')->groupBy('type')
                ->pluck('total', 'type')->map(fn ($n) => (int) $n)->all(),
        ];
    }
}
