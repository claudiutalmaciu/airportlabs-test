<?php

namespace App\Services\Allocation;

use App\Models\Flight;
use InvalidArgumentException;

/**
 * Decides moment T for a flight and returns the window it holds its gate for.
 */
final class GateWindowCalculator
{
    /**
     * T is when the fetch cron first saw the flight (the brief's example).
     */
    public const OBSERVED = 'observed';

    /**
     * T comes from the flight itself: landing for arrivals, one occupancy period before take-off for departures (the default).
     */
    public const FLIGHT_TIME = 'flight_time';

    /**
     * @param  int  $occupancyMinutes  how long a flight holds its gate (GATE_OCCUPANCY_MINUTES)
     * @param  string  $strategy  how moment T is chosen: self::OBSERVED or self::FLIGHT_TIME
     *
     * @throws InvalidArgumentException when the duration isn't positive or the strategy is unknown
     */
    public function __construct(private readonly int $occupancyMinutes, private readonly string $strategy = self::FLIGHT_TIME)
    {
        if ($occupancyMinutes <= 0) {
            throw new InvalidArgumentException('Gate occupancy must be a positive number of minutes.');
        }

        if (! in_array($strategy, [self::OBSERVED, self::FLIGHT_TIME], true)) {
            throw new InvalidArgumentException("Unknown gate window strategy [{$strategy}].");
        }
    }

    /**
     * Builds the calculator from config/gates.php. Bound in the service container, so it's what gets injected.
     */
    public static function fromConfig(): self
    {
        return new self(config('gates.occupancy_minutes'), config('gates.window_strategy'));
    }

    /**
     * The window [T, T + occupancy) during which the flight holds its gate.
     *
     * @throws InvalidArgumentException when the flight's direction is neither arrival nor departure
     */
    public function windowFor(Flight $flight): TimeWindow
    {
        $window = null;

        // Observed: the gate is held from the moment the fetch cron first saw the flight, whatever its direction.
        // NOTE: This mode is never used in the app, since OpenSky publishes the flights the night before
        // TODO: OBSERVED mode can be used when the flights are observed dynamically
        if ($this->strategy === self::OBSERVED) {
            $window = TimeWindow::starting($flight->observed_at, $this->occupancyMinutes);
        }

        // Arrival: the gate is taken when the aircraft lands (its last position seen).
        if ($this->strategy === self::FLIGHT_TIME && $flight->direction === Flight::ARRIVAL) {
            $window = TimeWindow::starting($flight->last_seen, $this->occupancyMinutes);
        }

        // Departure: the gate is held for the time before take-off (its first position seen).
        if ($this->strategy === self::FLIGHT_TIME && $flight->direction === Flight::DEPARTURE) {
            $gateFrom = $flight->first_seen->copy()->subMinutes($this->occupancyMinutes);
            $window = TimeWindow::starting($gateFrom, $this->occupancyMinutes);
        }

        if ($window === null) {
            throw new InvalidArgumentException("Unknown flight direction [{$flight->direction}].");
        }

        return $window;
    }
}
