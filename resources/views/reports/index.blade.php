@extends('layouts.app')

@section('title', 'Reports')

@include('partials.datatables')

@php
    $labels = \App\Models\AllocationException::TYPE_LABELS;
@endphp

@section('content')
    <div class="header">
        <div>
            <h1>Reports</h1>
            <div class="muted">Each validation run checks every allocation, repairs conflicts and records the numbers · click a report to see what happened</div>
        </div>
    </div>

    <div class="stat-groups two-up">
        <section class="stat-group">
            <h2>Validation runs</h2>
            <div class="stats">
                <div class="card stat"><b>{{ number_format($count) }}</b><span>Reports stored</span></div>
                <div class="card stat">
                    @if ($latest)
                        <b><a class="heading-link" href="{{ route('reports.show', $latest) }}">{{ $latest->created_at->format('H:i') }}</a></b><span>Last run · {{ $latest->created_at->diffForHumans() }}</span>
                    @else
                        <b class="muted">—</b><span>Last run · none yet</span>
                    @endif
                </div>
                <div class="card stat"><b>{{ $schedule }}</b><span>Schedule · allocations:validate</span></div>
            </div>
        </section>
        <section class="stat-group">
            <h2>Logged <span class="muted">· last 24 hours</span></h2>
            <div class="stats">
                @foreach ([\App\Models\AllocationException::RESTRICTION_CONFLICT, \App\Models\AllocationException::NO_GATE_AVAILABLE, \App\Models\AllocationException::FETCH_FAILED] as $type)
                    <div class="card stat"><b>{{ number_format($lastDay[$type] ?? 0) }}</b><span>{{ $labels[$type] }}</span></div>
                @endforeach
            </div>
        </section>
    </div>

    <div class="table-wrap">
        <table id="reports" class="display hover" style="width:100%">
            <thead>
                <tr>
                    <th>Report</th>
                    <th>Run at (UTC)</th>
                    <th>Flights</th>
                    <th>Gate allocated</th>
                    <th>No gate available</th>
                    <th>Gates in use</th>
                    <th>Gates closed</th>
                    <th>Conflicts fixed</th>
                    <th>Logged since previous</th>
                </tr>
            </thead>
        </table>
    </div>
@endsection

@push('scripts')
<script>
    $(function () {
        var number = { className: 'num dt-right', orderable: false };

        $('#reports').DataTable({
            serverSide: true,
            processing: true,
            searching: false,
            pageLength: 25,
            order: [[1, 'desc']],
            ajax: @json(route('reports.list')),
            columns: [
                { data: 'id', name: 'id', render: function (id, type, row) {
                    return '<a class="mono" href="' + row.url + '">#' + id + '</a>';
                } },
                { data: 'created_at', name: 'created_at', className: 'num' },
                $.extend({ data: 'flights' }, number),
                $.extend({ data: 'allocated' }, number),
                $.extend({ data: 'unallocated' }, number),
                $.extend({ data: 'gates_in_use' }, number),
                $.extend({ data: 'gates_closed' }, number),
                $.extend({ data: 'fixed' }, number),
                $.extend({ data: 'exceptions' }, number)
            ],
            language: { emptyTable: 'No reports yet. They are created by php artisan allocations:validate, which the scheduler runs every 15 minutes.' }
        });
    });
</script>
@endpush
