<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\FictitiousBodies;
use Astronomy\Ephemeris;
use Astronomy\NodesAndApsides;
use Astronomy\OsculatingOrbit;
use Astronomy\Precession;
use Astronomy\Time;
use LogicException;
use ReflectionMethod;
use RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * The nodes and the apsides of each body, checked by two different roads.
 *
 * **The first is against its own definition**, which holds even with nobody on the other
 * side, and it is the same one `LunarPointsTest` uses with the Moon: a node is where the
 * orbit crosses the ecliptic, so the ascending node HAS to be the heliocentric longitude of
 * the body at the exact instant its heliocentric latitude goes from negative to positive. And
 * the perihelion, the longitude at the instant its distance to the Sun is smallest. Both
 * instants are found by a sweep and a bisection over `Ephemeris::heliocentric`, without
 * touching anything in `NodesAndApsides`. A match like that cannot come out by chance.
 *
 * That road has a virtue worth understanding before trusting it: **it catches geometry bugs
 * and not ephemeris ones**. At the crossing the z coordinate is zero, so the angular momentum
 * `r × v` comes out perpendicular to `r` no matter how wrong `v` is: the node checks out to
 * hundredths of an arcsecond even with a bad velocity. The perihelion does not get that for
 * free, for the usual reason, which is that it is divided by the eccentricity. That is why the
 * second road is needed.
 *
 * **The second is against JPL Horizons**, which serves real osculating elements
 * (`EPHEM_TYPE='ELEMENTS'`, center `500@10`, `REF_PLANE='ECLIPTIC'`). The figures are copied
 * here so the test runs with no network, exactly as Horizons prints them and in the same
 * layout, the same as `EphemerisTest` does with positions.
 *
 * Three things about that request that are worth knowing, and none of them warns:
 *
 * - **Horizons will not give elements in Terrestrial Time.** `TIME_TYPE='TT'` is rejected with
 *   "Only TDB is allowed for osculating element TIME_TYPE", so these dates go in as TDB and
 *   are read here as TT. It makes no difference: TDB and TT are at most 1.7 milliseconds
 *   apart, and that does not move a node. The project rule of always asking for TT is against
 *   delta T, which is seventy seconds and does bite.
 * - **The bodies are the BARYCENTERS** (1, 2, 4, 5, 6, 7, 8, 9), which is what VSOP87 and the
 *   tables represent, and not the centers of the planets. That comes from `horizonsId()`.
 * - **Its elements are in the J2000 ecliptic and ours are in that of the date.** There is no
 *   option to ask for them at the date, so the conversion is done here, rotating the plane's
 *   normal and the perihelion direction with our own precession and our own nutation. It is
 *   the same thing `StarsTest` does to compare catalogs. Comparing the raw angles would be
 *   measuring three hundred years of precession and calling it error.
 *
 * Worst case measured over fourteen bodies in 1700, 2000 and 2300:
 *
 * | | worst difference |
 * |---|---|
 * | Node, tabulated bodies | 0.06″ |
 * | Node, VSOP87 planets | 5.8″ |
 * | Eccentricity vector | 6.6e-6 |
 * | Eccentricity | 6.6e-6 |
 * | Inclination | 0.09″ |
 * | Semi-major axis | 1.2e-4 AU |
 * | Perihelion and aphelion, in distance | 2.4e-4 AU |
 *
 * **The apsis is not checked in degrees, and that is not for convenience.** The eccentricity
 * vector points at the perihelion and measures `e`, so the angle it defines is known with an
 * error equal to that of the vector DIVIDED BY `e`. In Neptune, with 0.0085 in 2300, six
 * millionths of vector turn into 64.7 arcseconds, and in Pallas, with 0.23, into 0.3. A
 * tolerance in degrees would need one per body, and all of them would say the same thing
 * divided by something else; the vector's is a single one and it grips all fourteen the same
 * way.
 */
class NodesAndApsidesTest extends TestCase
{
    /** Margin of the node for the bodies that come from a JPL table, in arcseconds. */
    private const TOLERANCE_NODE_TABULATED = 0.15;

    /** And for the ones that come from VSOP87, which carry the series' velocity error. */
    private const TOLERANCE_NODE = 8.0;

    /** Margin of the eccentricity vector, which is where the perihelion and the aphelion come from. */
    private const TOLERANCE_VECTOR = 1.0e-5;

    /** Margin of the three anomalies against the JPL, in degrees. See the test that compares them. */
    private const TOLERANCE_ANOMALY = 0.03;

    /**
     * The osculating elements published by JPL Horizons, heliocentric and in the J2000
     * ecliptic. Each row: julian day TDB, eccentricity, perihelion distance, inclination,
     * longitude of the ascending node, argument of perihelion and semi-major axis. That is
     * `EC`, `QR`, `IN`, `OM`, `W` and `A`, in the order it prints them.
     *
     * @return array<string, list<array{float, float, float, float, float, float, float}>>
     */
    public static function jplElements(): array
    {
        return [
        // Mercury Barycenter (199)
        'mercury' => [
            [2341970.0, 2.055692170635645E-01, 3.075226938044783E-01, 7.022765667327571E+00, 4.870648014436924E+01, 2.827747778447274E+01, 3.870981593484954E-01], // 1699-Dec-29
            [2451545.0, 2.056302933705567E-01, 3.074990932495130E-01, 7.005014303275355E+00, 4.833053855197922E+01, 2.912428165737838E+01, 3.870982121841597E-01], // 2000-Jan-01
            [2561120.0, 2.056850670522511E-01, 3.074780541203552E-01, 6.987086381565266E+00, 4.795363772228961E+01, 2.997865833967498E+01, 3.870984182297646E-01], // 2300-Jan-03
        ],
        // Venus Barycenter (299)
        'venus' => [
            [2341970.0, 6.930146352039281E-03, 7.183207964547763E-01, 3.396878290669167E+00, 7.751443106503140E+01, 5.375805582782174E+01, 7.233336041931830E-01], // 1699-Dec-29
            [2451545.0, 6.755786251885615E-03, 7.184402853673652E-01, 3.394589649080540E+00, 7.667837411646899E+01, 5.518596702940252E+01, 7.233269274796510E-01], // 2000-Jan-01
            [2561120.0, 6.613292488821962E-03, 7.185412055071299E-01, 3.391674184592509E+00, 7.584701273590866E+01, 5.594952034782933E+01, 7.233247637341117E-01], // 2300-Jan-03
        ],
        // Mars Barycenter (4)
        'mars' => [
            [2341970.0, 9.311435526695679E-02, 1.381758355373172E+00, 1.873943060918690E+00, 5.043972384693818E+01, 2.843247935954943E+02, 1.523630199020204E+00], // 1699-Dec-29
            [2451545.0, 9.331510156051126E-02, 1.381496732386509E+00, 1.849876455662937E+00, 4.956200645402956E+01, 2.865373814058887E+02, 1.523678992298457E+00], // 2000-Jan-01
            [2561120.0, 9.367909237781215E-02, 1.380977537864444E+00, 1.824978134037312E+00, 4.866284120184839E+01, 2.887486458860556E+02, 1.523718063050712E+00], // 2300-Jan-03
        ],
        // Jupiter Barycenter (5)
        'jupiter' => [
            [2341970.0, 4.899032457211936E-02, 4.947089623331130E+00, 1.309348040221964E+00, 9.988860380407658E+01, 2.742702777706219E+02, 5.201934061401976E+00], // 1699-Dec-29
            [2451545.0, 4.877487762533089E-02, 4.950429162680248E+00, 1.304625372771513E+00, 1.004916213525931E+02, 2.750660120162461E+02, 5.204266630723374E+00], // 2000-Jan-01
            [2561120.0, 4.894278613255685E-02, 4.948876953767113E+00, 1.296426633337931E+00, 1.010359105478868E+02, 2.743035652058956E+02, 5.203553352634450E+00], // 2300-Jan-03
        ],
        // Saturn Barycenter (6)
        'saturn' => [
            [2341970.0, 5.411718971838404E-02, 9.060944235740454E+00, 2.480765912167978E+00, 1.144735914007501E+02, 3.363320861438588E+02, 9.579351836452927E+00], // 1699-Dec-29
            [2451545.0, 5.572339465748773E-02, 9.048074649621714E+00, 2.485250440984045E+00, 1.136429621251987E+02, 3.360136248888934E+02, 9.582017174236523E+00], // 2000-Jan-01
            [2561120.0, 5.826941569091014E-02, 9.027811835504956E+00, 2.497326385412481E+00, 1.128467362175745E+02, 3.431718944272238E+02, 9.586406118612260E+00], // 2300-Jan-03
        ],
        // Uranus Barycenter (7)
        'uranus' => [
            [2341970.0, 4.510575621687151E-02, 1.826049323096001E+01, 7.773298493880171E-01, 7.368974157886478E+01, 9.946674630301243E+01, 1.912305299759169E+01], // 1699-Dec-29
            [2451545.0, 4.440552558427553E-02, 1.837552425376382E+01, 7.725675263741457E-01, 7.398941630128760E+01, 9.654166904616822E+01, 1.922941660477798E+01], // 2000-Jan-01
            [2561120.0, 5.355854257979099E-02, 1.827897037988134E+01, 7.694132832788720E-01, 7.426684886660617E+01, 9.471792947977212E+01, 1.931336612166778E+01], // 2300-Jan-03
        ],
        // Neptune Barycenter (8)
        'neptune' => [
            [2341970.0, 1.015733408703130E-02, 2.985644556369810E+01, 1.768993776331660E+00, 1.317837449135702E+02, 2.534707186835340E+02, 3.016281939732351E+01], // 1699-Dec-29
            [2451545.0, 1.121514293584095E-02, 2.976602302815446E+01, 1.767986348573780E+00, 1.317938660576663E+02, 2.656489453998150E+02, 3.010363964971507E+01], // 2000-Jan-01
            [2561120.0, 8.489003399038254E-03, 2.981909687554914E+01, 1.771450841175137E+00, 1.317355449710210E+02, 2.883657165978495E+02, 3.007439854703898E+01], // 2300-Jan-03
        ],
        // Pluto Barycenter (9)
        'pluto' => [
            [2341970.0, 2.491419542136336E-01, 2.951435295683555E+01, 1.718185153250399E+01, 1.103958719287385E+02, 1.146712249722438E+02, 3.930750042895985E+01], // 1699-Dec-29
            [2451545.0, 2.446745123195729E-01, 2.965735480828985E+01, 1.715136439626299E+01, 1.102869297417880E+02, 1.137629024885200E+02, 3.926433741745738E+01], // 2000-Jan-01
            [2561120.0, 2.500796674776525E-01, 2.947993175019542E+01, 1.717065391278590E+01, 1.104025170396207E+02, 1.126074292725212E+02, 3.931075138480384E+01], // 2300-Jan-03
        ],
        // 2060 Chiron (1977 UB)
        'chiron' => [
            [2341970.0, 3.699080071174158E-01, 8.350271572515997E+00, 7.007005481137385E+00, 2.112962230439474E+02, 3.355548728186570E+02, 1.325246419068850E+01], // 1699-Dec-29
            [2451545.0, 3.793438806545154E-01, 8.444071784279837E+00, 6.941566474839556E+00, 2.093966703094871E+02, 3.391420137427825E+02, 1.360507295599465E+01], // 2000-Jan-01
            [2561120.0, 3.642709827097221E-01, 8.360896103730406E+00, 7.183599405854787E+00, 2.048505528621152E+02, 3.445344689561102E+02, 1.315166663206246E+01], // 2300-Jan-03
        ],
        // 1 Ceres (A801 AA)
        'ceres' => [
            [2341970.0, 8.072579502401411E-02, 2.543506397740286E+00, 1.063606907376600E+01, 8.501266935537073E+01, 6.305382534896318E+01, 2.766863666980333E+00], // 1699-Dec-29
            [2451545.0, 7.837562652021973E-02, 2.549670161121415E+00, 1.058336045800169E+01, 8.049435747424498E+01, 7.392286265724256E+01, 2.766496019950750E+00], // 2000-Jan-01
            [2561120.0, 7.521008536905864E-02, 2.558511903577859E+00, 1.055146938491871E+01, 7.605448295229569E+01, 8.087826339886728E+01, 2.766587160067476E+00], // 2300-Jan-03
        ],
        // 5145 Pholus (1992 AD)
        'pholus' => [
            [2341970.0, 5.677221630449580E-01, 8.759065212921822E+00, 2.461554711998994E+01, 1.195357589169218E+02, 3.541131947983115E+02, 2.026258221939051E+01], // 1699-Dec-29
            [2451545.0, 5.724788137239305E-01, 8.652647406743807E+00, 2.469853519219395E+01, 1.193246008770351E+02, 3.544986568584763E+02, 2.023910787232052E+01], // 2000-Jan-01
            [2561120.0, 5.738844920816967E-01, 8.635710636486117E+00, 2.477186568895384E+01, 1.190500226721635E+02, 3.548594708209507E+02, 2.026612614657947E+01], // 2300-Jan-03
        ],
        // 2 Pallas (A802 FA)
        'pallas' => [
            [2341970.0, 2.491391201721710E-01, 2.079584336709428E+00, 3.444875427020846E+01, 1.763351493335537E+02, 3.079771435236797E+02, 2.769600058517195E+00], // 1699-Dec-29
            [2451545.0, 2.296435322899423E-01, 2.135676549255380E+00, 3.484614003653969E+01, 1.731977991334021E+02, 3.102656378876271E+02, 2.772322475079412E+00], // 2000-Jan-01
            [2561120.0, 2.189311780419401E-01, 2.162686943523171E+00, 3.527288834328313E+01, 1.701643481555217E+02, 3.131616629633301E+02, 2.768881413166046E+00], // 2300-Jan-03
        ],
        // 3 Juno (A804 RA)
        'juno' => [
            [2341970.0, 2.553235977090349E-01, 1.987330389757970E+00, 1.305377971755794E+01, 1.756467479930908E+02, 2.396983535005732E+02, 2.668716752194688E+00], // 1699-Dec-29
            [2451545.0, 2.584434726215207E-01, 1.978498696632367E+00, 1.296742544311105E+01, 1.701725855257129E+02, 2.480317243503198E+02, 2.668034901704225E+00], // 2000-Jan-01
            [2561120.0, 2.601439729988526E-01, 1.973077639012422E+00, 1.293279341042389E+01, 1.644720414483908E+02, 2.562513612214718E+02, 2.666839989139349E+00], // 2300-Jan-03
        ],
        // 4 Vesta (A807 FA)
        'vesta' => [
            [2341970.0, 8.819417146824704E-02, 2.152867852052832E+00, 7.116043900481204E+00, 1.066983931436449E+02, 1.432933593054644E+02, 2.361103411150064E+00], // 1699-Dec-29
            [2451545.0, 9.002244548533939E-02, 2.148943784822273E+00, 7.133935828282940E+00, 1.039514370848381E+02, 1.495866678127387E+02, 2.361534934747286E+00], // 2000-Jan-01
            [2561120.0, 9.125818316102349E-02, 2.145859581718528E+00, 7.154256650284077E+00, 1.012606775442566E+02, 1.564123667134145E+02, 2.361352302662617E+00], // 2300-Jan-03
        ],
        ];
    }

    /**
     * Where to start looking for a crossing or an apsis and what step to sweep with, per body.
     *
     * The step is set from the period and not from days: latitude and distance are almost
     * sinusoids with the period of the orbit, so a step of one hundredth of a revolution would
     * not fit a whole crossing between two samples. With half a day for all of them, Pluto
     * would need ninety thousand evaluations to find its own.
     *
     * Four of them start in 1700 on purpose: if the node were calculated in the J2000 ecliptic
     * and then precessed, instead of being cut against that of the date, Jupiter would be off
     * by 1.65 degrees there and nothing would show it in the year 2000.
     *
     * @return array<string, array{float, float}>
     */
    public static function whereToSearch(): array
    {
        return [
            'mercury' => [2341970.0, 5.0],
            'venus' => [2341970.0, 8.0],
            'mars' => [2341970.0, 20.0],
            'jupiter' => [2341970.0, 60.0],
            'saturn' => [2451545.0, 150.0],
            'neptune' => [2451545.0, 800.0],
            'pluto' => [2451545.0, 1200.0],
            'chiron' => [2451545.0, 200.0],
            'ceres' => [2451545.0, 20.0],
            'pholus' => [2451545.0, 400.0],
        ];
    }

    /**
     * The ascending node is the longitude of the body when its latitude crosses zero going
     * north, and the descending node when it crosses going south. The distance of each node
     * is whatever distance the body has to the Sun at that moment.
     *
     * It comes out to hundredths of an arcsecond, and the little that is left over has a
     * name: `heliocentric` returns the body where it was when its light left, that is five
     * hours earlier in Pluto, while the node asked of `NodesAndApsides` is the one for the
     * instant that is asked for. Pluto's OSCULATING node moves 46.6 arcseconds a year, so in
     * those five hours it moves three hundredths of a second, which is exactly what is left
     * over.
     */
    public function test_the_node_is_the_longitude_of_the_body_when_it_crosses_the_ecliptic(): void
    {
        foreach (self::whereToSearch() as $name => [$from, $step]) {
            $body = Body::from($name);

            foreach ([true, false] as $towardNorth) {
                $instant = $this->latitudeCrossing($body, $from, $step, $towardNorth);

                $actual = Ephemeris::heliocentric($body, $instant);
                $orbit = NodesAndApsides::of($body, $instant);

                $node = $towardNorth ? $orbit->ascendingNode : $orbit->descendingNode();
                $distance = $towardNorth
                    ? $orbit->ascendingNodeDistance
                    : $orbit->descendingNodeDistance;

                $this->assertLessThan(
                    0.1,
                    $this->differenceInArcSeconds($node, $actual->longitude),
                    sprintf('The node of %s does not fall where its orbit crosses the ecliptic', $body->name())
                );

                $this->assertLessThan(
                    1.0e-7,
                    abs($distance - $actual->distance),
                    sprintf('The distance of the node of %s is not the one it has there', $body->name())
                );
            }
        }
    }

    /**
     * The perihelion is the longitude of the body at the instant it passes closest to the
     * Sun, and its distance, whatever distance it has there. It is the same check as the
     * node's and with the same logic: at the apsis the velocity is perpendicular to the
     * radius, so the eccentricity vector points at the body itself.
     *
     * What gets compared is the angle between the two directions MULTIPLIED by the
     * eccentricity, which is the only thing that can be demanded equally of all ten: in
     * Neptune, with 0.011, the same vector error reads as 64 arcseconds, and in Mercury, with
     * 0.21, as three thousandths.
     */
    public function test_the_perihelion_is_the_longitude_of_the_body_where_it_passes_closest(): void
    {
        foreach (self::whereToSearch() as $name => [$from, $step]) {
            $body = Body::from($name);
            $instant = $this->minimumDistance($body, $from, $step);

            $actual = Ephemeris::heliocentric($body, $instant);
            $orbit = NodesAndApsides::of($body, $instant);

            $angle = deg2rad($this->differenceInArcSeconds($orbit->perihelion, $actual->longitude) / 3600);
            $inLatitude = deg2rad(abs($orbit->perihelionLatitude - $actual->latitude));

            $this->assertLessThan(
                self::TOLERANCE_VECTOR,
                $orbit->eccentricity * sqrt($angle ** 2 + $inLatitude ** 2),
                sprintf('The perihelion of %s does not fall where it passes closest to the Sun', $body->name())
            );

            $this->assertLessThan(
                1.0e-7,
                abs($orbit->perihelionDistance - $actual->distance),
                sprintf('The distance of the perihelion of %s is not the one it has there', $body->name())
            );
        }
    }

    /**
     * Against JPL Horizons's osculating elements, fourteen bodies and three epochs.
     */
    public function test_the_elements_agree_with_the_jpls(): void
    {
        foreach (self::jplElements() as $name => $cases) {
            $body = Body::from($name);

            foreach ($cases as [$jd, $eccentricity, $perihelion, $inclination, $node, $argument, $semiMajorAxis]) {
                $orbit = NodesAndApsides::of($body, $jd);
                $theirs = $this->fromJ2000ToDate($inclination, $node, $argument, $jd);

                $where = sprintf('%s at julian day %.1f', $body->name(), $jd);

                $this->assertLessThan(
                    $body->isTabulated() ? self::TOLERANCE_NODE_TABULATED : self::TOLERANCE_NODE,
                    $this->differenceInArcSeconds($orbit->ascendingNode, $theirs['node']),
                    'Node of '.$where
                );

                // The error of the eccentricity VECTOR: how far its modulus is off and how
                // far its direction is off, the latter weighted by the modulus itself.
                $this->assertLessThan(
                    self::TOLERANCE_VECTOR,
                    sqrt(
                        ($orbit->eccentricity - $eccentricity) ** 2
                        + ($eccentricity * $this->angleBetween($this->perihelionDirection($orbit), $theirs['perihelion'])) ** 2
                    ),
                    'Eccentricity vector of '.$where
                );

                $this->assertLessThan(1.0e-5, abs($orbit->eccentricity - $eccentricity), 'Eccentricity of '.$where);
                $this->assertLessThan(0.2, abs($orbit->inclination - $theirs['inclination']) * 3600, 'Inclination of '.$where);
                $this->assertLessThan(2.5e-4, abs($orbit->semiMajorAxis - $semiMajorAxis), 'Semi-major axis of '.$where);
                $this->assertLessThan(5.0e-4, abs($orbit->perihelionDistance - $perihelion), 'Perihelion distance of '.$where);
                $this->assertLessThan(5.0e-4, abs($orbit->aphelionDistance - $semiMajorAxis * (1 + $eccentricity)), 'Aphelion distance of '.$where);
            }
        }
    }

    /**
     * The five cases where this does not exist throw instead of returning a number.
     *
     * That is the rule of this engine: a made up value that looks good is worse than an
     * error. It hurts more here than elsewhere because **all five would return a perfectly
     * believable number**, with its degrees and its sign, and nobody would catch it by
     * looking at a wheel.
     */
    public function test_bodies_with_no_orbit_around_the_sun_throw(): void
    {
        $noOrbit = [
            Body::Sun,           // it is the origin of the frame
            Body::Earth,         // the ecliptic IS its orbit
            Body::Moon,          // its own are geocentric and live in LunarPoints
            Body::TrueNode,      // it is already an element of another orbit
            Body::MeanLilith,
            Body::Selena,        // fictitious body that orbits the Earth
            Body::Waldemath,
        ];

        foreach ($noOrbit as $body) {
            try {
                NodesAndApsides::of($body, 2451545.0);
                $this->fail($body->name().' should throw: it has no orbit around the Sun');
            } catch (LogicException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }

        // And the ones that do have one do not throw. The seventeen fictitious bodies that
        // orbit the Sun go in too: Nibiru has an eccentricity of 0.98 and a retrograde orbit,
        // which is where a badly written Kepler solver or arctangent would return a NAN.
        $withOrbit = [Body::Mercury, Body::Pluto, Body::Chiron];

        foreach (Body::fictitious() as $fictitious) {
            if (! FictitiousBodies::isGeocentric($fictitious)) {
                $withOrbit[] = $fictitious;
            }
        }

        foreach ($withOrbit as $body) {
            $orbit = NodesAndApsides::of($body, 2451545.0);

            $this->assertGreaterThan(0.0, $orbit->semiMajorAxis, $body->name());
            $this->assertTrue(
                is_finite($orbit->ascendingNode + $orbit->perihelion + $orbit->descendingNodeDistance),
                $body->name().' returns something that is not a number'
            );
        }
    }

    /**
     * The descending node is opposite the ascending one and the aphelion opposite the
     * perihelion, with the latitude flipped in sign. The DISTANCES are not, and that is the
     * asymmetry that has to be respected: the Sun sits at a focus of the ellipse, not at the
     * center, so one node falls farther away than the other. Measured in Pluto in the year
     * 2000: 40.95 astronomical units for the ascending one and 33.60 for the descending one.
     */
    public function test_the_opposite_points_are_derived_and_the_distances_are_not(): void
    {
        $orbit = NodesAndApsides::of(Body::Pluto, 2451545.0);

        $this->assertEqualsWithDelta(180.0, $this->differenceInArcSeconds($orbit->descendingNode(), $orbit->ascendingNode) / 3600, 1e-9);
        $this->assertEqualsWithDelta(180.0, $this->differenceInArcSeconds($orbit->aphelion(), $orbit->perihelion) / 3600, 1e-9);
        $this->assertEqualsWithDelta(-$orbit->perihelionLatitude, $orbit->aphelionLatitude(), 1e-12);

        $this->assertEqualsWithDelta(40.95, $orbit->ascendingNodeDistance, 0.01);
        $this->assertEqualsWithDelta(33.60, $orbit->descendingNodeDistance, 0.01);

        // And both are distances of the ellipse itself: between the perihelion and the aphelion.
        foreach ([$orbit->ascendingNodeDistance, $orbit->descendingNodeDistance] as $distance) {
            $this->assertGreaterThanOrEqual($orbit->perihelionDistance, $distance);
            $this->assertLessThanOrEqual($orbit->aphelionDistance, $distance);
        }
    }

    /**
     * The perihelion longitude of the textbooks is not the ecliptic longitude of the
     * perihelion.
     *
     * ϖ is a broken angle, measured half over the ecliptic and half over the plane of the
     * orbit; the ecliptic longitude is where the point is seen. They pull apart as much as
     * the orbit is tilted, and in Pallas, which runs at 35 degrees, that is five and a half
     * degrees: enough to change zodiac sign with no error at all.
     */
    public function test_the_longitude_of_the_perihelion_is_not_the_ecliptic_longitude_of_the_perihelion(): void
    {
        $pallas = NodesAndApsides::of(Body::Pallas, 2451545.0);
        $saturn = NodesAndApsides::of(Body::Saturn, 2451545.0);

        $this->assertEqualsWithDelta(5.64, $this->differenceInArcSeconds($pallas->perihelion, $pallas->perihelionLongitude()) / 3600, 0.01);
        $this->assertEqualsWithDelta(0.02, $this->differenceInArcSeconds($saturn->perihelion, $saturn->perihelionLongitude()) / 3600, 0.01);

        // And ϖ really is the node plus the argument, so retracing the path lands back on the
        // same point: turning the perihelion from the node within the plane of the orbit.
        foreach ([$pallas, $saturn] as $orbit) {
            $inclination = deg2rad($orbit->inclination);
            $argument = deg2rad($orbit->perihelionLongitude() - $orbit->ascendingNode);
            $node = deg2rad($orbit->ascendingNode);

            $rebuilt = [
                cos($node) * cos($argument) - sin($node) * sin($argument) * cos($inclination),
                sin($node) * cos($argument) + cos($node) * sin($argument) * cos($inclination),
                sin($argument) * sin($inclination),
            ];

            $this->assertLessThan(1.0e-9, $this->angleBetween($rebuilt, $this->perihelionDirection($orbit)));
        }
    }

    /**
     * And the anomalies, the mean motion, the period and the perihelion passage from that
     * same Horizons and from the same requests. Each row: julian day TDB, `Tp`, `N`, `MA`,
     * `TA` and `PR`, in the order it prints them.
     *
     * These go in a separate table and not in the one above so as not to rewrite that one,
     * whose docblock explains its own column order. They are the same requests and the same
     * dates.
     *
     * **`PR` comes in DAYS because the request carries `OUT_UNITS='AU-D'`**, and that
     * sidesteps in one stroke the question of how many days the year each program uses has:
     * Swiss returns its two periods in years and they have to be multiplied by 365.242727 to
     * get back to days, which is neither the tropical year nor the julian one nor the
     * sidereal one.
     *
     * @return array<string, list<array{float, float, float, float, float, float}>>
     */
    public static function jplAnomalies(): array
    {
        return [
        // Mercury Barycenter (1)
        'mercury' => [
            [2341970.0, 2341980.448342160787, 4.092346991364696E+00, 3.172417583930517E+02, 2.979519591994157E+02, 8.796908003149287E+01], // 1699-Dec-29
            [2451545.0, 2451502.287121773232, 4.092346153508083E+00, 1.747958829169579E+02, 1.764950862746721E+02, 8.796909804206008E+01], // 2000-Jan-01
            [2561120.0, 2561112.096667430829, 4.092342886083883E+00, 3.234314681607053E+01, 4.819232903935082E+01, 8.796916827868682E+01], // 2300-Jan-03
        ],
        // Venus Barycenter (2)
        'venus' => [
            [2341970.0, 2341859.360109434929, 1.602125905584785E+00, 1.772590348652820E+02, 1.772966850626917E+02, 2.247014412195013E+02], // 1699-Dec-29
            [2451545.0, 2451513.720262355171, 1.602148088418008E+00, 5.011477187404461E+01, 5.071202823960058E+01, 2.246983300747629E+02], // 2000-Jan-01
            [2561120.0, 2561167.664647121448, 1.602155277394964E+00, 2.836338340691037E+02, 2.828959432950006E+02, 2.246973218384579E+02], // 2300-Jan-03
        ],
        // Mars Barycenter (4)
        'mars' => [
            [2341970.0, 2342275.736229208298, 5.240645534601491E-01, 1.997744795632861E+02, 1.965238442320712E+02, 6.869382743463399E+02], // 1699-Dec-29
            [2451545.0, 2451508.062921958044, 5.240393802218966E-01, 1.935648348442421E+01, 2.333319752933276E+01, 6.869712727458829E+02], // 2000-Jan-01
            [2561120.0, 2561427.386756672524, 5.240192244443531E-01, 1.989234301639301E+02, 1.957919551155416E+02, 6.869976962805670E+02], // 2300-Jan-03
        ],
        // Jupiter Barycenter (5)
        'jupiter' => [
            [2341970.0, 2342983.636801110581, 8.311205891170731E-02, 2.757545584710195E+02, 2.701434609024442E+02, 4.331501405619609E+03], // 1699-Dec-29
            [2451545.0, 2451318.424855788704, 8.305618852833847E-02, 1.881846789343971E+01, 2.073114435871774E+01, 4.334415127623744E+03], // 2000-Jan-01
            [2561120.0, 2559635.254994330462, 8.307326652836744E-02, 1.233426175826529E+02, 1.278705839520098E+02, 4.333524069106744E+03], // 2300-Jan-03
        ],
        // Saturn Barycenter (6)
        'saturn' => [
            [2341970.0, 2345213.769055673387, 3.324772681306421E-02, 2.521520525923063E+02, 2.463788834604325E+02, 1.082780792876772E+04], // 1699-Dec-29
            [2451545.0, 2452738.125163387042, 3.323385547602711E-02, 3.203478507551869E+02, 3.160468724359260E+02, 1.083232730128715E+04], // 2000-Jan-01
            [2561120.0, 2560476.590618277900, 3.321103490410748E-02, 2.136829143400855E+01, 2.397675884020900E+01, 1.083977060755417E+04], // 2300-Jan-03
        ],
        // Uranus Barycenter (7)
        'uranus' => [
            [2341970.0, 2347516.293739159126, 1.178630599234982E-02, 2.946296848668161E+02, 2.898239069199307E+02, 3.054392107532815E+04], // 1699-Dec-29
            [2451545.0, 2439314.696290402673, 1.168865101225252E-02, 1.429557518353417E+02, 1.458896911494298E+02, 3.079910586967078E+04], // 2000-Jan-01
            [2561120.0, 2561991.774402591400, 1.161252320129129E-02, 3.498764995236170E+02, 3.487219331460461E+02, 3.100101448752918E+04], // 2300-Jan-03
        ],
        // Neptune Barycenter (8)
        'neptune' => [
            [2341970.0, 2344668.434332511853, 5.949868387055906E-03, 3.439446708704426E+02, 3.436187914312297E+02, 6.050554005248073E+04], // 1699-Dec-29
            [2451545.0, 2467001.301761686802, 5.967421980645073E-03, 2.677657251278258E+02, 2.664823502282389E+02, 6.032755872931988E+04], // 2000-Jan-01
            [2561120.0, 2531038.012755120639, 5.976127212430094E-03, 1.797737825780974E+02, 1.797775829458269E+02, 6.023968152003443E+04], // 2300-Jan-03
        ],
        // Pluto Barycenter (9)
        'pluto' => [
            [2341970.0, 2357395.172852694523, 3.999364006692046E-03, 2.983091188959295E+02, 2.700635613685360E+02, 9.001431212503290E+04], // 1699-Dec-29
            [2451545.0, 2447794.771048720460, 4.005960528060211E-03, 1.502326915001420E+01, 2.521019035239334E+01, 8.986608766570179E+04], // 2000-Jan-01
            [2561120.0, 2538238.661254433915, 3.998867902451334E-03, 9.149945107475973E+01, 1.188421996104049E+02, 9.002547940613829E+04], // 2300-Jan-03
        ],
        // 2060 Chiron
        'chiron' => [
            [2341970.0, 2342148.291978943162, 2.042956478158711E-02, 3.563575724661423E+02, 3.514931558496987E+02, 1.762152076408711E+04], // 1699-Dec-29
            [2451545.0, 2450119.516601655632, 1.964051003477336E-02, 2.799722098958118E+01, 6.052655600142104E+01, 1.832946289900939E+04], // 2000-Jan-01
            [2561120.0, 2558590.237718044780, 2.066487990486573E-02, 5.227723374446803E+01, 9.371963073840885E+01, 1.742086098043254E+04], // 2300-Jan-03
        ],
        // 1 Ceres
        'ceres' => [
            [2341970.0, 2342183.556609996594, 2.141521471920753E-01, 3.142663934221281E+02, 3.071602528227942E+02, 1.681047819133526E+03], // 1699-Dec-29
            [2451545.0, 2451516.163382899947, 2.141948374796613E-01, 6.176654513180486E+00, 7.246681675032618E+00, 1.680712776442072E+03], // 2000-Jan-01
            [2561120.0, 2560829.665646979585, 2.141842531850500E-01, 6.218504657566650E+01, 7.013107807019317E+01, 1.680795831843757E+03], // 2300-Jan-03
        ],
        // 5145 Pholus
        'pholus' => [
            [2341970.0, 2348847.346503807232, 1.080592350263959E-02, 2.856839197787149E+02, 2.234337504662398E+02, 3.331506093968386E+04], // 1699-Dec-29
            [2451545.0, 2448519.953459843993, 1.082472884219119E-02, 3.274530853219506E+01, 9.764583527162463E+01, 3.325718410578933E+04], // 2000-Jan-01
            [2561120.0, 2548977.613694234286, 1.080308918896943E-02, 1.311752822281055E+02, 1.632498107235378E+02, 3.332380152591728E+04], // 2300-Jan-03
        ],
        // 2 Pallas
        'pallas' => [
            [2341970.0, 2342057.581169300247, 2.138348490212967E-01, 3.412720938855898E+02, 3.284719390603867E+02, 1.683542236673247E+03], // 1699-Dec-29
            [2451545.0, 2451577.969820599072, 2.135199481026089E-01, 3.529602856167207E+02, 3.484836546657795E+02, 1.686025138161793E+03], // 2000-Jan-01
            [2561120.0, 2561104.035491089802, 2.139181036111810E-01, 3.415097471120876E+00, 5.459120712012607E+00, 1.682887020419452E+03], // 2300-Jan-03
        ],
        // 3 Juno
        'juno' => [
            [2341970.0, 2342130.324805042706, 2.260738608511607E-01, 3.237547523337790E+02, 3.011860569988345E+02, 1.592399929140909E+03], // 1699-Dec-29
            [2451545.0, 2452074.408704343718, 2.261605305007831E-01, 2.402686465738877E+02, 2.186287024649828E+02, 1.591789686745334E+03], // 2000-Jan-01
            [2561120.0, 2560424.420811397489, 2.263125488031068E-01, 1.574182990670298E+02, 1.661758748951856E+02, 1.590720452329853E+03], // 2300-Jan-03
        ],
        // 4 Vesta
        'vesta' => [
            [2341970.0, 2341572.774644731078, 2.716636684375909E-01, 1.079116972087213E+02, 1.171721265075514E+02, 1.325167999351751E+03], // 1699-Dec-29
            [2451545.0, 2451614.870837677270, 2.715892101325352E-01, 3.410238343838706E+02, 3.372748235054930E+02, 1.325531304518027E+03], // 2000-Jan-01
            [2561120.0, 2561659.629351280630, 2.716207186860582E-01, 2.134254877810526E+02, 2.081745211223305E+02, 1.325377540201900E+03], // 2300-Jan-03
        ],
        ];
    }

    /**
     * The three anomalies, the mean motion and the period, against JPL Horizons.
     *
     * None of the five depends on the frame, so nothing has to be rotated here: an anomaly is
     * measured from the body's own perihelion and a period is not measured from anywhere.
     * That is the difference from the angles in the table above, which do have to be carried
     * from the J2000 ecliptic to that of the date before comparing.
     *
     * Worst case of the fourteen bodies across the three epochs: **0.018 degrees in the
     * anomalies and 5.9 parts per million in the period**. And the two figures are the same
     * measurement: the period comes from the semi-major axis, which agrees with the JPL's to
     * 1.2e-4 astronomical units, and the anomalies come from the eccentricity vector divided
     * by the eccentricity, which is where the worst case of this class always comes from
     * (Neptune in 2300, with 0.0085).
     */
    public function test_the_anomalies_and_periods_agree_with_the_jpls(): void
    {
        foreach (self::jplAnomalies() as $name => $cases) {
            $body = Body::from($name);

            foreach ($cases as [$jd, $passage, $motion, $mean, $trueAnomaly, $period]) {
                $orbit = NodesAndApsides::of($body, $jd);
                $where = sprintf('%s at %.1f', $body->name(), $jd);

                $this->assertLessThan(
                    self::TOLERANCE_ANOMALY,
                    $this->differenceInArcSeconds($orbit->meanAnomaly, $mean) / 3600,
                    $where.': mean anomaly'
                );

                $this->assertLessThan(
                    self::TOLERANCE_ANOMALY,
                    $this->differenceInArcSeconds($orbit->trueAnomaly, $trueAnomaly) / 3600,
                    $where.': true anomaly'
                );

                $this->assertLessThan(
                    1.0e-5,
                    abs($orbit->dailyMotion - $motion) / $motion,
                    $where.': mean motion'
                );

                $this->assertLessThan(
                    1.0e-5,
                    abs($orbit->siderealPeriod - $period) / $period,
                    $where.': sidereal period'
                );

                /* The perihelion passage is compared IN ANOMALY and not in days, for the same
                   reason the apsis is compared as a vector: the days it is off by are the
                   degrees it is off by divided by the mean motion, so in Neptune three days
                   are two hundredths of a degree and in Mercury they would be twelve
                   revolutions. A tolerance in days would need one per body. */
                $this->assertLessThan(
                    self::TOLERANCE_ANOMALY,
                    $this->daysBetweenPassages($orbit->perihelionPassage, $passage, $orbit->siderealPeriod) * $motion,
                    $where.': perihelion passage'
                );
            }
        }
    }

    /**
     * The perihelion passage that comes back is the LAST one, and Horizons publishes the
     * CLOSEST one. That is worth stating plainly, because comparing the two head on gives
     * half an orbit of difference in half the cases and looks like a big bug.
     *
     * The rule is the same in both places and comes from the mean anomaly: ours is
     * normalized to [0, 360), so the perihelion is always left behind; Horizons picks
     * whichever revolution falls closest to the date asked for, so with the anomaly past 180
     * it moves on to the next one. Swiss does what we do. Measured in Neptune in the year
     * 2000: here 2406674, in Swiss 2406644, and in Horizons 2467001, which is 165 years
     * beyond.
     */
    public function test_the_perihelion_passage_is_the_last_one_and_not_the_closest(): void
    {
        $neptune = NodesAndApsides::of(Body::Neptune, 2451545.0);

        $this->assertGreaterThan(180.0, $neptune->meanAnomaly);
        $this->assertLessThan(2451545.0, $neptune->perihelionPassage);
        $this->assertEqualsWithDelta(2406674.4, $neptune->perihelionPassage, 1.0);

        // And the next one falls after it, a whole revolution past the previous one.
        $this->assertGreaterThan(2451545.0, $neptune->nextPerihelionPassage());
        $this->assertEqualsWithDelta(2467001.3, $neptune->nextPerihelionPassage(), 5.0);

        /* With the anomaly below 180 the two criteria agree, and that is why the bug hides:
           Uranus at the same date comes out the same in all three programs. */
        $uranus = NodesAndApsides::of(Body::Uranus, 2451545.0);

        $this->assertLessThan(180.0, $uranus->meanAnomaly);
        $this->assertEqualsWithDelta(2439314.7, $uranus->perihelionPassage, 1.0);
    }

    /**
     * The three anomalies are the same moment told three different ways, and they are
     * checked through the chain that binds them, not one by one.
     *
     * The mean one comes from the eccentric one through Kepler's equation, and the eccentric
     * one from the true one through the half-angle relation. What closes the chain from
     * outside, and is the only part that cannot come out right by chance, is **the
     * distance**: `a(1 - e*cos E)` has to be the distance to the Sun the ephemeris returns,
     * which has not gone through any of the three formulas.
     */
    public function test_the_three_anomalies_are_the_same_moment_told_three_ways(): void
    {
        foreach (self::jplAnomalies() as $name => $cases) {
            $body = Body::from($name);
            $orbit = NodesAndApsides::of($body, 2451545.0);

            $mean = deg2rad($orbit->meanAnomaly);
            $eccentric = deg2rad($orbit->eccentricAnomaly);
            $trueAnomaly = deg2rad($orbit->trueAnomaly);
            $e = $orbit->eccentricity;

            $this->assertLessThan(
                1.0e-11,
                abs(fmod($eccentric - $e * sin($eccentric) - $mean + 4 * M_PI, 2 * M_PI)),
                $body->name().': Kepler\'s equation does not close'
            );

            $this->assertLessThan(
                1.0e-10,
                abs(tan($trueAnomaly / 2) * sqrt((1 - $e) / (1 + $e)) - tan($eccentric / 2)),
                $body->name().': the eccentric and the true anomaly are not the same'
            );

            /* And the outside check, which is the one that cannot come out right by chance.
               The GEOMETRIC distance has to be asked for: `heliocentric` returns the body
               where it was when the light that now reaches the Sun left it, and in that time
               the distance changes. Without undoing that, Pholus is off by 2.3e-4 astronomical
               units and Mercury by 9.6e-7, and the two figures are EXACTLY its radial velocity
               times its light delay, so the residual says nothing about the elements. Undone,
               it is left at 3.4e-9. */
            $actual = Ephemeris::heliocentric($body, 2451545.0);
            $geometric = Ephemeris::heliocentric($body, 2451545.0 + 0.005775518331 * $actual->distance);

            $this->assertLessThan(
                1.0e-8,
                abs($orbit->semiMajorAxis * (1 - $e * cos($eccentric)) - $geometric->distance),
                $body->name().': the distance the eccentric anomaly gives is not the real one'
            );
        }
    }

    /**
     * The mean longitude is the mean anomaly plus the longitude of the perihelion, that is,
     * the same broken angle as ϖ with one more piece. Against Horizons it is checked ONLY in
     * the year 2000, for the usual reason: its angles are in the J2000 ecliptic and ours in
     * that of the date, so in 1700 and in 2300 the difference would be four degrees of
     * precession. In J2000 the two frames agree except for nutation, which is fourteen
     * arcseconds.
     *
     * **And watch out for Swiss, which is misleading here**: `swe_get_orbital_elements`
     * returns the angles in the J2000 ecliptic ALWAYS, whether the `SEFLG_J2000` flag is set
     * or not. Measured: with the flag and without it the same numbers come out to the last
     * decimal, and against ours they drift 4.19 degrees in 1700 and just as many in 2300,
     * which is exactly three hundred years of precession.
     */
    public function test_the_mean_longitude_is_the_perihelions_plus_the_mean_anomaly(): void
    {
        foreach (self::jplElements() as $name => $cases) {
            $body = Body::from($name);

            foreach ($cases as [$jd, , , , $node, $argument]) {
                $orbit = NodesAndApsides::of($body, $jd);

                $this->assertLessThan(
                    1.0e-9,
                    $this->differenceInArcSeconds(
                        $orbit->meanLongitude,
                        $orbit->perihelionLongitude() + $orbit->meanAnomaly
                    ) / 3600,
                    $body->name().': the mean longitude is not ϖ plus the mean anomaly'
                );

                if ($jd !== 2451545.0) {
                    continue;
                }

                /* The sum of three angles runs past a full turn and `differenceInArcSeconds`
                   only folds one: it has to be normalized before comparing. */
                $fromHorizons = fmod($node + $argument + $orbit->meanAnomaly, 360.0);

                $this->assertLessThan(
                    0.03,
                    $this->differenceInArcSeconds($orbit->meanLongitude, $fromHorizons) / 3600,
                    $body->name().': the mean longitude is not the one from Horizons'
                );
            }
        }
    }

    /**
     * The tropical period gains exactly the precession over the sidereal one, and the check
     * needs no body at all: **it is the same arithmetic that separates the tropical year from
     * the sidereal year**.
     *
     * The difference between the two angular rates has to come out the same for all fourteen
     * bodies, because it is a property of the equinox and not of the orbit. And applied to
     * the published sidereal year, 365.256363 days, it has to give back the published
     * tropical year, 365.242190. If the number were any other, it would show up right there.
     */
    public function test_the_tropical_period_gains_the_precession_over_the_sidereal_one(): void
    {
        $rates = [];

        foreach (self::jplAnomalies() as $name => $cases) {
            $orbit = NodesAndApsides::of(Body::from($name), 2451545.0);

            $this->assertLessThan($orbit->siderealPeriod, $orbit->tropicalPeriod, $name);

            $rates[$name] = 1 / $orbit->tropicalPeriod - 1 / $orbit->siderealPeriod;
        }

        foreach ($rates as $name => $rate) {
            $this->assertEqualsWithDelta(reset($rates), $rate, 1.0e-15, $name);
        }

        $siderealYear = 365.256363;
        $tropicalYear = 1 / (1 / $siderealYear + reset($rates));

        $this->assertEqualsWithDelta(365.242190, $tropicalYear, 1.0e-5);
    }

    /**
     * The synodic period is checked by turning it around: from it and from the sidereal one
     * comes the Earth's year, and that year has to be the same for all fourteen bodies and
     * the same as the one Horizons publishes for the Earth-Moon barycenter on that date,
     * 365.2543856 days.
     *
     * It is a three-story check and that is why it is worth something: it squares the
     * synodic arithmetic, it squares the sidereal period of each body, and it squares the
     * Earth's own orbit, which is the one this work had to fix. With the Earth's center
     * instead of its barycenter with the Moon, this used to come out to 365.50 days.
     */
    public function test_the_synodic_period_gives_back_the_earths_year(): void
    {
        $yearFromHorizons = 365.2543856031558;

        foreach (self::jplAnomalies() as $name => $cases) {
            $orbit = NodesAndApsides::of(Body::from($name), 2451545.0);

            $year = $orbit->siderealPeriod < $yearFromHorizons
                ? 1 / (1 / $orbit->siderealPeriod - 1 / $orbit->synodicPeriod)
                : 1 / (1 / $orbit->siderealPeriod + 1 / $orbit->synodicPeriod);

            $this->assertEqualsWithDelta($yearFromHorizons, $year, 0.01, $name);
        }

        // And as a bonus, the four synodic periods everybody knows.
        foreach (['mercury' => 115.88, 'venus' => 583.92, 'mars' => 779.94, 'jupiter' => 398.88] as $name => $published) {
            $this->assertEqualsWithDelta(
                $published,
                NodesAndApsides::of(Body::from($name), 2451545.0)->synodicPeriod,
                0.02,
                $name
            );
        }
    }

    /**
     * The sidereal period returns the body to where it was, and that is checked with the
     * ephemeris in a frame held still: the heliocentric longitude in J2000 after one full
     * period has to be the same.
     *
     * **This can only be demanded of the inner ones, and that is the lesson.** An osculating
     * period is that of the ellipse the body would trace if the other planets stopped pulling
     * on it, and over a whole revolution that difference builds up. Measured in the year
     * 2000: Mercury is off by 0.0008 degrees, Venus by 0.004 and Mars by 0.017, while Jupiter
     * drifts 0.27, Saturn 2.6 and Pluto 4.7. Saturn's has had a name since the eighteenth
     * century, the great inequality: its 5:2 resonance with Jupiter shifts its osculating
     * semi-major axis enough that its published period, 10,759 days, and the osculating one
     * for this date, 10,832, are a good week apart.
     */
    public function test_the_sidereal_period_returns_the_body_to_where_it_was(): void
    {
        foreach (['mercury' => 0.002, 'venus' => 0.006, 'mars' => 0.02] as $name => $margin) {
            $body = Body::from($name);
            $orbit = NodesAndApsides::of($body, 2451545.0);

            $this->assertLessThan(
                $margin,
                $this->differenceInArcSeconds(
                    $this->longitudeInJ2000($body, 2451545.0 + $orbit->siderealPeriod),
                    $this->longitudeInJ2000($body, 2451545.0)
                ) / 3600,
                $body->name().': it does not come back to where it was after one period'
            );
        }
    }

    /**
     * And the period gives back what the file of the fictitious bodies has published for
     * them, which is a free check from an entirely different family.
     *
     * `resources/astro/fictitious.php` is the engine's only hand-written data file, and of
     * its nineteen bodies there are four whose period Witte published together with the
     * semi-major axis: 262.5 years for Cupido, 360.66 for Hades, 455.64 for Zeus and 521.8
     * for Kronos. None of those numbers is read here: the ellipse is propagated, the
     * velocity is derived and the period comes out of the energy. That they agree to the
     * decimal says at once that the semi-major axes were copied correctly and that the whole
     * chain (state, semi-major axis, third law) closes.
     *
     * Sieggrün's four are left out on purpose, and that was already noted elsewhere: the
     * literature gives them 576, 617, 663 and 740 years where Kepler over their own
     * semi-major axes gives 589, 632, 679 and 765. The published figure is the semi-major
     * axis; the period is a consequence of it.
     *
     * Nibiru is in for a different reason: **its period IS its name**. Sitchin postulated it
     * with a revolution of 3,600 years and an eccentricity of 0.98, which is exactly where a
     * badly written Kepler solver returns anything at all with no error.
     */
    public function test_the_period_gives_back_the_published_one_for_the_bodies_that_do_not_exist(): void
    {
        $published = [
            'cupido' => 262.5,
            'hades' => 360.66,
            'zeus' => 455.64,
            'kronos' => 521.8,
            'nibiru' => 3600.0,
        ];

        foreach ($published as $name => $years) {
            $orbit = NodesAndApsides::of(Body::from($name), 2451545.0);

            $this->assertEqualsWithDelta(
                $years,
                $orbit->siderealPeriod / 365.25,
                0.1,
                $name.': the osculating period is not the one it has published'
            );
        }

        // And Vulcan, which orbits inside Mercury and comes full circle in eighteen days. It
        // is not Vulkanus, which runs out around 77 astronomical units: confusing the two
        // gives no error at all.
        $this->assertEqualsWithDelta(18.7, NodesAndApsides::of(Body::Vulcan, 2451545.0)->siderealPeriod, 0.1);
        $this->assertGreaterThan(200000.0, NodesAndApsides::of(Body::Vulkanus, 2451545.0)->siderealPeriod);
    }

    /**
     * The aphelion and the EMPTY FOCUS Swiss returns, heliocentric. Each row: julian day,
     * longitude and latitude of the aphelion, and longitude, latitude and distance of the
     * focus.
     *
     * Both points are here on purpose and the table reads at a glance: **the two longitudes
     * are the same to the sixth figure**, and so are the two latitudes. That is what shows
     * the empty focus is not a new point in the sky but a new distance along a line that was
     * already there, the major axis.
     *
     * These are `swe_nod_aps` with `NODBIT_OSCU` and with `NODBIT_OSCU|NODBIT_FOPOINT`,
     * **asked for with `SEFLG_HELCTR`**, and that detail is worth an afternoon: by default
     * Swiss returns the apsides in GEOCENTRIC coordinates, and there Neptune's focus comes
     * out 36 degrees from its aphelion because it sits thirty astronomical units closer to
     * us. It looks like a different point and it is the same one seen from here.
     *
     * @return array<string, list<array{float, float, float, float, float, float}>>
     */
    public static function swissFoci(): array
    {
        return [
            'mars' => [
                [2341970.0, 150.582427, 1.801721, 150.582427, 1.801721, 0.283751],
                [2451545.0, 156.103128, 1.773350, 156.103129, 1.773350, 0.284359],
                [2561120.0, 161.610597, 1.739370, 161.610597, 1.739370, 0.285489],
            ],
            'jupiter' => [
                [2341970.0, 189.987263, 1.318173, 189.987264, 1.318173, 0.509666],
                [2451545.0, 195.551751, 1.299533, 195.551752, 1.299533, 0.507692],
                [2561120.0, 199.520749, 1.278639, 199.520750, 1.278639, 0.509352],
            ],
            'saturn' => [
                [2341970.0, 266.653651, 1.034060, 266.653660, 1.034059, 1.037034],
                [2451545.0, 269.659165, 1.010549, 269.659175, 1.010548, 1.068048],
                [2561120.0, 280.231051, 0.684168, 280.231092, 0.684156, 1.116814],
            ],
            'uranus' => [
                [2341970.0, 348.979251, -0.765069, 348.979250, -0.765069, 1.725121],
                [2451545.0, 350.552936, -0.767497, 350.552936, -0.767497, 1.706673],
                [2561120.0, 353.204383, -0.770250, 353.204382, -0.770250, 2.068567],
            ],
            'neptune' => [
                [2341970.0, 201.038074, 1.715110, 201.038075, 1.715110, 0.612242],
                [2451545.0, 217.279715, 1.762454, 217.279716, 1.762454, 0.674658],
                [2561120.0, 244.206362, 1.646405, 244.206365, 1.646405, 0.505944],
            ],
            'pluto' => [
                [2341970.0, 41.878936, -15.601586, 41.878936, -15.601586, 19.588475],
                [2451545.0, 45.022536, -15.657886, 45.022536, -15.657886, 19.216251],
                [2561120.0, 48.144719, -15.785349, 48.144719, -15.785349, 19.665739],
            ],
        ];
    }

    /**
     * The empty focus against Swiss: the direction is that of the aphelion and the distance
     * is `2ae`.
     *
     * The direction is compared like the rest of the apsides in this file, by the ANGLE
     * between the two vectors weighted by the eccentricity, and not in degrees: the apsis of
     * a nearly round orbit is poorly defined and a tolerance in degrees would need one per
     * body.
     *
     * **The distance is compared in RELATIVE terms, and the reason is what has to be
     * understood about this point.** `2ae` amplifies any difference in eccentricity by `2a`:
     * in Neptune, where our `e` and Swiss's are 1.6e-5 apart, that is 60 times more, or almost
     * five thousandths of an astronomical unit. It is not a new error, it is the one the orbit
     * already had seen under a magnifying glass, and that is why it is measured against the
     * size of the orbit itself: in relative terms the worst case drops to 7.6e-5. Whoever
     * wants the fine number should look at the eccentricity, which is where it lives.
     *
     * **And the vector's tolerance is wider here than in the test against the JPL, on
     * purpose: the reference is not the same one.** Horizons's elements are DE440; these are
     * Swiss's, and pyswisseph runs here with no files, that is, with Moshier, which is
     * another analytical series. Against the JPL our eccentricity vector stays at 1e-5, and
     * against Moshier it climbs to **3.2e-5** (Neptune in the year 2000), and that difference
     * is theirs, not ours. It is the same reason the extreme distances further below are
     * compared against Swiss to 2.3e-3 astronomical units, while today's distance, which does
     * not pass through any ellipse, is compared to 1e-4.
     */
    public function test_the_empty_focus_is_in_the_direction_of_the_aphelion_and_at_two_ae(): void
    {
        foreach (self::swissFoci() as $name => $cases) {
            $body = Body::from($name);

            foreach ($cases as [$jd, , , $longitude, $latitude, $distance]) {
                $orbit = NodesAndApsides::of($body, $jd);
                $where = sprintf('%s at julian day %.1f', $body->name(), $jd);

                $theirs = [
                    cos(deg2rad($latitude)) * cos(deg2rad($longitude)),
                    cos(deg2rad($latitude)) * sin(deg2rad($longitude)),
                    sin(deg2rad($latitude)),
                ];

                $ours = [
                    cos(deg2rad($orbit->aphelionLatitude())) * cos(deg2rad($orbit->aphelion())),
                    cos(deg2rad($orbit->aphelionLatitude())) * sin(deg2rad($orbit->aphelion())),
                    sin(deg2rad($orbit->aphelionLatitude())),
                ];

                // Worst measured over the eighteen cases: 3.16e-5, Neptune in the year 2000.
                $this->assertLessThan(
                    5.0e-5,
                    $orbit->eccentricity * $this->angleBetween($ours, $theirs),
                    'The empty focus of '.$where.' does not fall in the direction of the aphelion'
                );

                // And here, 7.58e-5, Neptune in 2300.
                $this->assertLessThan(
                    1.2e-4,
                    abs($orbit->secondFocusDistance() - $distance) / (2 * $orbit->semiMajorAxis),
                    'The distance to the empty focus of '.$where
                );
            }
        }
    }

    /**
     * And the check that asks nothing of anybody, which is the very definition of the
     * ellipse: **from any point of the orbit, the sum of the distances to the two foci is
     * `2a`**.
     *
     * It is done with the ascending NODE on purpose, and not with an apsis: the node's
     * distance comes from the equation of the conic (`p / (1 + e*cos v)`) and the focus's
     * from `2ae`, that is, from two roads that never touch. With an apsis the identity would
     * hold by construction and would say nothing, because `a(1-e) + a(1+e)` is `2a` whatever
     * one writes.
     */
    public function test_the_sum_of_the_distances_to_the_two_foci_is_the_major_axis(): void
    {
        foreach (array_keys(self::swissFoci()) as $name) {
            $body = Body::from($name);
            $orbit = NodesAndApsides::of($body, 2451545.0);

            // The ascending node is on the ecliptic, so its latitude is zero.
            $node = [
                $orbit->ascendingNodeDistance * cos(deg2rad($orbit->ascendingNode)),
                $orbit->ascendingNodeDistance * sin(deg2rad($orbit->ascendingNode)),
                0.0,
            ];

            $focus = [
                $orbit->secondFocusDistance() * cos(deg2rad($orbit->aphelionLatitude())) * cos(deg2rad($orbit->aphelion())),
                $orbit->secondFocusDistance() * cos(deg2rad($orbit->aphelionLatitude())) * sin(deg2rad($orbit->aphelion())),
                $orbit->secondFocusDistance() * sin(deg2rad($orbit->aphelionLatitude())),
            ];

            $toFocus = sqrt(
                ($node[0] - $focus[0]) ** 2 + ($node[1] - $focus[1]) ** 2 + ($node[2] - $focus[2]) ** 2
            );

            $this->assertEqualsWithDelta(
                2 * $orbit->semiMajorAxis,
                $orbit->ascendingNodeDistance + $toFocus,
                1.0e-9,
                $body->name().': the node does not satisfy the definition of the ellipse'
            );

            // And the identity the distance comes from, which is free and fixes the sign.
            $this->assertEqualsWithDelta(
                $orbit->aphelionDistance - $orbit->perihelionDistance,
                $orbit->secondFocusDistance(),
                1.0e-12,
                $body->name()
            );
        }
    }

    /**
     * The closest and the farthest each planet can be from the Earth, against Swiss.
     *
     * What is left over is the ephemeris and not the geometry: pyswisseph runs here with no
     * files, that is, with Moshier, and the difference shows up above all in the extremes of
     * Pluto and Neptune, which are the two it knows worst. Today's distance, which does not
     * depend on any ellipse, agrees much better: 2.8e-7 astronomical units in Jupiter.
     *
     * @return array<string, array{float, float, float}>
     */
    public static function swissDistances(): array
    {
        return [
            // maximum, minimum and current for the year 2000, geocentric and in astronomical units.
            'mercury' => [1.451508845, 0.549032790, 1.415503417],
            'venus' => [1.735900132, 0.264300621, 1.137658217],
            'mars' => [2.676000501, 0.372824945, 1.849611715],
            'jupiter' => [6.457510468, 3.951092439, 4.621163946],
            'saturn' => [11.099785508, 8.064567083, 8.652780409],
            'uranus' => [21.076989044, 17.383174079, 20.727161849],
            'neptune' => [31.434809615, 28.774418269, 31.024503071],
            'pluto' => [49.845773624, 28.687127417, 31.064430516],
        ];
    }

    public function test_the_extreme_distances_agree_with_swiss(): void
    {
        foreach (self::swissDistances() as $name => [$maximum, $minimum, $current]) {
            $body = Body::from($name);
            $distances = NodesAndApsides::distances($body, 2451545.0);

            $this->assertEqualsWithDelta($maximum, $distances->maximum, 3.0e-3, $name.': maximum');
            $this->assertEqualsWithDelta($minimum, $distances->minimum, 2.0e-3, $name.': minimum');
            $this->assertEqualsWithDelta($current, $distances->current, 1.0e-4, $name.': current');

            // And today's falls between the two, which is what they are for.
            $this->assertGreaterThanOrEqual($distances->minimum, $distances->current, $name);
            $this->assertLessThanOrEqual($distances->maximum, $distances->current, $name);
            $this->assertGreaterThanOrEqual(0.0, $distances->fraction(), $name);
            $this->assertLessThanOrEqual(1.0, $distances->fraction(), $name);
        }
    }

    /**
     * The extremes really are the extremes, and it is checked by brute force against a
     * second construction of the two ellipses: this one is built from the published ANGLES
     * (inclination, node and argument of perihelion) and not from the eccentricity vector and
     * the angular momentum, which is where the internal ones come from. So if the angles and
     * the vectors did not describe the same ellipse, it would show up here.
     *
     * The sweep can never beat the refined value, and that is why the check runs one way
     * only: the grid's maximum has to fall below ours and its minimum above, and the two
     * close together. Measured over the eight planets with 720 points per ellipse, the
     * biggest difference is 7.3e-5 astronomical units, in Pluto.
     */
    public function test_the_extreme_distances_are_really_the_extremes(): void
    {
        $earth = $this->earthEllipseByReflection(2451545.0);

        foreach (['mercury', 'mars', 'pluto'] as $name) {
            $body = Body::from($name);
            $orbit = NodesAndApsides::of($body, 2451545.0);
            $distances = NodesAndApsides::distances($body, 2451545.0);

            [$maximum, $minimum] = $this->extremesByBruteForce($this->ellipseFromAngles($orbit), $earth, 360);

            $this->assertLessThanOrEqual($distances->maximum + 1.0e-9, $maximum, $name.': the sweep beats the maximum');
            $this->assertGreaterThanOrEqual($distances->minimum - 1.0e-9, $minimum, $name.': the sweep beats the minimum');

            $this->assertEqualsWithDelta($distances->maximum, $maximum, 1.0e-3, $name.': maximum');
            $this->assertEqualsWithDelta($distances->minimum, $minimum, 1.0e-3, $name.': minimum');
        }
    }

    /**
     * And the maximum is NOT the sum of the two aphelions, which is the quick arithmetic and
     * the one anyone writes without thinking. Two aphelions only add up if they fall in
     * opposite directions, and the apsis lines are wherever they are: in Jupiter in the year
     * 2000 the quick arithmetic gives 6.4748 astronomical units and the right one 6.4575,
     * that is, seventeen thousandths more.
     *
     * It is pinned down because it is an error that always comes out too high, never too low,
     * so no check that today's distance falls inside can ever catch it.
     */
    public function test_the_maximum_is_not_the_sum_of_the_two_aphelions(): void
    {
        $earth = $this->earthEllipseByReflection(2451545.0);
        $earthAphelion = $earth['a'] * (1 + $earth['e']);

        $jupiter = NodesAndApsides::of(Body::Jupiter, 2451545.0);
        $distances = NodesAndApsides::distances(Body::Jupiter, 2451545.0);

        $this->assertEqualsWithDelta(6.4748, $jupiter->aphelionDistance + $earthAphelion, 1.0e-3);
        $this->assertEqualsWithDelta(6.4575, $distances->maximum, 1.0e-3);
        $this->assertLessThan($jupiter->aphelionDistance + $earthAphelion, $distances->maximum);
    }

    /**
     * The extreme distances are refused for the same bodies as the nodes, and for the same
     * reason: with no orbit around the Sun there are no two ellipses to compare. The Moon is
     * the one case where Swiss does answer, with its orbit around the Earth, and here that
     * lives in `LunarPoints` along with everything else that is its own.
     */
    public function test_distances_do_not_exist_for_a_body_with_no_orbit(): void
    {
        foreach ([Body::Sun, Body::Earth, Body::Moon, Body::TrueNode] as $body) {
            try {
                NodesAndApsides::distances($body, 2451545.0);
                $this->fail($body->name().' should throw');
            } catch (LogicException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    /**
     * The instant the heliocentric latitude crosses zero, found with the ephemeris and only
     * with it: a sweep until the sign change is found, then a bisection within that interval.
     *
     * @param Body $body
     * @param float $from
     * @param float $step
     * @param bool $towardNorth
     * @return float
     */
    private function latitudeCrossing(Body $body, float $from, float $step, bool $towardNorth): float
    {
        $latitude = fn (float $jd): float => Ephemeris::heliocentric($body, $jd)->latitude;
        $before = $latitude($from);

        for ($interval = 0; $interval < 400; $interval++) {
            $after = $latitude($from + ($interval + 1) * $step);

            if ($towardNorth ? ($before < 0 && $after >= 0) : ($before > 0 && $after <= 0)) {
                return $this->bisect(
                    fn (float $jd): bool => $towardNorth ? $latitude($jd) < 0 : $latitude($jd) > 0,
                    $from + $interval * $step,
                    $from + ($interval + 1) * $step
                );
            }

            $before = $after;
        }

        throw new RuntimeException($body->name().' does not cross the ecliptic in the searched window');
    }

    /**
     * The instant of minimum distance to the Sun.
     *
     * It is found by the sign change of the SLOPE and not of the value, because a minimum
     * located by comparing values cannot be refined past the square root of the machine's
     * epsilon: near the bottom the curve is flat and two instants fairly far apart give the
     * same distance. It is the same reason `LunarPointsTest` refines its apsides with care.
     *
     * @param Body $body
     * @param float $from
     * @param float $step
     * @return float
     */
    private function minimumDistance(Body $body, float $from, float $step): float
    {
        $delta = max($step / 200, 0.01);
        $slope = fn (float $jd): float => Ephemeris::heliocentric($body, $jd + $delta)->distance
            - Ephemeris::heliocentric($body, $jd - $delta)->distance;

        $before = $slope($from);

        for ($interval = 0; $interval < 400; $interval++) {
            $after = $slope($from + ($interval + 1) * $step);

            if ($before < 0 && $after >= 0) {
                return $this->bisect(
                    fn (float $jd): bool => $slope($jd) < 0,
                    $from + $interval * $step,
                    $from + ($interval + 1) * $step
                );
            }

            $before = $after;
        }

        throw new RuntimeException($body->name().' does not pass through its perihelion in the searched window');
    }

    /**
     * Bisection over a condition that is true at the low end and false at the high end.
     *
     * Eighty rounds is more than what is needed: a julian day is around two and a half
     * million, so the resolution of a float there is about five ten-billionths of a day, and
     * the interval stops shrinking well before that. Extra rounds are left in because they
     * cost nothing: the condition no longer changes value.
     *
     * @param callable(float): bool $condition
     * @param float $low
     * @param float $high
     * @return float
     */
    private function bisect(callable $condition, float $low, float $high): float
    {
        for ($round = 0; $round < 80; $round++) {
            $mid = ($low + $high) / 2;

            if ($condition($mid)) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }

        return ($low + $high) / 2;
    }

    /**
     * Horizons's elements, which are in the J2000 ecliptic, written in the true ecliptic of
     * the date, which is where the engine gives them.
     *
     * The ANGLES are not rotated, the vectors that define the orbit are: the plane's normal,
     * from which the node and the inclination come, and the perihelion direction. Rotating
     * `OM` by adding precession to it would give the node of the J2000 plane carried forward
     * to the date, which is not the crossing with the ecliptic of the date: they are two
     * different planes and their difference is divided by the tangent of the inclination.
     *
     * @param float $inclination
     * @param float $node
     * @param float $argument
     * @param float $jd
     * @return array{node: float, inclination: float, perihelion: array{float, float, float}}
     */
    private function fromJ2000ToDate(float $inclination, float $node, float $argument, float $jd): array
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
     * From the J2000 ecliptic to the true one of the date: precession and nutation, with the
     * same classes the engine uses.
     *
     * @param array{float, float, float} $vector
     * @param float $jd
     * @return array{float, float, float}
     */
    private function toTheDate(array $vector, float $jd): array
    {
        $centuries = Time::centuries($jd);
        $dated = Precession::toDate($vector, $centuries);

        $nutation = Time::nutation($centuries)[0];
        $cosine = cos($nutation);
        $sine = sin($nutation);

        return [
            $cosine * $dated[0] - $sine * $dated[1],
            $sine * $dated[0] + $cosine * $dated[1],
            $dated[2],
        ];
    }

    /**
     * The perihelion's direction as a unit vector, from its longitude and its latitude.
     *
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
     * The angle between two vectors, in radians, by the arctangent of the modulus of the
     * cross product over the dot product. Not by the arccosine: when the two point almost the
     * same way, which is what is expected here, the cosine is close to one and there the
     * arccosine loses half its figures.
     *
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
     * The heliocentric longitude in a frame held STILL, the J2000 one.
     *
     * It is needed to measure a sidereal period: in the ecliptic of the date, which is the
     * one `Ephemeris` works in, a full revolution of the body does not give back the same
     * longitude because the frame has rotated underneath it. That is exactly what separates
     * the tropical period from the sidereal one, and this is measuring the other one.
     *
     * @param Body $body
     * @param float $jd
     * @return float
     */
    private function longitudeInJ2000(Body $body, float $jd): float
    {
        $position = Ephemeris::heliocentric($body, $jd);
        $longitude = deg2rad($position->longitude);
        $latitude = deg2rad($position->latitude);

        $vector = Precession::toJ2000([
            $position->distance * cos($latitude) * cos($longitude),
            $position->distance * cos($latitude) * sin($longitude),
            $position->distance * sin($latitude),
        ], Time::centuries($jd));

        return rad2deg(atan2($vector[1], $vector[0]));
    }

    /**
     * The ellipse of a body built from its ANGLES: semi-major axis, eccentricity,
     * inclination, node and argument of perihelion. It is the textbook construction, and here
     * it is the second opinion: inside `NodesAndApsides` the same two directions come from the
     * eccentricity vector and the angular momentum, without going through any angle at all.
     *
     * @param OsculatingOrbit $orbit
     * @return array{a: float, e: float, p: array{float, float, float}, q: array{float, float, float}}
     */
    private function ellipseFromAngles(OsculatingOrbit $orbit): array
    {
        $inclination = deg2rad($orbit->inclination);
        $node = deg2rad($orbit->ascendingNode);
        $argument = deg2rad($orbit->argumentOfPerihelion);

        return [
            'a' => $orbit->semiMajorAxis,
            'e' => $orbit->eccentricity,
            'p' => [
                cos($node) * cos($argument) - sin($node) * sin($argument) * cos($inclination),
                sin($node) * cos($argument) + cos($node) * sin($argument) * cos($inclination),
                sin($argument) * sin($inclination),
            ],
            'q' => [
                -cos($node) * sin($argument) - sin($node) * cos($argument) * cos($inclination),
                -sin($node) * sin($argument) + cos($node) * cos($argument) * cos($inclination),
                cos($argument) * sin($inclination),
            ],
        ];
    }

    /**
     * The Earth's orbit, which is the only one `NodesAndApsides` will not let anyone ask for
     * head on: its `of()` throws because its nodes on the ecliptic do not exist, and what is
     * needed here is the ellipse, which does. It is pulled out by reflection instead of being
     * rebuilt so that what gets compared really is the same orbit.
     *
     * @param float $jd
     * @return array{a: float, e: float, p: array{float, float, float}, q: array{float, float, float}}
     */
    private function earthEllipseByReflection(float $jd): array
    {
        $ellipse = new ReflectionMethod(NodesAndApsides::class, 'earthEllipse');
        $frame = new ReflectionMethod(NodesAndApsides::class, 'frame');

        return $frame->invoke(null, $ellipse->invoke(null, $jd));
    }

    /**
     * The largest and the smallest distance between two ellipses, found brute force over a
     * grid.
     *
     * @param array{a: float, e: float, p: array{float, float, float}, q: array{float, float, float}} $one
     * @param array{a: float, e: float, p: array{float, float, float}, q: array{float, float, float}} $other
     * @param int $samples
     * @return array{float, float}
     */
    private function extremesByBruteForce(array $one, array $other, int $samples): array
    {
        $ofOne = [];
        $ofOther = [];

        for ($k = 0; $k < $samples; $k++) {
            $ofOne[$k] = $this->pointOnEllipse($one, 2 * M_PI * $k / $samples);
            $ofOther[$k] = $this->pointOnEllipse($other, 2 * M_PI * $k / $samples);
        }

        $maximum = -INF;
        $minimum = INF;

        foreach ($ofOne as $here) {
            foreach ($ofOther as $there) {
                $separation = sqrt(
                    ($here[0] - $there[0]) ** 2 + ($here[1] - $there[1]) ** 2 + ($here[2] - $there[2]) ** 2
                );

                $maximum = max($maximum, $separation);
                $minimum = min($minimum, $separation);
            }
        }

        return [$maximum, $minimum];
    }

    /**
     * @param array{a: float, e: float, p: array{float, float, float}, q: array{float, float, float}} $ellipse
     * @param float $eccentric
     * @return array{float, float, float}
     */
    private function pointOnEllipse(array $ellipse, float $eccentric): array
    {
        $x = $ellipse['a'] * (cos($eccentric) - $ellipse['e']);
        $y = $ellipse['a'] * sqrt(1 - $ellipse['e'] ** 2) * sin($eccentric);

        return [
            $x * $ellipse['p'][0] + $y * $ellipse['q'][0],
            $x * $ellipse['p'][1] + $y * $ellipse['q'][1],
            $x * $ellipse['p'][2] + $y * $ellipse['q'][2],
        ];
    }

    /**
     * How many days two perihelion passages are apart, allowing for the fact that they can
     * be on different revolutions: here the last one is returned and Horizons publishes the
     * closest one.
     *
     * @param float $one
     * @param float $other
     * @param float $period
     * @return float
     */
    private function daysBetweenPassages(float $one, float $other, float $period): float
    {
        $difference = fmod(abs($one - $other), $period);

        return min($difference, $period - $difference);
    }

    /**
     * The difference between two longitudes in arcseconds, folded to the smaller arc and
     * taken as an absolute value. Just subtracting them fails between 359 and 1 degree, which
     * are two degrees apart and not three hundred and fifty eight.
     *
     * @param float $one
     * @param float $other
     * @return float
     */
    private function differenceInArcSeconds(float $one, float $other): float
    {
        return abs(fmod($one - $other + 540, 360) - 180) * 3600;
    }
}
