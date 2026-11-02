<?php

namespace Database\Seeders;

use App\Models\Airport;
use App\Models\Flight;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sample flights for every airport, for running the app without OpenSky credentials:
 * php artisan db:seed --class=DemoFlightsSeeder && php artisan flights:allocate
 */
class DemoFlightsSeeder extends Seeder
{
    private const AIRLINES = ['DLH', 'BAW', 'AFR', 'KLM', 'UAE', 'ROT', 'THY', 'RYR', 'SWR', 'AUA'];

    private const AIRPORTS = ['EDDF', 'EGLL', 'LFPG', 'EHAM', 'OMDB', 'LROP', 'LRCL', 'LTFM', 'LEMD', 'LSZH', 'LOWW', 'KJFK'];

    public function run(int $count = 300): void
    {
        // Airports that already have flights are left alone, so the seeder can be re-run after adding airports.
        Airport::whereDoesntHave('flights')->each(fn (Airport $airport) => $this->seedAirport($airport, $count));
    }

    private function seedAirport(Airport $airport, int $count): void
    {
        $others = array_values(array_diff(self::AIRPORTS, [$airport->icao_code]));
        $now = now()->startOfMinute();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $direction = $i % 2 === 0 ? Flight::ARRIVAL : Flight::DEPARTURE;
            $other = $others[array_rand($others)];
            $flightMinutes = random_int(60, 600);

            if ($direction === Flight::ARRIVAL) {
                $lastSeen = $now->copy()->subMinutes(random_int(60, 36 * 60));
                $firstSeen = $lastSeen->copy()->subMinutes($flightMinutes);
            } else {
                $firstSeen = $now->copy()->subMinutes(random_int(60, 36 * 60));
                $lastSeen = $firstSeen->copy()->addMinutes($flightMinutes);
            }

            $airline = self::AIRLINES[array_rand(self::AIRLINES)];
            $rows[] = [
                'airport_id' => $airport->id,
                'direction' => $direction,
                'icao24' => bin2hex(random_bytes(3)),
                'callsign' => $airline.random_int(100, 9999),
                'airline_code' => $airline,
                'airline_name' => Flight::airlineName($airline),
                'departure_airport' => $direction === Flight::ARRIVAL ? $other : $airport->icao_code,
                'arrival_airport' => $direction === Flight::ARRIVAL ? $airport->icao_code : $other,
                'first_seen' => $firstSeen,
                'last_seen' => $lastSeen,
                'observed_at' => $now,
                'gate_status' => Flight::STATUS_PENDING,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('flights')->insertOrIgnore($rows);
    }
}
