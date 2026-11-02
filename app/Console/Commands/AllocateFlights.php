<?php

namespace App\Console\Commands;

use App\Models\Airport;
use App\Services\Allocation\GateAllocator;
use Illuminate\Console\Command;

class AllocateFlights extends Command
{
    protected $signature = 'flights:allocate {airport? : ICAO code; defaults to every airport with pending flights}';

    protected $description = 'Assign every pending flight to a free gate at its airport';

    public function handle(GateAllocator $allocator): int
    {
        $airport = null;

        if ($code = $this->argument('airport')) {
            if (! $airport = Airport::findByCode($code)) {
                $this->error("Unknown airport [{$code}].");

                return self::FAILURE;
            }
        }

        $result = $allocator->allocatePending($airport);

        $this->info("Allocated {$result['allocated']} flights, {$result['unallocated']} had no free gate.");

        return self::SUCCESS;
    }
}
