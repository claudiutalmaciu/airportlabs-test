<?php

namespace App\Console\Commands;

use App\Models\Airport;
use App\Services\Allocation\GateRestrictor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RestrictGate extends Command
{
    protected $signature = 'gates:restrict {gate : Gate code, e.g. B8} {starts : Start, e.g. "2025-01-10 00:00"} {ends : End (exclusive)} {--airport= : ICAO code; defaults to the configured airport} {--reason=Maintenance}';

    protected $description = 'Close a gate for a period and move the flights booked on it then to other free gates';

    public function handle(GateRestrictor $restrictor): int
    {
        $airport = $this->option('airport') ? Airport::findByCode($this->option('airport')) : Airport::default();

        if (! $airport) {
            $this->error("Unknown airport [{$this->option('airport')}].");

            return self::FAILURE;
        }

        $gate = $airport->gates()->where('code', strtoupper($this->argument('gate')))->first();

        if (! $gate) {
            $this->error("Unknown gate [{$this->argument('gate')}] at {$airport->icao_code}.");

            return self::FAILURE;
        }

        $starts = CarbonImmutable::parse($this->argument('starts'), 'UTC');
        $ends = CarbonImmutable::parse($this->argument('ends'), 'UTC');

        if ($ends <= $starts) {
            $this->error('The end must be after the start.');

            return self::FAILURE;
        }

        $result = $restrictor->restrict($gate, $starts, $ends, $this->option('reason'));

        $this->info("{$airport->icao_code} gate {$gate->code} blocked from {$starts} to {$ends} UTC.");

        foreach ($result['moved'] as $move) {
            $this->line(sprintf('  %s moved to gate %s', $move['flight']->callsign ?? $move['flight']->icao24, $move['gate']->code));
        }

        foreach ($result['dropped'] as $flight) {
            $this->warn(sprintf('  %s: no other gate was free, now without a gate', $flight->callsign ?? $flight->icao24));
        }

        return self::SUCCESS;
    }
}
