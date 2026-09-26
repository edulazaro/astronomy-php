<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\ReferenceEcliptic;
use Astronomy\Ephemeris;
use Astronomy\Position;
use Astronomy\Time;
use Astronomy\PositionType;
use PHPUnit\Framework\TestCase;

/**
 * The position with options: what light corrections it carries, which ecliptic it is measured
 * against, and the same position in rectangular coordinates.
 *
 * These are the Swiss flags `SEFLG_TRUEPOS`, `SEFLG_ASTROMETRIC`, `SEFLG_NOABERR`,
 * `SEFLG_NOGDEFL`, `SEFLG_NONUT`, `SEFLG_J2000` and `SEFLG_XYZ`, and they are checked three ways
 * that do not measure the same thing:
 *
 * - **Against JPL Horizons vectors**, which is the true reference: geocentric, in the J2000
 *   ecliptic and with the three corrections Horizons can give separately.
 * - **Against Swiss, but in DIFFERENCES**: how much a position moves when a correction is
 *   removed from it. Here Swiss runs on Moshier and we run with the correction towards the JPL,
 *   and in a subtraction of the same body against itself that cancels out.
 * - **Against its own definition**: the geometric vectors of two frames subtract, and the
 *   velocity in rectangular coordinates is the derivative of the rectangular coordinates.
 */
class PositionOptionsTest extends TestCase
{
    /**
     * Geocentric vectors from JPL Horizons in the J2000 ecliptic, in AU, with `TIME_TYPE='TT'`,
     * `REF_PLANE='ECLIPTIC'`, `REF_SYSTEM='ICRF'` and `CENTER='500@399'`. The twelve bodies in
     * the year 2000, and the Sun, the Moon, Mars and Neptune also in 1700 and in 2300. For the
     * planets, the barycentres are requested, which is what VSOP87 and its correction represent.
     *
     * `VEC_CORR` is what decides the type, and Horizons only tells three apart: `NONE` is the
     * geometric, `LT` the astrometric and `LT+S` the one without deflection, because its vectors
     * never carry the gravitational deflection. That is why the apparent is not here: it is
     * checked in `EphemerisTest` against the apparent longitudes of the date.
     *
     * @return array<string, array{0: Body, 1: PositionType, 2: float, 3: float, 4: float, 5: float}>
     */
    private static function jplVectors(): array
    {
        return [
            'sun geometric 2341972.5' => [Body::Sun, PositionType::Geometric, 2341972.5, 0.2529600969219284, -0.9500547320690258, -0.0006389952153219483],
            'sun astrometric 2341972.5' => [Body::Sun, PositionType::Astrometric, 2341972.5, 0.2529601378075502, -0.9500547108033054, -0.0006389964212188688],
            'sun nodeflection 2341972.5' => [Body::Sun, PositionType::NoDeflection, 2341972.5, 0.2528641239923532, -0.9500802700887754, -0.000639015815641632],
            'moon geometric 2341972.5' => [Body::Moon, PositionType::Geometric, 2341972.5, 0.001798093334329363, 0.001748038324327974, -0.0001781337163043791],
            'moon astrometric 2341972.5' => [Body::Moon, PositionType::Astrometric, 2341972.5, 0.001798345440526866, 0.001748097804151702, -0.0001781332590711025],
            'moon nodeflection 2341972.5' => [Body::Moon, PositionType::NoDeflection, 2341972.5, 0.00179825795493833, 0.001748186199172794, -0.0001781489681526656],
            'mars geometric 2341972.5' => [Body::Mars, PositionType::Geometric, 2341972.5, -1.38937417838778, -0.7310293907002396, 0.04535161575562111],
            'mars astrometric 2341972.5' => [Body::Mars, PositionType::Astrometric, 2341972.5, -1.389362308775971, -0.7309142289815819, 0.04535371509025677],
            'mars nodeflection 2341972.5' => [Body::Mars, PositionType::NoDeflection, 2341972.5, -1.389378872185815, -0.730883022525549, 0.04534921780049252],
            'neptune geometric 2341972.5' => [Body::Neptune, PositionType::Geometric, 2341972.5, 29.75397314546059, 3.657003551434594, -0.7748429719952545],
            'neptune astrometric 2341972.5' => [Body::Neptune, PositionType::Astrometric, 2341972.5, 29.7540606712927, 3.656463299690922, -0.7748338646309579],
            'neptune nodeflection 2341972.5' => [Body::Neptune, PositionType::NoDeflection, 2341972.5, 29.75410934502044, 3.656050641087262, -0.7749119982972924],
            'sun geometric 2451545.0' => [Body::Sun, PositionType::Geometric, 2451545.0, 0.1771350992582233, -0.9672416867691899, 4.085281582660778e-06],
            'sun astrometric 2451545.0' => [Body::Sun, PositionType::Astrometric, 2451545.0, 0.1771350687128628, -0.9672416447035024, 4.085817374187628e-06],
            'sun nodeflection 2451545.0' => [Body::Sun, PositionType::NoDeflection, 2451545.0, 0.1770373563853167, -0.9672595340759091, 4.085877445597722e-06],
            'moon geometric 2451545.0' => [Body::Moon, PositionType::Geometric, 2451545.0, -0.00194928164999959, -0.00183812603971763, 0.000242457973887658],
            'moon astrometric 2451545.0' => [Body::Moon, PositionType::Astrometric, 2451545.0, -0.001949020170108597, -0.001838070290855237, 0.0002424580769660216],
            'moon nodeflection 2451545.0' => [Body::Moon, PositionType::NoDeflection, 2451545.0, -0.001949122762704727, -0.001837964201313937, 0.0002424375946415407],
            'mercury geometric 2451545.0' => [Body::Mercury, PositionType::Geometric, 2451545.0, 0.0470414938647836, -1.414529304899118, -0.0245942216743747],
            'mercury astrometric 2451545.0' => [Body::Mercury, PositionType::Astrometric, 2451545.0, 0.04686679122095029, -1.414476488135669, -0.02457388009950305],
            'mercury nodeflection 2451545.0' => [Body::Mercury, PositionType::NoDeflection, 2451545.0, 0.04672546056652214, -1.414481170270497, -0.02457351167741196],
            'venus geometric 2451545.0' => [Body::Venus, PositionType::Geometric, 2451545.0, -0.5411671970878376, -0.99989599495191, 0.04101826730870115],
            'venus astrometric 2451545.0' => [Body::Venus, PositionType::Astrometric, 2451545.0, -0.5411724638419937, -0.9997626044802175, 0.04102039233031288],
            'venus nodeflection 2451545.0' => [Body::Venus, PositionType::NoDeflection, 2451545.0, -0.5412512045532774, -0.9997200846455191, 0.04101779431901477],
            'mars geometric 2451545.0' => [Body::Mars, PositionType::Geometric, 2451545.0, 1.567851021003945, -0.9806580049343152, -0.03446357749452551],
            'mars astrometric 2451545.0' => [Body::Mars, PositionType::Astrometric, 2451545.0, 1.567843781275304, -0.9808201693146092, -0.03446679878099244],
            'mars nodeflection 2451545.0' => [Body::Mars, PositionType::NoDeflection, 2451545.0, 1.567776836310707, -0.9809270826619412, -0.03446936703966962],
            'jupiter geometric 2451545.0' => [Body::Jupiter, PositionType::Geometric, 2451545.0, 4.178312260388125, 1.971334420191489, -0.1017811913522247],
            'jupiter astrometric 2451545.0' => [Body::Jupiter, PositionType::Astrometric, 2451545.0, 4.178434039924902, 1.971162648662317, -0.1017832059350203],
            'jupiter nodeflection 2451545.0' => [Body::Jupiter, PositionType::NoDeflection, 2451545.0, 4.178382870169839, 1.971270600574725, -0.1017931435608892],
            'saturn geometric 2451545.0' => [Body::Saturn, PositionType::Geometric, 2451545.0, 6.583543958794645, 5.602747930140092, -0.3690723409633573],
            'saturn astrometric 2451545.0' => [Body::Saturn, PositionType::Astrometric, 2451545.0, 6.583758194319583, 5.602553881255386, -0.3690774808204441],
            'saturn nodeflection 2451545.0' => [Body::Saturn, PositionType::NoDeflection, 2451545.0, 6.583474156019186, 5.602885521653841, -0.3691097516431551],
            'uranus geometric 2451545.0' => [Body::Uranus, PositionType::Geometric, 2451545.0, 14.60899171363775, -14.70156315554241, -0.2381376025176469],
            'uranus astrometric 2451545.0' => [Body::Uranus, PositionType::Astrometric, 2451545.0, 14.60867046958932, -14.701882213898, -0.2381346256934434],
            'uranus nodeflection 2451545.0' => [Body::Uranus, PositionType::NoDeflection, 2451545.0, 14.60744457505942, -14.70310001451403, -0.2381482103650572],
            'neptune geometric 2451545.0' => [Body::Neptune, PositionType::Geometric, 2451545.0, 16.98918206730893, -25.95900457605537, 0.1272269652019116],
            'neptune astrometric 2451545.0' => [Body::Neptune, PositionType::Astrometric, 2451545.0, 16.98871893978111, -25.95932163409096, 0.127244167411359],
            'neptune nodeflection 2451545.0' => [Body::Neptune, PositionType::NoDeflection, 2451545.0, 16.98630079134157, -25.96090397263713, 0.127249144527796],
            'pluto geometric 2451545.0' => [Body::Pluto, PositionType::Geometric, 2451545.0, -9.698217130667908, -28.92603831295825, 5.85045000176872],
            'pluto astrometric 2451545.0' => [Body::Pluto, PositionType::Astrometric, 2451545.0, -9.698761490657859, -28.92576108068156, 5.850577795971048],
            'pluto nodeflection 2451545.0' => [Body::Pluto, PositionType::NoDeflection, 2451545.0, -9.701381700175203, -28.92493925573727, 5.850296680978146],
            'chiron geometric 2451545.0' => [Body::Chiron, PositionType::Geometric, 2451545.0, -3.352462267848169, -10.09460238442216, 0.7572405630723552],
            'chiron astrometric 2451545.0' => [Body::Chiron, PositionType::Astrometric, 2451545.0, -3.352768761991434, -10.0943767803877, 0.7571983900780955],
            'chiron nodeflection 2451545.0' => [Body::Chiron, PositionType::NoDeflection, 2451545.0, -3.35366539842932, -10.09408168294646, 0.757161628145063],
            'ceres geometric 2451545.0' => [Body::Ceres, PositionType::Geometric, 2451545.0, -2.202192617987635, -0.1717556795284527, 0.4630096579776833],
            'ceres astrometric 2451545.0' => [Body::Ceres, PositionType::Astrometric, 2451545.0, -2.20214596639678, -0.1716176032025231, 0.4630053069409518],
            'ceres nodeflection 2451545.0' => [Body::Ceres, PositionType::NoDeflection, 2451545.0, -2.202153637101761, -0.1716419951292816, 0.4629597794729037],
            'sun geometric 2561118.5' => [Body::Sun, PositionType::Geometric, 2561118.5, 0.1177404982291871, -0.9764562492521959, 0.0006504337915864104],
            'sun astrometric 2561118.5' => [Body::Sun, PositionType::Astrometric, 2561118.5, 0.1177404646541708, -0.9764562858970984, 0.0006504349037340203],
            'sun nodeflection 2561118.5' => [Body::Sun, PositionType::NoDeflection, 2561118.5, 0.1176419002400737, -0.9764681656759822, 0.0006504458375382798],
            'moon geometric 2561118.5' => [Body::Moon, PositionType::Geometric, 2561118.5, 0.001997765031375761, 0.001410528154751614, -0.0001583269211936442],
            'moon astrometric 2561118.5' => [Body::Moon, PositionType::Astrometric, 2561118.5, 0.00199801579270604, 0.001410551265768136, -0.0001583275359932312],
            'moon nodeflection 2561118.5' => [Body::Moon, PositionType::NoDeflection, 2561118.5, 0.001997947554980336, 0.001410646343977444, -0.0001583415602035331],
            'mars geometric 2561118.5' => [Body::Mars, PositionType::Geometric, 2561118.5, -1.528202584274097, -0.7614509779957013, 0.04455212841185005],
            'mars astrometric 2561118.5' => [Body::Mars, PositionType::Astrometric, 2561118.5, -1.52818966195531, -0.7613261058183108, 0.04455444906088753],
            'mars nodeflection 2561118.5' => [Body::Mars, PositionType::NoDeflection, 2561118.5, -1.528215408916951, -0.7612746693342044, 0.04455023027518301],
            'neptune geometric 2561118.5' => [Body::Neptune, PositionType::Geometric, 2561118.5, -15.09637240152979, -27.19910809266846, 0.8916534021492658],
            'neptune astrometric 2561118.5' => [Body::Neptune, PositionType::Astrometric, 2561118.5, -15.09685689735373, -27.19882855909068, 0.8916588154205096],
            'neptune nodeflection 2561118.5' => [Body::Neptune, PositionType::NoDeflection, 2561118.5, -15.09907889723934, -27.1975968304897, 0.8916062528825858],
        ];
    }

    /**
     * Measured: the worst direction is off by 0.013 arcseconds in the geometric, 0.027 in the
     * astrometric and 0.033 in the no-deflection one, and the worst distance is 1.7e-6 AU, both
     * in Neptune and Pluto with light time. Without light time what is left is what the
     * ephemerides carry; with it, what our light delay carries against Horizons' own also comes
     * in, which at thirty astronomical units is four hours of travel.
     *
     * And it does not pass by doing nothing: between the geometric and the astrometric of Mars
     * there are sixteen arcseconds, and between the astrometric and the no-deflection one,
     * fourteen. An option that was not applied would fall outside by hundreds of times the
     * margin.
     */
    public function test_the_three_corrections_match_the_jpl_vectors(): void
    {
        foreach (self::jplVectors() as $case => [$body, $type, $jd, $x, $y, $z]) {
            $ours = Ephemeris::position($body, $jd, $type, ReferenceEcliptic::J2000)->rectangular();

            $this->assertLessThan(0.04, self::angleInArcseconds($ours, [$x, $y, $z]), "the direction of {$case}");
            $this->assertEqualsWithDelta(self::magnitude([$x, $y, $z]), self::magnitude($ours), 2.5e-6, "the distance of {$case}");
        }
    }

    /**
     * How much each body moves when each correction is removed from it, against the same thing
     * in pyswisseph 2.10.03 with Moshier, in arcseconds of longitude and against the apparent of
     * the date. Measured: everything under 0.005.
     *
     * These figures show the two things that are hardest to guess. **Light time does not move
     * the Sun at all**: its geometric, its astrometric and its no-aberration are the same,
     * because it is at the origin of the frame the path of light is measured in. **And removing
     * aberration from the Moon moves it eleven arcseconds in the year 2000, not the twenty of a
     * planet**, because in its apparent the aberration already comes half eaten by its light
     * time: it moves along with us. That is why its astrometric comes out of the general path
     * and not its own.
     */
    public function test_what_each_correction_moves_is_what_it_moves_in_swiss(): void
    {
        $swiss = [
            // [body, JD in TT, geometric, astrometric, no aberration, no deflection, mean of date]
            [Body::Sun, 2451545.0, 20.8417, 20.8417, 20.8417, 0.0000, 13.9315],
            [Body::Moon, 2451545.0, 0.6719, 11.3726, 11.3726, 0.0000, 13.9315],
            [Body::Mars, 2451545.0, 29.8252, 14.0600, 14.0650, -0.0050, 13.9315],
            [Body::Jupiter, 2451545.0, 3.9193, -5.3469, -5.3443, -0.0026, 13.9315],
            [Body::Sun, 2305447.5, 20.8316, 20.8316, 20.8316, 0.0000, -15.1023],
            [Body::Moon, 2305447.5, 0.7065, -20.7635, -20.7635, 0.0000, -15.1023],
            [Body::Mars, 2305447.5, -2.5175, -16.5501, -16.5507, 0.0006, -15.1023],
            [Body::Jupiter, 2305447.5, -7.0950, -15.7289, -15.7301, 0.0013, -15.1023],
        ];

        foreach ($swiss as [$body, $jd, $geometric, $astrometric, $noAberration, $noDeflection, $mean]) {
            $apparent = Ephemeris::position($body, $jd)->longitude;
            $moves = fn (PositionType $type, ReferenceEcliptic $ecliptic = ReferenceEcliptic::TrueOfDate): float => self::difference(
                Ephemeris::position($body, $jd, $type, $ecliptic)->longitude,
                $apparent
            );
            $where = "{$body->name()} at JD {$jd}";

            $this->assertEqualsWithDelta($geometric, $moves(PositionType::Geometric), 0.006, "geometric of {$where}");
            $this->assertEqualsWithDelta($astrometric, $moves(PositionType::Astrometric), 0.006, "astrometric of {$where}");
            $this->assertEqualsWithDelta($noAberration, $moves(PositionType::NoAberration), 0.006, "no aberration of {$where}");
            $this->assertEqualsWithDelta($noDeflection, $moves(PositionType::NoDeflection), 0.006, "no deflection of {$where}");
            $this->assertEqualsWithDelta($mean, $moves(PositionType::Apparent, ReferenceEcliptic::MeanOfDate), 0.006, "mean of {$where}");
        }

        // From the Sun only light time counts: Mars against `SEFLG_HELCTR | SEFLG_TRUEPOS`.
        foreach ([[2451545.0, 18.1048], [2305447.5, 15.3735]] as [$jd, $expected]) {
            $apparent = Ephemeris::heliocentric(Body::Mars, $jd)->longitude;

            $this->assertEqualsWithDelta($expected, self::difference(
                Ephemeris::heliocentric(Body::Mars, $jd, PositionType::Geometric)->longitude,
                $apparent
            ), 0.006, "heliocentric geometric of Mars at JD {$jd}");

            foreach ([PositionType::Astrometric, PositionType::NoAberration, PositionType::NoDeflection] as $type) {
                $this->assertSame($apparent, Ephemeris::heliocentric(Body::Mars, $jd, $type)->longitude, 'from the Sun there is no aberration or deflection to remove');
            }
        }
    }

    /**
     * In the mean of date only nutation is removed, which on the ecliptic moves the longitude
     * and nothing else. And on 1 January 2000 itself the J2000 and the mean are the same,
     * because precession has not yet turned anything there: if the rotation started from the
     * true, they would be off by the whole of nutation right on the date anyone tests it.
     */
    public function test_the_mean_removes_nutation_and_j2000_starts_from_the_mean(): void
    {
        foreach ([Body::Sun, Body::Moon, Body::Venus, Body::Pluto] as $body) {
            foreach ([2341972.5, 2451545.0, 2561118.5] as $jd) {
                $true = Ephemeris::position($body, $jd);
                $mean = Ephemeris::position($body, $jd, ecliptic: ReferenceEcliptic::MeanOfDate);
                $nutation = rad2deg(Time::nutation(Time::centuries($jd))[0]) * 3600;
                $where = "{$body->name()} at JD {$jd}";

                $this->assertEqualsWithDelta($nutation, self::difference($true->longitude, $mean->longitude), 1e-6, "the nutation of {$where}");
                $this->assertSame($true->latitude, $mean->latitude, "the latitude of {$where}");
                $this->assertSame($true->distance, $mean->distance, "the distance of {$where}");
            }

            $mean = Ephemeris::position($body, 2451545.0, ecliptic: ReferenceEcliptic::MeanOfDate);
            $j2000 = Ephemeris::position($body, 2451545.0, ecliptic: ReferenceEcliptic::J2000);

            $this->assertEqualsWithDelta(0.0, self::difference($mean->longitude, $j2000->longitude), 1e-6, $body->name());
            $this->assertEqualsWithDelta($mean->latitude, $j2000->latitude, 1e-10, $body->name());
        }
    }

    /**
     * The geometric vectors subtract: Mars seen from Earth is Mars seen from the Sun minus Earth
     * seen from the Sun, and Mars seen from Jupiter, the same with Jupiter. Without light time
     * or aberration nothing is left that depends on who is looking, so three separate code paths
     * have to give the same vector. In J2000, so that nutation does not come in.
     */
    public function test_the_geometric_frames_subtract_as_vectors(): void
    {
        $geometric = fn (callable $ask, float $jd): array => $ask($jd)->rectangular();

        foreach ([2341972.5, 2451545.0, 2561118.5] as $jd) {
            $fromTheSun = fn (Body $body): array => Ephemeris::heliocentric($body, $jd, PositionType::Geometric, ReferenceEcliptic::J2000)->rectangular();

            $mars = $fromTheSun(Body::Mars);
            $earth = $fromTheSun(Body::Earth);
            $jupiter = $fromTheSun(Body::Jupiter);

            $fromEarth = Ephemeris::position(Body::Mars, $jd, PositionType::Geometric, ReferenceEcliptic::J2000)->rectangular();
            $fromJupiter = Ephemeris::planetocentric(Body::Mars, Body::Jupiter, $jd, PositionType::Geometric, ReferenceEcliptic::J2000)->rectangular();

            foreach ([0, 1, 2] as $axis) {
                $this->assertEqualsWithDelta($mars[$axis] - $earth[$axis], $fromEarth[$axis], 1e-12, "Mars from Earth, axis {$axis}, JD {$jd}");
                $this->assertEqualsWithDelta($mars[$axis] - $jupiter[$axis], $fromJupiter[$axis], 1e-12, "Mars from Jupiter, axis {$axis}, JD {$jd}");
            }
        }
    }

    /**
     * The rectangular coordinates come from the spherical ones with the usual formulas, and
     * their velocity from deriving them. Checked against the six numbers Swiss gives for Mars in
     * spherical and in rectangular coordinates (`SEFLG_SPEED` and `SEFLG_XYZ | SEFLG_SPEED`),
     * which match to 5e-10, which is the rounding they are written to.
     */
    public function test_the_rectangular_coordinates_match_swiss(): void
    {
        $swiss = [
            // [JD in TT, [longitude, latitude, distance and their three velocities], [x, y, z and their three velocities]]
            [2451545.0, [327.962740281, -1.067792162, 1.849683427, 0.775672746, 0.012475471, 0.005424804], [1.567710473, -0.981032365, -0.034469609, 0.017885447, 0.018342551, 0.000301583]],
            [2305447.5, [137.484985030, 3.812827562, 0.746598515, -0.162080766, 0.039762446, -0.005276370], [-0.549099893, 0.503422136, 0.049646778, 0.005330103, -0.002027757, 0.000166118]],
        ];

        foreach ($swiss as [$jd, [$l, $b, $r, $dl, $db, $dr], $expected]) {
            $position = new Position(Body::Mars, $l, $b, $r, $dl, $db, $dr);
            $ours = [...$position->rectangular(), ...$position->rectangularVelocity()];

            foreach ($expected as $i => $value) {
                $this->assertEqualsWithDelta($value, $ours[$i], 2e-9, "component {$i} at JD {$jd}");
            }
        }

        // Without the other two velocities none is invented, which would be assuming they do not change.
        $this->assertNull((new Position(Body::Mars, 10.0, 1.0, 1.5, 0.5))->rectangularVelocity());
    }

    /**
     * And the rectangular velocity of the ephemerides is the derivative of their rectangular
     * coordinates, in every frame and with options: one of the three velocities built wrong, or
     * derived with the step size of another frame, would show up here. The margin is that of the
     * six hour centred difference, measured: one part in four thousand in Mercury and in the
     * topocentric Moon, which are what changes fastest.
     */
    public function test_the_rectangular_velocity_is_the_derivative_of_the_rectangular_coordinates(): void
    {
        $h = 0.01;

        $cases = [
            'the geocentric of Mercury' => fn (float $t): Position => Ephemeris::position(Body::Mercury, $t),
            'the astrometric Moon in J2000' => fn (float $t): Position => Ephemeris::position(Body::Moon, $t, PositionType::Astrometric, ReferenceEcliptic::J2000),
            'the heliocentric of Jupiter' => fn (float $t): Position => Ephemeris::heliocentric(Body::Jupiter, $t),
            'the barycentric Sun in the mean' => fn (float $t): Position => Ephemeris::barycentric(Body::Sun, $t, ecliptic: ReferenceEcliptic::MeanOfDate),
            'Mars from Jupiter' => fn (float $t): Position => Ephemeris::planetocentric(Body::Mars, Body::Jupiter, $t),
            'the topocentric Moon in Madrid' => fn (float $t): Position => Ephemeris::topocentric(Body::Moon, $t, 40.4168, -3.7038),
        ];

        foreach ([2341972.5, 2451545.0, 2561118.5] as $jd) {
            foreach ($cases as $case => $at) {
                $velocity = $at($jd)->rectangularVelocity();
                $before = $at($jd - $h)->rectangular();
                $after = $at($jd + $h)->rectangular();
                $derivative = [];

                foreach ([0, 1, 2] as $axis) {
                    $derivative[$axis] = ($after[$axis] - $before[$axis]) / (2 * $h);
                }

                $error = self::magnitude([$velocity[0] - $derivative[0], $velocity[1] - $derivative[1], $velocity[2] - $derivative[2]]);

                $this->assertLessThan(5e-4 * self::magnitude($derivative), $error, "{$case} at JD {$jd}");
            }
        }
    }

    /**
     * With the two options left at default it is the usual position, the same number and not a
     * similar one, and it already carries the two new velocities, which used to be computed and
     * thrown away. And shifting to the sidereal zodiac does not lose them.
     */
    public function test_by_default_it_is_the_usual_one_and_carries_the_three_velocities(): void
    {
        foreach (Body::classical() as $body) {
            $position = Ephemeris::position($body, 2446227.0);

            $this->assertSame(Ephemeris::apparentLongitude($body, 2446227.0), $position->longitude, $body->name());
            $this->assertNotNull($position->latitudeSpeed, $body->name());
            $this->assertNotNull($position->distanceSpeed, $body->name());

            $sidereal = $position->shifted(24.0);

            $this->assertSame($position->latitudeSpeed, $sidereal->latitudeSpeed);
            $this->assertSame($position->distanceSpeed, $sidereal->distanceSpeed);
        }
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @return float
     */
    private static function angleInArcseconds(array $a, array $b): float
    {
        $cross = [$a[1] * $b[2] - $a[2] * $b[1], $a[2] * $b[0] - $a[0] * $b[2], $a[0] * $b[1] - $a[1] * $b[0]];

        return rad2deg(atan2(self::magnitude($cross), $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2])) * 3600;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $vector
     * @return float
     */
    private static function magnitude(array $vector): float
    {
        return sqrt($vector[0] ** 2 + $vector[1] ** 2 + $vector[2] ** 2);
    }

    /**
     * The difference between two longitudes, signed and in arcseconds.
     *
     * @param float $first
     * @param float $second
     * @return float
     */
    private static function difference(float $first, float $second): float
    {
        return (fmod($first - $second + 540, 360) - 180) * 3600;
    }
}
