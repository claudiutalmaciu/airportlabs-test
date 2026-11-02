<?php

namespace App\Console\Commands;

use App\Models\GateRestriction;
use Illuminate\Console\Command;

class ListGateRestrictions extends Command
{
    protected $signature = 'gates:restrictions';

    protected $description = 'List gate restrictions';

    public function handle(): int
    {
        $this->table(
            ['ID', 'Airport', 'Gate', 'From (UTC)', 'To (UTC)', 'Reason'],
            GateRestriction::with('gate.airport')->orderBy('starts_at')->get()->map(fn (GateRestriction $r) => [
                $r->id, $r->gate->airport->icao_code, $r->gate->code, $r->starts_at, $r->ends_at, $r->reason,
            ]),
        );

        return self::SUCCESS;
    }
}
