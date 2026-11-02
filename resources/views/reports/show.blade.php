@extends('layouts.app')

@section('title', 'Report #'.$report->id)

@php
    use App\Models\AllocationException;

    $stats = $report->stats;
    $issues = $stats['issues'] ?? [];
    $totals = $stats['totals'] ?? [];
    $badge = [
        AllocationException::NO_GATE_AVAILABLE => 'unallocated',
        AllocationException::FETCH_FAILED => 'pending',
        AllocationException::RESTRICTION_CONFLICT => 'moved',
        AllocationException::OVERLAP => 'moved',
    ];
@endphp

@push('styles')
<style>
    .back { display: inline-block; margin-bottom: 12px; text-decoration: none; }
    .report-nav { display: flex; gap: 8px; align-items: center; }
    .badge.moved { color: var(--accent); background: color-mix(in srgb, var(--accent) 12%, transparent); }
    section { margin-bottom: 20px; }
    td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
    .counts { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
</style>
@endpush

@section('content')
    <a class="back" href="{{ route('reports.index') }}">← All reports</a>

    <div class="header">
        <div>
            <h1>Report <span class="mono">#{{ $report->id }}</span></h1>
            <div class="muted">
                Run at {{ $report->created_at->format('Y-m-d H:i') }} UTC
                · covers {{ $previous ? 'since '.$previous->created_at->format('Y-m-d H:i') : 'everything before it (first report)' }}
            </div>
        </div>
        <div class="report-nav">
            @if ($previous)<a class="button secondary small" href="{{ route('reports.show', $previous) }}"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i>#{{ $previous->id }}</a>@endif
            @if ($next)<a class="button secondary small" href="{{ route('reports.show', $next) }}">#{{ $next->id }}<i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>@endif
        </div>
    </div>

    <div class="stat-groups">
        <section class="stat-group">
            <h2>Checks <span class="muted">· what this run repaired</span></h2>
            <div class="stats">
                <div class="card stat"><b>{{ $issues['restriction_conflicts'] ?? 0 }}</b><span>Flights on closed gates</span></div>
                <div class="card stat"><b>{{ $issues['overlaps'] ?? 0 }}</b><span>Overlapping bookings</span></div>
                <div class="card stat"><b>{{ $issues['reallocated'] ?? 0 }} <small class="muted">/ {{ $issues['dropped'] ?? 0 }}</small></b><span>Moved / lost their gate</span></div>
            </div>
        </section>
        <section class="stat-group">
            <h2>Flights <span class="muted">· at report time</span></h2>
            <div class="stats">
                <div class="card stat"><b>{{ number_format(data_get($totals, 'flights.total', 0)) }}</b><span>Total</span></div>
                <div class="card stat"><b>{{ number_format(data_get($totals, 'flights.allocated', 0)) }}</b><span>Gate allocated</span></div>
                <div class="card stat"><b>{{ number_format(data_get($totals, 'flights.unallocated', 0)) }}</b><span>No gate available</span></div>
            </div>
        </section>
        <section class="stat-group">
            <h2>Gates <span class="muted">· at report time</span></h2>
            <div class="stats">
                <div class="card stat"><b>{{ data_get($totals, 'gates.occupied', 0) }}</b><span><span class="status-swatch in-use" aria-hidden="true"></span>In use</span></div>
                <div class="card stat"><b>{{ data_get($totals, 'gates.restricted', 0) }}</b><span><span class="status-swatch closed" aria-hidden="true"></span>Closed</span></div>
                <div class="card stat"><b>{{ data_get($totals, 'gates.free', 0) }} <small class="muted">/ {{ data_get($totals, 'gates.total', 0) }}</small></b><span><span class="status-swatch free" aria-hidden="true"></span>Free</span></div>
            </div>
        </section>
    </div>

    <section>
        <h2>What happened <span class="muted" style="font-weight:400">· {{ $exceptions->count() }} {{ \Illuminate\Support\Str::plural('event', $exceptions->count()) }} logged {{ $previous ? 'since the previous report' : 'up to this report' }}</span></h2>
        @if ($exceptions->isEmpty())
            <p class="muted">Nothing was logged in this period.</p>
        @else
            <div class="counts">
                @foreach ($exceptionCounts as $type => $total)
                    <span class="badge {{ $badge[$type] ?? 'neutral' }}">{{ AllocationException::TYPE_LABELS[$type] ?? $type }} · {{ $total }}</span>
                @endforeach
            </div>
            <div class="table-wrap">
                <table class="plain">
                    <thead>
                        <tr><th>When (UTC)</th><th>Airport</th><th>What</th><th>Flight</th><th>Details</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($exceptions as $exception)
                            <tr>
                                <td>{{ $exception->created_at->format('H:i:s') }}</td>
                                <td>
                                    @if ($exception->airport)
                                        <a href="{{ route('airports.show', $exception->airport) }}">{{ $exception->airport->icao_code }}</a>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                                <td><span class="badge {{ $badge[$exception->type] ?? 'neutral' }}">{{ $exception->type_label }}</span></td>
                                <td>
                                    @if ($exception->flight)
                                        <a class="mono" href="{{ route('flights.show', $exception->flight) }}">{{ $exception->flight->callsign ?? $exception->flight->icao24 }}</a>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </td>
                                <td style="white-space: normal">{{ $exception->message }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section>
        <h2>Per airport <span class="muted" style="font-weight:400">· at report time</span></h2>
        <div class="table-wrap">
            <table class="plain">
                <thead>
                    <tr>
                        <th>Airport</th><th class="num">Flights</th><th class="num">Gate allocated</th><th class="num">No gate available</th>
                        <th class="num">Gates in use</th><th class="num">Closed</th><th class="num">Free</th><th class="num">Logged</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($stats['airports'] ?? [] as $code => $airportStats)
                        <tr>
                            <td class="mono">{{ $code }}</td>
                            <td class="num">{{ number_format(data_get($airportStats, 'flights.total', 0)) }}</td>
                            <td class="num">{{ number_format(data_get($airportStats, 'flights.allocated', 0)) }}</td>
                            <td class="num">{{ number_format(data_get($airportStats, 'flights.unallocated', 0)) }}</td>
                            <td class="num">{{ data_get($airportStats, 'gates.occupied', 0) }}</td>
                            <td class="num">{{ data_get($airportStats, 'gates.restricted', 0) }}</td>
                            <td class="num">{{ data_get($airportStats, 'gates.free', 0) }}</td>
                            <td class="num">{{ array_sum($airportStats['exceptions_since_last_report'] ?? []) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endsection
