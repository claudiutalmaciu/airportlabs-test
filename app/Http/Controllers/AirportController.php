<?php

namespace App\Http\Controllers;

use App\Models\Airport;
use App\Models\Flight;
use App\Models\Gate;
use App\Services\Allocation\AllocationStats;
use App\Services\OpenSky\LiveAircraft;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AirportController
{
    public function index(AllocationStats $stats): View
    {
        $now = now();

        return view('airports.index', [
            'totals' => [
                'airports' => Airport::count(),
                'gates' => Gate::count(),
            ],
            'busiest' => Airport::withCount('flights')->orderByDesc('flights_count')->orderBy('name')->first(),
            'mostGatesInUse' => Airport::withCount(['gates as gates_in_use_count' => fn (Builder $query) => $query->whereHas(
                'allocations', fn (Builder $allocations) => $allocations->where('starts_at', '<=', $now)->where('ends_at', '>', $now),
            )])->orderByDesc('gates_in_use_count')->orderBy('name')->first(),
            'stats' => $stats->snapshot(),
        ]);
    }

    /**
     * Server-side DataTables endpoint for the airports list, with per-airport counts.
     */
    public function airportsList(): JsonResponse
    {
        $now = now();

        $query = Airport::query()->withCount([
            'gates',
            'flights',
            'flights as arrivals_count' => fn (Builder $query) => $query->where('direction', Flight::ARRIVAL),
            'flights as departures_count' => fn (Builder $query) => $query->where('direction', Flight::DEPARTURE),
            'flights as unallocated_count' => fn (Builder $query) => $query->where('gate_status', Flight::STATUS_UNALLOCATED),
            'gates as gates_in_use_count' => fn (Builder $query) => $query->whereHas(
                'allocations', fn (Builder $allocations) => $allocations->where('starts_at', '<=', $now)->where('ends_at', '>', $now),
            ),
        ]);

        return DataTables::eloquent($query)
            ->editColumn('name', fn (Airport $airport) => sprintf(
                '<a href="%s">%s</a> <span class="muted mono">(%s)</span>',
                route('airports.show', $airport),
                e($airport->name),
                e($airport->icao_code),
            ))
            ->addColumn('flights_url', fn (Airport $airport) => route('flights.index', ['airport' => $airport->icao_code]))
            ->addColumn('gates_url', fn (Airport $airport) => route('gates.index', ['airport' => $airport->icao_code]))
            // Searching the airport column matches its name or its ICAO code.
            ->filterColumn('name', fn (Builder $query, string $keyword) => $query->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$keyword}%")
                ->orWhere('icao_code', 'like', "%{$keyword}%")))
            // Aggregates are not real columns, so sort on their aliases directly.
            ->orderColumns(
                ['gates_count', 'flights_count', 'arrivals_count', 'departures_count', 'unallocated_count', 'gates_in_use_count'],
                ':column $1',
            )
            ->rawColumns(['name'])
            ->toJson();
    }

    /**
     * One airport at a glance: its numbers, its gates right now, its latest flights and a link to all of them.
     */
    public function show(Airport $airport, AllocationStats $stats): View
    {
        $now = now();
        $activeNow = fn (Builder $query) => $query->where('starts_at', '<=', $now)->where('ends_at', '>', $now);

        $gates = $airport->gates()
            ->withExists(['allocations as in_use' => $activeNow, 'restrictions as closed' => $activeNow])
            ->get()
            ->sortBy(fn (Gate $gate) => sprintf('%s%04d', $gate->terminal, (int) substr($gate->code, 1)))
            ->groupBy('terminal');

        $latest = fn (string $direction) => $airport->flights()
            ->with('allocation.gate')
            ->where('direction', $direction)
            ->orderByDesc($direction === Flight::ARRIVAL ? 'last_seen' : 'first_seen')
            ->limit(5)
            ->get();

        return view('airports.show', [
            'airport' => $airport,
            'stats' => $stats->snapshot($airport),
            'gatesByTerminal' => $gates,
            'latestArrivals' => $latest(Flight::ARRIVAL),
            'latestDepartures' => $latest(Flight::DEPARTURE),
            'topAirlines' => $airport->flights()
                ->whereNotNull('airline_code')
                ->selectRaw('airline_code, MAX(airline_name) as airline_name, COUNT(*) as total')
                ->groupBy('airline_code')
                ->orderByDesc('total')
                ->limit(6)
                ->get(),
        ]);
    }

    /**
     * Live aircraft around the airport, polled by the map on the flights page.
     */
    public function aircraft(Airport $airport, LiveAircraft $liveAircraft): JsonResponse
    {
        abort_unless($airport->hasCoordinates(), 404, 'This airport has no coordinates.');

        return response()->json($liveAircraft->around($airport) + [
            'refresh_seconds' => config('gates.live_map_refresh_seconds'),
        ]);
    }
}
