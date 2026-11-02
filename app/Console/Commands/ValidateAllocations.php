<?php

namespace App\Console\Commands;

use App\Models\Airport;
use App\Models\ValidationReport;
use App\Services\Allocation\AllocationStats;
use App\Services\Allocation\AllocationValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ValidateAllocations extends Command
{
    protected $signature = 'allocations:validate';

    protected $description = 'Check gate allocations against the rules, repair conflicts and report statistics';

    public function handle(AllocationValidator $validator, AllocationStats $stats): int
    {
        $issues = $validator->run();

        $airports = Airport::orderBy('icao_code')->get();

        $report = [
            'issues' => $issues,
            'totals' => $stats->snapshot(),
            'airports' => $airports->mapWithKeys(fn (Airport $airport) => [$airport->icao_code => $stats->snapshot($airport)])->all(),
        ];

        ValidationReport::create(['stats' => $report]);
        Log::info('Gate allocation report', $report);

        $this->table(
            ['Airport', 'Flights', 'Allocated', 'Arr. / Dep. without gate', 'Pending', 'Gates free / total', 'Occupied / restricted', 'Exceptions'],
            collect($report['airports'])->put('All', $report['totals'])->map(fn (array $s, string $code) => [
                $code,
                $s['flights']['total'],
                $s['flights']['allocated'],
                "{$s['flights']['arrival']['unallocated']} / {$s['flights']['departure']['unallocated']}",
                $s['flights']['arrival']['pending'] + $s['flights']['departure']['pending'],
                "{$s['gates']['free']} / {$s['gates']['total']}",
                "{$s['gates']['occupied']} / {$s['gates']['restricted']}",
                collect($s['exceptions_since_last_report'])->map(fn ($n, $type) => "{$type}={$n}")->implode(', ') ?: '—',
            ])->values(),
        );

        $this->table(['Check', 'Count'], [
            ['Restriction conflicts fixed', $issues['restriction_conflicts']],
            ['Overlaps fixed', $issues['overlaps']],
            ['Reallocated / dropped', "{$issues['reallocated']} / {$issues['dropped']}"],
            ['Allocations with wrong duration', $issues['wrong_duration']],
        ]);

        return self::SUCCESS;
    }
}
