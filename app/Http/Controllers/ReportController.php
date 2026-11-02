<?php

namespace App\Http\Controllers;

use App\Models\AllocationException;
use App\Models\ValidationReport;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class ReportController
{
    public function index(): View
    {
        $latest = ValidationReport::latest('id')->first();

        return view('reports.index', [
            'count' => ValidationReport::count(),
            'latest' => $latest,
            'lastDay' => AllocationException::where('created_at', '>', now()->subDay())
                ->selectRaw('type, COUNT(*) as total')->groupBy('type')
                ->pluck('total', 'type')->map(fn ($n) => (int) $n),
            'schedule' => $this->describeCron(config('gates.validate_cron')),
        ]);
    }

    /**
     * Server-side DataTables endpoint for the reports list. Numbers come from each report's stored snapshot.
     */
    public function reportsList(): JsonResponse
    {
        $stat = fn (ValidationReport $report, string $path) => (int) data_get($report->stats, $path, 0);

        return DataTables::eloquent(ValidationReport::query()->select(['id', 'stats', 'created_at']))
            ->addColumn('url', fn (ValidationReport $report) => route('reports.show', $report))
            ->editColumn('created_at', fn (ValidationReport $report) => $report->created_at->format('Y-m-d H:i'))
            ->addColumn('flights', fn (ValidationReport $report) => $stat($report, 'totals.flights.total'))
            ->addColumn('allocated', fn (ValidationReport $report) => $stat($report, 'totals.flights.allocated'))
            ->addColumn('unallocated', fn (ValidationReport $report) => $stat($report, 'totals.flights.unallocated'))
            ->addColumn('gates_in_use', fn (ValidationReport $report) => $stat($report, 'totals.gates.occupied'))
            ->addColumn('gates_closed', fn (ValidationReport $report) => $stat($report, 'totals.gates.restricted'))
            ->addColumn('fixed', fn (ValidationReport $report) => $stat($report, 'issues.restriction_conflicts') + $stat($report, 'issues.overlaps'))
            ->addColumn('exceptions', fn (ValidationReport $report) => array_sum(data_get($report->stats, 'totals.exceptions_since_last_report', [])))
            ->toJson();
    }

    /**
     * One report: the numbers it recorded, per airport, and everything logged since the report before it.
     */
    public function show(ValidationReport $report): View
    {
        $previous = ValidationReport::where('id', '<', $report->id)->latest('id')->first();
        $next = ValidationReport::where('id', '>', $report->id)->oldest('id')->first();

        $exceptions = AllocationException::with(['airport', 'flight'])
            ->when($previous, fn ($query) => $query->where('created_at', '>', $previous->created_at))
            ->where('created_at', '<=', $report->created_at)
            ->latest('id')
            ->get();

        return view('reports.show', [
            'report' => $report,
            'previous' => $previous,
            'next' => $next,
            'exceptions' => $exceptions,
            'exceptionCounts' => $exceptions->countBy('type'),
        ]);
    }

    /**
     * "Every 15 minutes" for simple interval schedules; anything else is shown as the cron expression itself.
     */
    private function describeCron(string $cron): string
    {
        if (preg_match('#^\*/(\d+) \* \* \* \*$#', trim($cron), $matches)) {
            return "Every {$matches[1]} minutes";
        }

        return $cron;
    }
}
