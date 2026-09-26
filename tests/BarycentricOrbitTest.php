<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\DataFolder;
use Astronomy\NodesAndApsides;
use Astronomy\OsculatingOrbit;
use Astronomy\Precession;
use Astronomy\Time;
use ReflectionMethod;
use PHPUnit\Framework\TestCase;

/**
 * The osculating orbit measured from the solar system BARYCENTER: `SE_NODBIT_OSCU_BAR`.
 *
 * **Here the reference cannot be Swiss, and that is the first thing to know.** pyswisseph
 * without ephemeris files does not compute the barycentric one: it returns the heliocentric and
 * does not say so. Measured on Jupiter, Saturn, Neptune and Pluto over three epochs, all four
 * give **exactly the same numbers** with `NODBIT_OSCU_BAR` as with `NODBIT_OSCU`, down to the
 * last decimal. So checking this against Swiss would have come out green comparing the
 * heliocentric against itself, which is the worst way to sign off a computation.
 *
 * So it is checked against **JPL Horizons with `CENTER='500@0'`**, which does serve them
 * (`EPHEM_TYPE='ELEMENTS'`, `REF_PLANE='ECLIPTIC'`, `REF_SYSTEM='J2000'`, `OUT_UNITS='AU-D'`),
 * with the figures copied here so the suite runs with no network. The same three warnings from
 * `NodesAndApsidesTest` apply: they go in TDB because Horizons cannot be asked for elements in
 * TT, the bodies are the barycenters of each system, and **its angles are on the J2000 ecliptic
 * and ours on the ecliptic of the date**, so the vectors have to be rotated before comparing.
 *
 * Worst case over six bodies and three epochs from 1700 to 2300:
 *
 * | | worst difference |
 * |---|---|
 * | Eccentricity and eccentricity vector | 6.6e-6 |
 * | Semi-major axis | 1.2e-4 AU |
 * | Node | 3.5″ |
 * | Inclination | 0.09″ |
 * | Perihelion distance | 2.3e-4 AU |
 *
 * **And those figures are the same as for the heliocentric orbit**, which was already at 6.6e-6
 * for the vector and 1.2e-4 for the semi-major axis. That is exactly what needed to be shown:
 * changing origin degrades nothing, because the only thing that changes is where the position is
 * measured from, and everything else (light delay, nutation, the derivative, vis-viva) is the
 * same code.
 */
class BarycentricOrbitTest extends TestCase
{
    /** Margin for the node, in arcseconds. Worst measured: 3.46 (Neptune in 2300). */
    private const NODE_TOLERANCE = 5.0;

    /** For the inclination, in arcseconds. Worst measured: 0.09. */
    private const INCLINATION_TOLERANCE = 0.2;

    /** For the eccentricity vector, which is where the perihelion and aphelion come from. */
    private const VECTOR_TOLERANCE = 1.0e-5;

    /**
     * The BARYCENTRIC osculating elements JPL Horizons publishes, on the J2000 ecliptic.
     * Each row: TDB Julian day, `EC`, `QR`, `IN`, `OM`, `W` and `A`, in the order it prints them.
     *
     * @return array<string, list<array{float, float, float, float, float, float, float}>>
     */
    public static function fromJpl(): array
    {
        return [
        // Mercury Barycenter (1)
        'mercury' => [
            [2341970.0, 1.838106411669136E-01, 3.077622796647774E-01, 7.076111093031152E+00, 4.965165712318601E+01, 2.732059282982478E+01, 3.770721540707025E-01], // 1699-Dec-29
            [2451545.0, 1.976185216542616E-01, 3.156821785714055E-01, 7.013867492063333E+00, 4.812387243949261E+01, 2.581620098603785E+01, 3.934315373558275E-01], // 2000-Jan-01
            [2561120.0, 1.785162373196830E-01, 3.005391880671105E-01, 7.127868829586355E+00, 4.754923738211634E+01, 2.680606826617159E+01, 3.658492129978548E-01], // 2300-Jan-03
        ],
        // Mars Barycenter (4)
        'mars' => [
            [2341970.0, 9.124659969819980E-02, 1.392799845385097E+00, 1.870637171886486E+00, 5.039288610392988E+01, 2.827047429917735E+02, 1.532648840623368E+00], // 1699-Dec-29
            [2451545.0, 8.524928797805197E-02, 1.374590035563710E+00, 1.847422900257416E+00, 4.947411430089571E+01, 2.857002664253750E+02, 1.502693594548116E+00], // 2000-Jan-01
            [2561120.0, 9.919967902264205E-02, 1.358706896629869E+00, 1.828454638048308E+00, 4.870922918729386E+01, 2.907118842664170E+02, 1.508333051164644E+00], // 2300-Jan-03
        ],
        // Jupiter Barycenter (5)
        'jupiter' => [
            [2341970.0, 4.949307079422268E-02, 4.934904721430145E+00, 1.309038348919975E+00, 9.990133808730263E+01, 2.751882870815591E+02, 5.191866118802146E+00], // 1699-Dec-29
            [2451545.0, 4.760644156914177E-02, 4.941874254759558E+00, 1.304245062486530E+00, 1.004855062837313E+02, 2.740367214565450E+02, 5.188899285398022E+00], // 2000-Jan-01
            [2561120.0, 4.959366482950064E-02, 4.931416210991179E+00, 1.296125249021566E+00, 1.010321780938617E+02, 2.728790741457026E+02, 5.188745096176680E+00], // 2300-Jan-03
        ],
        // Saturn Barycenter (6)
        'saturn' => [
            [2341970.0, 5.594155023072742E-02, 9.010870745446493E+00, 2.481795790126999E+00, 1.144973207548513E+02, 3.389575744698015E+02, 9.544822937233119E+00], // 1699-Dec-29
            [2451545.0, 5.264618442914223E-02, 9.030943681968536E+00, 2.485933535206377E+00, 1.136523991812459E+02, 3.390649499888777E+02, 9.532809741761220E+00], // 2000-Jan-01
            [2561120.0, 5.461841786879737E-02, 9.017480758556909E+00, 2.498828707554404E+00, 1.128426639254405E+02, 3.414686262993572E+02, 9.538456141940619E+00], // 2300-Jan-03
        ],
        // Neptune Barycenter (8)
        'neptune' => [
            [2341970.0, 8.531838527047784E-03, 2.981367406010751E+01, 1.769116493867605E+00, 1.318020457072253E+02, 2.739960134376397E+02, 3.007022839322999E+01], // 1699-Dec-29
            [2451545.0, 8.677896457329776E-03, 2.980995261773710E+01, 1.770208103469000E+00, 1.317829469636023E+02, 2.732186282649977E+02, 3.007090481610952E+01], // 2000-Jan-01
            [2561120.0, 8.764339438771609E-03, 2.981319924709369E+01, 1.770891803262863E+00, 1.317757931569024E+02, 2.720392725812333E+02, 3.007680255390907E+01], // 2300-Jan-03
        ],
        // Pluto Barycenter (9)
        'pluto' => [
            [2341970.0, 2.487109184873744E-01, 2.965161013742343E+01, 1.714119698104858E+01, 1.103369631069075E+02, 1.137796901317380E+02, 3.946764416930387E+01], // 1699-Dec-29
            [2451545.0, 2.489763560923634E-01, 2.965598268019627E+01, 1.714055930776762E+01, 1.103012538561415E+02, 1.137774660018993E+02, 3.948741550384992E+01], // 2000-Jan-01
            [2561120.0, 2.493329645249638E-01, 2.965443136221616E+01, 1.713939972689235E+01, 1.102791806405003E+02, 1.138065177766134E+02, 3.950410762802483E+01], // 2300-Jan-03
        ],
        ];
    }

    public function test_the_barycentric_elements_match_the_jpls(): void
    {
        foreach (self::fromJpl() as $name => $cases) {
            $body = Body::from($name);

            foreach ($cases as [$jd, $eccentricity, $perihelion, $inclination, $node, $argument, $semiMajorAxis]) {
                $orbit = NodesAndApsides::barycentric($body, $jd);
                $reference = $this->fromJ2000ToTheDate($inclination, $node, $argument, $jd);
                $where = sprintf('%s on Julian day %.1f', $body->name(), $jd);

                $this->assertLessThan(
                    self::NODE_TOLERANCE,
                    $this->differenceInArcseconds($orbit->ascendingNode, $reference['node']),
                    'Barycentric node of '.$where
                );

                $this->assertLessThan(
                    self::VECTOR_TOLERANCE,
                    sqrt(
                        ($orbit->eccentricity - $eccentricity) ** 2
                        + ($eccentricity * $this->angleBetween($this->perihelionDirection($orbit), $reference['perihelion'])) ** 2
                    ),
                    'Barycentric eccentricity vector of '.$where
                );

                $this->assertLessThan(self::INCLINATION_TOLERANCE, abs($orbit->inclination - $reference['inclination']) * 3600, 'Inclination of '.$where);
                $this->assertLessThan(2.5e-4, abs($orbit->semiMajorAxis - $semiMajorAxis), 'Semi-major axis of '.$where);
                $this->assertLessThan(5.0e-4, abs($orbit->perihelionDistance - $perihelion), 'Perihelion distance of '.$where);
            }
        }
    }

    /**
     * The barycentric one is NOT the heliocentric with another decimal: it is another orbit,
     * and it shows to the naked eye in Jupiter, which is the one that moves the Sun the most.
     *
     * Its semi-major axis goes from 5.2043 around the Sun to 5.1889 around the barycenter,
     * fifteen thousandths of an astronomical unit, and its eccentricity from 0.0488 to 0.0476.
     * The reason is that the Sun circles the barycenter at 743,000 kilometres of distance, more
     * than its own radius, pulled mostly by Jupiter itself: a jovian orbit referred to the Sun's
     * center carries inside it the wobble Jupiter causes.
     *
     * **This test is also the one that would have caught the silent failure**: if
     * `barycentric()` were left returning the heliocentric, as happens to Swiss without files,
     * it would show up here.
     */
    public function test_the_barycentric_is_not_the_heliocentric(): void
    {
        $fromTheSun = NodesAndApsides::of(Body::Jupiter, 2451545.0);
        $fromTheBarycenter = NodesAndApsides::barycentric(Body::Jupiter, 2451545.0);

        $this->assertEqualsWithDelta(5.2043, $fromTheSun->semiMajorAxis, 1.0e-3);
        $this->assertEqualsWithDelta(5.1889, $fromTheBarycenter->semiMajorAxis, 1.0e-3);
        $this->assertEqualsWithDelta(0.0488, $fromTheSun->eccentricity, 1.0e-4);
        $this->assertEqualsWithDelta(0.0476, $fromTheBarycenter->eccentricity, 1.0e-4);

        // And for Pluto, which is far away and weighs nothing, it also changes, though much less.
        $this->assertNotEqualsWithDelta(
            NodesAndApsides::of(Body::Pluto, 2451545.0)->semiMajorAxis,
            NodesAndApsides::barycentric(Body::Pluto, 2451545.0)->semiMajorAxis,
            1.0e-6
        );
    }

    /**
     * The μ of a barycentric orbit is `(M - m)³ / M²`, with `M` the mass of the whole solar
     * system and `m` that of the body, and **that is not an armchair deduction: it is exactly
     * the "Keplerian GM" Horizons prints** when asked for elements with `CENTER='500@0'`.
     *
     * Around the barycenter there is no mass at all, so neither the Sun's GM nor the combined
     * one applies; what there is is the two-body problem seen from the center of mass, with the
     * body on one side and everything else on the other. The check shows it well: Jupiter's μ
     * comes out SMALLER than the Sun's GM, because it gets deducted from the central mass, and
     * Mercury's comes out larger, because the other eight planets get added to it.
     */
    public function test_the_barycentric_mu_is_the_one_horizons_publishes(): void
    {
        $mu = new ReflectionMethod(NodesAndApsides::class, 'mu');

        $fromHorizons = [
            'mercury' => [2.9591225740912142E-04, 2.9630912756469950E-04],
            'jupiter' => [2.9619474286160611E-04, 2.9546247915104113E-04],
            'saturn' => [2.9599680534422878E-04, 2.9605555621049204E-04],
            'neptune' => [2.9592745186772209E-04, 2.9626354654144389E-04],
            'pluto' => [2.9591221045906181E-04, 2.9630926841485401E-04],
        ];

        foreach ($fromHorizons as $name => [$heliocentric, $barycentric]) {
            $body = Body::from($name);

            $this->assertEqualsWithDelta($heliocentric, $mu->invoke(null, $body, false), $heliocentric * 1.0e-7, $name.' heliocentric');
            $this->assertEqualsWithDelta($barycentric, $mu->invoke(null, $body, true), $barycentric * 1.0e-7, $name.' barycentric');
        }

        $sunGm = (require DataFolder::path('masses.php'))['gm']['sun'];

        $this->assertLessThan($sunGm, $mu->invoke(null, Body::Jupiter, true), "Jupiter's has to come out smaller than the Sun's GM");
        $this->assertGreaterThan($sunGm, $mu->invoke(null, Body::Mercury, true), "and Mercury's, larger");
    }

    /**
     * A body with no mass in the DE440 header falls back to the limit on its own, with no
     * special case: with `m = 0` the formula leaves `μ = M`, the GM of the whole solar system.
     * It is the same thing Horizons does with an asteroid.
     */
    public function test_a_body_with_no_mass_uses_the_whole_system_gm(): void
    {
        $mu = new ReflectionMethod(NodesAndApsides::class, 'mu');
        $gm = (require DataFolder::path('masses.php'))['gm'];
        $total = array_sum($gm);

        foreach ([Body::Chiron, Body::Ceres, Body::Pholus] as $body) {
            $this->assertEqualsWithDelta($total, $mu->invoke(null, $body, true), $total * 1.0e-12, $body->name());
            $this->assertEqualsWithDelta($gm['sun'], $mu->invoke(null, $body, false), $gm['sun'] * 1.0e-12, $body->name());
        }
    }

    /**
     * Horizons's elements, which are on the J2000 ecliptic, written on the true ecliptic of the
     * date, which is where the engine gives them. The VECTORS that define the orbit are rotated,
     * not the angles, for the reason spelled out in `NodesAndApsidesTest`.
     *
     * @param float $inclination
     * @param float $node
     * @param float $argument
     * @param float $jd
     * @return array{node: float, inclination: float, perihelion: array{float, float, float}}
     */
    private function fromJ2000ToTheDate(float $inclination, float $node, float $argument, float $jd): array
    {
        $i = deg2rad($inclination);
        $om = deg2rad($node);
        $w = deg2rad($argument);

        $normal = $this->toTheDate([sin($i) * sin($om), -sin($i) * cos($om), cos($i)], $jd);

        $perihelion = $this->toTheDate([
            cos($om) * cos($w) - sin($om) * sin($w) * cos($i),
            sin($om) * cos($w) + cos($om) * sin($w) * cos($i),
            sin($w) * sin($i),
        ], $jd);

        return [
            'node' => fmod(fmod(rad2deg(atan2($normal[0], -$normal[1])), 360) + 360, 360),
            'inclination' => rad2deg(atan2(sqrt($normal[0] ** 2 + $normal[1] ** 2), $normal[2])),
            'perihelion' => $perihelion,
        ];
    }

    /**
     * @param array{float, float, float} $vector
     * @param float $jd
     * @return array{float, float, float}
     */
    private function toTheDate(array $vector, float $jd): array
    {
        $centuries = Time::centuries($jd);
        $date = Precession::toDate($vector, $centuries);

        $nutation = Time::nutation($centuries)[0];

        return [
            cos($nutation) * $date[0] - sin($nutation) * $date[1],
            sin($nutation) * $date[0] + cos($nutation) * $date[1],
            $date[2],
        ];
    }

    /**
     * @param OsculatingOrbit $orbit
     * @return array{float, float, float}
     */
    private function perihelionDirection(OsculatingOrbit $orbit): array
    {
        $longitude = deg2rad($orbit->perihelion);
        $latitude = deg2rad($orbit->perihelionLatitude);

        return [cos($latitude) * cos($longitude), cos($latitude) * sin($longitude), sin($latitude)];
    }

    /**
     * @param array{float, float, float} $a
     * @param array{float, float, float} $b
     * @return float
     */
    private function angleBetween(array $a, array $b): float
    {
        $cross = [
            $a[1] * $b[2] - $a[2] * $b[1],
            $a[2] * $b[0] - $a[0] * $b[2],
            $a[0] * $b[1] - $a[1] * $b[0],
        ];

        return atan2(
            sqrt($cross[0] ** 2 + $cross[1] ** 2 + $cross[2] ** 2),
            $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2]
        );
    }

    /**
     * @param float $first
     * @param float $second
     * @return float
     */
    private function differenceInArcseconds(float $first, float $second): float
    {
        return abs(fmod($first - $second + 540, 360) - 180) * 3600;
    }
}
