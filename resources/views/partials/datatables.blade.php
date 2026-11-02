{{-- DataTables (server-side paging tables), loaded once per page, with styling that follows the page palette. --}}
@pushOnce('styles', 'datatables-css')
<link rel="stylesheet" href="https://cdn.datatables.net/2.1.8/css/dataTables.dataTables.min.css" integrity="sha384-bK2L0Q3Sn2fB8YZTIjKejpTUJXd8xNtKn4RcIm7KM3EX3orMlOI31hwtNH0iyky/" crossorigin="anonymous">
<style>
    .toolbar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
    .table-wrap { padding: 12px; }
    table.dataTable { white-space: nowrap; }
    table.dataTable td.num { font-variant-numeric: tabular-nums; }
    /* Blend DataTables' own styling into the page palette, including dark mode. */
    div.dt-container, div.dt-container .dt-info, div.dt-container .dt-length, div.dt-container .dt-search { color: var(--text); }
    div.dt-container .dt-search input, div.dt-container .dt-length select { background: var(--surface); color: var(--text); border: 1px solid var(--border); }
    div.dt-container .dt-paging .dt-paging-button { color: var(--text) !important; border-radius: 6px; }
    div.dt-container .dt-paging .dt-paging-button.current, div.dt-container .dt-paging .dt-paging-button.current:hover { background: var(--accent); color: #fff !important; border-color: var(--accent); }
    div.dt-container .dt-paging .dt-paging-button:hover { background: var(--bg); color: var(--text) !important; border-color: var(--border); }
    div.dt-container .dt-paging .dt-paging-button.disabled { color: var(--muted) !important; }
    table.dataTable > thead > tr > th { font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: var(--muted); border-bottom-color: var(--border); }
    table.dataTable > tbody > tr > td { border-top-color: var(--border); }
    table.dataTable.hover > tbody > tr:hover > * { box-shadow: inset 0 0 0 9999px color-mix(in srgb, var(--accent) 7%, transparent); }
    div.dt-processing > div:last-child > div { background: var(--accent); }
</style>
@endPushOnce

@pushOnce('scripts', 'datatables-js')
<script src="https://cdn.datatables.net/2.1.8/js/dataTables.min.js" integrity="sha384-MgwUq0TVErv5Lkj/jIAgQpC+iUIqwhwXxJMfrZQVAOhr++1MR02yXH8aXdPc3fk0" crossorigin="anonymous"></script>
@endPushOnce
