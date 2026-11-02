@extends('layouts.app')

@section('title', 'Airports')

@include('partials.datatables')

@push('styles')
<style>
    td.row-actions { white-space: nowrap; }
    td.row-actions .button + .button { margin-left: 6px; }
</style>
@endpush

@section('content')
    <div class="header">
        <div>
            <h1>Airports</h1>
            <div class="muted">Click an airport for its overview, or “Gates” / “Flights” to go straight to them</div>
        </div>
    </div>

    {{-- First group of the shared stats grid, on a row of its own; flights and gates share the row below. --}}
    @section('stat-groups-before')
        <section class="stat-group full-row">
            <h2>Airports</h2>
            <div class="stats">
                <div class="card stat"><b>{{ $totals['airports'] }}</b><span>Airports · {{ number_format($totals['gates']) }} gates</span></div>
                <div class="card stat">
                    @if ($busiest)
                        <b><a class="heading-link" href="{{ route('airports.show', $busiest) }}">{{ $busiest->icao_code }}</a></b><span>Most flights · {{ number_format($busiest->flights_count) }}</span>
                    @else
                        <b class="muted">—</b><span>Most flights</span>
                    @endif
                </div>
                <div class="card stat">
                    @if ($mostGatesInUse?->gates_in_use_count)
                        <b><a class="heading-link" href="{{ route('airports.show', $mostGatesInUse) }}">{{ $mostGatesInUse->icao_code }}</a></b><span>Busiest right now · {{ $mostGatesInUse->gates_in_use_count }} {{ \Illuminate\Support\Str::plural('gate', $mostGatesInUse->gates_in_use_count) }} in use</span>
                    @else
                        <b class="muted">—</b><span>Busiest right now · no gates in use</span>
                    @endif
                </div>
            </div>
        </section>
    @endsection

    @include('partials.airport-stats', ['airport' => null, 'statGroupsClass' => 'two-up'])

    <div class="table-wrap">
        <table id="airports" class="display hover" style="width:100%">
            <thead>
                <tr>
                    <th>Airport</th>
                    <th>City</th>
                    <th>Gates</th>
                    <th>In use now</th>
                    <th>Arrivals</th>
                    <th>Departures</th>
                    <th>Flights</th>
                    <th>Without a gate</th>
                    <th></th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@push('scripts')
<script>
    $(function () {
        var number = { className: 'num dt-right', searchable: false };

        $('#airports').DataTable({
            serverSide: true,
            processing: true,
            pageLength: 25,
            order: [[0, 'asc']],
            ajax: @json(route('airports.list')),
            columns: [
                { data: 'name', name: 'name' },
                { data: 'city', name: 'city', render: function (city) { return city || '<span class="muted">—</span>'; } },
                $.extend({ data: 'gates_count', name: 'gates_count' }, number),
                $.extend({ data: 'gates_in_use_count', name: 'gates_in_use_count' }, number),
                $.extend({ data: 'arrivals_count', name: 'arrivals_count' }, number),
                $.extend({ data: 'departures_count', name: 'departures_count' }, number),
                $.extend({ data: 'flights_count', name: 'flights_count' }, number),
                $.extend({ data: 'unallocated_count', name: 'unallocated_count' }, number),
                { data: 'flights_url', name: 'flights_url', orderable: false, searchable: false, className: 'dt-right row-actions', render: function (url, type, row) {
                    return '<a class="button secondary small" href="' + row.gates_url + '"><i class="fa-solid fa-person-walking-luggage" aria-hidden="true"></i>Gates</a>'
                        + '<a class="button secondary small" href="' + url + '"><i class="fa-solid fa-plane" aria-hidden="true"></i>Flights</a>';
                } }
            ],
            language: {
                search: 'Search:',
                searchPlaceholder: 'Name, ICAO code, city…'
            }
        });
    });
</script>
@endpush
