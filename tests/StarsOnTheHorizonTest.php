<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\Star;
use Astronomy\Stars;
use Astronomy\Horizon;
use Astronomy\Place;
use Astronomy\Occultations;
use Astronomy\RiseSet;
use Astronomy\Pass;
use Astronomy\Time;
use Astronomy\EclipseType;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Fixed stars on the horizon: rises, sets, culminations and lunar occultations, against
 * `swe_rise_trans`, `swe_lun_occult_when_glob` and `swe_lun_occult_when_loc` with the star's
 * name as the target body.
 *
 * The figures are from Swiss Ephemeris 2.10.03 with Moshier and `sefstars.txt`, atmosphere
 * of 1010 mbar and 10 degrees, computed separately and copied here as constants so the suite
 * runs without Swiss, the way `RiseSetTest` and `OccultationsTest` already do for the planets.
 *
 * Measured before fixing the tolerances: 39 rises and 39 sets of five stars at three places
 * at **0.10 seconds** worst case and 0.03 median, and 90 culminations at 0.04; the maxima of
 * the 27 global occultations of Antares and Aldebaran at 14 seconds worst case and 8 median,
 * and in 31 local ones the maximum at 12 and the contacts at 18 worst case with 4 median. It
 * is the same as the planets give, and what is left is delta T (Swiss carries 69 seconds in
 * 2026 and the Espenak and Meeus polynomial 75) plus how far the Moon departs from the JPL.
 * Remeasured after moving the nutation from four terms to the full series and nothing moved
 * by a thousandth: the Moon and the star get the same rotation and it cancels out in the
 * relative geometry.
 */
class StarsOnTheHorizonTest extends TestCase
{
    /** Margin for a star's rises, sets and culminations, in seconds. */
    private const PASS_TOLERANCE = 0.5;

    /** Margin for the instants of an occultation, in seconds. */
    private const OCCULTATION_TOLERANCE = 30.0;

    private const PLACES = [
        'Madrid' => [40.4168, -3.7038, 'Europe/Madrid'],
        'Oslo' => [59.9139, 10.7522, 'Europe/Oslo'],
        'Ushuaia' => [-54.8019, -68.3030, 'America/Argentina/Ushuaia'],
        'Sydney' => [-33.8688, 151.2093, 'Australia/Sydney'],
        'Johannesburg' => [-26.2041, 28.0473, 'Africa/Johannesburg'],
    ];

    /**
     * [place, jd of 0h UT, star, rise, set, upper culmination, lower culmination] per
     * `swe_rise_trans`: the next event from that instant onward. Null where Swiss returns
     * -2, which is its way of saying circumpolar or invisible: Vega does not set in Oslo and
     * does not rise in Ushuaia.
     */
    private const SWISS_PASSES = [
        ['Madrid', 2460389.5, 'regulus', 2460390.156618517, 2460389.719344696, 2460390.4366163956, 2460389.937981603],
        ['Madrid', 2460389.5, 'sirius', 2460390.085283042, 2460389.508867979, 2460390.2957102815, 2460389.797075492],
        ['Madrid', 2460389.5, 'antares', 2460389.521027009, 2460389.8856958556, 2460389.7033614013, 2460390.2019961895],
        ['Madrid', 2460389.5, 'fomalhaut', 2460389.799844027, 2460390.144383511, 2460389.9721137397, 2460390.4707485326],
        ['Madrid', 2460389.5, 'vega', 2460390.415744068, 2460390.1639513187, 2460389.7912128824, 2460390.289847672],
        ['Madrid', 2460665.5, 'regulus', 2460666.4003193397, 2460665.9630277166, 2460665.6830387125, 2460666.181673509],
        ['Madrid', 2460665.5, 'sirius', 2460666.328982275, 2460665.7525808816, 2460665.542146843, 2460666.040781638],
        ['Madrid', 2460665.5, 'antares', 2460665.7647095527, 2460666.129373313, 2460665.9470413993, 2460666.445676198],
        ['Madrid', 2460665.5, 'fomalhaut', 2460666.0435434193, 2460666.388104043, 2460666.21582376, 2460665.717188963],
        ['Madrid', 2460665.5, 'vega', 2460665.6621075473, 2460666.4076573867, 2460666.034882424, 2460665.5362476287],
        ['Madrid', 2459114.5, 'regulus', 2459114.6486338777, 2459115.20873507, 2459114.9286844763, 2459115.4273192403],
        ['Madrid', 2459114.5, 'sirius', 2459114.5773689747, 2459114.998279493, 2459114.7878242466, 2459115.286459012],
        ['Madrid', 2459114.5, 'antares', 2459115.0103288502, 2459115.375059209, 2459115.192693999, 2459114.6940592322],
        ['Madrid', 2459114.5, 'fomalhaut', 2459115.2892928934, 2459114.6364449663, 2459115.4615036626, 2459114.962868899],
        ['Madrid', 2459114.5, 'vega', 2459114.907850187, 2459114.6561508975, 2459115.2806353187, 2459114.782000553],
        ['Oslo', 2460389.5, 'regulus', 2460390.0849505276, 2460389.7109205727, 2460390.396570405, 2460389.8979356135],
        ['Oslo', 2460389.5, 'sirius', 2460390.0892091603, 2460390.4221195397, 2460390.2556643155, 2460389.7570295264],
        ['Oslo', 2460389.5, 'antares', 2460389.571616888, 2460389.755014023, 2460389.663315499, 2460390.161950287],
        ['Oslo', 2460389.5, 'fomalhaut', 2460389.883431833, 2460389.9807039555, 2460389.932067909, 2460390.4307027007],
        ['Oslo', 2460389.5, 'vega', null, null, 2460389.7511670296, 2460390.2498018187],
        ['Oslo', 2460665.5, 'regulus', 2460666.3286616816, 2460665.9545935798, 2460665.6429927987, 2460666.141627595],
        ['Oslo', 2460665.5, 'sirius', 2460666.3328998103, 2460665.6685715523, 2460665.5021008686, 2460666.000735664],
        ['Oslo', 2460665.5, 'antares', 2460665.815305472, 2460665.9986855993, 2460665.906995568, 2460666.4056303664],
        ['Oslo', 2460665.5, 'fomalhaut', 2460666.1270782957, 2460666.224477281, 2460666.175777826, 2460665.6771430294],
        ['Oslo', 2460665.5, 'vega', null, null, 2460665.994836585, 2460666.493471384],
        ['Oslo', 2459114.5, 'regulus', 2459114.576905489, 2459115.2003718233, 2459114.8886386403, 2459115.3872734047],
        ['Oslo', 2459114.5, 'sirius', 2459114.581259807, 2459114.914297025, 2459114.7477783826, 2459115.2464131485],
        ['Oslo', 2459114.5, 'antares', 2459115.060844979, 2459115.244451286, 2459115.1526480806, 2459114.6540133134],
        ['Oslo', 2459114.5, 'fomalhaut', 2459115.373173318, 2459115.4697420844, 2459115.421457667, 2459114.922822903],
        ['Oslo', 2459114.5, 'vega', null, null, 2459115.24058935, 2459114.741954584],
        ['Ushuaia', 2460389.5, 'regulus', 2460390.411235096, 2460389.8226325517, 2460389.618299015, 2460390.1169338017],
        ['Ushuaia', 2460389.5, 'sirius', 2460390.152166447, 2460389.7998893266, 2460390.474662642, 2460389.976027849],
        ['Ushuaia', 2460389.5, 'antares', 2460389.5040789447, 2460390.2605480365, 2460389.8823134913, 2460390.380948283],
        ['Ushuaia', 2460389.5, 'fomalhaut', 2460389.748615027, 2460389.556247791, 2460390.1510661286, 2460389.652431341],
        ['Ushuaia', 2460389.5, 'vega', null, null, 2460389.9701650343, 2460390.4687998267],
        ['Ushuaia', 2460665.5, 'regulus', 2460665.657642444, 2460666.0663392553, 2460665.8619908113, 2460666.3606256093],
        ['Ushuaia', 2460665.5, 'sirius', 2460666.395884754, 2460666.043582679, 2460665.721098951, 2460666.2197337477],
        ['Ushuaia', 2460665.5, 'antares', 2460665.747753382, 2460665.506964445, 2460666.1259937603, 2460665.627358964],
        ['Ushuaia', 2460665.5, 'fomalhaut', 2460665.992353202, 2460665.7999295397, 2460666.3947762065, 2460665.8961414085],
        ['Ushuaia', 2460665.5, 'vega', null, null, 2460666.213834892, 2460665.715200095],
        ['Ushuaia', 2459114.5, 'regulus', 2459114.9033925543, 2459115.311880987, 2459115.107636798, 2459114.6090020305],
        ['Ushuaia', 2459114.5, 'sirius', 2459114.644330899, 2459115.2892220034, 2459114.9667764097, 2459115.465411173],
        ['Ushuaia', 2459114.5, 'antares', 2459114.9934809217, 2459114.752542576, 2459115.371646442, 2459114.8730116775],
        ['Ushuaia', 2459114.5, 'fomalhaut', 2459115.237850906, 2459115.0457912297, 2459114.6431862833, 2459115.141821051],
        ['Ushuaia', 2459114.5, 'vega', null, null, 2459115.4595877063, 2459114.9609529427],
    ];

    /**
     * Antares's thirteen occultations in 2026 per `swe_lun_occult_when_glob`, one by one:
     * [maximum, start and end somewhere on Earth]. All total and central.
     */
    private const SWISS_ANTARES_2026 = [
        [2461055.3342845663, 2461055.249407875, 2461055.4190301234],
        [2461082.6601486118, 2461082.5820454, 2461082.7381189773],
        [2461110.002263463, 2461109.9250641824, 2461110.0793347363],
        [2461137.3300371077, 2461137.2467273897, 2461137.4132345617],
        [2461164.622790361, 2461164.5337548484, 2461164.711735722],
        [2461191.8826134913, 2461191.7916728924, 2461191.973464987],
        [2461219.1321821935, 2461219.042928991, 2461219.2213253397],
        [2461246.3999062497, 2461246.315127243, 2461246.4845564463],
        [2461273.7031484414, 2461273.621648921, 2461273.7845155355],
        [2461301.037055865, 2461300.9529661783, 2461301.121030034],
        [2461328.376959841, 2461328.287175088, 2461328.4666685406],
        [2461355.692819762, 2461355.5995291965, 2461355.7860640646],
        [2461382.9689863133, 2461382.8749351273, 2461383.0629832568],
    ];

    /** Aldebaran's fourteen in 2017, the same. */
    private const SWISS_ALDEBARAN_2017 = [
        [2457763.101187278, 2457763.017710798, 2457763.1845711865],
        [2457790.399017439, 2457790.3121806057, 2457790.485785041],
        [2457817.624383718, 2457817.5370512092, 2457817.711661816],
        [2457844.88036907, 2457844.7958571645, 2457844.9648105553],
        [2457872.2323381244, 2457872.1526228935, 2457872.3119520615],
        [2457899.6642946163, 2457899.5875225165, 2457899.740949622],
        [2457927.1089419923, 2457927.0307837916, 2457927.1869822447],
        [2457954.4956868314, 2457954.4138418464, 2457954.577420401],
        [2457981.7897131913, 2457981.70539445, 2457981.8739306014],
        [2458009.018482663, 2458008.935247858, 2458009.1016133884],
        [2458036.2639085418, 2458036.1863755262, 2458036.341316955],
        [2458063.6043843473, 2458063.5345125953, 2458063.6741190744],
        [2458091.0490035964, 2458090.9821991134, 2458091.115679731],
        [2458118.525137753, 2458118.454928205, 2458118.595223086],
    ];

    /**
     * Four occultations seen from a place, per `swe_lun_occult_when_loc`: [star, place,
     * global maximum, local maximum, disappearance, reappearance, altitude of the star at
     * maximum, and whether the disappearance, the maximum and the reappearance are visible].
     *
     * For a star Swiss gives the first contact equal to the second and the third equal to
     * the fourth, because a point has no disc to cover gradually. The Johannesburg one
     * starts with the Moon below the horizon and only the reappearance is visible; the
     * second Sydney one the other way round. Antares was not occulted as seen from Madrid
     * in 2026 (they all fall in the southern hemisphere), so the Madrid one is Aldebaran's.
     */
    private const SWISS_LOCAL = [
        ['antares', 'Sydney', 2461055.3342845663, 2461055.2798814406, 2461055.262967441, 2461055.297577499, 37.065977587058725, true, true, true],
        ['antares', 'Johannesburg', 2461137.3300371077, 2461137.270950957, 2461137.2534800875, 2461137.289067926, -2.2536106408559227, false, false, true],
        ['antares', 'Sydney', 2461301.037055865, 2461301.1008282215, 2461301.083502952, 2461301.1176042897, -4.615378521094103, true, false, false],
        ['aldebaran', 'Madrid', 2457790.399017439, 2457790.437851816, 2457790.415225084, 2457790.459638105, 47.37259039680117, true, true, true],
    ];

    private function place(string $name): Place
    {
        [$latitude, $longitude, $timezone] = self::PLACES[$name];

        return new Place($name, null, '', '', $latitude, $longitude, $timezone);
    }

    private static function jd(string $date): float
    {
        return Time::julianDay(new DateTimeImmutable($date, new DateTimeZone('UTC')));
    }

    private function seconds(float $jdA, float $jdB): float
    {
        return abs($jdA - $jdB) * 86400.0;
    }

    private function star(string $key): Star
    {
        $star = Stars::find($key);
        $this->assertNotNull($star, "{$key} is not in the catalogue");

        return $star;
    }

    public function test_rises_sets_and_culminations_of_stars_match_swiss(): void
    {
        $worst = 0.0;

        foreach (self::SWISS_PASSES as [$name, $jd0, $key, $rise, $set, $upper, $lower]) {
            $place = $this->place($name);
            $star = $this->star($key);

            foreach ([
                [Pass::Rise, $rise],
                [Pass::Set, $set],
                [Pass::UpperCulmination, $upper],
                [Pass::LowerCulmination, $lower],
            ] as [$pass, $expected]) {
                $instant = RiseSet::next($star, $place, $jd0, $pass);

                if ($expected === null) {
                    $this->assertNull($instant, "{$pass->name} of {$key} at {$name} ({$jd0}): Swiss says there is none");

                    continue;
                }

                $this->assertNotNull($instant, "{$pass->name} of {$key} at {$name} ({$jd0})");

                $difference = $this->seconds($instant->jdUt, $expected);
                $worst = max($worst, $difference);

                $this->assertLessThan(self::PASS_TOLERANCE, $difference, sprintf(
                    '%s of %s at %s (%s): %.3f seconds of difference',
                    $pass->name, $key, $name, $jd0, ($instant->jdUt - $expected) * 86400
                ));

                $this->assertSame($place->timezone, $instant->date->getTimezone()->getName());
            }
        }

        // That the engine is not being checked against itself.
        $this->assertGreaterThan(0.005, $worst, 'the worst case is suspiciously good');
    }

    /**
     * Vega, at 39 degrees north, does not set in Oslo and does not rise in Ushuaia: rise
     * and set are both null in the two places, and the two culminations always exist. Which
     * is which is told apart by looking at the altitude, which does not touch the passes
     * code: in Oslo it is above the horizon even at the lower culmination, and in Ushuaia
     * below it even at the upper one.
     *
     * And the case in between, which is the one a tracking sweep with no bracketing loses:
     * Fomalhaut in Oslo culminates at half a degree of altitude and stays a little over two
     * hours above the horizon. It rises and sets the same day, and both figures are in the
     * Swiss table.
     */
    public function test_a_circumpolar_star_neither_rises_nor_sets_but_still_culminates(): void
    {
        $vega = $this->star('Vega');
        $day = new DateTimeImmutable('2024-03-20');

        $oslo = Stars::passes($vega, $this->place('Oslo'), $day);

        $this->assertSame('Vega', $oslo->name);
        $this->assertSame($vega, $oslo->target);
        $this->assertNull($oslo->rise);
        $this->assertNull($oslo->set);
        $this->assertNotNull($oslo->upperCulmination);
        $this->assertNotNull($oslo->lowerCulmination);
        $this->assertSame([], $oslo->twilights);
        $this->assertGreaterThan(0.0, (new Horizon($this->place('Oslo')))->at($vega, $oslo->lowerCulmination->jdUt)->altitude);

        $ushuaia = Stars::passes($vega, $this->place('Ushuaia'), $day);

        $this->assertNull($ushuaia->rise);
        $this->assertNull($ushuaia->set);
        $this->assertNotNull($ushuaia->upperCulmination);
        $this->assertLessThan(0.0, (new Horizon($this->place('Ushuaia')))->at($vega, $ushuaia->upperCulmination->jdUt)->altitude);

        $fomalhaut = Stars::passes($this->star('Fomalhaut'), $this->place('Oslo'), $day);

        $this->assertNotNull($fomalhaut->rise);
        $this->assertNotNull($fomalhaut->set);
        $hours = $fomalhaut->rise->secondsTo($fomalhaut->set) / 3600;
        $this->assertGreaterThan(2.0, $hours);
        $this->assertLessThan(2.5, $hours);
        $this->assertLessThan(1.0, (new Horizon($this->place('Oslo')))->at($this->star('Fomalhaut'), $fomalhaut->upperCulmination->jdUt)->altitude);

        // And a planet still comes in as before, with its name. It is the engine's, which
        // means English: nobody in the application reads this property, the sheet asks the
        // body for its name.
        $sun = RiseSet::ofTheDay(Body::Sun, $this->place('Madrid'), $day);
        $this->assertSame('Sun', $sun->name);
        $this->assertSame(Body::Sun, $sun->target);
    }

    /**
     * The count check, as with the eclipses: the Moon occulted Antares thirteen times in
     * 2026 and Aldebaran fourteen in 2017, and they have to come out one by one on the same
     * date, all total and central, and with their name.
     */
    public function test_the_occultations_of_antares_and_aldebaran_come_out_one_by_one_as_in_swiss(): void
    {
        foreach ([
            ['Antares', 'Antares', 2026, self::SWISS_ANTARES_2026],
            ['Aldebaran', 'Aldebaran', 2017, self::SWISS_ALDEBARAN_2017],
        ] as [$key, $name, $year, $swiss]) {
            $star = $this->star($key);
            $occultations = Stars::occultations($star, self::jd("{$year}-01-01"), self::jd(($year + 1).'-01-01'));

            $this->assertCount(count($swiss), $occultations, "occultations of {$name} in {$year}");

            foreach ($swiss as $i => [$maximum, $start, $end]) {
                $occultation = $occultations[$i];
                $label = sprintf('%s, occultation %d of %d', $name, $i + 1, $year);

                // Against the name the star itself gives, not one typed by hand: the
                // engine's is the catalogue name, so this holds whatever language it is
                // read in.
                $this->assertSame($star->name, $occultation->name, $label);
                $this->assertSame($star, $occultation->target, $label);
                $this->assertSame($star, $occultation->star(), $label);
                $this->assertSame(EclipseType::Total, $occultation->type, $label);
                $this->assertTrue($occultation->central, $label);
                $this->assertLessThan(1.0, abs($occultation->gamma), $label);

                $this->assertLessThan(self::OCCULTATION_TOLERANCE, $this->seconds($occultation->maximum->jdUt, $maximum), sprintf(
                    '%s: maximum at %.1f seconds from Swiss', $label, ($occultation->maximum->jdUt - $maximum) * 86400
                ));
                $this->assertLessThan(self::OCCULTATION_TOLERANCE, $this->seconds($occultation->start->jdUt, $start), "{$label}: start");
                $this->assertLessThan(self::OCCULTATION_TOLERANCE, $this->seconds($occultation->end->jdUt, $end), "{$label}: end");

                // A star gets covered as seen from somewhere if its minimum geocentric
                // separation does not exceed the Moon's parallax plus its semidiameter.
                $this->assertLessThan(1.3, $occultation->minimumSeparation, $label);
            }

            // In order and a month apart each.
            for ($i = 1; $i < count($occultations); $i++) {
                $this->assertGreaterThan($occultations[$i - 1]->maximum->jdUt + 20.0, $occultations[$i]->maximum->jdUt);
            }
        }

        // And a planet does not change name or object along the way.
        $saturn = Occultations::next(Body::Saturn, self::jd('2024-08-01'));
        $this->assertSame('Saturn', $saturn->name);
        $this->assertSame(Body::Saturn, $saturn->target);
        $this->assertNull($saturn->star());
    }

    /**
     * Disappearance and reappearance seen from a place. For a point the four contacts are
     * two, and they are the umbral limb's, as in Swiss: a star has no partial phase and no
     * disc to cover gradually.
     */
    public function test_a_star_occultation_seen_from_a_place_matches_swiss(): void
    {
        foreach (self::SWISS_LOCAL as [$key, $name, $globalMaximum, $maximum, $disappearance, $reappearance, $altitude, $seesDisappearance, $seesMaximum, $seesReappearance]) {
            $star = $this->star($key);
            $occultation = Occultations::next($star, $globalMaximum - 5.0);

            $this->assertNotNull($occultation);
            $this->assertLessThan(self::OCCULTATION_TOLERANCE, $this->seconds($occultation->maximum->jdUt, $globalMaximum));

            $local = Occultations::local($occultation, $this->place($name));
            $label = "{$key} from {$name}";

            $this->assertNotNull($local, $label);
            $this->assertSame(EclipseType::Total, $local->type, $label);
            $this->assertTrue($local->isVisible(), $label);

            foreach (['maximum' => $maximum, 'contact1' => $disappearance, 'contact2' => $disappearance, 'contact3' => $reappearance, 'contact4' => $reappearance] as $contact => $expected) {
                $this->assertLessThan(self::OCCULTATION_TOLERANCE, $this->seconds($local->$contact->jdUt, $expected), sprintf(
                    '%s of %s: %.1f seconds of difference', $contact, $label, ($local->$contact->jdUt - $expected) * 86400
                ));
            }

            // It gets covered all at once: the first contact IS the second, and the third
            // the fourth.
            $this->assertSame($local->contact1->jdUt, $local->contact2->jdUt, $label);
            $this->assertSame($local->contact3->jdUt, $local->contact4->jdUt, $label);
            $this->assertSame(1.0, $local->magnitude, $label);
            $this->assertSame(0.0, $local->diameterRatio, $label);

            $this->assertEqualsWithDelta($altitude, $local->altitude, 0.05, $label);
            $this->assertSame($seesDisappearance, $local->visible['contact1'], "{$label}: whether the disappearance is seen");
            $this->assertSame($seesMaximum, $local->visible['maximum'], "{$label}: whether the maximum is seen");
            $this->assertSame($seesReappearance, $local->visible['contact4'], "{$label}: whether the reappearance is seen");

            // The instants come in the place's timezone.
            $this->assertSame($this->place($name)->timezone, $local->maximum->date->getTimezone()->getName());
        }
    }

    /**
     * Vega is 61 degrees from the ecliptic and the Moon never departs from it by more than
     * five: no occultation is possible, and searching for one over ten years cannot cost
     * more than a single position. Without the latitude cutoff that is a hundred and thirty
     * conjunctions worked out just to return an empty list, which take seconds.
     */
    public function test_a_star_far_from_the_ecliptic_never_occults_and_costs_nothing(): void
    {
        $vega = $this->star('Vega');

        $this->assertGreaterThan(7.0, abs(Stars::position($vega, Time::J2000)->latitude));

        $start = microtime(true);
        $occultations = Stars::occultations($vega, self::jd('2020-01-01'), self::jd('2030-01-01'));
        $next = Occultations::next($vega, self::jd('2020-01-01'));
        $duration = microtime(true) - $start;

        $this->assertSame([], $occultations);
        $this->assertNull($next);
        $this->assertLessThan(0.5, $duration, sprintf('looking for Vega\'s occultations took %.2f seconds', $duration));

        // And one that is within reach is not discarded: Antares is less than five away.
        $this->assertLessThan(7.0, abs(Stars::position($this->star('Antares'), Time::J2000)->latitude));
        $this->assertNotNull(Occultations::next($this->star('Antares'), self::jd('2026-01-01')));
    }
}
