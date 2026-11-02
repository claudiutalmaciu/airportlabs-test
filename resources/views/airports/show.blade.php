@extends('layouts.app')

@section('title', $airport->name.' ('.$airport->icao_code.')')

@push('styles')
<style>
    .back { display: inline-block; margin-bottom: 12px; text-decoration: none; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .airport-meta { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .columns { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 16px; margin-bottom: 16px; }
    .card h2 { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
    .card h2 a { font-size: 13px; font-weight: 400; text-decoration: none; }
    .terminal { display: flex; align-items: flex-start; gap: 12px; padding: 8px 0; border-top: 1px solid var(--border); }
    .terminal:first-of-type { border-top: 0; }
    .terminal-name { width: 84px; flex: none; color: var(--muted); font-size: 13px; padding-top: 3px; }
    .gate-grid { display: flex; flex-wrap: wrap; gap: 6px; }
    .legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px; color: var(--muted); margin-top: 10px; }
    .airline-row { display: grid; grid-template-columns: minmax(0, 1fr) 48px; gap: 4px 12px; align-items: center; margin-bottom: 8px; }
    .airline-bar { grid-column: 1 / -1; height: 6px; border-radius: 3px; background: var(--bg); overflow: hidden; }
    .airline-bar span { display: block; height: 100%; background: var(--accent); border-radius: 3px; }
    .airline-row .count { text-align: right; font-variant-numeric: tabular-nums; }
    .table-wrap.compact table.plain td, .table-wrap.compact table.plain th { padding: 7px 10px; }
</style>
@endpush

@section('content')
    <a class="back" href="{{ route('airports.index') }}">← All airports</a>

    <div class="header">
        <div>
            <h1>{{ $airport->name }} <span class="mono muted">({{ $airport->icao_code }})</span></h1>
            <div class="airport-meta muted">
                @if ($airport->city)<span>{{ $airport->city }}</span>@endif
                @if ($airport->city && $airport->hasCoordinates()) ·@endif
                @if ($airport->hasCoordinates())<span class="mono">{{ number_format($airport->latitude, 4) }}, {{ number_format($airport->longitude, 4) }}</span>@endif
            </div>
        </div>
        <div class="actions">
            <a class="button" href="{{ route('flights.index', ['airport' => $airport->icao_code]) }}"><i class="fa-solid fa-list" aria-hidden="true"></i>View all flights</a>
            <a class="button secondary" href="{{ route('gates.index', ['airport' => $airport->icao_code]) }}"><i class="fa-solid fa-person-walking-luggage" aria-hidden="true"></i>View gates</a>
        </div>
    </div>

    @include('partials.airport-stats')

    <div class="columns">
        <section class="card">
            <h2>Gates right now <a href="{{ route('gates.index', ['airport' => $airport->icao_code]) }}">All gates →</a></h2>
            @forelse ($gatesByTerminal as $terminal => $gates)
                <div class="terminal">
                    <div class="terminal-name">Terminal {{ $terminal }}</div>
                    <div class="gate-grid">
                        @foreach ($gates as $gate)
                            @php($state = $gate->closed ? 'closed' : ($gate->in_use ? 'in-use' : 'free'))
                            <a href="{{ route('gates.show', $gate) }}" class="gate-pill {{ $state }}" title="Gate {{ $gate->code }}: {{ ['closed' => 'closed', 'in-use' => 'in use', 'free' => 'free'][$state] }}">{{ $gate->code }}</a>
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="muted">This airport has no gates.</p>
            @endforelse
            <div class="legend">
                <span><span class="status-swatch free" aria-hidden="true"></span> Free</span>
                <span><span class="status-swatch in-use" aria-hidden="true"></span> In use</span>
                <span><span class="status-swatch closed" aria-hidden="true"></span> Closed</span>
            </div>
        </section>

        <section class="card">
            <h2>Busiest airlines <span class="muted" style="font-size:13px;font-weight:400">by flights stored</span></h2>
            @php($busiest = $topAirlines->max('total') ?: 1)
            @forelse ($topAirlines as $airline)
                <div class="airline-row">
                    <span>{{ $airline->airline_name ?? $airline->airline_code }} <span class="muted mono">({{ $airline->airline_code }})</span></span>
                    <span class="count">{{ $airline->total }}</span>
                    <div class="airline-bar"><span style="width: {{ round($airline->total / $busiest * 100) }}%"></span></div>
                </div>
            @empty
                <p class="muted">No flights yet.</p>
            @endforelse
        </section>
    </div>

    <div class="columns">
        @foreach (['arrival' => $latestArrivals, 'departure' => $latestDepartures] as $direction => $flights)
            <section class="card">
                <h2>
                    <span><i class="fa-solid {{ $direction === 'arrival' ? 'fa-plane-arrival' : 'fa-plane-departure' }}" aria-hidden="true"></i> Latest {{ $direction }}s</span>
                    <a href="{{ route('flights.index', ['airport' => $airport->icao_code, 'direction' => $direction]) }}">All {{ $direction }}s →</a>
                </h2>
                @if ($flights->isEmpty())
                    <p class="muted">No {{ $direction }}s yet.</p>
                @else
                    <div class="table-wrap compact">
                        <table class="plain">
                            <thead>
                                <tr><th>Callsign</th><th>{{ $direction === 'arrival' ? 'Landed at' : 'Departed at' }}</th><th>Gate</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($flights as $flight)
                                    <tr>
                                        <td>
                                            <a class="mono" href="{{ route('flights.show', $flight) }}">{{ $flight->callsign ?? $flight->icao24 }}</a>
                                            @if ($flight->airline_name)<span class="muted"> · {{ $flight->airline_name }}</span>@endif
                                        </td>
                                        <td>{{ ($direction === 'arrival' ? $flight->last_seen : $flight->first_seen)->format('d M H:i') }}</td>
                                        <td>
                                            @if ($flight->allocation)
                                                <a class="gate-pill" href="{{ route('gates.show', $flight->allocation->gate) }}">{{ $flight->allocation->gate->code }}</a>
                                            @else
                                                <span class="muted">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endforeach
    </div>

    @include('flights._live-map')
@endsection
