<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Gate Scheduler')</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <style>
        :root {
            --bg: #f6f7f9; --surface: #ffffff; --text: #1d2330; --muted: #667085; --border: #e3e6eb;
            --accent: #1f5fbf; --ok: #1a7f4b; --ok-bg: #e6f4ec; --warn: #a15c00; --warn-bg: #fdf1dc;
            --bad: #b42318; --bad-bg: #fde8e7; --departure: #6941c6;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #12151b; --surface: #1a1e26; --text: #e6e8ec; --muted: #98a2b3; --border: #2b313c;
                --accent: #6ea0ff; --ok: #5fd197; --ok-bg: #173325; --warn: #f2b45c; --warn-bg: #3a2c14;
                --bad: #ff8a80; --bad-bg: #3d1c1a; --departure: #9e77ed;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { width: 100%; max-width: 1480px; min-width: 0; margin: 0 auto; padding: 24px 16px 48px; }
        /* App shell: a sidebar beside the page. It narrows to icons on mid-size screens and becomes a top bar on phones. */
        .shell { display: grid; grid-template-columns: 220px minmax(0, 1fr); min-height: 100vh; }
        .sidebar { position: sticky; top: 0; height: 100vh; background: var(--surface); border-right: 1px solid var(--border); padding: 16px 12px; display: flex; flex-direction: column; gap: 18px; }
        .sidebar .brand { display: flex; align-items: center; gap: 10px; padding: 4px 8px; font-weight: 700; color: var(--text); text-decoration: none; white-space: nowrap; }
        .sidebar .brand i { color: var(--accent); font-size: 18px; width: 20px; text-align: center; }
        .sidebar nav { display: flex; flex-direction: column; gap: 2px; }
        .sidebar nav a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 8px; color: var(--muted); text-decoration: none; white-space: nowrap; }
        .sidebar nav a i { width: 20px; text-align: center; }
        .sidebar nav a:hover { background: var(--bg); color: var(--text); }
        .sidebar nav a.active { background: color-mix(in srgb, var(--accent) 12%, transparent); color: var(--accent); font-weight: 600; }
        .sidebar .sidebar-footer { margin-top: auto; padding: 0 8px; font-size: 12px; }
        @media (max-width: 1200px) {
            .shell { grid-template-columns: 64px minmax(0, 1fr); }
            .sidebar { padding: 16px 8px; align-items: center; }
            .sidebar .label, .sidebar .sidebar-footer { display: none; }
            .sidebar nav a { justify-content: center; padding: 10px; }
        }
        @media (max-width: 700px) {
            .shell { grid-template-columns: minmax(0, 1fr); }
            .sidebar { position: static; height: auto; flex-direction: row; align-items: center; padding: 8px 12px; border-right: 0; border-bottom: 1px solid var(--border); gap: 12px; }
            .sidebar .label { display: inline; }
            .sidebar .brand .label { display: none; }
            .sidebar nav { flex-direction: row; }
            .sidebar nav a { padding: 8px 10px; }
        }
        h1 { font-size: 20px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 0 0 10px; }
        a { color: var(--accent); }
        a.heading-link { color: inherit; text-decoration: none; }
        a.heading-link:hover { color: var(--accent); }
        .muted { color: var(--muted); }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .header { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 14px 16px; }
        .stat-groups { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 12px 20px; margin-bottom: 20px; }
        .stat-group h2 { font-size: 13px; margin: 0 0 8px; }
        .stat-group h2 .muted { font-weight: 400; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 12px; margin-bottom: 20px; }
        /* Groups in a row share one height, and tiles fill it, so wrapped labels never leave boxes of different sizes. */
        .stat-group { display: flex; flex-direction: column; }
        /* Exactly two equal columns (one on narrow screens), so paired groups always split the row 50/50. */
        .stat-groups.two-up { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px 24px; }
        @media (max-width: 900px) { .stat-groups.two-up { grid-template-columns: minmax(0, 1fr); } }
        .stat-groups .full-row { grid-column: 1 / -1; }
        .stat-group .stats { flex: 1; margin-bottom: 0; grid-auto-rows: 1fr; }
        .stat b small { font-size: 14px; font-weight: 600; }
        .stat b { display: block; font-size: 20px; font-variant-numeric: tabular-nums; }
        .stat span { color: var(--muted); font-size: 12px; }
        .stat .split-counts { white-space: nowrap; }
        .stat .status-swatch { width: 16px; height: 9px; margin-right: 6px; vertical-align: 0; }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .gate-pill { transition: opacity .15s, background-color .15s; display: inline-block; min-width: 34px; padding: 2px 8px; border-radius: 999px; background: #000; color: #fff; font-weight: 700; font-size: 12px; text-align: center; letter-spacing: .02em; }
        @media (prefers-color-scheme: dark) { .gate-pill { box-shadow: 0 0 0 1px var(--border); } }
        a.gate-pill { text-decoration: none; }
        a.gate-pill:not(.free):not(.in-use):not(.closed):hover { background: var(--accent); }
        .button { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 8px; background: var(--accent); color: #fff; text-decoration: none; font-weight: 600; border: 1px solid var(--accent); }
        .button:hover { filter: brightness(1.1); }
        .button.secondary { background: var(--surface); color: var(--text); border-color: var(--border); }
        .button.secondary:hover { background: var(--bg); filter: none; }
        .button.small { padding: 3px 10px; font-size: 12px; gap: 6px; font-weight: 500; }
        /* Gate status palette, used wherever a gate's state is shown: tint, solid fill and stripes stay apart without colour. */
        .gate-pill.free, .status-swatch.free { background: var(--ok-bg); color: var(--ok); box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--ok) 45%, transparent); }
        .gate-pill.in-use, .status-swatch.in-use { background: var(--accent); color: #fff; box-shadow: none; }
        .gate-pill.closed, .status-swatch.closed { background: repeating-linear-gradient(45deg, var(--bad-bg), var(--bad-bg) 4px, var(--surface) 4px, var(--surface) 8px); color: var(--bad); box-shadow: inset 0 0 0 1px var(--bad); }
        a.gate-pill.free:hover, a.gate-pill.in-use:hover, a.gate-pill.closed:hover { opacity: .65; }
        .status-swatch { display: inline-block; width: 22px; height: 12px; border-radius: 999px; vertical-align: -1px; }
        .icon-button { font: inherit; color: var(--text); background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 4px 8px; cursor: pointer; }
        .icon-button:hover { background: var(--bg); }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .badge.allocated { color: var(--ok); background: var(--ok-bg); }
        .badge.unallocated { color: var(--bad); background: var(--bad-bg); }
        .badge.pending, .badge.neutral { color: var(--warn); background: var(--warn-bg); }
        /* Arrival / departure: the same plane icons as the flights tabs, in the colours used on the gate timeline. */
        .direction-badge { display: inline-flex; align-items: center; gap: 7px; padding: 4px 12px 4px 10px; border-radius: 999px; color: #fff; font-size: 13px; font-weight: 700; letter-spacing: .02em; }
        .direction-badge.arrival { background: var(--accent); }
        .direction-badge.departure { background: var(--departure); }
        .direction-badge.large { align-self: center; gap: 10px; padding: 9px 20px 9px 16px; font-size: 16px; box-shadow: 0 2px 8px color-mix(in srgb, currentColor 0%, rgba(0, 0, 0, .18)); }
        .direction-badge.large i { font-size: 20px; }
        @media (prefers-color-scheme: dark) { .direction-badge { color: #12151b; } }
        .table-wrap { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; overflow-x: auto; }
        table.plain { width: 100%; border-collapse: collapse; white-space: nowrap; }
        table.plain th, table.plain td { text-align: left; padding: 9px 12px; border-bottom: 1px solid var(--border); }
        table.plain th { font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: var(--muted); font-weight: 600; }
        table.plain tbody tr:last-child td { border-bottom: 0; }
        select, input { font: inherit; color: var(--text); background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; }
    </style>
    @stack('styles')
</head>
<body>
    <div class="shell">
        <aside class="sidebar">
            <a class="brand" href="{{ route('flights.index') }}" title="Gate Scheduler"><i class="fa-solid fa-plane-circle-check" aria-hidden="true"></i><span class="label">Gate Scheduler</span></a>
            <nav aria-label="Main">
                <a href="{{ route('airports.index') }}" title="Airports" @class(['active' => request()->routeIs('airports.*')])><i class="fa-solid fa-passport" aria-hidden="true"></i><span class="label">Airports</span></a>
                <a href="{{ route('flights.index') }}" title="Flights" @class(['active' => request()->routeIs('flights.*')])><i class="fa-solid fa-plane" aria-hidden="true"></i><span class="label">Flights</span></a>
                <a href="{{ route('gates.index') }}" title="Gates" @class(['active' => request()->routeIs('gates.*')])><i class="fa-solid fa-person-walking-luggage" aria-hidden="true"></i><span class="label">Gates</span></a>
                <a href="{{ route('reports.index') }}" title="Reports" @class(['active' => request()->routeIs('reports.*')])><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i><span class="label">Reports</span></a>
            </nav>
            <div class="sidebar-footer muted">Times in UTC</div>
        </aside>
        <main>
            @yield('content')
        </main>
    </div>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    @stack('scripts')
</body>
</html>
