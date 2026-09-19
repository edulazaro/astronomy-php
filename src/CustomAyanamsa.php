<?php

namespace Astronomy;

use InvalidArgumentException;

/**
 * An ayanamsa defined by whoever uses it: "at the instant t0 it was worth a0". It is Swiss's
 * `SE_SIDM_USER`.
 *
 * The 43 in `Ayanamsa` are published pairs (t0, a0), and this one is the same algorithm with
 * whatever pair it is given: the vernal point of the date carried to the ecliptic of t0, and a0
 * minus its longitude there. The arithmetic is not repeated here, it is asked of
 * `Ayanamsa::fromEpoch`, because two copies of an algorithm are two algorithms that one day
 * diverge.
 *
 * What it is for: a school with its own sidereal zero, reproducing an old table, or trying out what
 * would happen with another anchoring. Whoever wants one of the published ones has the 43 in the
 * enum, and those are besides verified against Swiss one by one.
 *
 * The pair goes in Terrestrial Time, like every instant that enters the engine. Swiss also
 * allows giving it in UT with a separate bit (`SIDBIT_USER_UT`); not here, because the engine does
 * not have two doors for the same thing and going from one to the other is `Time::tt()`.
 *
 * What it does not do, and it is on purpose: it does not go into `ChartCode`. The link of a
 * shared chart stores the ayanamsa by its key (`lahiri`), and this is two numbers that do not fit
 * into a key. There it blows up instead of being saved half-way, which would mean opening the chart
 * with another zodiac and everything looking fine, which is exactly the failure the chart code has
 * written down to avoid.
 */
final readonly class CustomAyanamsa
{
    /**
     * @param float $epoch Julian day in Terrestrial Time: the t0 at which the ayanamsa was worth `$initialValue`.
     * @param float $initialValue Degrees: the a0, the MEAN ayanamsa at that epoch, without nutation.
     * @param string $name To write it out.
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public float $epoch,
        public float $initialValue,
        public string $name = 'custom',
    ) {
        if (! is_finite($epoch) || ! is_finite($initialValue)) {
            throw new InvalidArgumentException('The epoch and the initial value of an ayanamsa are numbers.');
        }

        if (abs($initialValue) > 180.0) {
            throw new InvalidArgumentException(sprintf(
                'An ayanamsa is between -180 and 180 degrees, and %.3f arrived. If those are arcseconds or minutes, they have to be turned into degrees.',
                $initialValue
            ));
        }
    }

    /**
     * The true ayanamsa in degrees: what has to be subtracted from a tropical longitude of this
     * engine. It carries the nutation, because tropical longitudes carry it.
     *
     * @param float $jdTT
     * @return float
     */
    public function value(float $jdTT): float
    {
        return Ayanamsa::center($this->mean($jdTT) + rad2deg(Time::nutation(Time::centuries($jdTT))[0]));
    }

    /**
     * The mean ayanamsa: without nutation, which is the one the tables give.
     *
     * @param float $jdTT
     * @return float
     */
    public function mean(float $jdTT): float
    {
        return Ayanamsa::fromEpoch($this->epoch, $this->initialValue, $jdTT);
    }

    /**
     * The sidereal longitude of a tropical longitude, in [0, 360).
     *
     * @param float $tropicalLongitude
     * @param float $jdTT
     * @return float
     */
    public function sidereal(float $tropicalLongitude, float $jdTT): float
    {
        return fmod(fmod($tropicalLongitude - $this->value($jdTT), 360.0) + 360.0, 360.0);
    }

    /**
     * The sidereal position measured on the ecliptic of t0, longitude and latitude: Swiss's
     * `SE_SIDBIT_ECL_T0`. The arithmetic is in `Ayanamsa`, with this one's pair.
     *
     * @param float $longitude Tropical longitude in the true ecliptic of date.
     * @param float $latitude
     * @param float $jdTT
     * @return array{0: float, 1: float}
     */
    public function projected(float $longitude, float $latitude, float $jdTT): array
    {
        return Ayanamsa::onEclipticOfT0($longitude, $latitude, $jdTT, $this->epoch, $this->initialValue);
    }

    /**
     * The sidereal position measured on the invariable plane of the solar system: Swiss's
     * `SE_SIDBIT_SSY_PLANE`. The arithmetic is in `Ayanamsa`, with this one's pair, and so is the
     * account of which zero point it counts from and which one it does not.
     *
     * A custom pair reaches it by the same door as the forty-three cases, which is the point of
     * that method being passed (t0, a0) loose: two copies of one rotation are two things that can
     * one day disagree.
     *
     * @param float $longitude Tropical longitude in the true ecliptic of date.
     * @param float $latitude
     * @param float $jdTT
     * @return array{0: float, 1: float}
     */
    public function projectedOnSolarSystemPlane(float $longitude, float $latitude, float $jdTT): array
    {
        return Ayanamsa::onSolarSystemPlane($longitude, $latitude, $jdTT, $this->epoch, $this->initialValue);
    }

    /**
     * What it is called, to write it out.
     *
     * It has no table to translate: this name is not a label of
     * the engine but the string whoever built it passed in, so there is nothing to
     * translate. It is here so that a `Ayanamsa|CustomAyanamsa` can be asked for its name
     * without the caller having to find out which of the two it is holding.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return float
     */
    public function epoch(): float
    {
        return $this->epoch;
    }

    /**
     * @return float
     */
    public function initialValue(): float
    {
        return $this->initialValue;
    }

    /**
     * No custom ayanamsa is anchored to a star: it is defined by its pair, not by the sky.
     *
     * @return Star|null
     */
    public function star(): ?Star
    {
        return null;
    }
}
