<?php

namespace Tests\Unit;

use App\Services\Allocation\GateSchedule;
use App\Services\Allocation\TimeWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class GateScheduleTest extends TestCase
{
    private function window(string $start, int $minutes = 90): TimeWindow
    {
        return TimeWindow::starting(CarbonImmutable::parse($start), $minutes);
    }

    public function test_it_gives_the_first_gate_when_everything_is_free(): void
    {
        $schedule = new GateSchedule([1, 2, 3]);

        $this->assertSame(1, $schedule->reserve($this->window('2025-01-10 10:00')));
    }

    public function test_it_never_puts_two_overlapping_flights_on_the_same_gate(): void
    {
        $schedule = new GateSchedule([1, 2]);

        $first = $schedule->reserve($this->window('2025-01-10 10:00'));
        $second = $schedule->reserve($this->window('2025-01-10 11:00'));

        $this->assertSame(1, $first);
        $this->assertSame(2, $second);
    }

    public function test_back_to_back_windows_can_share_a_gate(): void
    {
        $schedule = new GateSchedule([1, 2]);

        $schedule->reserve($this->window('2025-01-10 10:00'));

        $this->assertSame(1, $schedule->reserve($this->window('2025-01-10 11:30')));
    }

    public function test_it_returns_null_when_every_gate_is_taken(): void
    {
        $schedule = new GateSchedule([1, 2]);

        $schedule->reserve($this->window('2025-01-10 10:00'));
        $schedule->reserve($this->window('2025-01-10 10:30'));

        $this->assertNull($schedule->reserve($this->window('2025-01-10 11:00')));
    }

    public function test_a_restricted_gate_is_skipped(): void
    {
        // B8 (id 2 here) is under repair on 10-11 January 2025.
        $schedule = new GateSchedule([2, 3]);
        $schedule->restrict(2, new TimeWindow(CarbonImmutable::parse('2025-01-10'), CarbonImmutable::parse('2025-01-12')));

        $this->assertSame(3, $schedule->reserve($this->window('2025-01-11 08:00')));
        $this->assertFalse($schedule->isFree(2, $this->window('2025-01-11 23:00')));
        $this->assertTrue($schedule->isFree(2, $this->window('2025-01-12 00:00')));
    }

    public function test_a_restriction_that_only_partly_overlaps_still_blocks_the_gate(): void
    {
        $schedule = new GateSchedule([1]);
        $schedule->restrict(1, new TimeWindow(CarbonImmutable::parse('2025-01-10 11:00'), CarbonImmutable::parse('2025-01-10 11:05')));

        $this->assertNull($schedule->reserve($this->window('2025-01-10 10:00')));
    }

    public function test_an_unknown_gate_is_never_free(): void
    {
        $schedule = new GateSchedule([1]);

        $this->assertFalse($schedule->isFree(99, $this->window('2025-01-10 10:00')));
    }

    public function test_the_occupancy_length_is_respected(): void
    {
        $schedule = new GateSchedule([1, 2]);

        $schedule->reserve($this->window('2025-01-10 10:00', 30));

        $this->assertSame(1, $schedule->reserve($this->window('2025-01-10 10:30', 30)));
        $this->assertSame(2, $schedule->reserve($this->window('2025-01-10 10:59', 30)));
    }
}
