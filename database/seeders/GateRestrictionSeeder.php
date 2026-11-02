<?php

namespace Database\Seeders;

use App\Models\Airport;
use App\Models\GateRestriction;
use Illuminate\Database\Seeder;

class GateRestrictionSeeder extends Seeder
{
    /**
     * The example from the spec: gate B8 of the default airport is under repair on 10-11 January 2025.
     */
    public function run(): void
    {
        $gate = Airport::default()?->gates()->where('code', 'B8')->first();

        if (! $gate) {
            return;
        }

        GateRestriction::firstOrCreate([
            'gate_id' => $gate->id,
            'starts_at' => '2025-01-10 00:00:00',
            'ends_at' => '2025-01-12 00:00:00',
        ], ['reason' => 'Repairs']);
    }
}
