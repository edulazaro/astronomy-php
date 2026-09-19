<?php

namespace Astronomy;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Rise, set and meridian passes of a body on one day and at one place.
 *
 * It is what `swe_rise_trans` does, and with its same options: by default the upper
 * limb with refraction, which is what any almanac publishes, and on request the centre
 * of the disc, without refraction, or the three twilights.
 *
 * Never one ephemeris per second. A rise has to be pinned down to the second and a
 * position of the Moon costs milliseconds, so finding it by evaluating the ephemeris at
 * every step of a bisection would be sixty thousand rounds for one datum. What is done
 * is to sample the GEOCENTRIC position every hour, which is a very smooth curve, and
 * interpolate it; what changes fast (where the observer is, that is the parallax and the
 * hour angle) is computed exactly at every evaluation, because it is cheap. That way the
 * altitude at any instant of the day costs microseconds and can be bisected as much as
 * needed. The error of interpolating the Moon between one-hour samples is below a tenth
 * of an arcsecond.
 *
 * Polar day or night: null. If on that day the body does not cross the horizon,
 * there is no rise, and returning a number would be inventing it. The meridian passes do
 * always exist. And the same holds for a circumpolar star, or for one that never comes up
 * from that place: Vega does not set in Oslo nor rise in Ushuaia, and both cases are null
 * in rise and set with their two culminations.
 *
 * A star comes in like any other body. `Horizon::track()` translates it into an
 * apparent direction of the date, with no disc and no parallax, and from there on it is
 * the same as a planet. Swiss does the same with `swe_rise_trans` and a star name.
 */
readonly class RiseSet
{
    /**
     * The lowest a horizon can be dipped for the Bennett refraction to still hold, in
     * degrees. Measured: see `requireHorizonPossible`.
     */
    private const MIN_HORIZON = -1.8;

    /** Sampling step of the ephemeris, in days: one hour. */
    private const SAMPLE_STEP = 1 / 24;

    /** Step of the scan over the interpolated altitude, in days: ten minutes. */
    private const TRACKING_STEP = 10 / 1440;

    /**
     * When a solved pass is taken as converged, in days: when the last correction drops below one
     * second. What is left after that is the correction times the convergence ratio, which is the
     * ratio between how fast the body runs in right ascension and how fast the Earth turns: a
     * tenth for the Moon and a hundredth for a planet, so a tenth of a second in the worst case.
     */
    private const SOLVE_TOLERANCE = 1.0 / 86400.0;

    /**
     * How many rounds the fixed point is given before it is called a failure. Two or three are
     * enough for a planet and four for the Moon; this is far past any of them, and reaching it
     * would take a body whose right ascension ran as fast as the Earth turns.
     */
    private const SOLVE_ROUNDS = 12;

    /**
     * How near an instant a solved pass has to land to be read as being AT it rather than on one
     * side or the other, in days: a tenth of a second.
     *
     * It is the accuracy of the answer rounded up, not a number chosen for comfort: measured
     * against the tracker over 360 passes the worst is 0.038 seconds, so anything inside a tenth
     * is the same instant twice. Below that there is nothing to separate, and asking for the pass
     * before a rise would step back a whole sidereal day over a rounding.
     */
    private const PASS_SEAM = 0.1 / 86400.0;

    /**
     * @param string $name What it is that rises and sets, written out: «Sun», «Antares».
     * @param Body|Star|Closure(float): Equatorial $target The same thing, as an object,
     * so one can keep computing with it without looking it up again.
     * @param array<string, array{sunrise: UtInstant|null, dusk: UtInstant|null}> $twilights
     * Only for the Sun, by twilight name: civil, nautical and astronomical.
     */
    public function __construct(
        public string $name,
        public Body|Star|Closure $target,
        public ?UtInstant $rise,
        public ?UtInstant $set,
        public ?UtInstant $upperCulmination,
        public ?UtInstant $lowerCulmination,
        public array $twilights = [],
    ) {}

    /**
     * The passes of a body or a star during one civil day of the place, from local
     * midnight to local midnight.
     *
     * @param Body|Star|callable(float): Equatorial $target A body, a star from the
     * catalogue, or an arbitrary direction of the date as a function of the
     * Julian day TT.
     * @param Place $place
     * @param DateTimeInterface $day Only the date counts; the time is ignored.
     * @param Limb $limb
     * @param bool $refraction
     * @param float $heightMetres Height of the observer above sea level.
     * @return self
     */
    public static function ofTheDay(
        Body|Star|callable $target,
        Place $place,
        DateTimeInterface $day,
        Limb $limb = Limb::Superior,
        bool $refraction = true,
        float $heightMetres = 0.0,
        float $horizonAltitude = 0.0,
    ): self {
        self::requireHorizonPossible($horizonAltitude, $refraction);

        $timezone = $place->timeZone();
        $dayStart = new DateTimeImmutable($day->format('Y-m-d').' 00:00:00', $timezone);
        $dayEnd = $dayStart->modify('+1 day');

        $from = Time::julianDay($dayStart);
        $to = Time::julianDay($dayEnd);

        $horizon = new Horizon($place, $heightMetres);
        $radius = Horizon::radiusKm($target);
        $position = self::interpolator($target, $from, $to);

        $firstOf = fn (array $events, Pass $pass): ?UtInstant => self::first($events, $pass, $timezone);

        $horizonPasses = self::passes(
            $horizon,
            $position,
            $from,
            $to,
            self::referenceAltitude($radius, $limb, $refraction, $horizonAltitude),
            $radius
        );
        $meridian = self::meridianPasses($horizon, $position, $from, $to);

        $twilightTimes = [];

        /* Twilights do NOT move with the horizon altitude, and that is on purpose: a
           twilight is defined by how far the Sun has dropped BELOW THE ASTRONOMICAL
           HORIZON, which is a property of the sky and not of whatever one has in front.
           The mountain covers the disc, not the light that keeps painting the air above. */
        if ($target === Body::Sun) {
            foreach (Twilight::cases() as $twilight) {
                $events = self::passes($horizon, $position, $from, $to, fn () => -$twilight->value, $radius);

                $twilightTimes[strtolower($twilight->name)] = [
                    'sunrise' => $firstOf($events, Pass::Rise),
                    'dusk' => $firstOf($events, Pass::Set),
                ];
            }
        }

        return new self(
            name: Horizon::nameOf($target),
            target: $target instanceof Body || $target instanceof Star ? $target : Horizon::track($target),
            rise: $firstOf($horizonPasses, Pass::Rise),
            set: $firstOf($horizonPasses, Pass::Set),
            upperCulmination: $firstOf($meridian, Pass::UpperCulmination),
            lowerCulmination: $firstOf($meridian, Pass::LowerCulmination),
            twilights: $twilightTimes,
        );
    }

    /**
     * The next pass of a given kind from an instant, like `swe_rise_trans`: it is looked
     * for within the two days that follow and if there is none, null.
     *
     * @param Body|Star|callable(float): Equatorial $target
     * @param Place $place
     * @param float $jdUt
     * @param Pass $pass
     * @param Limb $limb
     * @param bool $refraction
     * @param Twilight|null $twilight Only makes sense with the Sun and with rise or set:
     * dawn is the «rise» of the twilight and dusk its «set».
     * @param float $heightMetres
     * @return UtInstant|null
     */
    public static function next(
        Body|Star|callable $target,
        Place $place,
        float $jdUt,
        Pass $pass,
        Limb $limb = Limb::Superior,
        bool $refraction = true,
        ?Twilight $twilight = null,
        float $heightMetres = 0.0,
        float $horizonAltitude = 0.0,
    ): ?UtInstant {
        self::requireHorizonPossible($horizonAltitude, $refraction);

        $horizon = new Horizon($place, $heightMetres);
        $to = $jdUt + 2.0;
        $radius = Horizon::radiusKm($target);
        $position = self::interpolator($target, $jdUt, $to);

        if ($pass->isMeridianPass()) {
            $events = self::meridianPasses($horizon, $position, $jdUt, $to);
        } else {
            $reference = $twilight === null
                ? self::referenceAltitude($radius, $limb, $refraction, $horizonAltitude)
                : fn () => -$twilight->value;

            $events = self::passes($horizon, $position, $jdUt, $to, $reference, $radius);
        }

        return self::first($events, $pass, $place->timeZone());
    }

    /**
     * The four passes of a civil day, SOLVED instead of tracked. Same answer as `ofTheDay()` and
     * the same shape, by a road that costs a tenth of the ephemerides.
     *
     * Which of the two is the right one
     *
     * `ofTheDay()` TRACKS the day: it samples the geocentric position every hour, interpolates,
     * and walks the altitude at ten minute steps looking for sign changes, refining the maxima
     * and minima between samples. That is what an almanac publishes and it handles the awkward
     * day, which is a real thing and not a corner: near the polar circle the Sun can peek above
     * the horizon and hide again inside one step, and a day with two passes of the same kind, or
     * with none, comes out right because the whole day was looked at.
     *
     * This one SOLVES instead. For a point with no disc and no refraction the rise sits at the
     * hour angle `H0 = arccos(-tan φ · tan δ)` and the culminations at zero and one hundred and
     * eighty, and putting a disc and an atmosphere in only changes the altitude the crossing
     * happens at. That gives the local sidereal time of the pass straight away, and from there
     * the instant, because over a day the Earth turns linearly. The catch is that δ belongs to
     * the position at the instant being looked for, so it is a fixed point: evaluate at noon,
     * solve, evaluate there, solve again. It converges as the ratio between how fast the body
     * runs in right ascension and how fast the Earth turns, which for the Moon, the worst, is
     * thirteen degrees a day against three hundred and sixty one. Two or three rounds for a
     * planet, four for the Moon, one ephemeris each.
     *
     * So: the tracker when the day itself is the question, and this when thousands of passes are
     * needed and the geometry is enough. It is the second case that put it here, in
     * `Houses::gauquelinSectorByRiseAndSet`, which needs three passes for every sector it places.
     *
     * What it costs and what it agrees with
     *
     * Measured against `ofTheDay()` under the same options, the ten classical bodies from three
     * places (Madrid, Oslo, Ushuaia) on three days, which is 360 passes: the worst disagreement
     * is 0.038 seconds of clock time, and it is the Moon setting in Oslo, which is the fastest
     * declination in the set. Where the tracker finds no pass this finds none either, in all of
     * them, the five days of that stretch on which the Moon does not rise included.
     *
     * And it costs about a third: the ten bodies for one day come to 113 milliseconds here
     * against 357 there. Per body, Mars 11.4 against 37.0 and the Moon 17.8 against 37.6. The
     * saving is not the algorithm, it is the ephemeris count: a tracked day is twenty nine
     * samples plus the walk over them and a solved one is a dozen positions with no walk at all.
     * It pays better where fewer than four passes are wanted, which is the Gauquelin case: three
     * passes there would be two whole tracked days.
     *
     * What it gives up, said plainly
     *
     * - A day with two passes of the same kind gets one, like `ofTheDay()`, because a
     * `RiseSet` has one slot per kind. That is the shape of `swe_rise_trans` too.
     * - The twilights are not filled in. They are the same closed form with a fixed altitude
     * and they could be; they are left out because nothing that wants speed wants them.
     * - A body that does not converge throws instead of falling back quietly. No real body
     * does; what would is one running in right ascension as fast as the planet turns.
     *
     * @param Body|Star|callable(float): Equatorial $target
     * @param Place $place
     * @param DateTimeInterface $day Only the date counts; the time is ignored.
     * @param Limb $limb
     * @param bool $refraction
     * @param float $heightMetres Height of the observer above sea level.
     * @param float $horizonAltitude Degrees above the astronomical horizon.
     * @return self
     */
    public static function solvedOfTheDay(
        Body|Star|callable $target,
        Place $place,
        DateTimeInterface $day,
        Limb $limb = Limb::Superior,
        bool $refraction = true,
        float $heightMetres = 0.0,
        float $horizonAltitude = 0.0,
    ): self {
        $timezone = $place->timeZone();
        $dayStart = new DateTimeImmutable($day->format('Y-m-d').' 00:00:00', $timezone);

        $from = Time::julianDay($dayStart);
        $to = Time::julianDay($dayStart->modify('+1 day'));

        $first = function (Pass $pass) use ($target, $place, $from, $to, $limb, $refraction, $heightMetres, $horizonAltitude): ?UtInstant {
            $instant = self::solvedPass($target, $place, $from, $pass, $limb, $refraction, $heightMetres, $horizonAltitude);

            return $instant !== null && $instant->jdUt < $to ? $instant : null;
        };

        return new self(
            name: Horizon::nameOf($target),
            target: $target instanceof Body || $target instanceof Star ? $target : Horizon::track($target),
            rise: $first(Pass::Rise),
            set: $first(Pass::Set),
            upperCulmination: $first(Pass::UpperCulmination),
            lowerCulmination: $first(Pass::LowerCulmination),
        );
    }

    /**
     * One pass, solved by fixed point: the first of its kind at or after an instant, or the last
     * at or before it. See `solvedOfTheDay()` for what separates this from the tracker.
     *
     * Null when that pass does not exist from that place: a circumpolar body never sets and one
     * below the horizon all day never rises, and in both cases the cosine of the hour angle falls
     * outside [-1, 1] and there is nothing to return. The culminations always exist, so for those
     * two it is never null.
     *
     * It is bracketed on the instant and not on the day, which is what makes it useful for
     * the Gauquelin sectors: asking for the last rise and the last set before an instant says
     * which arc the body is in without going anywhere near its altitude.
     *
     * @param Body|Star|callable(float): Equatorial $target
     * @param Place $place
     * @param float $jdUt
     * @param Pass $pass
     * @param Limb $limb
     * @param bool $refraction
     * @param float $heightMetres
     * @param float $horizonAltitude
     * @param bool $backwards Looking back from the instant instead of forward from it.
     * @return UtInstant|null
     */
    public static function solvedPass(
        Body|Star|callable $target,
        Place $place,
        float $jdUt,
        Pass $pass,
        Limb $limb = Limb::Superior,
        bool $refraction = true,
        float $heightMetres = 0.0,
        float $horizonAltitude = 0.0,
        bool $backwards = false,
    ): ?UtInstant {
        self::requireHorizonPossible($horizonAltitude, $refraction);

        $horizon = new Horizon($place, $heightMetres);
        $radius = Horizon::radiusKm($target);
        $track = Horizon::track($target);
        $reference = self::referenceAltitude($radius, $limb, $refraction, $horizonAltitude);

        $position = fn (float $jd): Equatorial => $horizon->topocentric($track(Time::tt($jd)), $jd);

        $sidereal = self::siderealTimeOfPass($position($jdUt), $pass, $place->latitude, $reference, $radius);

        if ($sidereal === null) {
            return null;
        }

        /* How far to the first crossing of that sidereal time on the side asked for, counted
           forwards in both cases so that an instant already ON the pass returns itself.
           `almostNothing` is what makes that true in practice and not only in algebra: at the
           instant of a rise the two sidereal times differ by however accurate that instant is,
           and folded into [0, 360) a difference BELOW zero comes back as 359.99, which sends the
           search a whole sidereal day away. That is the case the Gauquelin sectors land on at
           every rise, and it came out as sector 36.99999 instead of 1. */
        $here = $horizon->localSiderealTime($jdUt);
        $offset = self::almostNothing(self::turn($backwards ? $here - $sidereal : $sidereal - $here));

        $jd = $jdUt + ($backwards ? -$offset : $offset) / Time::ROTATION_PER_DAY;

        $jd = self::converge($position, $pass, $horizon, $reference, $radius, $jd);

        /* The estimate was made with the position of the starting instant and the body has moved
           since, so the converged pass can land on the wrong side of it: by a few minutes when
           the estimate was a real distance, and by whatever the snap above was worth when it was
           not. A whole sidereal day either way puts it back, and there is no third try, because
           the pass is periodic. */
        if ($jd !== null && $backwards && $jd > $jdUt + self::PASS_SEAM) {
            $jd = self::converge($position, $pass, $horizon, $reference, $radius, $jd - self::siderealDay());
        } elseif ($jd !== null && ! $backwards && $jd < $jdUt - self::PASS_SEAM) {
            $jd = self::converge($position, $pass, $horizon, $reference, $radius, $jd + self::siderealDay());
        }

        return $jd === null ? null : UtInstant::fromJd($jd, $place->timeZone());
    }

    /**
     * The local sidereal time at which a body sits on the pass, in degrees, or null when from that
     * latitude it does not do it.
     *
     * The culminations are the right ascension and the right ascension plus half a turn. For the
     * rise and the set it is the hour angle at which the altitude of the CENTRE is worth the
     * reference: `sin h = sin φ sin δ + cos φ cos δ cos H`. That reference is where the disc and
     * the atmosphere come in, and it is the same closure the tracker crosses, so the two cannot
     * mean different things by «rise».
     *
     * The position is the TOPOCENTRIC one, as in the tracker and as in Swiss: the parallax of the
     * Moon in right ascension reaches a degree, which is four minutes of clock time in the pass.
     *
     * @param Equatorial $topocentric
     * @param Pass $pass
     * @param float $latitude
     * @param Closure(float): float $reference
     * @param float $radiusKm
     * @return float|null
     */
    private static function siderealTimeOfPass(
        Equatorial $topocentric,
        Pass $pass,
        float $latitude,
        Closure $reference,
        float $radiusKm
    ): ?float {
        if ($pass->isMeridianPass()) {
            return self::turn($topocentric->rightAscension + ($pass === Pass::LowerCulmination ? 180.0 : 0.0));
        }

        $phi = deg2rad($latitude);
        $delta = deg2rad($topocentric->declination);
        $denominator = cos($phi) * cos($delta);

        // At the geographic pole, or with the body over one of the celestial poles, nothing rises
        // and nothing sets: every point keeps the altitude it has.
        if (abs($denominator) < 1e-12) {
            return null;
        }

        $altitude = deg2rad($reference(Horizon::semidiameter($topocentric, $radiusKm)));
        $cosine = (sin($altitude) - sin($phi) * sin($delta)) / $denominator;

        if ($cosine < -1.0 || $cosine > 1.0) {
            return null;
        }

        $hourAngle = rad2deg(acos($cosine));

        return self::turn($topocentric->rightAscension + ($pass === Pass::Rise ? -$hourAngle : $hourAngle));
    }

    /**
     * The fixed point: the position at the instant, the sidereal time its pass would happen at,
     * and the instant moved so that local sidereal time is worth exactly that. The correction is
     * folded to half a turn so as not to jump a cycle, and a pass that stops existing on some
     * round (a declination crossing the circumpolar limit) returns null.
     *
     * @param Closure(float): Equatorial $position
     * @param Pass $pass
     * @param Horizon $horizon
     * @param Closure(float): float $reference
     * @param float $radiusKm
     * @param float $jd
     * @return float|null
     */
    private static function converge(
        Closure $position,
        Pass $pass,
        Horizon $horizon,
        Closure $reference,
        float $radiusKm,
        float $jd
    ): ?float {
        for ($round = 0; $round < self::SOLVE_ROUNDS; $round++) {
            $sidereal = self::siderealTimeOfPass($position($jd), $pass, $horizon->place->latitude, $reference, $radiusKm);

            if ($sidereal === null) {
                return null;
            }

            $jump = self::fold($sidereal - $horizon->localSiderealTime($jd)) / Time::ROTATION_PER_DAY;
            $jd += $jump;

            if (abs($jump) < self::SOLVE_TOLERANCE) {
                return $jd;
            }
        }

        throw new RuntimeException(sprintf(
            'The %s does not converge: after %d rounds the instant is still moving by more than a second, which takes a body running in right ascension about as fast as the Earth turns. Use RiseSet::ofTheDay, which tracks the sky instead of solving for it.',
            $pass->name(),
            self::SOLVE_ROUNDS
        ));
    }

    /**
     * A sidereal day in days: how long the same sidereal time takes to come round again.
     *
     * @return float
     */
    private static function siderealDay(): float
    {
        return 360.0 / Time::ROTATION_PER_DAY;
    }

    /**
     * Degrees folded into [0, 360).
     *
     * @param float $degrees
     * @return float
     */
    private static function turn(float $degrees): float
    {
        return fmod(fmod($degrees, 360.0) + 360.0, 360.0);
    }

    /**
     * Degrees folded into [-180, 180): a displacement, not a place.
     *
     * @param float $degrees
     * @return float
     */
    private static function fold(float $degrees): float
    {
        return self::turn($degrees + 180.0) - 180.0;
    }

    /**
     * A turn that falls a hair short of a whole turn, read as no turn at all.
     *
     * The tolerance is `PASS_SEAM` turned into degrees of rotation, which is what it has to be and
     * not something smaller: the difference this is folding is worth however accurate the instant
     * it was measured at is, and that is four hundredths of a second, not a rounding. Set to
     * float noise instead, asking for the rise before a rise stepped a whole sidereal day back,
     * which is how it was found.
     *
     * Erring on the generous side costs nothing, and that is the other half of why it can be a
     * blunt number: a pass snapped to the instant and then found to be on the wrong side of it
     * gets its sidereal day back from the correction in `solvedPass`.
     *
     * @param float $degrees In [0, 360).
     * @return float
     */
    private static function almostNothing(float $degrees): float
    {
        return $degrees > 360.0 - self::PASS_SEAM * Time::ROTATION_PER_DAY ? 0.0 : $degrees;
    }

    /**
     * The true altitude of the centre at which the pass happens, as a function of its
     * apparent semidiameter at that moment.
     *
     * With refraction, the limb that is seen touching the horizon is geometrically 34
     * arcminutes lower; with the upper limb, the centre is moreover one semidiameter
     * below the limb. For the Sun they add up to 50 arcminutes, which at middle latitudes
     * is about three minutes of clock time.
     *
     * And the refraction is evaluated at the horizon altitude it is given, not at zero,
     * which is all that is needed for a mountain horizon. At five degrees the refraction is
     * 0.16 degrees and at the horizon 0.57: using the horizon one up there would leave the
     * rise four tenths of a degree of altitude off, which at middle latitudes is three
     * minutes of clock time. With altitude zero it comes out exactly as always, bit for bit.
     *
     * @param float $radiusKm
     * @param Limb $limb
     * @param bool $refraction
     * @param float $horizonAltitude Degrees above the astronomical horizon.
     * @return Closure(float): float Takes the semidiameter in degrees.
     */
    private static function referenceAltitude(
        float $radiusKm,
        Limb $limb,
        bool $refraction,
        float $horizonAltitude = 0.0,
    ): Closure {
        $apparentHorizon = $refraction
            ? $horizonAltitude - Horizon::refractionOf($horizonAltitude)
            : $horizonAltitude;

        $factor = $radiusKm > 0 ? $limb->factor() : 0.0;

        return fn (float $semiDiameter): float => $apparentHorizon - $factor * $semiDiameter;
    }

    /**
     * A horizon that cannot be corrected for refraction is rejected instead of returning a
     * good-looking time.
     *
     * The floor below is MEASURED and belongs to the formula, not to the sky: Bennett
     * stops growing as one goes below 1.8 degrees under the horizon**, and there its
     * refraction starts to shrink until it vanishes near its pole, at −4.4. That is, below
     * that it does not return a small refraction: it returns a wrong one. And 1.8 degrees of
     * dip are those of an observer at 3,100 metres, because the dip goes as `0.0322·√metres`:
     * it covers any mountain but the highest in the world. Without refraction there is
     * nothing to bound and anything is accepted.
     *
     * @param float $horizonAltitude
     * @param bool $refraction
     * @return void
     */
    private static function requireHorizonPossible(float $horizonAltitude, bool $refraction): void
    {
        if ($horizonAltitude >= 90.0 || $horizonAltitude <= -90.0) {
            throw new InvalidArgumentException('The horizon altitude is measured in degrees and has to fit in the sky.');
        }

        if ($refraction && $horizonAltitude < self::MIN_HORIZON) {
            throw new InvalidArgumentException(sprintf(
                'A horizon at %.2f degrees cannot be corrected for refraction: the Bennett formula stops holding '
                .'below %.1f, which is the dip of an observer at 3,100 metres. Ask for the pass without refraction.',
                $horizonAltitude,
                self::MIN_HORIZON
            ));
        }
    }

    /**
     * The horizon crossings within a stretch.
     *
     * @param Horizon $horizon
     * @param Closure(float): Equatorial $position Interpolated geocentric, by jdUt.
     * @param float $from
     * @param float $to
     * @param Closure(float): float $reference Altitude of the centre at which it crosses.
     * @param float $radiusKm Physical radius of the object, for the semidiameter.
     * @return list<array{0: float, 1: Pass}>
     */
    private static function passes(Horizon $horizon, Closure $position, float $from, float $to, Closure $reference, float $radiusKm): array
    {
        $function = function (float $jdUt) use ($horizon, $position, $reference, $radiusKm): float {
            $topocentric = $horizon->topocentric($position($jdUt), $jdUt);

            return $horizon->horizontal($topocentric, $jdUt)->altitude
                - $reference(Horizon::semidiameter($topocentric, $radiusKm));
        };

        $events = [];

        foreach (self::crossings($function, $from, $to) as [$jd, $rising]) {
            $events[] = [$jd, $rising ? Pass::Rise : Pass::Set];
        }

        return $events;
    }

    /**
     * The meridian passes within a stretch.
     *
     * The hour angle grows at fifteen degrees per hour and crosses zero at the upper
     * culmination and one hundred and eighty at the lower one. They are looked for as
     * crossings of two different functions, because a single one with a jump at one
     * hundred and eighty would fool the sign-change detector right where the pass is.
     *
     * With the TOPOCENTRIC right ascension, as Swiss does: for the Moon, the parallax in
     * right ascension reaches one degree, that is four minutes of clock time in the pass.
     *
     * @param Horizon $horizon
     * @param Closure(float): Equatorial $position
     * @param float $from
     * @param float $to
     * @return list<array{0: float, 1: Pass}>
     */
    private static function meridianPasses(Horizon $horizon, Closure $position, float $from, float $to): array
    {
        $hourAngle = function (float $jdUt, float $shift) use ($horizon, $position): float {
            $topocentric = $horizon->topocentric($position($jdUt), $jdUt);
            $h = $horizon->localSiderealTime($jdUt) - $topocentric->rightAscension + $shift;

            return fmod(fmod($h, 360.0) + 540.0, 360.0) - 180.0;
        };

        $events = [];

        foreach ([[0.0, Pass::UpperCulmination], [180.0, Pass::LowerCulmination]] as [$shift, $pass]) {
            $function = fn (float $jdUt): float => $hourAngle($jdUt, $shift);

            foreach (self::crossings($function, $from, $to) as [$jd, $rising]) {
                // The hour angle only grows: a downward crossing is the artificial jump
                // from minus one hundred and eighty to one hundred and eighty, not a pass.
                if ($rising) {
                    $events[] = [$jd, $pass];
                }
            }
        }

        usort($events, fn (array $a, array $b) => $a[0] <=> $b[0]);

        return $events;
    }

    /**
     * The zeros of a function within a stretch, with the direction of the crossing.
     *
     * It scans at a ten-minute step and bisects every sign change. And it refines the
     * maxima and minima between samples before looking at signs: near the polar circle the
     * Sun can peek out and hide again in less than ten minutes, and without this that day
     * would come out with no rise and no set. It is the same thing Swiss does with its
     * culminations.
     *
     * @param Closure(float): float $function
     * @param float $from
     * @param float $to
     * @return list<array{0: float, 1: bool}> [instant, whether it goes upwards]
     */
    private static function crossings(Closure $function, float $from, float $to): array
    {
        $samples = [];

        /* By index and not by accumulating the step: one hundred and forty-four additions
           over a seven-figure Julian day drag an error of tens of nanodays, and with that
           the last sample, the one of the closing midnight, fell outside the stretch and
           any pass in the last ten minutes of the day was lost. A lower culmination at
           23:56 in Longyearbyen caught it. */
        $sampleCount = (int) round(($to - $from) / self::TRACKING_STEP);

        for ($i = 0; $i <= $sampleCount; $i++) {
            $jd = $from + $i * self::TRACKING_STEP;
            $samples[] = [$jd, $function($jd)];
        }

        // The extrema between samples, inserted where they belong.
        $withExtrema = [];

        for ($i = 0; $i < count($samples); $i++) {
            $withExtrema[] = $samples[$i];

            if ($i < 1 || $i >= count($samples) - 1) {
                continue;
            }

            [$y0, $y1, $y2] = [$samples[$i - 1][1], $samples[$i][1], $samples[$i + 1][1]];

            $isMaximum = $y1 > $y0 && $y1 > $y2;
            $isMinimum = $y1 < $y0 && $y1 < $y2;

            if (! $isMaximum && ! $isMinimum) {
                continue;
            }

            // The extreme is only of interest if it can change sign with respect to its
            // neighbours: a maximum at thirty degrees of altitude hides no crossing.
            $extreme = self::extremum($function, $samples[$i - 1][0], $samples[$i][0], $samples[$i + 1][0], $isMaximum);

            if ($extreme !== null) {
                array_pop($withExtrema);
                $withExtrema[] = $extreme[0] < $samples[$i][0] ? $extreme : $samples[$i];
                $withExtrema[] = $extreme[0] < $samples[$i][0] ? $samples[$i] : $extreme;
            }
        }

        $crossings = [];

        for ($i = 1; $i < count($withExtrema); $i++) {
            [$t0, $y0] = $withExtrema[$i - 1];
            [$t1, $y1] = $withExtrema[$i];

            if ($y0 === 0.0) {
                $crossings[] = [$t0, $y1 > 0];

                continue;
            }

            if ($y0 * $y1 >= 0) {
                continue;
            }

            $crossings[] = [self::bisect($function, $t0, $y0, $t1), $y1 > $y0];
        }

        return $crossings;
    }

    /**
     * Refines an extreme between three samples by a parabola and evaluates it for real.
     *
     * @param Closure(float): float $function
     * @param float $t0
     * @param float $t1
     * @param float $t2
     * @param bool $maximum
     * @return array{0: float, 1: float}|null
     */
    private static function extremum(Closure $function, float $t0, float $t1, float $t2, bool $maximum): ?array
    {
        $y0 = $function($t0);
        $y1 = $function($t1);
        $y2 = $function($t2);

        $denominator = $y0 - 2 * $y1 + $y2;

        if (abs($denominator) < 1e-12) {
            return null;
        }

        // Vertex of the parabola through the three points, in fractions of the step.
        $x = 0.5 * ($y0 - $y2) / $denominator;
        $t = $t1 + $x * ($t1 - $t0);

        if ($t <= $t0 || $t >= $t2) {
            return null;
        }

        $y = $function($t);

        return ($maximum ? $y > $y1 : $y < $y1) ? [$t, $y] : null;
    }

    /**
     * @param Closure(float): float $function
     * @param float $t0
     * @param float $y0
     * @param float $t1
     * @return float
     */
    private static function bisect(Closure $function, float $t0, float $y0, float $t1): float
    {
        // Twenty rounds over ten minutes leave the instant at the thousandth of a second.
        for ($i = 0; $i < 20; $i++) {
            $middle = ($t0 + $t1) / 2;
            $y = $function($middle);

            if ($y * $y0 > 0) {
                $t0 = $middle;
                $y0 = $y;
            } else {
                $t1 = $middle;
            }
        }

        return ($t0 + $t1) / 2;
    }

    /**
     * @param list<array{0: float, 1: Pass}> $events
     * @param Pass $pass
     * @param DateTimeZone $timezone
     * @return UtInstant|null
     */
    private static function first(array $events, Pass $pass, DateTimeZone $timezone): ?UtInstant
    {
        foreach ($events as [$jd, $type]) {
            if ($type === $pass) {
                return UtInstant::fromJd($jd, $timezone);
            }
        }

        return null;
    }

    /**
     * The geocentric position as a continuous function of time, out of samples.
     *
     * It samples the ephemeris every hour with two hours of margin on each side and
     * returns a function that interpolates the vector (or the direction, if there is no
     * distance) with four-point Lagrange. The vector goes in km on the axes of the true
     * equator of the date, which is what `Horizon` needs in order to subtract the observer.
     *
     * The three rectangular components are interpolated and not the right ascension and
     * the declination: an angle that crosses 360 degrees halfway through the day breaks
     * any interpolation, and a vector crosses nothing.
     *
     * The samples of a body or a star are kept by stretch: asking for the rise, the set
     * and the two culminations of the same day is four calls over the same thirty
     * positions, and the Moon costs its bit. An arbitrary direction is not kept, because a
     * callable has no identity to keep it by. The star does: its catalogue key, with a
     * prefix so that «sol» and a star that happened to be named the same do not share a
     * stretch.
     *
     * @param Body|Star|callable(float): Equatorial $target
     * @param float $from jdUt
     * @param float $to jdUt
     * @return Closure(float): Equatorial By jdUt.
     */
    private static function interpolator(Body|Star|callable $target, float $from, float $to): Closure
    {
        static $cached = [];

        $key = match (true) {
            $target instanceof Body => sprintf('%s:%.6f:%.6f', $target->value, $from, $to),
            $target instanceof Star => sprintf('star-%s:%.6f:%.6f', $target->key, $from, $to),
            default => null,
        };

        if ($key !== null && isset($cached[$key])) {
            return $cached[$key];
        }

        $track = Horizon::track($target);
        $start = $from - 2 * self::SAMPLE_STEP;
        $sampleCount = (int) ceil(($to - $from) / self::SAMPLE_STEP) + 5;

        $samplePoints = [];
        $withDistance = true;

        for ($i = 0; $i < $sampleCount; $i++) {
            $jdUt = $start + $i * self::SAMPLE_STEP;
            $coordinate = $track(Time::tt($jdUt));

            if ($coordinate->distanceKm === null) {
                $withDistance = false;
                $samplePoints[] = $coordinate->unit();
            } else {
                $samplePoints[] = $coordinate->vector();
            }
        }

        $interpolator = function (float $jdUt) use ($samplePoints, $start, $withDistance): Equatorial {
            $x = ($jdUt - $start) / self::SAMPLE_STEP;
            $firstIndex = max(0, min((int) floor($x) - 1, count($samplePoints) - 4));

            $vector = [0.0, 0.0, 0.0];

            for ($i = 0; $i < 4; $i++) {
                $weight = 1.0;

                for ($j = 0; $j < 4; $j++) {
                    if ($i !== $j) {
                        $weight *= ($x - ($firstIndex + $j)) / ($i - $j);
                    }
                }

                $vector[0] += $weight * $samplePoints[$firstIndex + $i][0];
                $vector[1] += $weight * $samplePoints[$firstIndex + $i][1];
                $vector[2] += $weight * $samplePoints[$firstIndex + $i][2];
            }

            $coordinate = Equatorial::fromVector($vector);

            return $withDistance
                ? $coordinate
                : Equatorial::direction($coordinate->rightAscension, $coordinate->declination);
        };

        if ($key !== null) {
            // A few dozen stretches are enough for any page and for the test suite; beyond
            // that it starts over rather than growing without bound.
            if (count($cached) >= 64) {
                $cached = [];
            }

            $cached[$key] = $interpolator;
        }

        return $interpolator;
    }
}
