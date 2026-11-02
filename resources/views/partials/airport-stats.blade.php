{{-- Flight and gate numbers for one airport, or for all of them when $airport is null. Expects $stats (AllocationStats::snapshot).
     A page can put its own group first by defining a 'stat-groups-before' section. --}}
@php
    $flightStats = $stats['flights'];
    $gateStats = $stats['gates'];
    $arrivals = array_sum($flightStats['arrival']);
    $departures = array_sum($flightStats['departure']);
    $scope = $airport?->icao_code ?? 'all airports';
@endphp

{{-- Two time frames: every flight stored, and its gates at this moment. Each group adds up to its total. --}}
<div class="stat-groups {{ $statGroupsClass ?? '' }}">
    @yield('stat-groups-before')
    <section class="stat-group">
        <h2>Flights at {{ $scope }} <span class="muted">· all fetched</span></h2>
        <div class="stats">
            <div class="card stat"><b>{{ number_format($flightStats['total']) }}</b><span class="split-counts"><span title="Arrivals"><i class="fa-solid fa-plane-arrival" aria-hidden="true"></i> {{ number_format($arrivals) }}<span class="sr-only"> arrivals</span></span> · <span title="Departures"><i class="fa-solid fa-plane-departure" aria-hidden="true"></i> {{ number_format($departures) }}<span class="sr-only"> departures</span></span></span></div>
            <div class="card stat"><b>{{ number_format($flightStats['allocated']) }}</b><span>Gate allocated</span></div>
            <div class="card stat"><b>{{ number_format($flightStats['unallocated']) }}</b><span>No gate available{{ $flightStats['pending'] ? ' · '.number_format($flightStats['pending']).' awaiting gate' : '' }}</span></div>
        </div>
    </section>
    <section class="stat-group">
        <h2>Gates at {{ $scope }} <span class="muted">· right now, {{ now()->format('H:i') }} UTC</span></h2>
        <div class="stats">
            <div class="card stat"><b>{{ $gateStats['occupied'] }}</b><span>In use</span></div>
            <div class="card stat"><b>{{ $gateStats['restricted'] }}</b><span>Closed (restrictions)</span></div>
            <div class="card stat"><b>{{ $gateStats['free'] }} <small class="muted">/ {{ $gateStats['total'] }}</small></b><span>Free</span></div>
        </div>
    </section>
</div>
