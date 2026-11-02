@extends('layouts.app')

@php
    $isArrival = $flight->direction === \App\Models\Flight::ARRIVAL;
    $allocation = $flight->allocation;
@endphp

@section('title', ($flight->callsign ?? $flight->icao24).' · '.$flight->airport->icao_code)

@push('styles')
<style>
    .back { display: inline-block; margin-bottom: 12px; text-decoration: none; }
    .title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; margin-bottom: 16px; }
    dl { display: grid; grid-template-columns: max-content 1fr; gap: 8px 20px; margin: 0; }
    dt { color: var(--muted); }
    dd { margin: 0; font-variant-numeric: tabular-nums; }
    .route { font-size: 18px; font-weight: 600; margin-bottom: 12px; }
    .gate-code { font-size: 32px; font-weight: 700; line-height: 1.1; }
    .gate-code a { color: inherit; text-decoration: none; }
    .gate-code a:hover { color: var(--accent); }
    section { margin-bottom: 16px; }
    .card-title { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
    .card-title .badge { font-size: 12px; font-weight: 600; }
    table.plain tr.current td { font-weight: 600; background: color-mix(in srgb, var(--accent) 10%, transparent); }
    table.plain tr.current td:first-child { box-shadow: inset 3px 0 0 var(--accent); }
    .route-icon { width: 18px; color: var(--muted); }
    .badge.this-flight { color: #fff; background: var(--accent); margin-left: 6px; font-family: system-ui, sans-serif; }
</style>
@endpush

@section('content')
    <a class="back" href="{{ route('flights.index', ['airport' => $flight->airport->icao_code, 'direction' => $flight->direction]) }}">← All {{ $flight->airport->label }} {{ $flight->direction }}s</a>

    <div class="header">
        <div>
            <div class="title">
                <h1 class="mono">{{ $flight->callsign ?? 'No callsign' }}</h1>
            </div>
            <div class="muted">@if ($flight->airline_name){{ $flight->airline_name }} · @endif{{ ucfirst($flight->direction) }} at <a href="{{ route('airports.show', $flight->airport) }}">{{ $flight->airport->label }}</a> · times in UTC</div>
        </div>
        <span class="direction-badge large {{ $flight->direction }}">
            <i class="fa-solid {{ $isArrival ? 'fa-plane-arrival' : 'fa-plane-departure' }}" aria-hidden="true"></i>{{ ucfirst($flight->direction) }}
        </span>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Flight</h2>
            <div class="route mono">{{ $flight->departure_airport ?? '????' }} → {{ $flight->arrival_airport ?? '????' }}</div>
            <dl>
                @foreach (['From' => $flight->departure_airport, 'To' => $flight->arrival_airport] as $label => $code)
                    <dt>{{ $label }}</dt>
                    <dd>
                        @if ($code && $airportNames->has($code))
                            {{ $airportNames[$code] }} <span class="muted mono">({{ $code }})</span>
                        @else
                            <span class="mono">{{ $code ?? 'Unknown' }}</span>
                        @endif
                    </dd>
                @endforeach
                <dt>Airline</dt><dd>@if ($flight->airline_name){{ $flight->airline_name }} <span class="muted mono">({{ $flight->airline_code }})</span>@else{{ $flight->airline_code ?? 'Unknown' }}@endif</dd>
                <dt>ICAO24</dt><dd class="mono">{{ $flight->icao24 }}</dd>
                <dt>First seen</dt><dd>{{ $flight->first_seen->format('Y-m-d H:i') }}@unless ($isArrival) <span class="muted">(take-off)</span>@endunless</dd>
                <dt>Last seen</dt><dd>{{ $flight->last_seen->format('Y-m-d H:i') }}@if ($isArrival) <span class="muted">(landing)</span>@endif</dd>
                <dt>Fetched at</dt><dd>{{ $flight->observed_at->format('Y-m-d H:i') }}</dd>
            </dl>
        </div>

        <div class="card">
            <h2 class="card-title">Gate <span class="badge {{ $flight->gate_status }}">{{ $flight->gate_status_label }}</span></h2>
            @if ($allocation)
                <div class="gate-code"><a href="{{ route('gates.show', [$allocation->gate, 'date' => $allocation->starts_at->toDateString()]) }}" title="View gate {{ $allocation->gate->code }}">{{ $allocation->gate->code }}</a></div>
                <p class="muted" style="margin:4px 0 12px">Terminal {{ $allocation->gate->terminal }} · {{ $flight->airport->name }}</p>
                <dl>
                    <dt>From</dt><dd>{{ $allocation->starts_at->format('Y-m-d H:i') }}</dd>
                    <dt>Until</dt><dd>{{ $allocation->ends_at->format('Y-m-d H:i') }}</dd>
                    <dt>Duration</dt><dd>{{ $allocation->starts_at->diffInMinutes($allocation->ends_at) }} min</dd>
                </dl>
            @elseif ($flight->gate_status === \App\Models\Flight::STATUS_UNALLOCATED)
                <p>Every gate was in use or closed for this flight's window.</p>
            @else
                <p class="muted">The next allocation run will look for a free one.</p>
            @endif
        </div>
    </div>

    @include('flights._route-map')

    @if ($allocation)
        <section>
            <h2>Gate <a href="{{ route('gates.show', [$allocation->gate, 'date' => $allocation->starts_at->toDateString()]) }}">{{ $allocation->gate->code }}</a> around this flight</h2>
            <div class="table-wrap">
                <table class="plain">
                    <thead>
                        <tr><th>Callsign</th><th>Airline</th><th>Route</th><th>From</th><th>Until</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($neighbours->push($allocation->setRelation('flight', $flight))->sortBy('starts_at') as $slot)
                            @php($isCurrent = $slot->id === $allocation->id)
                            <tr @class(['current' => $isCurrent]) @if ($isCurrent) aria-current="true" @endif>
                                <td class="mono">
                                    @if ($isCurrent)
                                        {{ $flight->callsign ?? $flight->icao24 }}<span class="badge this-flight">This flight</span>
                                    @else
                                        <a href="{{ route('flights.show', $slot->flight) }}">{{ $slot->flight->callsign ?? $slot->flight->icao24 }}</a>
                                    @endif
                                </td>
                                <td>
                                    @if ($slot->flight->airline_name)
                                        {{ $slot->flight->airline_name }} <span class="muted mono">({{ $slot->flight->airline_code }})</span>
                                    @else
                                        <span class="mono">{{ $slot->flight->airline_code ?? '—' }}</span>
                                    @endif
                                </td>
                                {{-- Every flight here uses this airport, so only the other end of the route is shown. --}}
                                @php($arriving = $slot->flight->direction === \App\Models\Flight::ARRIVAL)
                                @php($otherCode = $arriving ? $slot->flight->departure_airport : $slot->flight->arrival_airport)
                                <td>
                                    <i class="fa-solid {{ $arriving ? 'fa-plane-arrival' : 'fa-plane-departure' }} route-icon" aria-hidden="true"></i>
                                    <span class="muted">{{ $arriving ? 'from' : 'to' }}</span>
                                    @if ($otherCode && $airportNames->has($otherCode))
                                        {{ $airportNames[$otherCode] }} <span class="muted mono">({{ $otherCode }})</span>
                                    @else
                                        <span class="mono">{{ $otherCode ?? '????' }}</span>
                                    @endif
                                </td>
                                <td>{{ $slot->starts_at->format('d M H:i') }}</td>
                                <td>{{ $slot->ends_at->format('d M H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section>
        <h2>Exceptions</h2>
        @if ($exceptions->isEmpty())
            <p class="muted">None recorded for this flight.</p>
        @else
            <div class="table-wrap">
                <table class="plain">
                    <thead>
                        <tr><th>When</th><th>Type</th><th>Message</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($exceptions as $exception)
                            <tr>
                                <td>{{ $exception->created_at->format('Y-m-d H:i') }}</td>
                                <td class="mono">{{ $exception->type }}</td>
                                <td style="white-space: normal">{{ $exception->message }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
