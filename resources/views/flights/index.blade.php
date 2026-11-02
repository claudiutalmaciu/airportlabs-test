@extends('layouts.app')

@section('title', ucfirst($direction).'s · '.$airport->icao_code)

@include('partials.datatables')

@push('styles')
<style>
    .tabs { display: inline-flex; border: 1px solid var(--border); border-radius: 8px; overflow: hidden; background: var(--surface); }
    .tabs a { display: inline-flex; align-items: center; gap: 7px; padding: 7px 14px; text-decoration: none; color: var(--text); }
    .tabs a.active { background: var(--accent); color: #fff; }
    #airport-select { max-width: 100%; }
    table.dataTable td.airport-col { white-space: nowrap; }
</style>
@endpush

@section('content')
    <div class="header">
        <div>
            <h1><a href="{{ route('airports.show', $airport) }}" class="heading-link" title="Airport overview">{{ $airport->name }}</a> <span class="mono muted">({{ $airport->icao_code }})</span></h1>
            <div class="muted">Gate allocations · times in UTC</div>
        </div>
    </div>

    @include('partials.airport-stats')

    @include('flights._live-map')

    <div class="toolbar">
        <select id="airport-select" aria-label="Airport">
            @foreach ($airports as $option)
                <option value="{{ route('flights.index', ['airport' => $option->icao_code, 'direction' => $direction]) }}" @selected($option->is($airport))>{{ $option->label }}</option>
            @endforeach
        </select>
        <div class="tabs">
            <a href="{{ route('flights.index', ['airport' => $airport->icao_code, 'direction' => 'arrival']) }}" @class(['active' => $direction === 'arrival'])><i class="fa-solid fa-plane-arrival" aria-hidden="true"></i>Arrivals</a>
            <a href="{{ route('flights.index', ['airport' => $airport->icao_code, 'direction' => 'departure']) }}" @class(['active' => $direction === 'departure'])><i class="fa-solid fa-plane-departure" aria-hidden="true"></i>Departures</a>
        </div>
        <select id="status-filter" aria-label="Gate status">
            <option value="">Any gate status</option>
            @foreach (\App\Models\Flight::STATUS_LABELS as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    <div class="table-wrap">
        <table id="flights" class="display hover" style="width:100%">
            <thead>
                <tr>
                    <th>Callsign</th>
                    <th>Airline</th>
                    <th>ICAO24</th>
                    <th>{{ $direction === 'arrival' ? 'From' : 'To' }}</th>
                    <th>{{ $direction === 'arrival' ? 'Landed at' : 'Departed at' }}</th>
                    <th>Gate</th>
                    <th>Gate window</th>
                    <th>Gate status</th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@push('scripts')
<script>
    $(function () {
        var isArrival = @json($direction === 'arrival');
        var dash = function (value) { return value || '<span class="muted">—</span>'; };

        var table = $('#flights').DataTable({
            serverSide: true,
            processing: true,
            pageLength: 25,
            order: [[4, 'desc']],
            ajax: {
                url: @json(route('flights.list')),
                data: function (params) {
                    params.airport = @json($airport->icao_code);
                    params.direction = @json($direction);
                    params.status = $('#status-filter').val();
                }
            },
            // "name" is the SQL column the server sorts and searches on; "data" is the JSON key it returns.
            columns: [
                { data: 'callsign', name: 'flights.callsign' },
                // Airlines missing from config/airlines.php fall back to their ICAO code.
                { data: 'airline_name', name: 'flights.airline_name', render: function (name, type, row) {
                    if (!name) {
                        return dash(row.airline_code);
                    }
                    return $('<span>').text(name).prop('outerHTML') + (row.airline_code ? ' <span class="muted mono">(' + row.airline_code + ')</span>' : '');
                } },
                { data: 'icao24', name: 'flights.icao24', className: 'mono muted' },
                isArrival
                    ? { data: 'departure_airport', name: 'departure_airport', className: 'airport-col', render: dash }
                    : { data: 'arrival_airport', name: 'arrival_airport', className: 'airport-col', render: dash },
                isArrival
                    ? { data: 'last_seen', name: 'flights.last_seen', className: 'num', searchable: false }
                    : { data: 'first_seen', name: 'flights.first_seen', className: 'num', searchable: false },
                // One Gate column: the gate when one is allocated, otherwise why there is none ("No gate available").
                { data: 'gate_code', name: 'g.code', render: function (code, type, row) {
                    if (!code) {
                        return row.gate_status;
                    }
                    return $('<a class="gate-pill">').attr({ href: @json(url('/gates')) + '/' + row.gate_id, title: 'View gate ' + code }).text(code).prop('outerHTML');
                } },
                { data: 'gate_starts_at', name: 'ga.starts_at', className: 'num', searchable: false, render: dash },
                // Hidden, but still searchable, so "no gate available" finds those flights.
                { data: 'gate_status', name: 'flights.gate_status', visible: false }
            ],
            language: {
                search: 'Search:',
                searchPlaceholder: 'Callsign, airline, airport, gate…',
                emptyTable: 'No flights match. Try another gate status, or the other tab.',
                zeroRecords: 'No flights match your search.'
            }
        });

        // The stat tiles are rendered for one airport, so switching airport reloads the page.
        $('#airport-select').on('change', function () {
            window.location = this.value;
        });

        $('#status-filter').on('change', function () {
            table.ajax.reload();
        });
    });
</script>
@endpush
