@extends('layouts.app')

@section('title', 'Gates')

@include('partials.datatables')

@push('styles')
<link rel="stylesheet" href="https://cdn.datatables.net/rowgroup/1.5.1/css/rowGroup.dataTables.min.css" integrity="sha384-2NAdExmXbiSJhyJfJuW1pBfkyLCpS4n+S+DwrmjE2MYXaZCdMnhBtLMGPVhaDN56" crossorigin="anonymous">
<style>
    #airport-filter { max-width: 100%; }
    /* One header row per airport so gates of different airports never blur together. */
    table.dataTable tr.dtrg-group th { background: var(--bg) !important; border-top: 2px solid var(--border); padding: 10px 12px; text-transform: none; letter-spacing: 0; font-size: 14px; color: var(--text); }
    .group-head { display: flex; align-items: center; gap: 10px; }
    .group-head .terminal-icon { color: var(--accent); }
    .group-head a { font-weight: 700; }
    .gate-state { display: inline-flex; align-items: center; gap: 6px; }
    .gate-state.free { color: var(--ok); }
    .gate-state.in_use { color: var(--accent); }
    .gate-state.closed { color: var(--bad); }
</style>
@endpush

@section('content')
    <div class="header">
        <div>
            <h1>Gates</h1>
            <div class="muted">{{ number_format($airports->sum('gates_count')) }} gates across {{ $airports->count() }} airports</div>
        </div>
    </div>

    @php($scope = $selected?->icao_code ?? 'all airports')
    <div class="stat-groups">
        <section class="stat-group">
            <h2>Gates at {{ $scope }} <span class="muted">· right now, {{ now()->format('H:i') }} UTC</span></h2>
            <div class="stats">
                <div class="card stat"><b>{{ $stats['gates']['occupied'] }}</b><span><span class="status-swatch in-use" aria-hidden="true"></span>In use</span></div>
                <div class="card stat"><b>{{ $stats['gates']['restricted'] }}</b><span><span class="status-swatch closed" aria-hidden="true"></span>Closed (restrictions)</span></div>
                <div class="card stat"><b>{{ $stats['gates']['free'] }} <small class="muted">/ {{ $stats['gates']['total'] }}</small></b><span><span class="status-swatch free" aria-hidden="true"></span>Free</span></div>
            </div>
        </section>
        <section class="stat-group">
            <h2>Gates at {{ $scope }} <span class="muted">· today, {{ now()->format('D j M') }}</span></h2>
            <div class="stats">
                <div class="card stat"><b>{{ number_format($today['flights']) }}</b><span>Flights at a gate</span></div>
                <div class="card stat"><b>{{ $today['busy_percent'] }}%</b><span>Time in use · {{ $today['idle_gates'] }} {{ \Illuminate\Support\Str::plural('gate', $today['idle_gates']) }} unused all day</span></div>
                <div class="card stat">
                    @if ($today['busiest'])
                        <b><a class="gate-pill" href="{{ route('gates.show', $today['busiest']) }}">{{ $today['busiest']->code }}</a>@unless ($selected) <small class="muted">{{ $today['busiest']->airport->icao_code }}</small>@endunless</b>
                        <span>Busiest gate · {{ $today['busiest_count'] }} flights</span>
                    @else
                        <b class="muted">—</b><span>Busiest gate · no flights yet</span>
                    @endif
                </div>
            </div>
        </section>
    </div>

    <div class="toolbar">
        <select id="airport-filter" aria-label="Airport">
            <option value="">All airports</option>
            @foreach ($airports as $airport)
                <option value="{{ $airport->icao_code }}" @selected($selected?->is($airport))>{{ $airport->label }} · {{ $airport->gates_count }} gates</option>
            @endforeach
        </select>
        <select id="status-filter" aria-label="Right now">
            <option value="">Any status right now</option>
            <option value="free">Free</option>
            <option value="in_use">In use</option>
            <option value="closed">Closed</option>
        </select>
    </div>

    <div class="table-wrap">
        <table id="gates" class="display hover" style="width:100%">
            <thead>
                <tr>
                    <th>Airport</th>
                    <th>Gate</th>
                    <th>Terminal</th>
                    <th>Right now</th>
                    <th>Current flight</th>
                    <th>Next flight at</th>
                    <th>Flights today</th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@push('scripts')
<script src="https://cdn.datatables.net/rowgroup/1.5.1/js/dataTables.rowGroup.min.js" integrity="sha384-T3BVwaNY2bpNuIPnMFf2CxjIZZAkCXv6+GxwAs50oId58u5/TIAvrY1eR3+aAHjH" crossorigin="anonymous"></script>
<script>
    $(function () {
        var dash = '<span class="muted">—</span>';
        var states = {
            free: 'Free',
            in_use: 'In use',
            closed: 'Closed'
        };

        function text(value) {
            return $('<span>').text(value).html();
        }

        var table = $('#gates').DataTable({
            serverSide: true,
            processing: true,
            pageLength: 50,
            // Always sorted by airport first, so each airport's gates stay together under its header row.
            orderFixed: { pre: [0, 'asc'] },
            order: [[1, 'asc']],
            ajax: {
                url: @json(route('gates.list')),
                data: function (params) {
                    params.airport = $('#airport-filter').val();
                    params.status = $('#status-filter').val();
                }
            },
            rowGroup: {
                dataSrc: 'airport_label',
                startRender: function (rows, group) {
                    var first = rows.data()[0];

                    return '<div class="group-head"><i class="fa-solid fa-passport terminal-icon" aria-hidden="true"></i>'
                        + '<a href="' + first.airport_url + '">' + text(group) + '</a></div>';
                }
            },
            columns: [
                { data: 'airport_name', name: 'airports.name', visible: false },
                { data: 'code', name: 'gates.code', render: function (code, type, row) {
                    return $('<a class="gate-pill">').attr({ href: row.url, title: 'View gate ' + code }).text(code).prop('outerHTML');
                } },
                { data: 'terminal', name: 'gates.terminal', render: function (terminal) { return 'Terminal ' + text(terminal); } },
                { data: 'status', name: 'status', orderable: false, searchable: false, render: function (status, type, row) {
                    var label = '<span class="gate-state ' + status + '"><span class="status-swatch ' + status.replace('_', '-') + '" aria-hidden="true"></span>' + states[status] + '</span>';

                    return status === 'closed' && row.closed_reason ? label + ' <span class="muted">· ' + text(row.closed_reason) + '</span>' : label;
                } },
                { data: 'current_callsign', name: 'current_callsign', orderable: false, searchable: false, render: function (callsign, type, row) {
                    if (!callsign) {
                        return dash;
                    }
                    return '<a class="mono" href="' + row.current_flight_url + '">' + text(callsign) + '</a> <span class="muted">until ' + row.current_until + '</span>';
                } },
                { data: 'next_starts_at', name: 'next_starts_at', orderable: false, searchable: false, className: 'num', render: function (value) { return value || dash; } },
                { data: 'flights_today_count', name: 'flights_today_count', searchable: false, className: 'num dt-right' }
            ],
            language: {
                search: 'Search:',
                searchPlaceholder: 'Airport, gate, terminal…'
            }
        });

        // The stat tiles are worked out per airport on the server, so switching airport reloads the page.
        $('#airport-filter').on('change', function () {
            window.location = @json(route('gates.index')) + (this.value ? '?airport=' + encodeURIComponent(this.value) : '');
        });

        $('#status-filter').on('change', function () {
            table.ajax.reload();
        });
    });
</script>
@endpush
