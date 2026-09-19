<?php

namespace Astronomy;

/**
 * Everything that is measured about an object at one instant in order to decide whether it can
 * be seen: this is Swiss's `swe_heliacal_pheno_ut`.
 *
 * `HeliacalPhenomenon` answers «on what day», which is the question the technique exists for.
 * This one answers «what did the sky look like at this moment», and it exists because the
 * numbers that go into the decision are worth reading one by one: the two altitudes of the
 * object, the depression of the Sun, the arc of light between them, the width of the crescent
 * and, for the Moon, Yallop's q-test. Everything here is a single instant, chosen by whoever
 * calls; nothing is searched for.
 *
 * ### Where the reckoning comes from
 *
 * The geometry is this engine's own, and the crescent is **Yallop (1997), NAO Technical Note
 * 69**, `SOURCE` below, read from the paper. Four of its equations are used and they are quoted
 * where they are applied:
 *
 * - **(2.1)** `cos ARCL = cos ARCV · cos DAZ`, which defines the arc of light out of the arc of
 *   vision and the difference in azimuth. **It is a definition to copy, not an approximation to
 *   improve.** It is not the true angular separation of the two directions, and it is not meant
 *   to be: it is exact in the limit of small angles, which is where a crescent lives, and it
 *   drifts away from there. Measured at Madrid on three evenings of 2000, against the true
 *   separation of the very same two directions: at an arc of light of 6.36 degrees they differ
 *   by 0.0005, at 19.17 by 0.058 and at 31.89 by 0.288. Yallop's whole calibration, the 295
 *   observations of his Table 4 and the six class boundaries of his Table 5, rests on this
 *   ARCL. Replacing it with the true separation would leave the criterion measuring something
 *   the criterion was never calibrated on. Far from a crescent the two part company for good,
 *   and that is not a failure either: measured on Saturn from Babylon at an arc of 107 degrees,
 *   107.00 against 118.33.
 * - **(3.8)** `SD = 0.27245 π`, **(3.9)** `SD' = SD (1 + sin h · sin π)` and **(3.10)**
 *   `W' = SD' (1 − cos ARCL)`, the topocentric width of the crescent. See `crescentWidth` for
 *   which parallax goes into (3.8), which is the one place where Swiss and the paper part.
 * - **(6.1)** the q-test, and **Table 5**, its six classes. See `yallopQ` and `yallopClass`.
 * - **(4.1)** `Tb = Ts + (4/9) Lag`, the best time to look. See `bestTime`.
 *
 * ### The thirty values, and which ones are not here
 *
 * Swiss returns an array of fifty floats of which it documents the first thirty. The names below are after what each one IS, so
 * that nobody needs Swiss's manual open; this table is for whoever wants to compare anyway.
 *
 * | here | Swiss | index |
 * |---|---|---|
 * | `topocentricAltitude` | AltO | 0 |
 * | `apparentAltitude` | AppAltO | 1 |
 * | `geocentricAltitude` | GeoAltO | 2 |
 * | `azimuth` | AziO | 3 |
 * | `sunAltitude` | AltS | 4 |
 * | `sunAzimuth` | AziS | 5 |
 * | `topocentricArcOfVision` | TAVact | 6 |
 * | `arcOfVision` | ARCVact | 7 |
 * | `azimuthDifference` | DAZact | 8 |
 * | `arcOfLight` | ARCLact | 9 |
 * | `extinctionCoefficient` | kact | 10 |
 * | `minimumTopocentricArc` | minTAV | 11 |
 * | `firstVisible` | TfistVR | 12 |
 * | `bestVisibleByContrast` | TbVR | 13 |
 * | `lastVisible` | TlastVR | 14 |
 * | `bestTime` | TbYallop | 15 |
 * | `crescentWidth` | WMoon | 16 |
 * | `yallopQ` | qYal | 17 |
 * | `yallopClass` | qCrit | 18 |
 * | `parallax` | ParO | 19 |
 * | `magnitude` | Magn | 20 |
 * | `objectPass` | RiseO | 21 |
 * | `sunPass` | RiseS | 22 |
 * | `lag` | Lag | 23 |
 * | `visibilityDuration` | TvisVR | 24 |
 * | `crescentLength` | LMoon | 25 |
 * | `crescentVisibilityAngle` | CVAact | 26 |
 * | `illuminatedPercent` | Illum | 27 |
 * | not carried | CVAact (28), MSk (29) | 28, 29 |
 *
 * **The last row is measured, not assumed**: Swiss 2.10.03 declares indices 28 and 29 and
 * leaves them at exactly 0.0. Checked over 108 combinations of four objects, three dates, three
 * hours and two event kinds: the set of values returned there has one element, `(0.0, 0.0)`.
 * There is nothing to map, so there is no field for them rather than a field that would always
 * be null.
 *
 * **Eight of the thirty come back null, and every one of them says why in its own docblock.**
 * They fall into three groups and only the first was expected:
 *
 * - **Five need Schaefer's contrast model**: `minimumTopocentricArc`, `firstVisible`,
 *   `bestVisibleByContrast`, `lastVisible` and `visibilityDuration`. Measured: sweeping the
 *   observer's age from 20 to 80 and the Snellen ratio from 0.5 to 2.0, those five move and the
 *   other twenty-five do not, `crescentWidth` and `yallopQ` among them.
 * - **One needs an atmosphere this package does not carry**: `extinctionCoefficient`. It does
 *   not move with the observer, but it does move with the relative humidity, which is the half
 *   of Schaefer that `ArcusVisionis` already writes down as a thing this engine will not
 *   invent. That one was not expected, and it is not the observer's eyes: it is the air.
 * - **Two have no published definition**: `crescentLength` and `crescentVisibilityAngle`, and
 *   Swiss's own values for them contradict their own names. Measured at Madrid at 19:30 UT: the
 *   «crescent length» comes out NEGATIVE, −13.91 arcminutes, on 2000-08-29 at an arc of light
 *   of 6.48 degrees, and on 2000-09-05, at an arc of 87.08, it comes out at 30.57 arcminutes
 *   against a topocentric lunar diameter of 26.79, that is 14 % longer than the disc it is
 *   drawn on. A length is neither of those things.
 *
 * ### The floor of any comparison against Swiss, which is Swiss's and not ours
 *
 * **Swiss's heliacal internals disagree with Swiss's own `swe_azalt` at the same instant.**
 * Measured at Madrid on 2000-08-30 19:30 UT: its AltS is 7.34 arcseconds above the geocentric
 * altitude that `swe_azalt` gives for the Sun, its AltO is 7.29 arcseconds above the
 * topocentric one it gives for the Moon, and the two azimuths are off by 32 and 14 arcseconds.
 * The two altitudes would both be explained by evaluating 0.68 seconds earlier, and the
 * azimuths would not, so it is not a shifted clock. Whatever it is, it is the floor of any
 * comparison of these numbers against Swiss, and it is thirty-five to a hundred and sixty times
 * this engine's own 0.2-arcsecond floor against the JPL.
 *
 * **It largely cancels in the differences, which is the good news and worth knowing before
 * anybody goes hunting.** The two altitudes carry the same offset, so the arcs built out of
 * them do not: measured over the three Madrid crescents, `arcOfVision` and
 * `topocentricArcOfVision` land within 0.43 arcseconds of Swiss while the altitudes they are
 * made of are 7 to 8 arcseconds away from it.
 */
readonly class HeliacalDetails
{
    /** Where the crescent reckoning comes from, so it can be written wherever it is needed. */
    public const SOURCE = 'Yallop, B. D. (1997), «A Method for Predicting the First Sighting of the New Crescent Moon», NAO Technical Note No 69';

    /**
     * Yallop's Table 5, the six classes of the q-test: [letter, lower bound of q, remark].
     *
     * The bounds are read as «q greater than this one», so A is `q > +0.216` and F is whatever
     * is left below the last bound. The remarks are Yallop's own wording from the table.
     */
    private const CLASSES = [
        ['A', 0.216, 'Easily visible to the unaided eye'],
        ['B', -0.014, 'Visible under perfect atmospheric conditions'],
        ['C', -0.160, 'May need optical aid to find the crescent'],
        ['D', -0.232, 'Will need optical aid to find the crescent'],
        ['E', -0.293, 'Not visible with a telescope'],
        ['F', null, 'Not visible, below the Danjon limit'],
    ];

    /**
     * What the sky was doing at one instant, from one place, for one object.
     */
    public function __construct(
        /** What is being looked at, written out: «Moon», «Venus», «Sirius». */
        public string $name,
        public Body|Star $target,
        /** The instant all of this is measured at, which is the one that was asked for. */
        public UtInstant $instant,
        /**
         * Which crossing of the horizon `lag` and `bestTime` are measured over, and therefore
         * which side of the Sun the object is on.
         *
         * **It is not asked for: it is read off the geometry**, and that is why this method
         * takes no event where Swiss takes one. An object east of the Sun in longitude sets
         * after it and is an evening object; one west of it rises before it and is a morning
         * object. That is the old distinction between the evening star and the morning star,
         * and for the Moon it is exactly waxing against waning.
         */
        public Pass $pass,
        /**
         * Altitude of the object from the ground, in degrees, GEOMETRIC: where the object is,
         * not where it is seen.
         */
        public float $topocentricAltitude,
        /**
         * The same, refracted: where it is SEEN. With the standard atmosphere of `Horizon`,
         * 1010 millibars and 10 degrees, which is the only atmosphere this package has.
         */
        public float $apparentAltitude,
        /**
         * Altitude of the object from the centre of the Earth, in degrees. It is the one
         * Yallop's ARCV is built on, and for the Moon it is up to a degree above the
         * topocentric one.
         */
        public float $geocentricAltitude,
        /** Azimuth of the object, in degrees from NORTH towards east, as all of `Horizon`. */
        public float $azimuth,
        /**
         * Altitude of the Sun, in degrees, geometric and GEOCENTRIC. Negative after sunset,
         * which is when any of this is worth measuring.
         *
         * **Geocentric because that is how Yallop defines ARCV**, «the geocentric difference in
         * altitude between the centre of the Sun and the centre of the Moon ... ignoring the
         * effects of refraction», section 2 of the paper. The Sun's own parallax in altitude is
         * 8.6 arcseconds, measured at Madrid, worth 0.0002 in a q whose class boundaries are
         * spaced between 0.05 and 0.2: it cannot move a class.
         *
         * **And Swiss's AltS is labelled «topocentric altitude of Sun» and measured it is not.**
         * At Madrid on three evenings of 2000 it sits 7.29 to 7.88 arcseconds from the
         * geocentric altitude `swe_azalt` gives and 15.93 to 16.52 from the topocentric one,
         * where the parallax itself is 8.6: it is the geocentric one, carrying the offset the
         * class docblock describes. So following Yallop here follows Swiss as well, and the
         * agreement in `arcOfVision` says so.
         */
        public float $sunAltitude,
        /** Azimuth of the Sun, in degrees from north towards east, geocentric to match. */
        public float $sunAzimuth,
        /**
         * The topocentric arcus visionis, in degrees: how far the Sun is below the object as
         * both are seen from the ground. Swiss's TAVact.
         */
        public float $topocentricArcOfVision,
        /**
         * Yallop's ARCV, in degrees: the geocentric altitude of the object minus the altitude
         * of the Sun. It is the vertical half of the crescent criterion, and the quantity all
         * three of the twentieth-century methods in Yallop's paper are tabulated against.
         */
        public float $arcOfVision,
        /**
         * Yallop's DAZ, in degrees: the difference in azimuth, **Sun minus object**, wrapped to
         * [-180, 180). The sign is Yallop's, section 2, and Swiss keeps it.
         */
        public float $azimuthDifference,
        /**
         * Yallop's ARCL, in degrees, out of equation (2.1) and not out of the true separation.
         * See the class docblock for why that is deliberate and what it costs.
         */
        public float $arcOfLight,
        /**
         * Parallax of the object in ALTITUDE, in degrees: how much lower it is seen from the
         * ground than from the centre of the Earth. The Moon reaches almost a degree; a planet
         * is thousandths; a star is exactly zero, and that zero is true rather than missing.
         */
        public float $parallax,
        /**
         * The object's crossing of the horizon that brackets the observation: its setting for
         * an evening object, its rising for a morning one. Yallop's Tm.
         *
         * **With the almanac convention, the upper limb and with refraction**, which is what
         * `RiseSet` gives by default and what «sunset» and «moonset» mean in the table Yallop
         * calibrated on. Swiss's heliacal code takes the CENTRE of the disc instead. Measured
         * against `swe_rise_trans` asked for that same convention, Swiss's own value sits
         * within 0.9 to 2.5 seconds of it, so that is what it is; measured against this,
         * a body with a disc comes out 80 to 123 seconds later here.
         *
         * **What that does downstream is not the same for the Moon and for a planet.** The two
         * semi-diameters are nearly equal, so for the Moon the shift very largely cancels in
         * `lag`: measured over five crescents, from −1.4 to +7.0 seconds against Swiss. A planet
         * has no disc worth the name, so only the Sun's end moves and the whole 80 seconds
         * stay in the lag. `bestTime`, which hangs off both, moves by some 82 seconds either
         * way.
         *
         * Null when there is no such crossing: polar day or night, a circumpolar object, or
         * whatever never rises from there. `lag` and `bestTime` go with it.
         */
        public ?UtInstant $objectPass,
        /** The Sun's crossing of the same night, same convention: Yallop's Ts. */
        public ?UtInstant $sunPass,
        /**
         * Yallop's Lag, **in minutes**: from the Sun's crossing to the object's. Positive for
         * an evening object, which sets after the Sun, and NEGATIVE for a morning one, which
         * rises before it. That sign is Yallop's own: the morning entries of his Table 4 carry
         * negative lags.
         *
         * Minutes because that is the unit of his Table 4, column 13. Swiss returns it in days.
         */
        public ?float $lag,
        /**
         * Yallop's Tb, equation (4.1): `Ts + (4/9) Lag`, the moment the twilight sky and the
         * sinking object strike their best balance.
         *
         * The four ninths are not a fit to anything astronomical. Yallop got them off Bruin's
         * 1977 curves: a straight line through the origin and through the minimum of the
         * W = 0.5 curve passes through the minima of all of them, which puts the best moment at
         * `4h = 5s`, and that is the whole derivation. That Swiss's TbYallop is this same
         * expression and nothing else was checked rather than assumed: `RiseS + (4/9)·Lag`
         * reproduces its own TbYallop to 4e-5 seconds.
         *
         * **It is given for every object, and Swiss gives it only for the Moon**, which returns
         * its 99999999.0 sentinel for everything else. The rule was published for the new
         * crescent and the curves behind it are the Moon's, so for a planet it is Yallop's
         * geometry carried over rather than Yallop's result; the geometry it describes, a sky
         * darkening while an object sinks, is the same for any of them.
         */
        public ?UtInstant $bestTime,
        /**
         * Yallop's W', the topocentric width of the crescent, **in ARCMINUTES**. Null for
         * anything but the Moon, because a crescent is what this measures.
         *
         * **Equation (3.8) takes the HORIZONTAL parallax, and Swiss takes the parallax in
         * altitude.** That is the one place where this class and Swiss part, and it is
         * measurable rather than arguable: 0.27245 is the ratio of the two radii,
         * 1737.4 / 6378.1366 = 0.272394, so (3.8) only yields a semi-diameter when it is fed
         * the horizontal parallax, and (3.9) is the ordinary geocentric-to-topocentric
         * augmentation, which needs a geocentric semi-diameter to augment. Yallop says as much
         * in the heading of his Table 4, column 14: «Parallax of the Moon (π) in minutes of
         * arc. Semi-diameter = 0·27245 π», and the column runs from 54 to 61 arcminutes, which
         * is the range of the horizontal parallax and not of the parallax in altitude.
         *
         * Measured at Madrid on 2000-08-30 19:30 UT, with the Moon 3.98 degrees up: with the
         * horizontal parallax the semi-diameter comes out at 16.1539 arcminutes against a true
         * geocentric 16.1502, so right to 0.004; with Swiss's parallax in altitude it comes out
         * at 16.1094, which is 0.041 arcminutes short, 0.25 % low. **The shortfall goes as the
         * cosine of the altitude, so it is smallest exactly where a crescent is looked for and
         * grows above it**: at the same place on 2000-08-15, with the Moon 13.67 degrees up, it
         * is 2.6 %. Over five crescents in Madrid, Babylon and Oslo, W' here runs 0.22 % to
         * 1.38 % above Swiss's, and recomputing it with Swiss's parallax reproduces Swiss to
         * between 0.02 % and 0.07 %, which is the two engines' positions and nothing more. That
         * is what makes the diagnosis a measurement rather than a reading of anybody's code.
         *
         * None of the five changed Yallop class.
         *
         * **Yallop as published is what is implemented**, which is the same choice this package
         * already makes with the 1976 precession over Vondrák's and with Simon's mean elements
         * over Swiss's table: take the source, and write the residual down.
         */
        public ?float $crescentWidth,
        /**
         * Yallop's q, equation (6.1), dimensionless and scaled by ten to sit roughly between
         * -1 and +1. Null for anything but the Moon.
         *
         * **W' goes into (6.1) in ARCMINUTES**, and this is where somebody comparing gets
         * bitten: Swiss returns WMoon in DEGREES while computing its own qYal from the same
         * number in arcminutes. Feeding Swiss's WMoon straight into (6.1) gives a q that is
         * wrong by a factor nobody would spot.
         *
         * **And q is defined AT THE BEST TIME**, not at any instant. This returns it for the
         * instant that was asked for, which is what Swiss does too; whoever wants Yallop's q
         * reads `bestTime` here and asks again there.
         */
        public ?float $yallopQ,
        /**
         * The class of Yallop's Table 5, a letter from 'A' to 'F'. Null for anything but the
         * Moon. `yallopRemark()` gives the wording.
         *
         * **Swiss calls this one «qCrit, q-test criterion of Yallop» and returns a float**,
         * which reads as though it were a threshold value. It is not: measured, it takes the
         * values 1.0 and 6.0, and those are the ordinals of the classes. A crescent at
         * q = +0.553 comes back 1.0 and one at q = -0.549 comes back 6.0, which are exactly A
         * and F. Here it is the letter, because that is what it is.
         */
        public ?string $yallopClass,
        /**
         * Visual magnitude of the object, or null where this engine has no model for it.
         *
         * A body goes through `Phenomena` and `Magnitudes`, which is fitted against the JPL body
         * by body, so it carries the phase: the Moon at a 2.8 % crescent is seven magnitudes
         * fainter than the full one. A star carries the catalogue's own. Null is the ordinary
         * answer for Uranus and Neptune outside the range of phase angles they were fitted
         * with, and for the catalogue's clusters, which have no published V magnitude.
         *
         * **Against Swiss it agrees for planets and parts company for the Moon, and the Moon is
         * the one to be careful with.** Measured on four planets, the two agree to 0.009
         * magnitudes or better. On the Moon at a phase angle of 173.6 degrees this gives −4.66
         * where `swe_pheno` gives −1.77, and the published Allen (1963) lunar curve, which is
         * what Yallop's paper cites for exactly this, gives −4.58. This model is the one fitted
         * against the JPL and it is the one that lands next to Allen.
         */
        public ?float $magnitude,
        /**
         * Percentage of the object's disc seen lit, from 0 to 100. Null for a star, which has
         * no phase and no disc.
         *
         * **Geocentric, and Swiss's Illum is topocentric.** It is `Phenomena`, which is this
         * package's one definition of a phase and is geocentric, rather than a second phase
         * reckoning grown inside a heliacal class. What that costs was measured rather than
         * waved at: for a planet, nothing (Venus 92.6737 against 92.6740); for the Moon near
         * the new one it is real, because the observer stands 6378 km off the centre and that
         * moves its phase angle by 0.61 degrees. At Madrid on 2000-08-30 this gives 2.80 % and
         * Swiss gives 2.63 %. Swiss's number is `swe_pheno` with `SEFLG_TOPOCTR`, checked: the
         * two match to every printed figure.
         */
        public ?float $illuminatedPercent,
        /**
         * Swiss's kact. **Always null**, and it is the one of the eight that was not expected.
         *
         * It is the total atmospheric extinction coefficient, and it does not depend on the
         * observer: measured, it does not move with age or with the Snellen ratio. It moves
         * with the RELATIVE HUMIDITY, which this package does not have and has already decided
         * twice not to invent: `ArcusVisionis` names «that day's atmospheric extinction
         * coefficient» and «the humidity» in the same breath as the age of whoever is looking,
         * as the parameters that make Schaefer's model describe an observer nobody entered.
         * Measured at Madrid on 2000-08-30, with everything else held still: at 0 % relative
         * humidity Swiss's kact is 0.2515, at 40 % it is 0.3272 and at 90 % it is 0.9074. It
         * nearly triples across a range of air that any evening can have, and this package has
         * no way to tell which of those evenings it was.
         */
        public ?float $extinctionCoefficient = null,
        /**
         * Swiss's minTAV. **Always null**: the smallest topocentric arc at which the object
         * would still be seen, which is Schaefer's contrast model and nothing else.
         *
         * That model asks for the observer's age and the acuity of their eyes, and this package
         * does not invent an observer. **How much that is worth is measured, and it is not
         * small.** At Madrid on 2000-08-30, moving the Snellen ratio from 0.5 to 2.0 and
         * touching nothing else moves this very number from 8.936 degrees to 5.629, and it
         * moves Swiss's own `swe_vis_limit_mag` from −3.7364 to −0.7261, that is **3.01
         * magnitudes on the acuity of one pair of eyes**; the observer's age from 20 to 80 is
         * worth another 1.05 degrees of arc. A number that swings by three magnitudes on a
         * parameter nobody entered would read exactly like a measured one.
         *
         * What can be said honestly instead is `ArcusVisionis::limitMagnitude`, which is
         * Schoch's published table read backwards, with its four conditions written out.
         */
        public ?float $minimumTopocentricArc = null,
        /** Swiss's TfistVR. **Always null**, Schaefer: see `minimumTopocentricArc`. */
        public ?UtInstant $firstVisible = null,
        /**
         * Swiss's TbVR. **Always null**, Schaefer: the best moment by contrast rather than by
         * Yallop's rule. `bestTime` is the one that can be computed here, and it is the one
         * Yallop published.
         */
        public ?UtInstant $bestVisibleByContrast = null,
        /** Swiss's TlastVR. **Always null**, Schaefer: see `minimumTopocentricArc`. */
        public ?UtInstant $lastVisible = null,
        /** Swiss's TvisVR. **Always null**, Schaefer: how long the object stays visible. */
        public ?float $visibilityDuration = null,
        /**
         * Swiss's LMoon. **Always null**, and not for want of a model but for want of a
         * definition: no published source gives it, and Swiss's own value contradicts its name
         * twice over.
         *
         * Measured at Madrid, at 19:30 UT of four evenings of 2000: at an arc of light of 6.48
         * degrees it comes out at −13.91 arcminutes, and a length is not negative; at 87.08
         * degrees it comes out at 30.57 against a topocentric diameter of 26.79, and a crescent
         * drawn on a disc cannot be 14 % longer than the disc. Geometry says the cusps of any
         * crescent sit at the ends of a diameter, so its length is the diameter and nothing
         * else; what makes an observed crescent look shorter is that the light dies away
         * towards the cusps, which is a contrast question and lands back on Schaefer.
         */
        public ?float $crescentLength = null,
        /**
         * Swiss's CVAact. **Always null**, same reason as `crescentLength`: it has no published
         * definition, and measured it exceeds the arc of light it is supposed to sit inside, by
         * 4.18 degrees at an arc of light of 87.08.
         */
        public ?float $crescentVisibilityAngle = null,
    ) {}

    /**
     * Yallop's ARCL out of his ARCV and DAZ, equation (2.1): `cos ARCL = cos ARCV · cos DAZ`.
     *
     * It is public because it is the published definition and because that is what makes it
     * checkable against the paper itself rather than against another program: fed the ARCV and
     * DAZ of the eight rows of his Table 4 that the test carries, it returns their published
     * ARCL to the tenth of a degree they are printed with.
     *
     * @param float $arcOfVision Degrees.
     * @param float $azimuthDifference Degrees, Sun minus object. Only its cosine is used, so
     *        the sign and the branch it is wrapped into do not matter here.
     * @return float Degrees.
     */
    public static function arcOfLightOf(float $arcOfVision, float $azimuthDifference): float
    {
        $cosine = cos(deg2rad($arcOfVision)) * cos(deg2rad($azimuthDifference));

        return rad2deg(acos(max(-1.0, min(1.0, $cosine))));
    }

    /**
     * Yallop's topocentric crescent width W', **in arcminutes**, out of his (3.8), (3.9) and
     * (3.10):
     *
     * ```
     * SD  = 0.27245 π
     * SD' = SD (1 + sin h sin π)
     * W'  = SD' (1 − cos ARCL)
     * ```
     *
     * **π is the HORIZONTAL parallax**, which is the one thing here that Swiss does otherwise;
     * the measurement that settles it is in `$crescentWidth`.
     *
     * @param float $parallax The horizontal parallax of the Moon, in DEGREES.
     * @param float $geocentricAltitude Yallop's h, in degrees.
     * @param float $arcOfLight Degrees.
     * @return float Arcminutes.
     */
    public static function crescentWidthOf(float $parallax, float $geocentricAltitude, float $arcOfLight): float
    {
        $semiDiameter = 0.27245 * $parallax;
        $topocentricSemiDiameter = $semiDiameter
            * (1.0 + sin(deg2rad($geocentricAltitude)) * sin(deg2rad($parallax)));

        return 60.0 * $topocentricSemiDiameter * (1.0 - cos(deg2rad($arcOfLight)));
    }

    /**
     * Yallop's q-test, equation (6.1): the Indian method written as a cubic in W' and scaled by
     * ten so that it sits roughly between -1 and +1.
     *
     * @param float $arcOfVision Degrees.
     * @param float $crescentWidth **Arcminutes**, which is the trap: Swiss carries the same
     *        quantity in degrees.
     * @return float
     */
    public static function qOf(float $arcOfVision, float $crescentWidth): float
    {
        $w = $crescentWidth;

        return ($arcOfVision - (11.8371 - 6.3226 * $w + 0.7319 * $w ** 2 - 0.1018 * $w ** 3)) / 10.0;
    }

    /**
     * Yallop's class for a value of q, a letter from 'A' to 'F'. His Table 5.
     *
     * @param float $q
     * @return string
     */
    public static function classOf(float $q): string
    {
        foreach (self::CLASSES as [$letter, $lowerBound, $remark]) {
            if ($lowerBound === null || $q > $lowerBound) {
                return $letter;
            }
        }

        return 'F';
    }

    /**
     * What Yallop's Table 5 says about this crescent, or null when there is no crescent because
     * the object is not the Moon.
     *
     * @return string|null
     */
    public function yallopRemark(): ?string
    {
        foreach (self::CLASSES as [$letter, $lowerBound, $remark]) {
            if ($letter === $this->yallopClass) {
                return $remark;
            }
        }

        return null;
    }

    /**
     * Whether the object is on the morning side of the Sun, that is, whether it rises before
     * it. False is the evening side.
     *
     * @return bool
     */
    public function isMorning(): bool
    {
        return $this->pass === Pass::Rise;
    }

    /**
     * A written line, to paste into a text or a table: «Moon on 2000-08-30: 2.6 % lit, arc of
     * light 19.17°, crescent 0.89', q = +0.553 (A, easily visible to the unaided eye)».
     *
     * For anything without a crescent the tail is dropped, because there is nothing to say
     * there and a zero would read as a measurement.
     *
     * @return string
     */
    public function summary(): string
    {
        $line = sprintf(
            '%s on %s: arc of light %.2f°, arc of vision %.2f°, Sun %.2f° below the horizon',
            $this->name,
            $this->instant->date->format('Y-m-d H:i'),
            $this->arcOfLight,
            $this->arcOfVision,
            -$this->sunAltitude
        );

        if ($this->yallopQ === null || $this->crescentWidth === null) {
            return $line;
        }

        return $line.sprintf(
            ", crescent %.2f', q = %+.3f (%s, %s)",
            $this->crescentWidth,
            $this->yallopQ,
            $this->yallopClass,
            lcfirst((string) $this->yallopRemark())
        );
    }
}
