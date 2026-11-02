@extends('layouts.app')

@section('title', 'Gate '.$gate->code.' · '.$gate->airport->icao_code)

@push('styles')
<style>
    .back { display: inline-block; margin-bottom: 12px; text-decoration: none; }
    .title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    /* The code sits inside the heading on its baseline, sized like a gate sign rather than a large pill. */
    h1 .gate-sign { display: inline-block; min-width: 0; margin-left: 4px; padding: .12em .45em; border-radius: 6px; font-size: .9em; line-height: 1.15; letter-spacing: .03em; vertical-align: baseline; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-bottom: 16px; }
    .now-state { display: flex; align-items: center; gap: 8px; font-size: 18px; font-weight: 600; margin: 2px 0 6px; }
    .now-state.in-use { color: var(--accent); }
    .now-state.closed { color: var(--bad); }
    .now-state.free { color: var(--ok); }
    section { margin-bottom: 16px; }
    .day-nav { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
    .day-nav h2 { margin: 0; flex: 1; }
    .day-nav a { text-decoration: none; }

    /* 24-hour strip: bars are positioned as percentages of the day. */
    .timeline { padding: 14px 16px 10px; }
    .timeline-track { position: relative; height: 34px; background: var(--bg); border-radius: 6px; }
    .timeline-bar { position: absolute; top: 4px; bottom: 4px; border-radius: 4px; overflow: hidden; white-space: nowrap; font-size: 11px; font-weight: 600; line-height: 26px; padding: 0 4px; color: #fff; text-decoration: none; min-width: 2px; }
    .timeline-bar.arrival { background: var(--accent); }
    .timeline-bar.departure { background: var(--departure); }
    .timeline-bar.closed { top: 0; bottom: 0; color: var(--bad); background: repeating-linear-gradient(45deg, var(--bad-bg), var(--bad-bg) 6px, transparent 6px, transparent 12px); border: 1px solid var(--bad); }
    .timeline-bar:hover { filter: brightness(1.15); }
    .timeline-now { position: absolute; top: -4px; bottom: -4px; width: 2px; background: var(--bad); }
    .timeline-hours { position: relative; height: 16px; margin-top: 4px; font-size: 11px; color: var(--muted); }
    .timeline-hours span { position: absolute; transform: translateX(-50%); }
    .timeline-hours span:first-child { transform: none; }
    .timeline-hours span:last-child { transform: translateX(-100%); }
    .legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 12px; color: var(--muted); margin-top: 8px; }
    .legend i { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 4px; vertical-align: -1px; }
    .route-icon { width: 18px; color: var(--muted); }
    .notice { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; border: 1px solid color-mix(in srgb, var(--accent) 35%, transparent); background: color-mix(in srgb, var(--accent) 8%, var(--surface)); }
    .notice ul { margin: 6px 0 0; padding-left: 18px; }
    .notice.warning { border-color: color-mix(in srgb, var(--bad) 40%, transparent); background: color-mix(in srgb, var(--bad) 6%, var(--surface)); }
    .close-gate-form { display: grid; grid-template-columns: minmax(170px, 1fr) minmax(170px, 1fr) minmax(220px, 2fr) auto; gap: 12px; align-items: end; margin-bottom: 8px; }
    @media (max-width: 900px) { .close-gate-form { grid-template-columns: minmax(0, 1fr); } }
    .close-gate-form label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--muted); font-weight: 600; }
    .close-gate-form input { width: 100%; }
    .field-error { color: var(--bad); font-size: 12px; font-weight: 400; }
    button.button { font: inherit; font-weight: 600; cursor: pointer; }
    button.button.danger { background: var(--bad); border-color: var(--bad); }
</style>
@endpush

@section('content')
    <a class="back" href="{{ route('flights.index', ['airport' => $gate->airport->icao_code]) }}">← All {{ $gate->airport->label }} flights</a>

    @if ($result = session('restriction_result'))
        <div @class(['notice', 'warning' => $result['dropped']]) role="status">
            <strong>Gate {{ $result['gate'] }} closed from {{ $result['from'] }} until {{ $result['until'] }} UTC.</strong>
            @if (! $result['moved'] && ! $result['dropped'])
                No flights were booked on it during that time.
            @else
                Allocation was re-run for the flights booked on it:
                <ul>
                    @foreach ($result['moved'] as $move)
                        <li><a class="mono" href="{{ $move['url'] }}">{{ $move['callsign'] }}</a> moved to gate <strong>{{ $move['gate'] }}</strong></li>
                    @endforeach
                    @foreach ($result['dropped'] as $flight)
                        <li><a class="mono" href="{{ $flight['url'] }}">{{ $flight['callsign'] }}</a>: no other gate was free, now <strong>No gate available</strong></li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <div class="header">
        <div>
            <div class="title">
                <h1>Gate <span class="gate-pill gate-sign">{{ $gate->code }}</span></h1>
            </div>
            <div class="muted">Terminal {{ $gate->terminal }} · <a href="{{ route('airports.show', $gate->airport) }}">{{ $gate->airport->label }}</a> · times in UTC</div>
        </div>
        <a class="button secondary" href="#close-gate"><i class="fa-solid fa-road-barrier" aria-hidden="true"></i>Close gate</a>
    </div>

    <div class="grid">
        <div class="card">
            <h2>Right now</h2>
            @if ($closedNow)
                <div class="now-state closed"><span class="status-swatch closed" aria-hidden="true"></span>Closed</div>
                <div class="muted">{{ $closedNow->reason }} until {{ $closedNow->ends_at->format('d M H:i') }}</div>
            @elseif ($current)
                <div class="now-state in-use"><span class="status-swatch in-use" aria-hidden="true"></span>In use</div>
                <div>
                    <a href="{{ route('flights.show', $current->flight) }}" class="mono">{{ $current->flight->callsign ?? $current->flight->icao24 }}</a>
                    <span class="muted">until {{ $current->ends_at->format('H:i') }}</span>
                </div>
            @else
                <div class="now-state free"><span class="status-swatch free" aria-hidden="true"></span>Free</div>
            @endif
            @if ($next)
                <div class="muted" style="margin-top:6px">
                    Next: <a href="{{ route('flights.show', $next->flight) }}" class="mono">{{ $next->flight->callsign ?? $next->flight->icao24 }}</a>
                    at {{ $next->starts_at->format('d M H:i') }}
                </div>
            @endif
        </div>

        <div class="card stat">
            <h2>{{ $day->format('D j M') }}</h2>
            <b>{{ $allocations->count() }}</b><span>{{ \Illuminate\Support\Str::plural('flight', $allocations->count()) }} at this gate</span>
        </div>

        <div class="card stat">
            <h2>Time in use</h2>
            <b>{{ round($busyMinutes / 14.4) }}%</b><span>{{ intdiv($busyMinutes, 60) }}h {{ $busyMinutes % 60 }}m of the day</span>
        </div>
    </div>

    <section>
        <div class="day-nav">
            <h2>Schedule</h2>
            <a href="{{ route('gates.show', [$gate, 'date' => $day->subDay()->toDateString()]) }}" class="icon-button" aria-label="Previous day"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></a>
            <strong>{{ $day->format('l, j F Y') }}</strong>
            <a href="{{ route('gates.show', [$gate, 'date' => $day->addDay()->toDateString()]) }}" class="icon-button" aria-label="Next day"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>
            @unless ($isToday)
                <a href="{{ route('gates.show', $gate) }}">Today</a>
            @endunless
        </div>

        <div class="card timeline">
            <div class="timeline-track">
                @foreach ($timeline as $bar)
                    @if ($bar['url'])
                        <a href="{{ $bar['url'] }}" class="timeline-bar {{ $bar['kind'] }}" style="left: {{ $bar['left'] }}%; width: {{ $bar['width'] }}%" title="{{ $bar['title'] }}">{{ $bar['label'] }}</a>
                    @else
                        <span class="timeline-bar {{ $bar['kind'] }}" style="left: {{ $bar['left'] }}%; width: {{ $bar['width'] }}%" title="{{ $bar['title'] }}">{{ $bar['label'] }}</span>
                    @endif
                @endforeach
                @if ($isToday)
                    <span class="timeline-now" style="left: {{ $nowPercent }}%" title="Now"></span>
                @endif
            </div>
            <div class="timeline-hours" aria-hidden="true">
                @foreach (range(0, 24, 3) as $hour)
                    <span style="left: {{ $hour / 24 * 100 }}%">{{ sprintf('%02d:00', $hour % 24) }}</span>
                @endforeach
            </div>
            <div class="legend">
                <span><i style="background: var(--accent)"></i>Arrival</span>
                <span><i style="background: var(--departure)"></i>Departure</span>
                <span><span class="status-swatch closed" aria-hidden="true" style="margin-right:4px"></span>Closed</span>
                @if ($isToday)<span><i style="background: var(--bad); width: 2px"></i>Now</span>@endif
            </div>
        </div>
    </section>

    <section>
        @if ($allocations->isEmpty())
            <p class="muted">No flights at this gate on this day.</p>
        @else
            <div class="table-wrap">
                <table class="plain">
                    <thead>
                        <tr><th>Callsign</th><th>Airline</th><th>Route</th><th>From</th><th>Until</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($allocations as $allocation)
                            @php
                                $flight = $allocation->flight;
                                $arriving = $flight->direction === \App\Models\Flight::ARRIVAL;
                                $otherCode = $arriving ? $flight->departure_airport : $flight->arrival_airport;
                            @endphp
                            <tr>
                                <td class="mono"><a href="{{ route('flights.show', $flight) }}">{{ $flight->callsign ?? $flight->icao24 }}</a></td>
                                <td>
                                    @if ($flight->airline_name)
                                        {{ $flight->airline_name }} <span class="muted mono">({{ $flight->airline_code }})</span>
                                    @else
                                        <span class="mono">{{ $flight->airline_code ?? '—' }}</span>
                                    @endif
                                </td>
                                <td>
                                    <i class="fa-solid {{ $arriving ? 'fa-plane-arrival' : 'fa-plane-departure' }} route-icon" aria-hidden="true"></i>
                                    <span class="muted">{{ $arriving ? 'from' : 'to' }}</span>
                                    @if ($otherCode && $airportNames->has($otherCode))
                                        {{ $airportNames[$otherCode] }} <span class="muted mono">({{ $otherCode }})</span>
                                    @else
                                        <span class="mono">{{ $otherCode ?? '????' }}</span>
                                    @endif
                                </td>
                                <td>{{ $allocation->starts_at->format('d M H:i') }}</td>
                                <td>{{ $allocation->ends_at->format('d M H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section id="close-gate">
        <h2>Restrictions</h2>
        <form class="card close-gate-form" method="post" action="{{ route('gates.restrictions.create', $gate) }}">
            @csrf
            <label>
                Closed from (UTC)
                <input type="datetime-local" name="starts_at" required value="{{ old('starts_at', now()->addHour()->startOfHour()->format('Y-m-d\\TH:i')) }}">
                @error('starts_at')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label>
                Until (UTC)
                <input type="datetime-local" name="ends_at" required value="{{ old('ends_at', now()->addHours(3)->startOfHour()->format('Y-m-d\\TH:i')) }}">
                @error('ends_at')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <label>
                Reason
                <input type="text" name="reason" required maxlength="255" placeholder="e.g. Repairs, cleaning, VIP arrival" value="{{ old('reason') }}">
                @error('reason')<span class="field-error">{{ $message }}</span>@enderror
            </label>
            <button type="submit" class="button danger"><i class="fa-solid fa-road-barrier" aria-hidden="true"></i>Close gate</button>
        </form>
        <p class="muted" style="margin: 0 0 12px">Flights already booked on this gate during the closure are moved to another free gate at {{ $gate->airport->name }}, or marked “No gate available” if none is free.</p>
        @if ($restrictions->isEmpty())
            <p class="muted">This gate has never been closed.</p>
        @else
            <div class="table-wrap">
                <table class="plain">
                    <thead>
                        <tr><th>From</th><th>Until</th><th>Reason</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($restrictions as $restriction)
                            <tr>
                                <td>{{ $restriction->starts_at->format('Y-m-d H:i') }}</td>
                                <td>{{ $restriction->ends_at->format('Y-m-d H:i') }}</td>
                                <td style="white-space: normal">{{ $restriction->reason }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
