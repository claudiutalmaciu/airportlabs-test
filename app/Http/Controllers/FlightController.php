<?php

namespace App\Http\Controllers;

use App\Models\Airport;
use App\Models\Flight;
use App\Models\GateAllocation;
use App\Services\Allocation\AllocationStats;
use App\Services\OpenSky\FlightTrack;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class FlightController
{
    public function index(Request $request, AllocationStats $stats): View
    {
        $direction = $request->query('direction') === Flight::DEPARTURE ? Flight::DEPARTURE : Flight::ARRIVAL;
        $airport = Airport::findByCode($request->query('airport')) ?? Airport::default();

        abort_unless($airport, 404, 'No airports configured.');

        return view('flights.index', [
            'direction' => $direction,
            'airport' => $airport,
            'airports' => Airport::orderBy('name')->get(),
            'stats' => $stats->snapshot($airport),
        ]);
    }

    /**
     * Server-side DataTables endpoint: paging, sorting and search happen in MySQL.
     */
    public function flightsList(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'airport' => ['required', 'string', 'exists:airports,icao_code'],
            'direction' => ['required', Rule::in([Flight::ARRIVAL, Flight::DEPARTURE])],
            'status' => ['nullable', Rule::in([Flight::STATUS_ALLOCATED, Flight::STATUS_UNALLOCATED, Flight::STATUS_PENDING])],
        ]);

        $airport = Airport::findByCode($filters['airport']);
        // For arrivals the "other" airport is where the flight came from, for departures where it is going.
        $otherColumn = $filters['direction'] === Flight::ARRIVAL ? 'departure_airport' : 'arrival_airport';

        $query = Flight::query()
            ->leftJoin('gate_allocations as ga', 'ga.flight_id', '=', 'flights.id')
            ->leftJoin('gates as g', 'g.id', '=', 'ga.gate_id')
            ->leftJoin('airports as other', 'other.icao_code', '=', "flights.{$otherColumn}")
            ->where('flights.airport_id', $airport->id)
            ->where('flights.direction', $filters['direction'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('flights.gate_status', $status))
            ->select(
                'flights.*', 'g.code as gate_code', 'ga.gate_id', 'ga.starts_at as gate_starts_at', 'ga.ends_at as gate_ends_at',
                'other.name as other_airport_name',
            );

        return DataTables::eloquent($query)
            // "Paris Charles de Gaulle Airport (LFPG)"; airports we have no name for show just the code.
            ->editColumn($otherColumn, fn (Flight $flight) => match (true) {
                ! $flight->{$otherColumn} => null,
                ! $flight->other_airport_name => sprintf('<span class="mono">%s</span>', e($flight->{$otherColumn})),
                default => sprintf('%s <span class="muted mono">(%s)</span>', e($flight->other_airport_name), e($flight->{$otherColumn})),
            })
            // Sort by what is shown first: the name, falling back to the code.
            ->orderColumn($otherColumn, "COALESCE(other.name, flights.{$otherColumn}) \$1")
            // Searching the airport column matches both the code and the commercial name.
            ->filterColumn($otherColumn, fn ($query, $keyword) => $query->where(fn ($q) => $q
                ->where("flights.{$otherColumn}", 'like', "%{$keyword}%")
                ->orWhere('other.name', 'like', "%{$keyword}%")))
            ->editColumn('callsign', fn (Flight $flight) => sprintf(
                '<a href="%s" class="mono">%s</a>',
                route('flights.show', $flight),
                e($flight->callsign ?? $flight->icao24),
            ))
            ->editColumn('first_seen', fn (Flight $flight) => $flight->first_seen->format('Y-m-d H:i'))
            ->editColumn('last_seen', fn (Flight $flight) => $flight->last_seen->format('Y-m-d H:i'))
            ->editColumn('gate_starts_at', fn (Flight $flight) => $flight->gate_starts_at
                ? Carbon::parse($flight->gate_starts_at)->format('d M H:i').' – '.Carbon::parse($flight->gate_ends_at)->format('H:i')
                : null)
            ->editColumn('gate_status', fn (Flight $flight) => sprintf(
                '<span class="badge %1$s">%2$s</span>', e($flight->gate_status), e($flight->gate_status_label),
            ))
            // Searching the status matches what people see ("gate allocated"), not only the stored value.
            ->filterColumn('flights.gate_status', fn ($query, string $keyword) => $query->whereIn('flights.gate_status', array_keys(array_filter(
                Flight::STATUS_LABELS,
                fn (string $label, string $status) => str_contains(strtolower($label.' '.$status), strtolower($keyword)),
                ARRAY_FILTER_USE_BOTH,
            ))))
            ->rawColumns(['callsign', 'gate_status', $otherColumn])
            ->toJson();
    }

    /**
     * The flight's path for the route map, requested by the flight page after it loads.
     */
    public function track(Flight $flight, FlightTrack $track): JsonResponse
    {
        return response()->json($track->for($flight));
    }

    public function show(Flight $flight): View
    {
        $flight->load('airport', 'allocation.gate');

        // Flights using the same gate just before and after this one, to show where it sits in the gate's day.
        $neighbours = collect();
        if ($allocation = $flight->allocation) {
            $neighbours = GateAllocation::with('flight')
                ->where('gate_id', $allocation->gate_id)
                ->whereKeyNot($allocation->id)
                ->overlapping($allocation->starts_at->copy()->subHours(4), $allocation->ends_at->copy()->addHours(4))
                ->orderBy('starts_at')
                ->get();
        }

        // Names for every airport code on the page: this flight's route and the routes in the gate table.
        $codes = $neighbours->pluck('flight')->push($flight)
            ->flatMap(fn (Flight $f) => [$f->departure_airport, $f->arrival_airport])
            ->filter()->unique()->values();

        return view('flights.show', [
            'flight' => $flight,
            'neighbours' => $neighbours,
            'exceptions' => $flight->exceptions()->latest('id')->get(),
            'airportNames' => Airport::whereIn('icao_code', $codes)->pluck('name', 'icao_code'),
        ]);
    }
}
