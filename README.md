# Gate Scheduler

Laravel app that fetches arrivals and departures for a set of airports from the
[OpenSky Network](https://openskynetwork.github.io/opensky-api/rest.html) and assigns them to gates.

## Running it

```bash
cp .env.example .env            # then set APP_KEY with: docker compose run --rm app php artisan key:generate
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan migrate --seed
```

- UI: http://localhost:8088/flights — pick an airport from the dropdown (e.g. "Frankfurt Airport (EDDF)"), then arrivals / departures in a DataTables grid (server-side paging, sorting and
  search via `yajra/laravel-datatables-oracle`, JSON at `/flights/list`). Click a callsign for the flight page
  (`/flights/{id}`): gate, window, the gate's neighbouring flights and any exceptions.
- **Airports** (http://localhost:8088/airports): every airport with gate and flight counts and how many gates are in
  use now; click an airport to open its flights. **Gates** (http://localhost:8088/gates): every gate grouped under a
  header row per airport, with an airport filter, what each gate is doing right now and its flights today; click a
  gate for its day schedule.
- **Reports** (http://localhost:8088/reports): every `allocations:validate` run, newest first. A report shows what that
  run repaired, the flight and gate numbers at that moment (overall and per airport), and every event logged since
  the previous report: fetch failures, flights moved off closed gates, flights left without a gate.
- The flights page also has a **live map** of aircraft within `LIVE_MAP_RADIUS_KM` (80 km) of the selected airport,
  from OpenSky's `/states/all` with a bounding box. This works without an OpenSky account: anonymous users get 400
  `/states` credits a day and each refresh costs 1. Responses are cached per airport for `LIVE_MAP_REFRESH_SECONDS`
  (60 s) so every open page shares one call, polling pauses while the tab is hidden, and when the limit is hit the
  map keeps the last positions and says when credits return. Aircraft we also hold a flight for link to its page.
- Each **flight page** has a **route map** of the path the aircraft flew, from OpenSky's `/tracks/all`. It is
  requested by the page after it loads (never by the crons), then cached per flight: 7 days for a track, 1 hour when
  OpenSky has none. Live and very recent tracks work without an account; past flights need `OPENSKY_CLIENT_ID` /
  `OPENSKY_CLIENT_SECRET`, and OpenSky keeps tracks for 30 days only. A track costs 4+ credits from the `/tracks` quota.
- The `scheduler` container runs `php artisan schedule:work`, so both crons run on their own.
- MySQL is exposed on `localhost:33061`; ports can be changed with `APP_PORT` / `FORWARD_DB_PORT`.

### OpenSky credentials

OpenSky's flight endpoints return `403` without an account. Create an API client on your OpenSky account page
and set `OPENSKY_CLIENT_ID` / `OPENSKY_CLIENT_SECRET`. Without them you can use demo data:

```bash
docker compose exec app php artisan db:seed --class=DemoFlightsSeeder   # 300 flights per airport
docker compose exec app php artisan flights:allocate
```

## Commands

| Command | What it does | Schedule |
|---|---|---|
| `flights:fetch [EDDF LROP …]` | Pulls arrivals + departures for every airport (or the ones given), stores new ones, then allocates gates | `FETCH_CRON` (every 10 min) |
| `flights:allocate [EDDF]` | Assigns every pending flight to a free gate at its airport | after each fetch |
| `allocations:validate` | Re-checks allocations, repairs conflicts, stores and prints statistics | `VALIDATE_CRON` (every 15 min) |
| `airports:list` | Airports with gate and flight counts | manual |
| `airports:add LIRF "Rome Fiumicino Airport" --city=Rome --lat=41.80 --lon=12.25` | Add an airport (it gets its own gates) | manual |
| `gates:restrict B8 "2025-01-10" "2025-01-12" --airport=EDDF --reason=Repairs` | Closes a gate for a period and moves the flights booked on it then to other free gates (same as the form on each gate page) | manual |
| `gates:restrictions` | Lists restrictions | manual |

## Settings (`.env`)

| Key | Default | Meaning |
|---|---|---|
| `AIRPORT_ICAO` | `LRCL` | The default airport in the UI and commands |
| `GATE_COUNT` | `20` | Gates each airport gets |
| `GATE_OCCUPANCY_MINUTES` | `90` | How long a flight holds its gate |
| `GATE_WINDOW_STRATEGY` | `flight_time` | How moment T is chosen (see below) |
| `FETCH_LOOKBACK_HOURS` | `48` | How far back each fetch looks |
| `LIVE_MAP_RADIUS_KM` | `80` | Radius of the live aircraft map around the airport |
| `LIVE_MAP_REFRESH_SECONDS` | `60` | How often the live map refreshes (and how long responses are cached) |

## Design notes

- **Airports** (`airports`: ICAO code, commercial name, city, coordinates). The migration adds 13 major airports;
  every one of them is fetched, and `AIRPORT_ICAO` (Cluj) is the default shown when none is chosen. Each flight belongs to the airport it was fetched for (`flights.airport_id`), so a
  Frankfurt→Bucharest flight can exist once as an EDDF departure and once as an LROP arrival.
- **Airlines.** A flight's `airline_code` is the first three letters of an airline callsign (`DLH4AB` → `DLH`);
  private callsigns get none. Its `airline_name` is looked up in `config/airlines.php` (~40 common carriers) when the
  flight is stored. Airlines missing from that list are shown by their code.
- **Gates** belong to an airport: the `create_gates_table` migration gives every airport A1–A10, B1–B10, and flights
  are only placed on their own airport's gates. The seeder adds the spec's example restriction: B8 at the default
  airport closed for repairs on 10–11 January 2025.
- **Moment T.** OpenSky only publishes flights in a nightly batch (previous day or earlier), so "first time we saw
  the flight" would give hundreds of flights the same T and most would get no gate. The default `flight_time`
  strategy uses the flight's own times instead: an arrival holds its gate from landing (`lastSeen`) for 90 minutes,
  a departure for the 90 minutes before take-off (`firstSeen`). Set `GATE_WINDOW_STRATEGY=observed` to use the
  first-observed timestamp as the spec's example suggests.
- **One flight per gate.** Windows are half-open `[start, end)`, so back-to-back flights may share a gate.
  Allocation runs in a transaction that locks the gate rows (`SELECT … FOR UPDATE`), so two runs cannot book the
  same gate; scheduled commands also use `withoutOverlapping()`.
- **Gate choice.** Flights are processed in order of their window start, and each takes the first free gate
  (by id). If none is free, the flight is marked `unallocated` and a `no_gate_available` exception is stored.
- **Closing a gate** (the "Close gate" form on each gate page, or `gates:restrict`) stores the restriction and
  immediately re-runs allocation for every flight booked on that gate during the closure: each moves to another
  free gate of the same airport, or becomes "No gate available" if none is free. This happens in the same locked
  transaction as allocation runs, and each move is logged as a `restriction_conflict` exception.
- **Validation** finds allocations that clash with a restriction added later, and overlaps (a safety net), then
  moves them to another free gate or drops them. Each run stores a `validation_reports` row and logs:
  flights allocated/unallocated per direction, gates free/occupied/restricted now, and exceptions by type.
- **Exceptions** (`allocation_exceptions`): `no_gate_available`, `fetch_failed`, `restriction_conflict`, `overlap`.

## Tests

```bash
docker compose exec app php artisan test
```

The core rules live in `GateSchedule` (pure PHP, unit tested without a database). Feature tests cover the
allocator, the validator, the OpenSky fetch (with faked HTTP) and the flights page, against the `gates_test`
MySQL database.
