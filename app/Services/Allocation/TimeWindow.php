<?php

namespace App\Services\Allocation;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * A half-open time interval [start, end): back-to-back windows do not overlap.
 */
final readonly class TimeWindow
{
    /**
     * First moment inside the window.
     */
    public CarbonImmutable $start;

    /**
     * First moment after the window (not included).
     */
    public CarbonImmutable $end;

    /**
     * @throws InvalidArgumentException when the window doesn't end after it starts
     */
    public function __construct(DateTimeInterface $start, DateTimeInterface $end)
    {
        $this->start = CarbonImmutable::instance($start);
        $this->end = CarbonImmutable::instance($end);

        if ($this->end <= $this->start) {
            throw new InvalidArgumentException('A time window must end after it starts.');
        }
    }

    /**
     * A window of the given length, e.g. TimeWindow::starting($landing, 90).
     */
    public static function starting(DateTimeInterface $start, int $minutes): self
    {
        $start = CarbonImmutable::instance($start);

        return new self($start, $start->addMinutes($minutes));
    }

    /**
     * Whether the two windows share any moment. One ending exactly when the other starts doesn't count.
     */
    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $this->end > $other->start;
    }
}
