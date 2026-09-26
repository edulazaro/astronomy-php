<?php

namespace Astronomy\Tests;

use Astronomy\Twilight;
use Astronomy\Body;
use Astronomy\Equatorial;
use Astronomy\Horizon;
use Astronomy\Limb;
use Astronomy\Place;
use Astronomy\RiseSet;
use Astronomy\Pass;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Rises, sets and meridian passages, against `swe_rise_trans`.
 *
 * The figures are from Swiss Ephemeris 2.10.03 with Moshier, 1010 mbar atmosphere and 10
 * degrees, and are the next event from zero hours UT of the day given: exactly what
 * `swe_rise_trans(jd0, ...)` returns. Copied in by hand so the test runs with no Swiss.
 *
 * The target was ten seconds and the Moon was the hard one because of parallax. Measured,
 * everything comes out under a second: the planets to a tenth and the Moon to eight
 * tenths, which is what six seconds of difference in delta T is worth.
 */
class RiseTransitSetTest extends TestCase
{
    /** Margin for rises and sets, in seconds. */
    private const TOLERANCE = 10.0;

    /** Margin for culminations, in seconds. */
    private const TOLERANCE_MERIDIAN = 5.0;

    private const PLACES = [
        'Madrid' => [40.4168, -3.7038, 'Europe/Madrid'],
        'Oslo' => [59.9139, 10.7522, 'Europe/Oslo'],
        'Ushuaia' => [-54.8019, -68.3030, 'America/Argentina/Ushuaia'],
        'Singapur' => [1.3521, 103.8198, 'Asia/Singapore'],
    ];

    /** [place, jd at 0h UT, body, rise, set, upper culmination, lower] according to Swiss. */
    private const SWISS = [
        ['Madrid', 2460389.5, 'sun', 2460389.762225461, 2460390.2689694036, 2460390.015360957, 2460389.5154636228],
        ['Madrid', 2460389.5, 'moon', 2460390.0763985883, 2460389.682585082, 2460390.3936367934, 2460389.8773966385],
        ['Madrid', 2460389.5, 'venus', 2460389.7351658214, 2460390.197600653, 2460389.9661302986, 2460390.4663791927],
        ['Madrid', 2460389.5, 'jupiter', 2460389.8433300895, 2460390.422566116, 2460390.1329010995, 2460389.6339912],
        ['Madrid', 2460665.5, 'sun', 2460665.815629073, 2460666.202581207, 2460666.009104812, 2460665.5089319325],
        ['Madrid', 2460665.5, 'moon', 2460666.472331011, 2460665.9881552984, 2460665.7128030946, 2460666.226907945],
        ['Madrid', 2460665.5, 'venus', 2460665.9377329936, 2460666.354118266, 2460666.1457137074, 2460665.6455480414],
        ['Madrid', 2460665.5, 'jupiter', 2460666.1535793375, 2460665.770871241, 2460666.460666903, 2460665.9622184383],
        ['Madrid', 2459114.5, 'sun', 2459114.7518309704, 2459115.257925847, 2459115.0051114806, 2459114.5052331337],
        ['Madrid', 2459114.5, 'moon', 2459114.9975408656, 2459115.404224319, 2459115.2026908314, 2459114.6829380537],
        ['Madrid', 2459114.5, 'venus', 2459114.6055636127, 2459115.184412311, 2459114.895203037, 2459115.3954248223],
        ['Madrid', 2459114.5, 'jupiter', 2459115.113517064, 2459114.504030464, 2459115.3074569013, 2459114.8087759833],
        ['Oslo', 2460389.5, 'sun', 2460389.7204405223, 2460390.230953461, 2460389.975213627, 2460390.4751099963],
        ['Oslo', 2460389.5, 'moon', 2460389.959495694, 2460389.719616916, 2460390.35219898, 2460389.835911051],
        ['Oslo', 2460389.5, 'venus', 2460389.7161218356, 2460390.1367444196, 2460389.9259545645, 2460390.4262037617],
        ['Oslo', 2460389.5, 'jupiter', 2460389.7603327464, 2460390.4255780997, 2460390.0928330203, 2460389.5939233312],
        ['Oslo', 2460665.5, 'sun', 2460665.8459807397, 2460666.091891817, 2460665.9689353295, 2460666.469107663],
        ['Oslo', 2460665.5, 'moon', 2460666.417456293, 2460665.96665926, 2460665.671497398, 2460666.18563306],
        ['Oslo', 2460665.5, 'venus', 2460665.945658422, 2460666.26618985, 2460666.105545031, 2460665.6053790227],
        ['Oslo', 2460665.5, 'jupiter', 2460666.0448627076, 2460665.799536287, 2460666.420635877, 2460665.922187635],
        ['Oslo', 2459114.5, 'sun', 2459114.7097273017, 2459115.219252934, 2459114.964965651, 2459115.4648436373],
        ['Oslo', 2459114.5, 'moon', 2459115.0201453003, 2459115.295264865, 2459115.1609422, 2459114.641205396],
        ['Oslo', 2459114.5, 'venus', 2459114.523043198, 2459115.1859094575, 2459114.8550295522, 2459115.3552515074],
        ['Oslo', 2459114.5, 'jupiter', 2459115.1412518146, 2459115.3935696175, 2459115.267407159, 2459114.768726569],
        ['Ushuaia', 2460389.5, 'sun', 2460389.9412578945, 2460390.4474863107, 2460390.1947660614, 2460389.694868954],
        ['Ushuaia', 2460389.5, 'moon', 2460390.4175279634, 2460389.691837181, 2460389.5461067157, 2460390.062736456],
        ['Ushuaia', 2460389.5, 'venus', 2460389.8572334335, 2460390.433011148, 2460390.1456621834, 2460389.6454112297],
        ['Ushuaia', 2460389.5, 'jupiter', 2460390.1236176593, 2460389.5026409524, 2460390.311952149, 2460389.813041998],
        ['Ushuaia', 2460665.5, 'sun', 2460665.8274475858, 2460665.5494226827, 2460666.1886089193, 2460665.688436081],
        ['Ushuaia', 2460665.5, 'moon', 2460665.679091339, 2460666.1261035623, 2460665.8973514466, 2460666.4113301965],
        ['Ushuaia', 2460665.5, 'venus', 2460665.996182141, 2460665.6545594046, 2460666.3252137424, 2460665.8250503084],
        ['Ushuaia', 2460665.5, 'jupiter', 2460666.482891079, 2460665.799274133, 2460665.6426565396, 2460666.1411036206],
        ['Ushuaia', 2459114.5, 'sun', 2459114.93073793, 2459115.4390612296, 2459115.1845099316, 2459114.6846315754],
        ['Ushuaia', 2459114.5, 'moon', 2459115.036113439, 2459114.696454277, 2459115.3892609864, 2459114.8694468676],
        ['Ushuaia', 2459114.5, 'venus', 2459114.884318739, 2459115.2656201324, 2459115.0747250007, 2459114.5745022353],
        ['Ushuaia', 2459114.5, 'jupiter', 2459115.1324833264, 2459114.843011972, 2459115.4864261043, 2459114.9877444394],
        ['Singapur', 2460389.5, 'sun', 2460390.464237026, 2460389.969035139, 2460389.7167459484, 2460390.2166422587],
        ['Singapur', 2460389.5, 'moon', 2460389.825694211, 2460390.3446768187, 2460390.0853331974, 2460389.568727727],
        ['Singapur', 2460389.5, 'venus', 2460390.4166320534, 2460389.9184614285, 2460389.6673037517, 2460390.1675538193],
        ['Singapur', 2460389.5, 'jupiter', 2460389.582717777, 2460390.0870359493, 2460389.8348757857, 2460390.333785171],
        ['Singapur', 2460665.5, 'sun', 2460666.4596595713, 2460665.9613352073, 2460665.7103251647, 2460666.210497158],
        ['Singapur', 2460665.5, 'moon', 2460666.176693274, 2460665.663100884, 2460666.433817786, 2460665.919849914],
        ['Singapur', 2460665.5, 'venus', 2460665.5964005245, 2460666.097487556, 2460665.846939094, 2460666.3471014462],
        ['Singapur', 2460665.5, 'jupiter', 2460665.910455037, 2460666.415378629, 2460666.162917228, 2460665.664469265],
        ['Singapur', 2459114.5, 'sun', 2459115.4540081196, 2459114.9587756186, 2459114.706507735, 2459115.206385323],
        ['Singapur', 2459114.5, 'moon', 2459114.6341755306, 2459115.15013251, 2459114.8921938012, 2459115.4120060327],
        ['Singapur', 2459114.5, 'venus', 2459115.344064433, 2459114.849177796, 2459114.5963934967, 2459115.0966154323],
        ['Singapur', 2459114.5, 'jupiter', 2459114.760055322, 2459115.2590812435, 2459115.009568216, 2459114.5108885807],
    ];

    /**
     * The Sun with the other options of `swe_rise_trans`: [place, jd0, rise of the
     * centre with no refraction, rise of the centre with refraction, civil dawn,
     * nautical, astronomical, civil dusk]. Null where Swiss does not find the event
     * that day.
     */
    private const SWISS_SUN = [
        ['Madrid', 2460389.5, 2460389.765294092, 2460389.763200991, 2460389.743397793, 2460389.7213189607, 2460389.6988459434, 2460390.2878375864],
        ['Madrid', 2460665.5, 2460665.8192314142, 2460665.8167804186, 2460665.794269006, 2460665.770427784, 2460665.7473628526, 2460666.223941248],
        ['Oslo', 2460389.5, 2460389.725096965, 2460389.721920981, 2460389.6917331223, 2460389.657170534, 2460389.619614522, 2460390.2597951107],
        ['Oslo', 2460665.5, 2460665.853483833, 2460665.8483449686, 2460665.8061236544, 2460665.7667500977, 2460665.731043419, 2460666.131747803],
        ['Ushuaia', 2460389.5, 2460389.9453211217, 2460389.9425495947, 2460389.9162666807, 2460389.8865389037, 2460389.8552111792, 2460390.4723867592],
        ['Ushuaia', 2460665.5, 2460665.8331578565, 2460665.8292960697, 2460665.7876080675, null, null, 2460665.5892616115],
        ['Singapur', 2460389.5, 2460390.466575904, 2460390.464980441, 2460390.44990802, 2460390.4332397003, 2460390.4165709037, 2460389.9833645034],
        ['Singapur', 2460665.5, 2460666.4622203438, 2460666.460480444, 2460666.444047055, 2460666.4258569893, 2460666.407609862, 2460665.9769478645],
    ];

    private function place(string $name): Place
    {
        [$latitude, $longitude, $timezone] = self::PLACES[$name];

        return new Place($name, null, '', '', $latitude, $longitude, $timezone);
    }

    private function seconds(?float $jdA, ?float $jdB): float
    {
        return abs(($jdA - $jdB) * 86400.0);
    }

    public function test_rises_sets_and_culminations_agree_with_swiss(): void
    {
        foreach (self::SWISS as [$name, $jd0, $body, $rise, $set, $upper, $lower]) {
            $place = $this->place($name);
            $object = Body::from($body);

            foreach ([
                [Pass::Rise, $rise, self::TOLERANCE],
                [Pass::Set, $set, self::TOLERANCE],
                [Pass::UpperCulmination, $upper, self::TOLERANCE_MERIDIAN],
                [Pass::LowerCulmination, $lower, self::TOLERANCE_MERIDIAN],
            ] as [$pass, $expected, $tolerance]) {
                $instant = RiseSet::next($object, $place, $jd0, $pass);

                $this->assertNotNull($instant, "{$pass->name} of {$body} at {$name} ({$jd0})");
                $this->assertLessThan(
                    $tolerance,
                    $this->seconds($instant->jdUt, $expected),
                    sprintf('%s of %s at %s (%s): %.1f seconds of difference', $pass->name, $body, $name, $jd0, ($instant->jdUt - $expected) * 86400)
                );

                // And the date comes in the timezone of the place.
                $this->assertSame($place->timezone, $instant->date->getTimezone()->getName());
            }
        }
    }

    public function test_the_centre_of_the_disc_with_no_refraction_and_the_twilights(): void
    {
        foreach (self::SWISS_SUN as [$name, $jd0, $centreNoRefraction, $centre, $civil, $nautical, $astronomical, $civilDusk]) {
            $place = $this->place($name);

            $cases = [
                'centre with no refraction' => [$centreNoRefraction, RiseSet::next(Body::Sun, $place, $jd0, Pass::Rise, Limb::Center, false)],
                'centre with refraction' => [$centre, RiseSet::next(Body::Sun, $place, $jd0, Pass::Rise, Limb::Center, true)],
                'civil dawn' => [$civil, RiseSet::next(Body::Sun, $place, $jd0, Pass::Rise, twilight: Twilight::Civil)],
                'nautical dawn' => [$nautical, RiseSet::next(Body::Sun, $place, $jd0, Pass::Rise, twilight: Twilight::Nautical)],
                'astronomical dawn' => [$astronomical, RiseSet::next(Body::Sun, $place, $jd0, Pass::Rise, twilight: Twilight::Astronomical)],
                'civil dusk' => [$civilDusk, RiseSet::next(Body::Sun, $place, $jd0, Pass::Set, twilight: Twilight::Civil)],
            ];

            foreach ($cases as $case => [$expected, $instant]) {
                if ($expected === null) {
                    // In Ushuaia in December there is no nautical night: the Sun does
                    // not go below twelve degrees. Swiss does not find it that day and
                    // here it should not turn up before the next day either.
                    $this->assertTrue($instant === null || $instant->jdUt > $jd0 + 1, "{$case} at {$name}: there should not be one");

                    continue;
                }

                $this->assertNotNull($instant, "{$case} at {$name} ({$jd0})");
                $this->assertLessThan(self::TOLERANCE, $this->seconds($instant->jdUt, $expected), "{$case} at {$name} ({$jd0})");
            }
        }
    }

    /**
     * Polar day and night: in Longyearbyen the Sun does not set in June or rise in
     * December, and returning an hour would mean making it up. The culmination does
     * always exist though.
     */
    /**
     * What `swe_rise_trans_true_hor` returns with the horizon at different altitudes,
     * on 1 January 2000 and with the standard atmosphere of the rest of the file. Each
     * row: place, body, altitude of the horizon in degrees, rise and set.
     *
     * There is the Moon at Oslo at one degree, which is the odd case and that is why
     * it is in: with the horizon half a degree lower it sets right at the start of
     * the day and with a whole degree that set has already gone by, so the next one
     * falls **the next day**. A jump of a day in the answer is not a fault, it is the
     * question («the next set from this instant on») answered correctly.
     *
     * @return list<array{0: string, 1: string, 2: float, 3: float, 4: float}>
     */
    public static function swissHorizons(): array
    {
        return [
            ['Madrid', 'sun', 0.5, 2451545.820547344, 2451545.204776526],
            ['Madrid', 'sun', 1.0, 2451545.822982710, 2451545.202337981],
            ['Madrid', 'sun', 3.0, 2451545.832326970, 2451545.192981323],
            ['Madrid', 'sun', 10.0, 2451545.865215701, 2451545.160035213],
            ['Madrid', 'moon', 0.5, 2451545.653145235, 2451545.071834167],
            ['Madrid', 'moon', 1.0, 2451545.655435166, 2451545.069619417],
            ['Madrid', 'moon', 3.0, 2451545.664156041, 2451545.061200222],
            ['Madrid', 'moon', 10.0, 2451545.693894308, 2451545.032714923],
            ['Madrid', 'venus', 0.5, 2451545.694547257, 2451545.101675908],
            ['Madrid', 'venus', 1.0, 2451545.696858964, 2451545.099371672],
            ['Madrid', 'venus', 3.0, 2451545.705695074, 2451545.090565920],
            ['Madrid', 'venus', 10.0, 2451545.736278933, 2451545.060114145],
            ['Oslo', 'sun', 0.5, 2451545.851076048, 2451545.093549172],
            ['Oslo', 'sun', 1.0, 2451545.856141808, 2451545.088455757],
            ['Oslo', 'sun', 3.0, 2451545.877230885, 2451545.067219178],
            ['Oslo', 'moon', 0.5, 2451545.648776930, 2451545.001002233],
            ['Oslo', 'moon', 1.0, 2451545.652592104, 2451546.008055927],
            ['Oslo', 'moon', 3.0, 2451545.667451621, 2451545.993352335],
            ['Oslo', 'moon', 10.0, 2451545.725053073, 2451545.936177971],
            ['Oslo', 'venus', 0.5, 2451545.707004946, 2451545.009632190],
            ['Oslo', 'venus', 1.0, 2451545.711178066, 2451545.005501986],
            ['Oslo', 'venus', 3.0, 2451545.727787576, 2451545.987844866],
            ['Oslo', 'venus', 10.0, 2451545.805198432, 2451545.910459395],
            ['Ushuaia', 'sun', 0.5, 2451545.838106236, 2451545.546517453],
            ['Ushuaia', 'sun', 1.0, 2451545.841855766, 2451545.542768400],
            ['Ushuaia', 'sun', 3.0, 2451545.855490289, 2451545.529135626],
            ['Ushuaia', 'sun', 10.0, 2451545.896316451, 2451545.488316298],
            ['Ushuaia', 'moon', 0.5, 2451545.748986007, 2451545.334170422],
            ['Ushuaia', 'moon', 1.0, 2451545.752020440, 2451545.331097524],
            ['Ushuaia', 'moon', 3.0, 2451545.763333719, 2451545.319629908],
            ['Ushuaia', 'moon', 10.0, 2451545.799408834, 2451545.282927752],
            ['Ushuaia', 'venus', 0.5, 2451545.748317323, 2451545.406163296],
            ['Ushuaia', 'venus', 1.0, 2451545.751613857, 2451545.402864854],
            ['Ushuaia', 'venus', 3.0, 2451545.763774317, 2451545.390696765],
            ['Ushuaia', 'venus', 10.0, 2451545.801468771, 2451545.352971793],
            ['Singapur', 'sun', 0.5, 2451545.464893898, 2451545.963364365],
            ['Singapur', 'sun', 1.0, 2451545.466623863, 2451545.961635035],
            ['Singapur', 'sun', 3.0, 2451545.473165115, 2451545.955095531],
            ['Singapur', 'sun', 10.0, 2451545.494808436, 2451545.933457932],
            ['Singapur', 'moon', 0.5, 2451545.312454304, 2451545.822028877],
            ['Singapur', 'moon', 1.0, 2451545.314132134, 2451545.820339761],
            ['Singapur', 'moon', 3.0, 2451545.320473751, 2451545.813956819],
            ['Singapur', 'moon', 10.0, 2451545.341412148, 2451545.792894285],
            ['Singapur', 'venus', 0.5, 2451545.350506704, 2451545.848209780],
            ['Singapur', 'venus', 1.0, 2451545.352187078, 2451545.846528134],
            ['Singapur', 'venus', 3.0, 2451545.358540774, 2451545.840169988],
            ['Singapur', 'venus', 10.0, 2451545.379552604, 2451545.819144648],
        ];
    }

    /**
     * The rise and set over a horizon that is not at zero, which is
     * `swe_rise_trans_true_hor`: the Sun does not rise at the same time on a plain as
     * behind a mountain.
     *
     * **The refraction is evaluated at the altitude of the horizon and not at zero**,
     * which is the one thing that has any bite to it: at ten degrees the refraction
     * is worth 0.09 and at the horizon 0.57, so using the horizon's own value up there
     * would leave half a degree of altitude out. Measured over these 94 cases, with
     * that account the worst case against Swiss is **0.34 seconds of clock time** and
     * with the horizon's own it would be minutes.
     *
     * The worst case is in Oslo and that also makes sense: at sixty degrees of
     * latitude the Sun rises on a diagonal and very slowly, so the same difference in
     * altitude turns into more time. In Madrid and in Singapore it does not go past
     * 0.16.
     */
    public function test_the_rise_over_a_high_horizon_agrees_with_swiss(): void
    {
        foreach (self::swissHorizons() as [$name, $body, $altitude, $rise, $set]) {
            foreach ([[Pass::Rise, $rise], [Pass::Set, $set]] as [$pass, $theirs]) {
                $ours = RiseSet::next(
                    Body::from($body),
                    $this->place($name),
                    2451545.0,
                    $pass,
                    horizonAltitude: $altitude
                );

                $where = sprintf('%s, %s at %.1f degrees, %s', $name, $body, $altitude, $pass->name);

                $this->assertNotNull($ours, $where);
                $this->assertLessThan(0.5, $this->seconds($ours->jdUt, $theirs), $where);
            }
        }
    }

    /**
     * With the horizon at zero it comes out exactly as it always did, **bit for
     * bit**. That is what allows adding the parameter without touching anything that
     * was already verified: the refraction at zero altitude IS the refraction at the
     * horizon.
     */
    public function test_the_horizon_at_zero_changes_nothing(): void
    {
        foreach (array_keys(self::PLACES) as $name) {
            foreach ([Body::Sun, Body::Moon, Body::Venus] as $body) {
                foreach ([Pass::Rise, Pass::Set] as $pass) {
                    $unsaid = RiseSet::next($body, $this->place($name), 2451545.0, $pass);
                    $withZero = RiseSet::next($body, $this->place($name), 2451545.0, $pass, horizonAltitude: 0.0);

                    $this->assertSame($unsaid?->jdUt, $withZero?->jdUt, $name.' '.$body->value);
                }
            }
        }
    }

    /**
     * A higher horizon delays the rise and brings the set forward, and it does so
     * monotonically. This is the check that needs no Swiss: if the sign were flipped,
     * the table above would still add up in absolute value for a single case, but
     * this would not.
     */
    public function test_a_high_horizon_delays_the_rise_and_brings_the_set_forward(): void
    {
        $place = $this->place('Madrid');
        $rises = [];
        $sets = [];

        foreach ([0.0, 0.5, 1.0, 3.0, 10.0] as $altitude) {
            $rises[] = RiseSet::next(Body::Sun, $place, 2451545.0, Pass::Rise, horizonAltitude: $altitude)->jdUt;
            $sets[] = RiseSet::next(Body::Sun, $place, 2451545.0, Pass::Set, horizonAltitude: $altitude)->jdUt;
        }

        for ($i = 1; $i < count($rises); $i++) {
            $this->assertGreaterThan($rises[$i - 1], $rises[$i], 'the rise is delayed');
            $this->assertLessThan($sets[$i - 1], $sets[$i], 'the set is brought forward');
        }

        // Ten degrees of mountain are almost seventy minutes less sun in Madrid in January.
        $this->assertEqualsWithDelta(
            67.9,
            ($rises[4] - $rises[0]) * 1440.0,
            2.0,
            'what a ten degree horizon delays the rise by'
        );
    }

    /**
     * Twilights do NOT move with the altitude of the horizon, and that is on purpose:
     * a twilight is defined by how far the Sun has sunk **below the astronomical
     * horizon**, which is a property of the sky. The mountain blocks the disc, not
     * the light that still paints the air above.
     */
    public function test_the_twilights_do_not_move_with_the_horizon(): void
    {
        $place = $this->place('Madrid');
        $day = new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('UTC'));

        $plain = RiseSet::ofTheDay(Body::Sun, $place, $day);
        $mountain = RiseSet::ofTheDay(Body::Sun, $place, $day, horizonAltitude: 10.0);

        foreach (Twilight::cases() as $twilight) {
            $key = strtolower($twilight->name);

            $this->assertSame(
                $plain->twilights[$key]['sunrise']?->jdUt,
                $mountain->twilights[$key]['sunrise']?->jdUt,
                $key
            );
        }

        // And the rise HAS moved, because otherwise this would prove nothing.
        $this->assertNotSame($plain->rise?->jdUt, $mountain->rise?->jdUt);
    }

    /**
     * A horizon so sunken that Bennett's refraction no longer holds is rejected
     * instead of returning an hour that looks fine. The threshold is measured: below
     * 1.8 degrees under the horizon, Bennett stops growing as it drops and starts
     * returning refractions that shrink down to nothing near its own pole.
     *
     * With no refraction there is nothing to bound, and there it is allowed.
     */
    public function test_a_horizon_that_cannot_be_refracted_is_rejected(): void
    {
        $place = $this->place('Madrid');

        // Up to 1.8 degrees of depression, which is that of an observer at 3,100 metres.
        $this->assertNotNull(
            RiseSet::next(Body::Sun, $place, 2451545.0, Pass::Rise, horizonAltitude: -1.8)
        );

        foreach ([-2.0, -10.0, 90.0, -90.0] as $impossible) {
            try {
                RiseSet::next(Body::Sun, $place, 2451545.0, Pass::Rise, horizonAltitude: $impossible);
                $this->fail('a horizon at '.$impossible.' degrees should throw');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }

        // With no refraction there is no formula to break and any depression is allowed.
        $this->assertNotNull(RiseSet::next(
            Body::Sun,
            $place,
            2451545.0,
            Pass::Rise,
            refraction: false,
            horizonAltitude: -10.0
        ));
    }

    /**
     * And a body that never manages to peek above the horizon put in front of it does
     * not rise, which is the same thing that already happens on the polar day and is
     * solved the same way: with no crossings there is no event, and null is returned
     * instead of a made up hour.
     */
    public function test_a_body_that_does_not_clear_the_horizon_does_not_rise(): void
    {
        // The Sun in Oslo on 1 January barely climbs six degrees above the horizon.
        $thisOne = RiseSet::next(Body::Sun, $this->place('Oslo'), 2451545.0, Pass::Rise, horizonAltitude: 3.0);
        $thatOne = RiseSet::next(Body::Sun, $this->place('Oslo'), 2451545.0, Pass::Rise, horizonAltitude: 30.0);

        $this->assertNotNull($thisOne);
        $this->assertNull($thatOne);
    }

    public function test_on_the_polar_day_there_is_no_rise_or_set(): void
    {
        $longyearbyen = new Place('Longyearbyen', null, 'Norway', 'NO', 78.2167, 15.6333, 'Arctic/Longyearbyen');

        foreach (['2024-06-21', '2024-12-21'] as $date) {
            $day = RiseSet::ofTheDay(Body::Sun, $longyearbyen, new DateTimeImmutable($date));

            $this->assertNull($day->rise, "Rise on {$date}");
            $this->assertNull($day->set, "Set on {$date}");
            $this->assertNotNull($day->upperCulmination);
            $this->assertNotNull($day->lowerCulmination);
        }

        // Swiss gives the upper culmination of 21 June at 0.4579 of the day.
        $june = RiseSet::ofTheDay(Body::Sun, $longyearbyen, new DateTimeImmutable('2024-06-21'));
        $this->assertLessThan(self::TOLERANCE_MERIDIAN, $this->seconds($june->upperCulmination->jdUt, 2460482.9579023924));

        // And in summer the Sun is above the horizon at midnight too.
        $horizon = new Horizon($longyearbyen);
        $this->assertGreaterThan(0.0, $horizon->at(Body::Sun, $june->lowerCulmination->jdUt)->altitude);
    }

    /**
     * The civil day is the one of the place: from local midnight to local midnight,
     * with the timezone of the site and its clock change. In Madrid on 21 June 2024
     * the Sun rose at 6:44 and set at 21:48, official summer time.
     */
    public function test_the_civil_day_is_the_one_of_the_place(): void
    {
        $madrid = $this->place('Madrid');
        $day = RiseSet::ofTheDay(Body::Sun, $madrid, new DateTimeImmutable('2024-06-21', new DateTimeZone('Europe/Madrid')));

        $this->assertSame('Europe/Madrid', $day->rise->date->getTimezone()->getName());
        $this->assertSame('2024-06-21 06:44', $day->rise->date->format('Y-m-d H:i'));
        $this->assertSame('2024-06-21 21:48', $day->set->date->format('Y-m-d H:i'));

        // Swiss: rise at 4.7475 h UT and set at 19.8103 h UT.
        $this->assertLessThan(self::TOLERANCE, $this->seconds($day->rise->jdUt, 2460482.5 + 4.747480183839798 / 24));
        $this->assertLessThan(self::TOLERANCE, $this->seconds($day->set->jdUt, 2460482.5 + 19.810292188078165 / 24));

        // The three twilights, in order: the astronomical one dawns before the
        // nautical one and that one before the civil one, and at dusk the other way
        // round. At the solstice Madrid's astronomical night starts at 23:53, six
        // minutes before the civil day ends: a sweep that lost the last sample of
        // the day would leave it out, and that happened.
        $c = $day->twilights;
        $this->assertLessThan($c['nautical']['sunrise']->jdUt, $c['astronomical']['sunrise']->jdUt);
        $this->assertLessThan($c['civil']['sunrise']->jdUt, $c['nautical']['sunrise']->jdUt);
        $this->assertLessThan($day->rise->jdUt, $c['civil']['sunrise']->jdUt);
        $this->assertLessThan($c['civil']['dusk']->jdUt, $day->set->jdUt);
        $this->assertLessThan($c['nautical']['dusk']->jdUt, $c['civil']['dusk']->jdUt);
        $this->assertLessThan($c['astronomical']['dusk']->jdUt, $c['nautical']['dusk']->jdUt);
        $this->assertSame('23:53', $c['astronomical']['dusk']->date->format('H:i'));

        // A planet carries no twilights.
        $this->assertSame([], RiseSet::ofTheDay(Body::Venus, $madrid, new DateTimeImmutable('2024-06-21'))->twilights);
    }

    /**
     * An arbitrary direction (a star) is plugged in as a callable and rises and sets
     * like any body, with no parallax and no disc. It is checked against the
     * definition: at the instant of the rise its true altitude is minus the
     * refraction of the horizon, and at the culmination its right ascension is the
     * local sidereal time.
     */
    public function test_an_arbitrary_direction_rises_and_sets(): void
    {
        // Sirius, roughly: what matters is that it is a fixed direction.
        $sirius = fn (float $jdTT): Equatorial => Equatorial::direction(101.5, -16.75);
        $madrid = $this->place('Madrid');
        $horizon = new Horizon($madrid);

        $day = RiseSet::ofTheDay($sirius, $madrid, new DateTimeImmutable('2024-12-21'));

        $this->assertNotNull($day->rise);
        $this->assertNotNull($day->set);

        foreach ([$day->rise, $day->set] as $instant) {
            $altitude = $horizon->at($sirius(0.0), $instant->jdUt)->altitude;
            $this->assertEqualsWithDelta(-Horizon::refractionAtHorizon(), $altitude, 1 / 3600);
        }

        $this->assertEqualsWithDelta(101.5, $horizon->localSiderealTime($day->upperCulmination->jdUt), 1 / 3600);
        $this->assertEqualsWithDelta(281.5, $horizon->localSiderealTime($day->lowerCulmination->jdUt), 1 / 3600);

        // And a star that never rises from Madrid: the Southern Cross.
        $acrux = fn (float $jdTT): Equatorial => Equatorial::direction(186.65, -63.1);
        $this->assertNull(RiseSet::ofTheDay($acrux, $madrid, new DateTimeImmutable('2024-12-21'))->rise);
    }
}
