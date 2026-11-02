<?php

namespace App\Console\Commands;

use App\Models\Airport;
use Illuminate\Console\Command;

class AddAirport extends Command
{
    protected $signature = 'airports:add
        {icao : ICAO code, e.g. LIRF}
        {name : Commercial name, e.g. "Rome Fiumicino Airport"}
        {--city=}
        {--lat= : Latitude, for the live aircraft map}
        {--lon= : Longitude, for the live aircraft map}';

    protected $description = 'Add an airport and give it its gates';

    public function handle(): int
    {
        $code = strtoupper($this->argument('icao'));

        if (! preg_match('/^[A-Z0-9]{4}$/', $code)) {
            $this->error('An ICAO code is 4 letters or digits.');

            return self::FAILURE;
        }

        if (Airport::findByCode($code)) {
            $this->error("{$code} already exists.");

            return self::FAILURE;
        }

        $airport = Airport::create([
            'icao_code' => $code,
            'name' => $this->argument('name'),
            'city' => $this->option('city'),
            'latitude' => $this->option('lat'),
            'longitude' => $this->option('lon'),
        ]);
        $airport->ensureGates();

        $this->info("Added {$airport->label} with {$airport->gates()->count()} gates.");

        return self::SUCCESS;
    }
}
