<?php

namespace Tests\Feature;

use App\Models\Airport;
use App\Models\AllocationException;
use App\Models\ValidationReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportsPageTest extends TestCase
{
    use RefreshDatabase;

    private function logException(string $type, string $message, string $at): AllocationException
    {
        $exception = AllocationException::create([
            'airport_id' => Airport::findByCode('EDDF')->id,
            'type' => $type,
            'message' => $message,
        ]);
        $exception->forceFill(['created_at' => $at])->save();

        return $exception;
    }

    /**
     * Two validation runs, 15 minutes apart, each storing what the command stores.
     *
     * @return array{ValidationReport, ValidationReport}
     */
    private function twoReports(): array
    {
        Carbon::setTestNow('2025-01-10 10:00:00');
        $this->artisan('allocations:validate')->assertSuccessful();

        Carbon::setTestNow('2025-01-10 10:15:00');
        $this->artisan('allocations:validate')->assertSuccessful();
        Carbon::setTestNow();

        return [ValidationReport::oldest('id')->first(), ValidationReport::latest('id')->first()];
    }

    public function test_the_sidebar_links_to_the_reports_page(): void
    {
        $this->twoReports();

        $this->get(route('airports.index'))->assertSee('href="'.route('reports.index').'"', false);
        $this->get(route('reports.index'))
            ->assertOk()
            ->assertSeeInOrder(['<b>2</b>', 'Reports stored'], false)
            ->assertSee('Every 15 minutes')
            ->assertSee('id="reports"', false);
    }

    public function test_the_list_endpoint_returns_each_report_with_its_numbers(): void
    {
        [$first, $second] = $this->twoReports();

        $response = $this->getJson(route('reports.list', [
            'draw' => 1, 'start' => 0, 'length' => 10,
            'columns' => [
                ['data' => 'id', 'name' => 'id', 'searchable' => 'false', 'orderable' => 'true', 'search' => ['value' => '']],
                ['data' => 'created_at', 'name' => 'created_at', 'searchable' => 'false', 'orderable' => 'true', 'search' => ['value' => '']],
            ],
            'order' => [['column' => 1, 'dir' => 'desc']],
            'search' => ['value' => ''],
        ]))->assertOk();

        $response->assertJsonPath('recordsTotal', 2)
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.created_at', '2025-01-10 10:15')
            ->assertJsonPath('data.0.url', route('reports.show', $second))
            ->assertJsonPath('data.0.gates_in_use', 0)
            ->assertJsonPath('data.1.id', $first->id);
    }

    public function test_a_report_shows_only_what_was_logged_since_the_previous_one(): void
    {
        $this->logException(AllocationException::FETCH_FAILED, 'Before the first report', '2025-01-10 09:59:00');
        $this->logException(AllocationException::RESTRICTION_CONFLICT, 'DLH1 at gate A1: moved to gate A2.', '2025-01-10 10:05:00');
        [$first, $second] = $this->twoReports();
        $this->logException(AllocationException::FETCH_FAILED, 'After the second report', '2025-01-10 10:20:00');

        $this->get(route('reports.show', $second))
            ->assertOk()
            ->assertSee('Report <span class="mono">#'.$second->id.'</span>', false)
            ->assertSee('covers since 2025-01-10 10:00')
            ->assertSee('DLH1 at gate A1: moved to gate A2.')
            ->assertSee('Moved off a closed gate · 1')
            ->assertDontSee('Before the first report')
            ->assertDontSee('After the second report')
            ->assertSee('href="'.route('reports.show', $first).'"', false);

        $this->get(route('reports.show', $first))
            ->assertOk()
            ->assertSee('Before the first report')
            ->assertDontSee('DLH1 at gate A1');
    }

    public function test_a_report_lists_its_airports_and_unknown_reports_are_not_found(): void
    {
        [, $second] = $this->twoReports();

        $this->get(route('reports.show', $second))->assertOk()->assertSeeInOrder(['Per airport', 'EDDF', 'LRCL']);
        $this->get('/reports/999999')->assertNotFound();
    }
}
