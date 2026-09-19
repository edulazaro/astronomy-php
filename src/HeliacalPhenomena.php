<?php

namespace Astronomy;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Heliacal phenomena: the day an object becomes visible again after having been hidden by
 * the Sun, and the day it stops being visible.
 *
 * It is what `swe_heliacal_ut` does, and it is the technique of Babylonian and Hellenistic
 * astronomy. The four events and what each one means are in `HeliacalEvent`; the visibility
 * criterion and where its numbers come from, in `ArcusVisionis`.
 *
 * ### The calculation, which fits in three lines
 *
 * For each day the **arcus visionis** is measured: the depression of the Sun below the
 * horizon at the instant the object crosses the horizon, rising (morning events) or setting
 * (evening ones). That number grows day by day while the object moves away from the Sun and
 * shrinks when it comes back towards it. The event is the first day (or the last, depending
 * on the event) on which the arc reaches the minimum the table demands.
 *
 * **No refraction on either of the two bodies**, because that is how Schoch defines it and
 * because if it goes into one it has to go into the other. The 34 arcminutes of refraction
 * at the horizon shift the instant of the crossing by some two minutes, and in those two
 * minutes the Sun rises half a degree: half a day of date.
 *
 * ### Why the arc is measured at the horizon and not at the moment of best visibility
 *
 * Because that is the definition: «measured in the vertical circle for the moment when the
 * star sets on the last evening when it is visible or rises on the first morning when it is
 * visible». Swiss, which goes by Schaefer's contrast model, measures its topocentric arc at
 * the moment of best visibility, with the object between half a degree and six and a half
 * high. The arc changes little while the object rises, but not nothing, and how much depends
 * on where the Sun and the object rise: measured in Madrid in the year 2000, **four
 * hundredths of a degree in the heliacal rising of Sirius and seven tenths in that of
 * Aldebaran**. That, at the eight tenths of a degree per day that the arc moves, is where
 * almost all of the difference in date between the two programs comes from, measured in
 * `FenomenosHeliacosTest`: **from zero to two days in 97 % of the cases**.
 *
 * ### The search goes by days, not by minutes
 *
 * A heliacal phenomenon is a calendar DAY, not an instant: either it was seen that morning
 * or it was not. So it advances day by day and only within the day is the instant refined.
 * The day is the civil day of the place, from midnight to midnight and with its time zone,
 * the same as in `RiseSet`, so that both of them say «that day» about
 * the same thing.
 *
 * **And the ephemeris is sampled, it is not asked for one day after another.** A year of
 * searching is some four hundred crossings of the horizon, each one with two or three
 * fixed-point iterations, plus the position of the Sun at each one: asking the ephemeris
 * every time is several thousand calls and seconds of clock time. The geocentric position is
 * sampled every four days and interpolated, which is the same idea as `RiseSet::interpolator`
 * with a step of days instead of one of an hour: here nothing more is needed, because what is
 * being looked for has a resolution of one day. Measured: a year of searching costs 57
 * milliseconds with Sirius, 123 with Mercury and 238 with Saturn, which is the most expensive
 * ephemeris; the same 365 days asking the ephemeris on every iteration cost 2.2 seconds with
 * Mercury and 5.4 with Saturn (a closed form pass finder, which as a bonus gets the four crossings of
 * the day and not one).
 *
 * ### What is not here
 *
 * - **The Moon.** Swiss calculates its first evening visibility and its last morning one,
 *   but that is the visibility of the crescent, which is another problem and has its own
 *   literature (Yallop, Caldwell and Laney, Hoffman) because what decides it is not the
 *   depression of the Sun but the width of the crescent. Schoch gives it no entry in his
 *   table. It is rejected with an exception instead of returning a date that would look good.
 * - **The acronychal risings and settings** (the object rising as the Sun sets, and the other
 *   way round), which Swiss does not have either. They would be two more events of
 *   `HeliacalEvent`, with their arc: the machinery here would serve in full.
 */
class HeliacalPhenomena
{
    /** Ephemeris sampling step, in days. See `POINTS`, which are chosen together with it. */
    private const SAMPLE_STEP = 4.0;

    /**
     * How many samples go into each Lagrange interpolation.
     *
     * **The two numbers are measured together, and what has to be raised is the ORDER, not
     * the frequency.** What is expensive is each sample, because each one is an ephemeris
     * (Saturn costs two milliseconds); the points of the polynomial are arithmetic and cost
     * nothing. Measured against the crossing calculated by asking the ephemeris on every
     * iteration, over 364 crossings of Mercury, which is the fastest of everything that comes
     * in here (two degrees a day in right ascension), with the cost of a year of searching
     * for Saturn alongside:
     *
     * | step | points | worst Mercury | Saturn |
     * |---|---|---|---|
     * | 2 days | 6 | 0.018 s | 423 ms |
     * | 4 days | 6 | 0.577 s | 262 ms |
     * | 4 days | 8 | 0.154 s | 245 ms |
     * | **4 days** | **10** | **0.062 s** | **238 ms** |
     * | 3 days | 10 | 0.007 s | 318 ms |
     * | 6 days | 8 | 1.479 s | 176 ms |
     *
     * With four days and ten points, Mercury stays at six hundredths of a second of clock
     * time, which is nine tenths of an arcsecond of Sun, and everything else below two
     * thousandths of a second. Dropping the step to three days refines Mercury another eight
     * times over and costs a third more; it buys nothing, because what is being looked for is
     * a day and a day is eighty thousand times that.
     */
    private const POINTS = 10;

    /** Days looked ahead if nothing else is said. */
    public const DAYS = 400;

    /** How much sidereal time advances in one day of universal time, in degrees. */
    private const TURN_PER_DAY = 360.98564736629;

    /** A sidereal day, in days. */
    private const SIDEREAL_DAY = 360.0 / self::TURN_PER_DAY;

    /** When the instant of a crossing is taken as converged, in days: one second. */
    private const TOLERANCE = 1.0 / 86400.0;

    private const MAX_ROUNDS = 12;

    /**
     * The next phenomenon of a kind from an instant on, or null if there is none in the
     * window.
     *
     * **It returns the FIRST one it finds, and that is not always what Swiss returns.**
     * `swe_heliacal_ut` anchors its search on the next conjunction with the Sun, and with
     * Mercury, which makes three appearances a year, that makes it skip some: searching from
     * 1 January 2000 for its last morning visibility in Madrid, here the one of 4 April comes
     * out and there the one of 12 August, and both exist. To compare, one has to start near
     * the event.
     *
     * @param Body|Star $object
     * @param Place $place
     * @param float $jdUt From when it is searched. The civil day of the place it falls in
     *        counts.
     * @param HeliacalEvent $event
     * @param float|null $arcusVisionis The arc demanded, in degrees. By default, the one from
     *        Schoch's table for that object and that event.
     * @param float $heightMetres Height of the observer above sea level.
     * @param int $days How many days are looked ahead.
     * @return HeliacalPhenomenon|null
     */
    public static function find(
        Body|Star $object,
        Place $place,
        float $jdUt,
        HeliacalEvent $event,
        ?float $arcusVisionis = null,
        float $heightMetres = 0.0,
        int $days = self::DAYS,
    ): ?HeliacalPhenomenon {
        $found = self::events($object, $place, $event, self::fromJd($jdUt, $place), $days, $arcusVisionis, $heightMetres);

        return $found[0] ?? null;
    }

    /**
     * All the heliacal phenomena of an object during a civil year of the place, in order.
     *
     * It is the calendar of appearances: for Sirius, two lines; for Venus, up to four. The
     * search starts on 1 January and the ones falling outside the year are discarded, but the
     * window opens a few days earlier so that the transition of the first one can be seen.
     *
     * @param Body|Star $object
     * @param Place $place
     * @param int $year
     * @param float $heightMetres
     * @return list<HeliacalPhenomenon> By date.
     */
    public static function ofTheYear(Body|Star $object, Place $place, int $year, float $heightMetres = 0.0): array
    {
        $first = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year), $place->timeZone());
        $from = $first->modify('-20 days');
        $found = [];

        foreach (HeliacalEvent::forObject($object) as $event) {
            foreach (self::events($object, $place, $event, $from, self::DAYS, null, $heightMetres) as $phenomenon) {
                if ((int) $phenomenon->observation->date->format('Y') === $year) {
                    $found[] = $phenomenon;
                }
            }
        }

        usort($found, fn (HeliacalPhenomenon $a, HeliacalPhenomenon $b) => $a->observation->jdUt <=> $b->observation->jdUt);

        return $found;
    }

    /**
     * The arcus visionis of a particular day: the depression of the Sun, in degrees, at the
     * instant the object crosses the geometric horizon of that civil day.
     *
     * It is the bare calculation, with no criterion and no search, and that is why it is
     * public: it is what gets compared against another program to find out whether the
     * astronomy is right, without the comparison also dragging in the choice of threshold.
     *
     * Null if that day the object does not cross the horizon: circumpolar, invisible from
     * there, or one of those days on which a fast body is left without a rising because it
     * falls behind.
     *
     * @param Body|Star $object
     * @param Place $place
     * @param DateTimeInterface $day Only the date counts, in the time zone of the place.
     * @param HeliacalEvent $event Only which side it falls on is looked at: morning or
     *        evening.
     * @param float $heightMetres
     * @return array{0: float, 1: UtInstant}|null [arc in degrees, instant of the crossing]
     */
    public static function arcOfTheDay(
        Body|Star $object,
        Place $place,
        DateTimeInterface $day,
        HeliacalEvent $event,
        float $heightMetres = 0.0,
    ): ?array {
        self::check($object);

        $timezone = $place->timeZone();
        $start = new DateTimeImmutable($day->format('Y-m-d').' 00:00:00', $timezone);
        $from = Time::julianDay($start);
        $to = Time::julianDay($start->modify('+1 day'));

        $horizon = new Horizon($place, $heightMetres);
        $position = self::topocentric($horizon, self::interpolator($object, $from, $to));
        $sun = self::topocentric($horizon, self::interpolator(Body::Sun, $from, $to));

        $arc = self::arc($horizon, $position, $sun, $event->isMorning(), $from, $to);

        return $arc === null ? null : [$arc[0], UtInstant::fromJd($arc[1], $timezone)];
    }

    /**
     * Whether that day, in that place, the object is visible: the decision `events()` takes
     * inside.
     *
     * It is literally what decides there whether there is a phenomenon or not
     * (`arc >= threshold`), pulled out so that it can be asked on its own. A heliacal
     * phenomenon is the day on which this answer changes; this is the answer for any given
     * day.
     *
     * Null if that day the object does not cross the horizon, which is not the same as «it is
     * not visible»: it is that the question makes no sense there. It happens to a circumpolar
     * object, to whatever never rises from that latitude, and to a fast body on the day it is
     * left without a rising.
     *
     * @param Body|Star $object
     * @param Place $place
     * @param DateTimeInterface $day Only the date counts, in the time zone of the place.
     * @param HeliacalEvent $event
     * @param float|null $arcusVisionis The threshold, in degrees. Schoch's one by default.
     * @param float $heightMetres
     * @return bool|null
     */
    public static function isVisible(
        Body|Star $object,
        Place $place,
        DateTimeInterface $day,
        HeliacalEvent $event,
        ?float $arcusVisionis = null,
        float $heightMetres = 0.0,
    ): ?bool {
        $threshold = $arcusVisionis ?? ArcusVisionis::of($object, $event);

        if ($threshold === null) {
            throw new InvalidArgumentException(sprintf(
                'There is no published arcus visionis for %s in a %s: it has to be passed by hand. See ArcusVisionis.',
                Horizon::nameOf($object), $event->name()
            ));
        }

        $measured = self::arcOfTheDay($object, $place, $day, $event, $heightMetres);

        return $measured === null ? null : $measured[0] >= $threshold;
    }

    /**
     * Down to what magnitude one sees that day, in that place, at the instant of the crossing
     * of the object.
     *
     * **It is not `swe_vis_limit_mag`, and it is important not to sell it as if it were.**
     * That one answers what magnitude is visible at any point of the sky and at any hour,
     * with Schaefer's contrast model; this one answers what Schoch's table knows, which is a
     * narrower question and the only one that can be answered here without inventing an
     * observer. The conditions are in `ArcusVisionis::limitMagnitude`, and the underlying one
     * is that it holds **at the horizon and at the moment of the crossing**, not for the whole
     * sky.
     *
     * Why an object is needed in order to ask about a magnitude, which seems odd: the arcus
     * visionis is the depression of the Sun AT THE INSTANT the object crosses the horizon, so
     * without an object there is no instant to look at. The object picks the moment; the
     * magnitude that comes out is that of the sky at that moment, not its own.
     *
     * @param Body|Star $object The one that sets the instant: the crossing that is looked at.
     * @param Place $place
     * @param DateTimeInterface $day
     * @param HeliacalEvent $event
     * @param float $heightMetres
     * @return array{arco: float, magnitud: float|null, instante: UtInstant}|null Null if there
     *         is no crossing that day. The magnitude is null when the arc falls outside the
     *         table: see `ArcusVisionis::range`, which says on which side.
     */
    public static function magnitudeLimit(
        Body|Star $object,
        Place $place,
        DateTimeInterface $day,
        HeliacalEvent $event,
        float $heightMetres = 0.0,
    ): ?array {
        $measured = self::arcOfTheDay($object, $place, $day, $event, $heightMetres);

        if ($measured === null) {
            return null;
        }

        return [
            'arc' => $measured[0],
            'magnitude' => ArcusVisionis::limitMagnitude($measured[0], $event),
            'instant' => $measured[1],
        ];
    }

    /**
     * Everything that is measured at ONE instant in order to decide whether an object can be
     * seen: this is `swe_heliacal_pheno_ut`, and what it gives back is in `HeliacalDetails`.
     *
     * **The instant is chosen by whoever calls, and that is the whole shape of this method.**
     * Nothing is searched for and no day is decided: the caller says when, and this says what
     * the sky was doing then. It is the counterpart of `find()`, which answers «on what day».
     * A caller after Yallop's own numbers reads `bestTime` from a first call and asks again
     * there, because his q is defined at the best time and not at any instant.
     *
     * **The Moon is allowed here and it is not allowed in `find()`**, and that is not an
     * oversight in either place. `find()` looks for a heliacal date, and the Moon's first
     * visibility is the crescent's, which is decided by the width of the crescent and not by
     * the depression of the Sun, so there it throws. Here the crescent IS the subject: Yallop's
     * W' and his q-test only exist for the Moon, and for everything else they come back null.
     *
     * The event kind that Swiss takes as an argument is not taken here: which side of the Sun
     * the object is on is read off its longitude, and `HeliacalDetails::$pass` says which side
     * came out. See that field.
     *
     * **Where the time goes, measured, because it is not where one would guess**: a call for the
     * Moon costs 77 milliseconds and the geometry is 3 of them. The other 40-odd are Yallop's two
     * horizon crossings, 28 for the Moon's and 12 for the Sun's, because a crossing is found by
     * sampling the ephemeris every hour across two days while everything else here is a single
     * instant. A planet costs 41 and a star 16, for the same reason and in the same proportion.
     * Whoever means to sweep a whole evening minute by minute should read `bestTime` once and ask
     * again there, which is two calls, rather than asking a hundred times for a pair of crossings
     * that do not move.
     *
     * @param Body|Star $object The Moon included. The Sun is not: the arc is measured against
     *        it.
     * @param Place $place
     * @param float $jdUt The instant, in Universal Time.
     * @param float $heightMetres Height of the observer above sea level.
     * @return HeliacalDetails
     */
    public static function detailsAt(
        Body|Star $object,
        Place $place,
        float $jdUt,
        float $heightMetres = 0.0,
    ): HeliacalDetails {
        self::checkVisible($object);

        $jdTT = Time::tt($jdUt);
        $timezone = $place->timeZone();
        $horizon = new Horizon($place, $heightMetres);

        $geocentric = Horizon::equatorialOf($object, $jdTT);
        $sunGeocentric = Horizon::equatorialOf(Body::Sun, $jdTT);

        $fromTheCentre = $horizon->horizontal($geocentric, $jdUt);
        $fromTheGround = $horizon->horizontal($horizon->topocentric($geocentric, $jdUt), $jdUt);

        /* The Sun goes in GEOCENTRIC, which is how Yallop defines ARCV: «the geocentric
           difference in altitude between the centre of the Sun and the centre of the Moon».
           Its parallax in altitude is 8.6 arcseconds, measured at Madrid, so the choice is
           worth 0.0002 in a q whose class boundaries are spaced between 0.05 and 0.2. It is
           also what Swiss does, whatever its own label says: see `HeliacalDetails::$sunAltitude`. */
        $sunView = $horizon->horizontal($sunGeocentric, $jdUt);

        $arcOfVision = $fromTheCentre->altitude - $sunView->altitude;
        $azimuthDifference = self::wrap($sunView->azimuth - $fromTheGround->azimuth);

        /* Yallop (2.1): cos ARCL = cos ARCV · cos DAZ. It is his definition of the arc of
           light and not the true separation of the two directions, and the whole calibration
           of his q-test rests on it. See the class docblock of `HeliacalDetails`. */
        $arcOfLight = HeliacalDetails::arcOfLightOf($arcOfVision, $azimuthDifference);

        /* Which side of the Sun the object is on, off the geometry instead of off a parameter:
           east of the Sun in longitude it sets after it, so it is an evening object and the
           crossing that counts is the setting. */
        $ahead = self::normalize(
            Horizon::eclipticOf($geocentric, $jdTT)[0] - Horizon::eclipticOf($sunGeocentric, $jdTT)[0]
        );
        $pass = $ahead < 180.0 ? Pass::Set : Pass::Rise;

        [$objectPass, $sunPass, $lag, $bestTime] = self::yallopTiming($object, $place, $jdUt, $pass, $heightMetres, $timezone);
        [$crescentWidth, $yallopQ] = self::crescent($object, $geocentric, $fromTheCentre->altitude, $arcOfVision, $arcOfLight);

        $brightness = $object instanceof Star
            ? ['magnitude' => $object->magnitude, 'illuminated' => null]
            : self::brightness($object, $jdTT);

        return new HeliacalDetails(
            name: Horizon::nameOf($object),
            target: $object,
            instant: UtInstant::fromJd($jdUt, $timezone),
            pass: $pass,
            topocentricAltitude: $fromTheGround->altitude,
            apparentAltitude: $fromTheGround->apparentAltitude,
            geocentricAltitude: $fromTheCentre->altitude,
            azimuth: $fromTheGround->azimuth,
            sunAltitude: $sunView->altitude,
            sunAzimuth: $sunView->azimuth,
            topocentricArcOfVision: $fromTheGround->altitude - $sunView->altitude,
            arcOfVision: $arcOfVision,
            azimuthDifference: $azimuthDifference,
            arcOfLight: $arcOfLight,
            parallax: $fromTheCentre->altitude - $fromTheGround->altitude,
            objectPass: $objectPass,
            sunPass: $sunPass,
            lag: $lag,
            bestTime: $bestTime,
            crescentWidth: $crescentWidth,
            yallopQ: $yallopQ,
            yallopClass: $yallopQ === null ? null : HeliacalDetails::classOf($yallopQ),
            magnitude: $brightness['magnitude'],
            illuminatedPercent: $brightness['illuminated'],
        );
    }

    /**
     * Yallop's Ts, Tm, Lag and Tb: the two crossings of the horizon that bracket the
     * observation, and the best moment between them.
     *
     * **The crossings are the almanac's, the upper limb and with refraction**, which is what
     * `RiseSet` gives by default and what «sunset» and «moonset» mean in Yallop's Table 4.
     * Swiss's heliacal code takes the CENTRE of the disc instead; the difference and what it
     * costs are written in `HeliacalDetails::$objectPass`.
     *
     * **And they are the crossings that bracket the instant, not the ones that share its civil
     * date**, which is the one place where this parts from the day-by-day doctrine of the rest
     * of the class. An observation is made between sunset and the object's setting, and that
     * pair straddles midnight as often as not: Saturn near opposition sets at three in the
     * morning, and that setting belongs to the evening before, not to the calendar day it
     * happens to fall on. Both are therefore looked for forward from half a day before the
     * instant, which is the window that holds one of each and exactly one. Measured on Saturn
     * from Babylon on 2010-05-20: by civil date the setting came out 24 hours away from the
     * one Swiss pairs with that sunset, and with this it comes out the same one.
     *
     * If either crossing is missing, all four come back null rather than one of them being
     * invented: a best time between a sunset and a moonset that did not happen is not a time.
     * That is the polar day and night, a circumpolar object, and whatever never rises from
     * there.
     *
     * @param Body|Star $object
     * @param Place $place
     * @param float $jdUt
     * @param Pass $pass
     * @param float $heightMetres
     * @param DateTimeZone $timezone
     * @return array{0: UtInstant|null, 1: UtInstant|null, 2: float|null, 3: UtInstant|null}
     */
    private static function yallopTiming(
        Body|Star $object,
        Place $place,
        float $jdUt,
        Pass $pass,
        float $heightMetres,
        DateTimeZone $timezone,
    ): array {
        $from = $jdUt - 0.5;

        $objectPass = RiseSet::next($object, $place, $from, $pass, heightMetres: $heightMetres);
        $sunPass = RiseSet::next(Body::Sun, $place, $from, $pass, heightMetres: $heightMetres);

        if ($objectPass === null || $sunPass === null) {
            return [$objectPass, $sunPass, null, null];
        }

        $lag = $objectPass->jdUt - $sunPass->jdUt;

        // Yallop (4.1): Tb = Ts + (4/9) Lag. The four ninths come off Bruin's curves; see
        // `HeliacalDetails::$bestTime`.
        return [
            $objectPass,
            $sunPass,
            $lag * 1440.0,
            UtInstant::fromJd($sunPass->jdUt + 4.0 / 9.0 * $lag, $timezone),
        ];
    }

    /**
     * Yallop's topocentric crescent width W', in arcminutes, and his q, for the Moon; two nulls
     * for anything else, because a crescent is what this measures.
     *
     * The equations themselves live in `HeliacalDetails`, next to the docblocks that cite them,
     * so that a test can check them against Yallop's own Table 4 without going through an
     * ephemeris. What is decided here is the one thing they need from this side: **the
     * HORIZONTAL parallax**, which is what (3.8) is written for and the one place where this
     * parts from Swiss.
     *
     * @param Body|Star $object
     * @param Equatorial $geocentric
     * @param float $geocentricAltitude Yallop's h, in degrees.
     * @param float $arcOfVision
     * @param float $arcOfLight
     * @return array{0: float|null, 1: float|null}
     */
    private static function crescent(
        Body|Star $object,
        Equatorial $geocentric,
        float $geocentricAltitude,
        float $arcOfVision,
        float $arcOfLight,
    ): array {
        if ($object !== Body::Moon || $geocentric->distanceKm === null) {
            return [null, null];
        }

        $parallax = rad2deg(asin(Horizon::EQUATORIAL_RADIUS_KM / $geocentric->distanceKm));
        $width = HeliacalDetails::crescentWidthOf($parallax, $geocentricAltitude, $arcOfLight);

        return [$width, HeliacalDetails::qOf($arcOfVision, $width)];
    }

    /**
     * Visual magnitude and illuminated percentage of a body, which come out of the same
     * `Phenomena` call and are therefore asked for once.
     *
     * The magnitude carries the phase, which for this is the whole point: the Moon at a
     * two-per-cent crescent is four magnitudes fainter than the full one, and it is the
     * crescent that is hard to see. Null where `Magnitudes` has no fitted model for that body,
     * which is what already happens to Uranus and Neptune outside the range of phase angles
     * they were fitted with.
     *
     * @param Body $body
     * @param float $jdTT
     * @return array{magnitude: float|null, illuminated: float}
     */
    private static function brightness(Body $body, float $jdTT): array
    {
        $phenomenon = Phenomena::of($body, $jdTT);

        return [
            'magnitude' => $phenomenon->magnitude,
            'illuminated' => $phenomenon->illuminatedFraction * 100.0,
        ];
    }

    /**
     * What has no phenomenon to measure at all, and why. It throws instead of returning null
     * because it is an error of whoever calls, not a case the reckoning fails to find.
     *
     * **It is `check()` with the Moon let through**, and the two differ on exactly that one
     * case: there the Moon has no heliacal DATE to look for, here its crescent is the subject.
     * Everything else is rejected for the same reasons in both.
     *
     * @param Body|Star $object
     * @return void
     */
    private static function checkVisible(Body|Star $object): void
    {
        if (! $object instanceof Body) {
            return;
        }

        if ($object === Body::Sun) {
            throw new InvalidArgumentException('The Sun is what the arc is measured against: it has no arcus visionis of its own.');
        }

        if ($object->isLunarPoint() || $object === Body::Earth || $object->isFictitious()) {
            throw new InvalidArgumentException(sprintf('%s is not a body that can be seen.', $object->name()));
        }
    }

    /**
     * The phenomena of a kind there are in a window of days, in order.
     *
     * @param Body|Star $object
     * @param Place $place
     * @param HeliacalEvent $event
     * @param DateTimeImmutable $firstMidnight Local midnight of the first day.
     * @param int $days
     * @param float|null $arcusVisionis
     * @param float $heightMetres
     * @return list<HeliacalPhenomenon>
     */
    private static function events(
        Body|Star $object,
        Place $place,
        HeliacalEvent $event,
        DateTimeImmutable $firstMidnight,
        int $days,
        ?float $arcusVisionis,
        float $heightMetres,
    ): array {
        self::check($object);

        $arc = $arcusVisionis ?? ArcusVisionis::of($object, $event);

        if ($arc === null) {
            throw new InvalidArgumentException(sprintf(
                'There is no published arcus visionis for %s in a %s: it has to be passed by hand. See ArcusVisionis.',
                Horizon::nameOf($object), $event->name()
            ));
        }

        $timezone = $place->timeZone();
        $horizon = new Horizon($place, $heightMetres);

        // The local midnights, one per day. They are advanced with `modify` and not by adding
        // a Julian day: a civil day lasts twenty-three or twenty-five hours twice a year.
        $midnights = [];
        $midnight = $firstMidnight;

        for ($i = 0; $i <= $days; $i++) {
            $midnights[] = Time::julianDay($midnight);
            $midnight = $midnight->modify('+1 day');
        }

        $from = $midnights[0];
        $to = end($midnights);

        $position = self::topocentric($horizon, self::interpolator($object, $from, $to));
        $sun = self::topocentric($horizon, self::interpolator(Body::Sun, $from, $to));

        $morning = $event->isMorning();
        $first = $event->isFirst();

        $found = [];
        $previous = null;

        for ($i = 0; $i < $days; $i++) {
            $current = self::arc($horizon, $position, $sun, $morning, $midnights[$i], $midnights[$i + 1]);

            if ($current === null) {
                continue;
            }

            $visible = $current[0] >= $arc;

            if ($previous !== null) {
                $wasVisible = $previous[0] >= $arc;

                // An appearance is the first day on which it holds; a disappearance, the last.
                if ($first && $visible && ! $wasVisible) {
                    $found[] = self::compose($object, $horizon, $sun, $position, $event, $arc, $current, $timezone);
                } elseif (! $first && ! $visible && $wasVisible) {
                    $found[] = self::compose($object, $horizon, $sun, $position, $event, $arc, $previous, $timezone);
                }
            }

            $previous = $current;
        }

        return $found;
    }

    /**
     * The arcus visionis of a day: the depression of the Sun when the object crosses the
     * horizon.
     *
     * @param Horizon $horizon
     * @param Closure(float): Equatorial $position Topocentric position of the object, by jdUt.
     * @param Closure(float): Equatorial $sun Topocentric position of the Sun, by jdUt.
     * @param bool $morning Whether it is measured at the rising (morning) or at the setting
     *        (evening).
     * @param float $from
     * @param float $to
     * @return array{0: float, 1: float}|null [arc in degrees, jdUt of the crossing]
     */
    private static function arc(Horizon $horizon, Closure $position, Closure $sun, bool $morning, float $from, float $to): ?array
    {
        $jd = self::pass($horizon, $position, $morning, $from, $to);

        if ($jd === null) {
            return null;
        }

        return [-self::altitude($horizon, $sun, $jd), $jd];
    }

    /**
     * Assembles the finding: looks for the moment of the observation and measures there what
     * gets shown.
     *
     * @param Body|Star $object
     * @param Horizon $horizon
     * @param Closure(float): Equatorial $sun
     * @param Closure(float): Equatorial $position
     * @param HeliacalEvent $event
     * @param float $arc
     * @param array{0: float, 1: float} $day
     * @param DateTimeZone $timezone
     * @return HeliacalPhenomenon
     */
    private static function compose(
        Body|Star $object,
        Horizon $horizon,
        Closure $sun,
        Closure $position,
        HeliacalEvent $event,
        float $arc,
        array $day,
        DateTimeZone $timezone,
    ): HeliacalPhenomenon {
        [$arcOfTheDay, $jdPass] = $day;

        $jd = self::observation($horizon, $sun, $event->isMorning(), $jdPass, $arc);

        $objectTopo = $position($jd);
        $sunTopo = $sun($jd);
        $objectView = $horizon->horizontal($objectTopo, $jd);
        $sunView = $horizon->horizontal($sunTopo, $jd);

        return new HeliacalPhenomenon(
            name: Horizon::nameOf($object),
            target: $object,
            event: $event,
            observation: UtInstant::fromJd($jd, $timezone),
            pass: UtInstant::fromJd($jdPass, $timezone),
            arcusVisionis: $arcOfTheDay,
            arcusVisionisRequired: $arc,
            objectAltitude: $objectView->altitude,
            azimuthDifference: abs(self::wrap($objectView->azimuth - $sunView->azimuth)),
            elongation: $objectTopo->separation($sunTopo),
        );
    }

    /**
     * The instant at which the Sun is at the demanded depression, which is when one looks.
     *
     * In a morning event the object has already risen and the Sun is coming up, so that
     * moment is AFTER the crossing; in an evening one, before. It is searched for in that
     * direction because in the other one the Sun also passes through that altitude, half a
     * day earlier, with the object on the other side of the sky.
     *
     * @param Horizon $horizon
     * @param Closure(float): Equatorial $sun
     * @param bool $morning
     * @param float $jdPass
     * @param float $arc
     * @return float
     */
    private static function observation(Horizon $horizon, Closure $sun, bool $morning, float $jdPass, float $arc): float
    {
        $direction = $morning ? 1.0 : -1.0;
        $excess = fn (float $jd): float => self::altitude($horizon, $sun, $jd) + $arc;

        $y0 = $excess($jdPass);

        if ($y0 >= 0.0) {
            // The arc of the day is exactly the one demanded: the crossing is already the
            // moment to look.
            return $jdPass;
        }

        $t0 = $jdPass;
        $t1 = $jdPass;

        // Six hours are enough: the arc does not go beyond twenty degrees and the Sun rises
        // ten per hour even at the slowest latitudes of those calculated here.
        for ($jump = 0.01; $jump <= 0.26; $jump += 0.01) {
            $t1 = $jdPass + $direction * $jump;

            if ($excess($t1) >= 0.0) {
                break;
            }

            $t0 = $t1;
        }

        for ($i = 0; $i < 32; $i++) {
            $middle = ($t0 + $t1) / 2;

            if ($excess($middle) < 0.0) {
                $t0 = $middle;
            } else {
                $t1 = $middle;
            }
        }

        return ($t0 + $t1) / 2;
    }

    /**
     * The instant at which the object crosses the geometric horizon within a civil day, or
     * null if it does not cross it that day.
     *
     * **Mirror of the closed form pass finder**, with two differences: here the
     * position comes interpolated instead of being asked of the ephemeris on every iteration,
     * and a crossing that does not converge returns null instead of throwing, because in a
     * search of four hundred days one odd day cannot bring the whole calculation down. The
     * closed form is the same: `cos H = -tan φ · tan δ` gives the local sidereal time of the
     * crossing, from there the instant is worked out, and since the position moves it is
     * evaluated again there until the correction drops below one second.
     *
     * @param Horizon $horizon
     * @param Closure(float): Equatorial $position Topocentric, by jdUt.
     * @param bool $rising
     * @param float $from
     * @param float $to
     * @return float|null
     */
    private static function pass(Horizon $horizon, Closure $position, bool $rising, float $from, float $to): ?float
    {
        $latitude = $horizon->place->latitude;
        $target = self::siderealTimeOfPass($position(($from + $to) / 2), $rising, $latitude);

        if ($target === null) {
            return null;
        }

        $estimate = $from + self::normalize($target - $horizon->localSiderealTime($from)) / self::TURN_PER_DAY;
        $jd = self::converge($horizon, $position, $rising, $estimate);

        if ($jd === null) {
            return null;
        }

        if ($jd < $from) {
            $jd = self::converge($horizon, $position, $rising, $jd + self::SIDEREAL_DAY);
        } elseif ($jd >= $to) {
            $jd = self::converge($horizon, $position, $rising, $jd - self::SIDEREAL_DAY);
        }

        return $jd !== null && $jd >= $from && $jd < $to ? $jd : null;
    }

    /**
     * Fixed point over the sidereal time of the crossing.
     *
     * @param Horizon $horizon
     * @param Closure(float): Equatorial $position
     * @param bool $rising
     * @param float $jd
     * @return float|null
     */
    private static function converge(Horizon $horizon, Closure $position, bool $rising, float $jd): ?float
    {
        $latitude = $horizon->place->latitude;

        for ($turn = 0; $turn < self::MAX_ROUNDS; $turn++) {
            $target = self::siderealTimeOfPass($position($jd), $rising, $latitude);

            if ($target === null) {
                return null;
            }

            $jump = self::wrap($target - $horizon->localSiderealTime($jd)) / self::TURN_PER_DAY;
            $jd += $jump;

            if (abs($jump) < self::TOLERANCE) {
                return $jd;
            }
        }

        return null;
    }

    /**
     * The local sidereal time, in degrees, at which a direction crosses the geometric
     * horizon, or null if from that latitude it never crosses it. Mirror of
     * the sidereal time of a pass, for the two cases needed here.
     *
     * @param Equatorial $direction
     * @param bool $rising
     * @param float $latitude
     * @return float|null
     */
    private static function siderealTimeOfPass(Equatorial $direction, bool $rising, float $latitude): ?float
    {
        $cosH = -tan(deg2rad($latitude)) * tan(deg2rad($direction->declination));

        if ($cosH < -1.0 || $cosH > 1.0) {
            return null;
        }

        $h0 = rad2deg(acos($cosH));

        return self::normalize($direction->rightAscension + ($rising ? -$h0 : $h0));
    }

    /**
     * Geometric altitude, without refraction, of whatever the interpolator returns.
     *
     * @param Horizon $horizon
     * @param Closure(float): Equatorial $position
     * @param float $jd
     * @return float
     */
    private static function altitude(Horizon $horizon, Closure $position, float $jd): float
    {
        return $horizon->horizontal($position($jd), $jd)->altitude;
    }

    /**
     * Wraps a geocentric interpolator so that it returns topocentric positions.
     *
     * @param Horizon $horizon
     * @param Closure(float): Equatorial $geocentric
     * @return Closure(float): Equatorial
     */
    private static function topocentric(Horizon $horizon, Closure $geocentric): Closure
    {
        return fn (float $jdUt): Equatorial => $horizon->topocentric($geocentric($jdUt), $jdUt);
    }

    /**
     * The geocentric position as a continuous function of time, sampled at a step of days.
     *
     * It is `RiseSet::interpolator` with the step changed: there it is sampled every hour
     * because what is being looked for has to be nailed down to the second within a day, and
     * here every two days because what is being looked for is a day. The three rectangular
     * components are interpolated and not the right ascension and the declination, for the
     * same reason as there: an angle that crosses 360 degrees breaks any interpolation and a
     * vector crosses nothing.
     *
     * @param Body|Star $object
     * @param float $from jdUt
     * @param float $to jdUt
     * @return Closure(float): Equatorial By jdUt.
     */
    private static function interpolator(Body|Star $object, float $from, float $to): Closure
    {
        $track = Horizon::track($object);
        $half = intdiv(self::POINTS, 2);
        $start = $from - $half * self::SAMPLE_STEP;
        $count = (int) ceil(($to - $from) / self::SAMPLE_STEP) + self::POINTS + 1;

        $samples = [];
        $withDistance = true;

        for ($i = 0; $i < $count; $i++) {
            $coordinate = $track(Time::tt($start + $i * self::SAMPLE_STEP));

            if ($coordinate->distanceKm === null) {
                $withDistance = false;
                $samples[] = $coordinate->unit();
            } else {
                $samples[] = $coordinate->vector();
            }
        }

        return function (float $jdUt) use ($samples, $start, $withDistance): Equatorial {
            $x = ($jdUt - $start) / self::SAMPLE_STEP;
            $half = intdiv(self::POINTS, 2);
            $first = max(0, min((int) floor($x) - $half + 1, count($samples) - self::POINTS));

            $vector = [0.0, 0.0, 0.0];

            for ($i = 0; $i < self::POINTS; $i++) {
                $weight = 1.0;

                for ($j = 0; $j < self::POINTS; $j++) {
                    if ($i !== $j) {
                        $weight *= ($x - ($first + $j)) / ($i - $j);
                    }
                }

                $vector[0] += $weight * $samples[$first + $i][0];
                $vector[1] += $weight * $samples[$first + $i][1];
                $vector[2] += $weight * $samples[$first + $i][2];
            }

            $coordinate = Equatorial::fromVector($vector);

            return $withDistance
                ? $coordinate
                : Equatorial::direction($coordinate->rightAscension, $coordinate->declination);
        };
    }

    /**
     * What has no heliacal phenomenon and why. It throws instead of returning null because it
     * is an error of whoever calls, not a case the calculation fails to find.
     *
     * @param Body|Star $object
     * @return void
     */
    private static function check(Body|Star $object): void
    {
        if (! $object instanceof Body) {
            return;
        }

        if ($object === Body::Sun) {
            throw new InvalidArgumentException('The Sun has no heliacal phenomena: the arc is measured against it.');
        }

        if ($object === Body::Moon) {
            throw new InvalidArgumentException(
                'The Moon does not apply: its first and last visibility are those of the crescent, and that is decided by '
                .'the width of the crescent and not the depression of the Sun. See the Yallop criterion or the Caldwell and Laney one.'
            );
        }

        if ($object->isLunarPoint() || $object === Body::Earth) {
            throw new InvalidArgumentException(sprintf('%s is not a body that can be seen.', $object->name()));
        }
    }

    /**
     * The local midnight of the civil day an instant falls in.
     *
     * @param float $jdUt
     * @param Place $place
     * @return DateTimeImmutable
     */
    private static function fromJd(float $jdUt, Place $place): DateTimeImmutable
    {
        $timezone = $place->timeZone();

        return new DateTimeImmutable(
            UtInstant::fromJd($jdUt, $timezone)->date->format('Y-m-d').' 00:00:00',
            $timezone
        );
    }

    /**
     * @param float $degrees
     * @return float In [0, 360).
     */
    private static function normalize(float $degrees): float
    {
        return fmod(fmod($degrees, 360.0) + 360.0, 360.0);
    }

    /**
     * @param float $degrees
     * @return float In [-180, 180).
     */
    private static function wrap(float $degrees): float
    {
        return self::normalize($degrees + 180.0) - 180.0;
    }
}
