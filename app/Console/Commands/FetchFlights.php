<?php

namespace App\Console\Commands;

use App\Models\Airport;
use App\Models\AllocationException;
use App\Models\Flight;
use App\Services\OpenSky\OpenSkyClient;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchFlights extends Command
{
    protected $signature = 'flights:fetch
        {airports?* : ICAO codes to fetch; defaults to every airport}
        {--no-allocate : Only store the flights, do not assign gates}';

    protected $description = 'Fetch arrivals and departures from OpenSky for the selected airports, then allocate gates';

    public function handle(OpenSkyClient $openSky): int
    {
        $airports = $this->airports();

        if ($airports === null) {
            return self::FAILURE;
        }

        if ($airports->isEmpty()) {
            $this->warn('There are no airports yet. Add one with: php artisan airports:add LIRF "Rome Fiumicino Airport"');

            return self::SUCCESS;
        }

        $end = CarbonImmutable::now()->startOfMinute();
        $begin = $end->subHours(config('gates.fetch_lookback_hours'));
        $failed = false;

        foreach ($airports as $airport) {
            foreach ([Flight::ARRIVAL, Flight::DEPARTURE] as $direction) {
                try {
                    $rows = $openSky->flights($direction, $airport->icao_code, $begin, $end);
                } catch (Throwable $e) {
                    $failed = true;
                    $this->recordFailure($airport, $direction, $e);

                    continue;
                }

                $now = now();
                $rows = array_map(fn (array $row) => $row + [
                    'airport_id' => $airport->id,
                    'observed_at' => $now,
                    'gate_status' => Flight::STATUS_PENDING,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $rows);

                // The unique (airport_id, direction, icao24, first_seen) key matches flights we already have. For those,
                // only refresh what OpenSky learns later (a departure still in the air has no destination yet), so
                // observed_at keeps the moment we first saw each flight and the gate allocation stays untouched.
                $existing = $airport->flights()->where('direction', $direction)->count();
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('flights')->upsert(
                        $chunk,
                        ['airport_id', 'direction', 'icao24', 'first_seen'],
                        ['last_seen', 'departure_airport', 'arrival_airport'],
                    );
                }
                $new = $airport->flights()->where('direction', $direction)->count() - $existing;

                $this->info(sprintf('%s %ss: %d received, %d new.', $airport->icao_code, $direction, count($rows), $new));
            }
        }

        if (! $this->option('no-allocate')) {
            $this->call('flights:allocate');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, Airport>|null null when an unknown code was given
     */
    private function airports(): ?Collection
    {
        $codes = array_map('strtoupper', $this->argument('airports'));

        if ($codes === []) {
            return Airport::orderBy('icao_code')->get();
        }

        $airports = Airport::whereIn('icao_code', $codes)->get();
        $unknown = array_diff($codes, $airports->pluck('icao_code')->all());

        if ($unknown !== []) {
            $this->error('Unknown airport(s): '.implode(', ', $unknown).'. Add them with airports:add.');

            return null;
        }

        return $airports;
    }

    private function recordFailure(Airport $airport, string $direction, Throwable $e): void
    {
        $message = "Fetching {$direction}s for {$airport->icao_code} failed: {$e->getMessage()}";

        $this->error($message);
        Log::error($message);

        AllocationException::create([
            'airport_id' => $airport->id,
            'type' => AllocationException::FETCH_FAILED,
            'message' => mb_substr($message, 0, 1000),
            'context' => ['direction' => $direction, 'airport' => $airport->icao_code],
        ]);
    }
}
