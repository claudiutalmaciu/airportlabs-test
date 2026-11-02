<?php

namespace App\Console\Commands;

use App\Models\Airport;
use Illuminate\Console\Command;

class ListAirports extends Command
{
    protected $signature = 'airports:list';

    protected $description = 'List airports with their gate and flight counts';

    public function handle(): int
    {
        $this->table(
            ['ICAO', 'Name', 'City', 'Gates', 'Flights'],
            Airport::withCount(['gates', 'flights'])->orderBy('icao_code')->get()->map(fn (Airport $airport) => [
                $airport->icao_code,
                $airport->name,
                $airport->city,
                $airport->gates_count,
                $airport->flights_count,
            ]),
        );

        return self::SUCCESS;
    }
}
