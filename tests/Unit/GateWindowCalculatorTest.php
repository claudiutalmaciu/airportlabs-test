<?php

namespace Tests\Unit;

use App\Models\Flight;
use App\Services\Allocation\GateWindowCalculator;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

class GateWindowCalculatorTest extends TestCase
{
    private function flight(string $direction): Flight
    {
        return new Flight([
            'direction' => $direction,
            'first_seen' => CarbonImmutable::parse('2025-01-10 08:00'),
            'last_seen' => CarbonImmutable::parse('2025-01-10 10:00'),
            'observed_at' => CarbonImmutable::parse('2025-01-11 03:00'),
        ]);
    }

    public function test_an_arrival_holds_its_gate_from_landing(): void
    {
        $window = (new GateWindowCalculator(90))->windowFor($this->flight(Flight::ARRIVAL));

        $this->assertEquals(CarbonImmutable::parse('2025-01-10 10:00'), $window->start);
        $this->assertEquals(CarbonImmutable::parse('2025-01-10 11:30'), $window->end);
    }

    public function test_a_departure_leaves_its_gate_at_take_off(): void
    {
        $window = (new GateWindowCalculator(90))->windowFor($this->flight(Flight::DEPARTURE));

        $this->assertEquals(CarbonImmutable::parse('2025-01-10 06:30'), $window->start);
        $this->assertEquals(CarbonImmutable::parse('2025-01-10 08:00'), $window->end);
    }

    public function test_the_observed_strategy_starts_when_the_flight_was_first_seen_by_the_cron(): void
    {
        $window = (new GateWindowCalculator(45, GateWindowCalculator::OBSERVED))->windowFor($this->flight(Flight::DEPARTURE));

        $this->assertEquals(CarbonImmutable::parse('2025-01-11 03:00'), $window->start);
        $this->assertEquals(CarbonImmutable::parse('2025-01-11 03:45'), $window->end);
    }

    public function test_it_rejects_a_non_positive_duration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GateWindowCalculator(0);
    }
}
