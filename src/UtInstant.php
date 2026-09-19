<?php

namespace Astronomy;

use DateTimeImmutable;
use DateTimeZone;

/**
 * An instant that comes out of an astronomical computation, in the two shapes it is needed in.
 *
 * The Julian day in Universal Time is what the computations work with and what gets compared
 * against other ephemerides; the date in the time zone of the place is what gets read. They
 * travel together because converting one into the other is precisely the step where hours too
 * many or too few slip in, and doing it a single time, here, is the way not to repeat that
 * mistake in every place that prints a time.
 */
readonly class UtInstant
{
    /** Julian day of the start of the Unix epoch, 1970-01-01 00:00 UT. */
    private const JD_UNIX = 2440587.5;

    /**
     * An instant in Universal Time, as a julian day and as a clock reading.
     */
    public function __construct(
        public float $jdUt,
        public DateTimeImmutable $date,
    ) {}

    /**
     * @param float $jdUt
     * @param DateTimeZone|null $timezone Without a time zone, UTC.
     * @return self
     */
    public static function fromJd(float $jdUt, ?DateTimeZone $timezone = null): self
    {
        $seconds = ($jdUt - self::JD_UNIX) * 86400.0;

        /* To the millisecond, which is more than the computation guarantees and less than a
           `format('U.u')` would accept without complaining about the decimals.

           * *The integer part is taken with `floor` and the fraction is always left positive**, and
           that is what has to be done before 1970. `U.v` does not read a decimal number: it reads
           some seconds and some milliseconds and ADDS them. Handing it "-1234567890.250", which is
           what `sprintf` writes for an instant earlier than the epoch, it understands -1234567890
           and adds 250 milliseconds to it, that is, half a second on the other side of where it
           should have been. Since the error is less than two seconds and only happens before 1970,
           it came out in the rise and set of any chart from before then without anything failing:
           `jdUt` and `date`, inside the same object, stated two different instants. */
        $wholeSeconds = (int) floor($seconds);
        $milliseconds = (int) round(($seconds - $wholeSeconds) * 1000.0);

        if ($milliseconds >= 1000) {
            $wholeSeconds++;
            $milliseconds -= 1000;
        }

        $date = DateTimeImmutable::createFromFormat(
            'U.v',
            sprintf('%d.%03d', $wholeSeconds, $milliseconds),
            new DateTimeZone('UTC')
        );

        return new self($jdUt, $date->setTimezone($timezone ?? new DateTimeZone('UTC')));
    }

    /**
     * The same instant in another time zone.
     *
     * @param DateTimeZone $timezone
     * @return self
     */
    public function in(DateTimeZone $timezone): self
    {
        return new self($this->jdUt, $this->date->setTimezone($timezone));
    }

    /**
     * Seconds until another instant. Positive if the other one is later.
     *
     * @param UtInstant $other
     * @return float
     */
    public function secondsTo(UtInstant $other): float
    {
        return ($other->jdUt - $this->jdUt) * 86400.0;
    }
}
