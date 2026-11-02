<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateGateRestrictionRequest;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateRestriction;
use App\Services\Allocation\AllocationStats;
use App\Services\Allocation\GateRestrictor;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class GateController
{
    private const MINUTES_PER_DAY = 1440;

    public function index(Request $request, AllocationStats $stats): View
    {
        $selected = Airport::findByCode($request->query('airport'));

        return view('gates.index', [
            'airports' => Airport::withCount('gates')->orderBy('name')->get(),
            'selected' => $selected,
            'stats' => $stats->snapshot($selected),
            'today' => $this->todayStats($selected),
        ]);
    }

    /**
     * How the gates (of one airport, or all of them) are being used today, UTC.
     *
     * @return array{flights: int, busy_percent: int, idle_gates: int, busiest: ?Gate, busiest_count: int}
     */
    private function todayStats(?Airport $airport): array
    {
        $dayStart = CarbonImmutable::now()->startOfDay();
        $dayEnd = $dayStart->addDay();

        $gates = Gate::query()->when($airport, fn (Builder $query) => $query->where('airport_id', $airport->id));
        $gateCount = (clone $gates)->count();
        $todays = GateAllocation::query()
            ->whereIn('gate_id', (clone $gates)->select('id'))
            ->overlapping($dayStart, $dayEnd);

        // Allocations on one gate never overlap, so clipping each to today and summing gives the busy time.
        $busyMinutes = (int) (clone $todays)
            ->selectRaw('SUM(TIMESTAMPDIFF(MINUTE, GREATEST(starts_at, ?), LEAST(ends_at, ?))) as minutes', [$dayStart, $dayEnd])
            ->value('minutes');

        $busiest = (clone $gates)->with('airport')
            ->withCount(['allocations as today_count' => fn (Builder $query) => $query->overlapping($dayStart, $dayEnd)])
            ->orderByDesc('today_count')
            ->orderBy('id')
            ->first();

        return [
            'flights' => (clone $todays)->count(),
            'busy_percent' => $gateCount ? (int) round($busyMinutes / ($gateCount * self::MINUTES_PER_DAY) * 100) : 0,
            'idle_gates' => (clone $gates)->whereDoesntHave('allocations', fn (Builder $query) => $query->overlapping($dayStart, $dayEnd))->count(),
            'busiest' => $busiest?->today_count ? $busiest : null,
            'busiest_count' => (int) $busiest?->today_count,
        ];
    }

    /**
     * Server-side DataTables endpoint for the gates list: every gate with its airport and what it is doing right now.
     */
    public function gatesList(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'airport' => ['nullable', 'string', Rule::exists('airports', 'icao_code')],
            'status' => ['nullable', Rule::in(['free', 'in_use', 'closed'])],
        ]);

        $now = now();
        $currentAllocation = fn (QueryBuilder $query) => $query->from('gate_allocations as ga')
            ->whereColumn('ga.gate_id', 'gates.id')
            ->where('ga.starts_at', '<=', $now)
            ->where('ga.ends_at', '>', $now)
            ->limit(1);

        $query = Gate::query()
            ->join('airports', 'airports.id', '=', 'gates.airport_id')
            ->when($filters['airport'] ?? null, fn (Builder $query, string $code) => $query->where('airports.icao_code', $code))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $this->whereStatusNow($query, $status, $now))
            ->select('gates.*', 'airports.icao_code as airport_code', 'airports.name as airport_name')
            ->selectSub(fn (QueryBuilder $query) => $currentAllocation($query)->select('ga.flight_id'), 'current_flight_id')
            ->selectSub(fn (QueryBuilder $query) => $currentAllocation($query)
                ->join('flights as f', 'f.id', '=', 'ga.flight_id')
                ->select(DB::raw('COALESCE(f.callsign, f.icao24)')), 'current_callsign')
            ->selectSub(fn (QueryBuilder $query) => $currentAllocation($query)->select('ga.ends_at'), 'current_until')
            ->selectSub(fn (QueryBuilder $query) => $query->from((new GateRestriction)->getTable(), 'r')
                ->whereColumn('r.gate_id', 'gates.id')
                ->where('r.starts_at', '<=', $now)
                ->where('r.ends_at', '>', $now)
                ->select('r.reason')
                ->limit(1), 'closed_reason')
            ->selectSub(fn (QueryBuilder $query) => $query->from('gate_allocations as nx')
                ->whereColumn('nx.gate_id', 'gates.id')
                ->where('nx.starts_at', '>', $now)
                ->selectRaw('MIN(nx.starts_at)'), 'next_starts_at')
            ->withCount(['allocations as flights_today_count' => fn (Builder $query) => $query
                ->where('starts_at', '>=', $now->copy()->startOfDay())
                ->where('starts_at', '<', $now->copy()->startOfDay()->addDay())]);

        return DataTables::eloquent($query)
            ->addColumn('airport_label', fn (Gate $gate) => "{$gate->airport_name} ({$gate->airport_code})")
            ->addColumn('airport_url', fn (Gate $gate) => route('airports.show', $gate->airport_id))
            ->addColumn('url', fn (Gate $gate) => route('gates.show', $gate))
            ->addColumn('status', fn (Gate $gate) => match (true) {
                $gate->closed_reason !== null => 'closed',
                $gate->current_flight_id !== null => 'in_use',
                default => 'free',
            })
            ->addColumn('current_flight_url', fn (Gate $gate) => $gate->current_flight_id ? route('flights.show', $gate->current_flight_id) : null)
            ->editColumn('current_until', fn (Gate $gate) => $gate->current_until ? CarbonImmutable::parse($gate->current_until)->format('H:i') : null)
            ->editColumn('next_starts_at', fn (Gate $gate) => $gate->next_starts_at ? CarbonImmutable::parse($gate->next_starts_at)->format('d M H:i') : null)
            // Searching the airport column matches its name or its ICAO code.
            ->filterColumn('airports.name', fn (Builder $query, string $keyword) => $query->where(fn (Builder $q) => $q
                ->where('airports.name', 'like', "%{$keyword}%")
                ->orWhere('airports.icao_code', 'like', "%{$keyword}%")))
            // Gate codes sort naturally within a terminal: A1, A2 … A10.
            ->orderColumn('gates.code', 'gates.terminal $1, CAST(SUBSTRING(gates.code, 2) AS UNSIGNED) $1')
            ->orderColumn('flights_today_count', 'flights_today_count $1')
            ->toJson();
    }

    /**
     * Limits gates to one "right now" state. A closed gate counts as closed even if a flight is still booked on it,
     * matching how the status column is worked out.
     */
    private function whereStatusNow(Builder $query, string $status, DateTimeInterface $now): void
    {
        $activeNow = fn (Builder $relation) => $relation->where('starts_at', '<=', $now)->where('ends_at', '>', $now);

        match ($status) {
            'closed' => $query->whereHas('restrictions', $activeNow),
            'in_use' => $query->whereDoesntHave('restrictions', $activeNow)->whereHas('allocations', $activeNow),
            'free' => $query->whereDoesntHave('restrictions', $activeNow)->whereDoesntHave('allocations', $activeNow),
        };
    }

    /**
     * One gate's day: who uses it when, when it is closed, and what is happening right now.
     */
    public function show(Request $request, Gate $gate): View
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        $dayStart = CarbonImmutable::parse($request->query('date', now()->toDateString()), 'UTC')->startOfDay();
        $dayEnd = $dayStart->addDay();
        $now = CarbonImmutable::now();

        $gate->load('airport');

        $allocations = $gate->allocations()
            ->with('flight')
            ->overlapping($dayStart, $dayEnd)
            ->orderBy('starts_at')
            ->get();

        $dayRestrictions = $gate->restrictions()->overlapping($dayStart, $dayEnd)->orderBy('starts_at')->get();

        $codes = $allocations->pluck('flight')
            ->flatMap(fn (Flight $flight) => [$flight->departure_airport, $flight->arrival_airport])
            ->filter()->unique()->values();

        return view('gates.show', [
            'gate' => $gate,
            'day' => $dayStart,
            'isToday' => $dayStart->isSameDay($now),
            'nowPercent' => $this->percentOfDay($now, $dayStart),
            'allocations' => $allocations,
            'restrictions' => $gate->restrictions()->orderByDesc('starts_at')->get(),
            'timeline' => $this->timeline($allocations, $dayRestrictions, $dayStart, $dayEnd),
            'busyMinutes' => $this->busyMinutes($allocations, $dayStart, $dayEnd),
            'current' => $gate->allocations()->with('flight')->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->first(),
            'closedNow' => $gate->restrictions()->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->first(),
            'next' => $gate->allocations()->with('flight')->where('starts_at', '>', $now)->orderBy('starts_at')->first(),
            'airportNames' => Airport::whereIn('icao_code', $codes)->pluck('name', 'icao_code'),
        ]);
    }

    /**
     * Closes the gate from the gate page, then re-runs allocation for the flights booked on it during the closure.
     */
    public function createRestriction(CreateGateRestrictionRequest $request, Gate $gate, GateRestrictor $restrictor): RedirectResponse
    {
        $startsAt = $request->startsAt();
        $endsAt = $request->endsAt();

        $result = $restrictor->restrict($gate, $startsAt, $endsAt, $request->validated('reason'));

        return redirect()
            ->route('gates.show', [$gate, 'date' => $startsAt->toDateString()])
            ->with('restriction_result', [
                'gate' => $gate->code,
                'from' => $startsAt->format('d M H:i'),
                'until' => $endsAt->format('d M H:i'),
                'moved' => array_map(fn (array $move) => [
                    'callsign' => $move['flight']->callsign ?? $move['flight']->icao24,
                    'url' => route('flights.show', $move['flight']),
                    'gate' => $move['gate']->code,
                ], $result['moved']),
                'dropped' => array_map(fn (Flight $flight) => [
                    'callsign' => $flight->callsign ?? $flight->icao24,
                    'url' => route('flights.show', $flight),
                ], $result['dropped']),
            ]);
    }

    /**
     * Bars for the 24-hour strip, as percentages of the day so the view can position them with CSS.
     *
     * @return list<array{kind: string, left: float, width: float, label: string, title: string, url: ?string}>
     */
    private function timeline(Collection $allocations, Collection $restrictions, CarbonImmutable $dayStart, CarbonImmutable $dayEnd): array
    {
        $bars = [];

        foreach ($restrictions as $restriction) {
            $bars[] = $this->bar('closed', $restriction->starts_at, $restriction->ends_at, $dayStart, $dayEnd, 'Closed',
                "Closed: {$restriction->reason} ({$restriction->starts_at->format('d M H:i')} – {$restriction->ends_at->format('d M H:i')})");
        }

        /** @var GateAllocation $allocation */
        foreach ($allocations as $allocation) {
            $flight = $allocation->flight;
            $callsign = $flight->callsign ?? $flight->icao24;

            $bars[] = $this->bar($flight->direction, $allocation->starts_at, $allocation->ends_at, $dayStart, $dayEnd, $callsign,
                "{$callsign} · {$allocation->starts_at->format('H:i')} – {$allocation->ends_at->format('H:i')}",
                route('flights.show', $flight));
        }

        return $bars;
    }

    /**
     * @return array{kind: string, left: float, width: float, label: string, title: string, url: ?string}
     */
    private function bar(string $kind, DateTimeInterface $start, DateTimeInterface $end, CarbonImmutable $dayStart, CarbonImmutable $dayEnd, string $label, string $title, ?string $url = null): array
    {
        $left = $this->percentOfDay(max($start, $dayStart), $dayStart);
        $right = $this->percentOfDay(min($end, $dayEnd), $dayStart);

        return [
            'kind' => $kind,
            'left' => round($left, 3),
            'width' => round($right - $left, 3),
            'label' => $label,
            'title' => $title,
            'url' => $url,
        ];
    }

    private function percentOfDay(DateTimeInterface $moment, CarbonImmutable $dayStart): float
    {
        return min(max($dayStart->diffInMinutes($moment, false) / self::MINUTES_PER_DAY * 100, 0), 100);
    }

    /**
     * Minutes of the day the gate held a flight (allocations never overlap, so they can be summed).
     */
    private function busyMinutes(Collection $allocations, CarbonImmutable $dayStart, CarbonImmutable $dayEnd): int
    {
        return (int) $allocations->sum(fn (GateAllocation $allocation) => max(
            CarbonImmutable::instance(max($allocation->starts_at, $dayStart))->diffInMinutes(min($allocation->ends_at, $dayEnd)),
            0,
        ));
    }
}
