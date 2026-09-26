<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\Equatorial;
use Astronomy\Horizon;
use Astronomy\Place;
use Astronomy\RiseSet;
use Astronomy\Pass;
use Astronomy\Position;
use Astronomy\Time;
use PHPUnit\Framework\TestCase;

/**
 * The observer and their horizon, against Swiss Ephemeris.
 *
 * The figures are what `swe_calc_ut` with `SEFLG_TOPOCTR | SEFLG_EQUATORIAL` and
 * `swe_azalt` return (Swiss Ephemeris 2.10.03, Moshier ephemeris, 1010 mbar atmosphere and
 * 10 degrees), copied here by hand so the test runs without Swiss installed. What is
 * compared is the topocentric equatorials, which is where the parallax lives, and the
 * altitude and the azimuth, which is where everything else lives.
 *
 * Swiss measures the azimuth from south towards west and here it is measured from north
 * towards east; the figures are in its convention and are converted when compared.
 */
class HorizonTest extends TestCase
{
    /** Arcseconds of margin for the Sun and the planets. */
    private const TOLERANCE = 1.0;

    /**
     * The Moon carries more margin because Swiss with Moshier and we with ELP do not carry
     * the same ephemeris or the same delta T, and on the Moon six seconds of delta T are
     * three arcseconds.
     */
    private const MOON_TOLERANCE = 8.0;

    private const PLACES = [
        'Madrid' => [40.4168, -3.7038],
        'Oslo' => [59.9139, 10.7522],
        'Ushuaia' => [-54.8019, -68.3030],
        'Singapore' => [1.3521, 103.8198],
    ];

    /**
     * [place, jd UT, body, topocentric RA, declination, distance in AU, azimuth from the
     * south, true altitude] according to Swiss.
     */
    private const SWISS = [
        ['Madrid', 2448073.6458333335, 'sun', 99.863077, 23.130215, 1.01664238, 224.290805, -12.416751],
        ['Madrid', 2448073.6458333335, 'moon', 198.567945, -13.989418, 0.0027127, 105.823775, -38.555107],
        ['Madrid', 2448073.6458333335, 'venus', 65.735798, 20.020168, 1.35221251, 249.628146, 7.026168],
        ['Madrid', 2448073.6458333335, 'jupiter', 110.961233, 22.253483, 6.21369348, 215.768894, -18.598446],
        ['Madrid', 2448073.6458333335, 'mars', 21.227996, 6.712328, 1.19250432, 290.218757, 31.705736],
        ['Madrid', 2448073.6458333335, 'saturn', 294.822087, -21.386027, 9.02194325, 32.8164, 21.024192],
        ['Madrid', 2461400.2604166665, 'sun', 274.316162, -23.379617, 0.9835177, 71.784214, -14.912012],
        ['Madrid', 2461400.2604166665, 'moon', 121.434598, 21.78865, 0.00240021, 236.02452, -4.677409],
        ['Madrid', 2461400.2604166665, 'venus', 225.896473, -13.869989, 0.60607958, 114.112742, -45.102019],
        ['Madrid', 2461400.2604166665, 'jupiter', 149.299348, 13.442963, 4.68136016, 218.832828, -27.109163],
        ['Madrid', 2461400.2604166665, 'mars', 161.720981, 11.048198, 0.9644112, 207.217, -34.444824],
        ['Madrid', 2461400.2604166665, 'saturn', 8.446482, 0.943025, 9.28606322, 353.527561, 50.348786],
        ['Oslo', 2448073.6458333335, 'sun', 99.862789, 23.130002, 1.01662785, 235.175645, 7.224692],
        ['Oslo', 2448073.6458333335, 'moon', 198.840276, -14.195468, 0.00271179, 133.541619, -37.018778],
        ['Oslo', 2448073.6458333335, 'venus', 65.735322, 20.019908, 1.35220283, 265.084599, 20.434322],
        ['Oslo', 2448073.6458333335, 'jupiter', 110.961234, 22.253451, 6.2136783, 226.222398, 2.126903],
        ['Oslo', 2448073.6458333335, 'mars', 21.227379, 6.711875, 1.19250591, 314.223536, 29.196947],
        ['Oslo', 2448073.6458333335, 'saturn', 294.822072, -21.386051, 9.02195852, 43.236154, 0.037465],
        ['Oslo', 2461400.2604166665, 'sun', 274.316896, -23.379978, 0.98352627, 89.960909, -27.275119],
        ['Oslo', 2461400.2604166665, 'moon', 121.22731, 21.67276, 0.00238741, 248.443359, 12.656934],
        ['Oslo', 2461400.2604166665, 'venus', 225.897634, -13.87095, 0.6060767, 144.804794, -39.837142],
        ['Oslo', 2461400.2604166665, 'jupiter', 149.299346, 13.44289, 4.68134566, 228.100669, -6.640443],
        ['Oslo', 2461400.2604166665, 'mars', 161.721034, 11.047806, 0.96439676, 217.391416, -13.110935],
        ['Oslo', 2461400.2604166665, 'saturn', 8.44642, 0.942967, 9.28607441, 12.009299, 30.487317],
        ['Ushuaia', 2448073.6458333335, 'sun', 99.861161, 23.133423, 1.01666844, 28.225905, -55.961392],
        ['Ushuaia', 2448073.6458333335, 'moon', 198.633286, -12.854962, 0.002668, 103.716936, 25.072481],
        ['Ushuaia', 2448073.6458333335, 'venus', 65.734636, 20.022385, 1.35225162, 332.312437, -52.80517],
        ['Ushuaia', 2448073.6458333335, 'jupiter', 110.960934, 22.254053, 6.21371308, 43.803609, -51.373814],
        ['Ushuaia', 2448073.6458333335, 'mars', 21.227718, 6.715122, 1.19254231, 289.76592, -21.417716],
        ['Ushuaia', 2448073.6458333335, 'saturn', 294.822282, -21.385621, 9.02192647, 228.208352, 48.995399],
        ['Ushuaia', 2461400.2604166665, 'sun', 274.317605, -23.376788, 0.9834726, 138.738951, 53.336992],
        ['Ushuaia', 2461400.2604166665, 'moon', 120.675298, 23.060945, 0.00243318, 1.667414, -58.251047],
        ['Ushuaia', 2461400.2604166665, 'venus', 225.896337, -13.86499, 0.60603441, 95.079029, 20.591854],
        ['Ushuaia', 2461400.2604166665, 'jupiter', 149.298972, 13.443733, 4.68136956, 40.747485, -42.651817],
        ['Ushuaia', 2461400.2604166665, 'mars', 161.719253, 11.051968, 0.96441163, 53.521875, -35.245057],
        ['Ushuaia', 2461400.2604166665, 'saturn', 8.44656, 0.943406, 9.28608772, 251.820959, 11.283572],
        ['Singapore', 2448073.6458333335, 'sun', 99.862825, 23.132934, 1.0165971, 225.752429, 57.682652],
        ['Singapore', 2448073.6458333335, 'moon', 199.890319, -13.427415, 0.0027099, 285.311548, -33.929451],
        ['Singapore', 2448073.6458333335, 'venus', 65.734132, 20.021926, 1.35217783, 154.130884, 69.138917],
        ['Singapore', 2448073.6458333335, 'jupiter', 110.961424, 22.253917, 6.21364738, 236.255103, 49.485415],
        ['Singapore', 2448073.6458333335, 'mars', 21.225075, 6.713639, 1.19250175, 97.310261, 35.871008],
        ['Singapore', 2448073.6458333335, 'saturn', 294.821888, -21.385738, 9.02198959, 59.628151, -46.567523],
        ['Singapore', 2461400.2604166665, 'sun', 274.318947, -23.377224, 0.9835445, 323.680172, -62.180714],
        ['Singapore', 2461400.2604166665, 'moon', 120.858838, 22.898293, 0.00235781, 201.441037, 66.743676],
        ['Singapore', 2461400.2604166665, 'venus', 225.902378, -13.867684, 0.6060665, 284.560901, -23.660766],
        ['Singapore', 2461400.2604166665, 'jupiter', 149.299569, 13.443447, 4.68130752, 250.092159, 51.018694],
        ['Singapore', 2461400.2604166665, 'mars', 161.72232, 11.050389, 0.96435987, 256.751658, 39.555059],
        ['Singapore', 2461400.2604166665, 'saturn', 8.446124, 0.943186, 9.28610595, 91.291222, -13.369947],
    ];

    /** [true altitude, apparent according to Swiss (TRUE_TO_APP), true according to Swiss (APP_TO_TRUE)]. */
    private const REFRACTION = [
        [-0.5, 0.061463268244547065, -0.5],
        [0.0, 0.48303212307416615, 0.0],
        [0.5, 0.9167318148034549, 0.02161396051666392],
        [1.0, 1.362398113248594, 0.5944448492029872],
        [2.0, 2.282095251807906, 1.6954762025010335],
        [5.0, 5.161235443401907, 4.8362139030185105],
        [10.0, 10.09012801338559, 9.909229243709543],
        [15.0, 15.06124944302901, 14.938935261775784],
        [20.0, 20.044000845308283, 19.955561737880366],
        [30.0, 30.02791911941729, 29.971896879331965],
        [45.0, 45.01616488888889, 44.98402444167462],
        [60.0, 60.00934161288886, 59.99093807838235],
        [89.0, 89.00028255859043, 89.00035294550054],
    ];

    /**
     * What `swe_azalt_rev` returns with `SE_HOR2EQU`: right ascension and declination from
     * the azimuth and the TRUE altitude. Each row: place, Julian day UT, azimuth from the
     * north, true altitude, right ascension and declination.
     *
     * **Swiss is given the azimuth from the SOUTH towards the west**, so these requests had
     * 180 degrees subtracted from them. It is the same convention already noted above and the
     * one `Horizontal::azimuthFromSouth()` exists to serve.
     *
     * @return list<array{0: string, 1: float, 2: float, 3: float, 4: float, 5: float}>
     */
    public static function swissRoundTrips(): array
    {
        return [
            ['Madrid', 2451545.0, 45.0, 30.0, 8.282989003, 52.222931942],
            ['Madrid', 2451545.0, 275.0, 5.0, 187.334294707, 7.042768537],
            ['Madrid', 2460000.25, 130.0, -12.0, 132.243411946, -37.842217169],
            ['Madrid', 2415020.5, 350.0, 70.0, 89.670760735, 59.960868666],
            ['Oslo', 2451545.0, 45.0, 30.0, 45.720486291, 47.698999692],
            ['Oslo', 2451545.0, 275.0, 5.0, 199.394982966, 6.830844641],
            ['Oslo', 2460000.25, 130.0, -12.0, 134.694431899, -29.675609613],
            ['Oslo', 2415020.5, 350.0, 70.0, 92.644449940, 79.094606703],
            ['Ushuaia', 2451545.0, 45.0, 30.0, 249.984125030, -3.187710107],
            ['Ushuaia', 2451545.0, 275.0, 5.0, 129.116227584, -1.213310398],
            ['Ushuaia', 2460000.25, 130.0, -12.0, 126.268649571, -11.099402479],
            ['Ushuaia', 2415020.5, 350.0, 70.0, 27.726952216, -35.011149630],
            ['Singapore', 2451545.0, 45.0, 30.0, 75.873965869, 38.608837498],
            ['Singapore', 2451545.0, 275.0, 5.0, 299.177085348, 5.097824438],
            ['Singapore', 2460000.25, 130.0, -12.0, 272.618077106, -39.306752218],
            ['Singapore', 2415020.5, 350.0, 70.0, 200.359868714, 21.032882194],
        ];
    }

    /**
     * The horizon's return leg, against Swiss. It is `swe_azalt_rev`.
     *
     * **The declination comes out identical and what is left sits in the right ascension**,
     * and that is not chance or a tolerance picked by eye: the declination comes from a spin
     * that only uses the latitude, while the right ascension comes from the local sidereal
     * time. So a sidereal time offset can only show up there.
     *
     * The next test is what backs that up, and is what really closes the argument.
     */
    public function test_the_horizon_return_matches_swiss(): void
    {
        foreach (self::swissRoundTrips() as [$name, $jdUt, $azimuth, $altitude, $ra, $declination]) {
            $horizon = new Horizon($this->place($name));
            $equatorial = $horizon->equatorialFromHorizontal($azimuth, $altitude, $jdUt);

            $where = sprintf('%s at %.2f, azimuth %.0f and altitude %.0f', $name, $jdUt, $azimuth, $altitude);

            $this->assertLessThan(0.3, $this->differenceInSeconds($equatorial->rightAscension, $ra), $where);

            /* The declination matches as far as the table is written: its nine decimal places
               of degree are 3.6 millionths of an arcsecond, and what is measured here is that,
               not a residual of our own. Tightening it further would measure the table's
               rounding. */
            $this->assertLessThan(1.0e-5, abs($equatorial->declination - $declination) * 3600.0, $where);
        }
    }

    /**
     * And what is left against Swiss is EXACTLY our sidereal time minus theirs.
     *
     * It is the strongest check that can be made here, because it does not compare one number
     * against another: it says where the difference comes from. If the return leg had
     * something of its own wrong, the residual would change with the azimuth or with the
     * place, and it does not: **for a given date it is the same at the four places and in the
     * four directions**, as far as Swiss's table is written (nine decimal places of degree,
     * that is, millionths of an arcsecond). Measured: 0.017 arcseconds in the year 2000, 0.048
     * in 2023 and 0.283 in 1900, which are exactly the three sidereal time offsets.
     */
    public function test_what_is_left_of_the_return_is_sidereal_time_and_nothing_else(): void
    {
        $residuals = [];

        foreach (self::swissRoundTrips() as [$name, $jdUt, $azimuth, $altitude, $ra]) {
            $horizon = new Horizon($this->place($name));
            $equatorial = $horizon->equatorialFromHorizontal($azimuth, $altitude, $jdUt);

            $residuals[(string) $jdUt][] = fmod($equatorial->rightAscension - $ra + 540.0, 360.0) - 180.0;
        }

        // The same offset at the four places and the four directions of each date.
        foreach ($residuals as $jdUt => $list) {
            foreach ($list as $residual) {
                $this->assertEqualsWithDelta(reset($list), $residual, 1.0e-5 / 3600.0, 'jd '.$jdUt);
            }
        }

        // And that offset is the sidereal time's, measured against Swiss's on those dates.
        $fromSwiss = [
            '2451545' => 280.457072438,
            '2460000.25' => 64.353159593,
            '2415020.5' => 100.188297522,
        ];

        foreach ($fromSwiss as $jdUt => $sidereal) {
            $ours = Time::apparentSiderealTime((float) $jdUt);

            $this->assertEqualsWithDelta(
                fmod($ours - $sidereal + 540.0, 360.0) - 180.0,
                reset($residuals[$jdUt]),
                1.0e-5 / 3600.0,
                'jd '.$jdUt
            );
        }
    }

    /**
     * The outbound and return legs undo each other, which is what guarantees there is no
     * azimuth convention crossed along the way.
     *
     * It is done at machine precision and with the cases that hurt: an azimuth to the north, a
     * negative altitude and a point almost at the zenith, where the azimuth stops being
     * defined and the arithmetic leans on the clamped sine.
     */
    public function test_the_horizon_round_trip_undoes_itself(): void
    {
        foreach (array_keys(self::PLACES) as $name) {
            $horizon = new Horizon($this->place($name));

            foreach ([2451545.0, 2460000.25, 2415020.5] as $jdUt) {
                foreach ([[0.0, 15.0], [180.0, -30.0], [90.0, 89.9], [270.0, 0.0], [45.0, 60.0]] as [$azimuth, $altitude]) {
                    $equatorial = $horizon->equatorialFromHorizontal($azimuth, $altitude, $jdUt);
                    $back = $horizon->horizontal($equatorial, $jdUt);

                    $where = sprintf('%s, azimuth %.1f and altitude %.1f', $name, $azimuth, $altitude);

                    $this->assertLessThan(1.0e-8, abs($back->altitude - $altitude) * 3600.0, $where);

                    /* The azimuth allows a thousand times less than the altitude, and it is not
                       that the round trip is worse: **pinned to the zenith the azimuth stops
                       being defined**. At 89.9 degrees of altitude the cosine is worth 1.7
                       thousandths, so what in the vector is one part in ten billion comes out
                       here multiplied by six hundred. Measured, the worst of the sixty cases is
                       2.6e-8 arcseconds. */
                    $this->assertLessThan(1.0e-6, $this->differenceInSeconds($back->azimuth, $azimuth), $where);
                }
            }
        }
    }

    /**
     * The altitude that goes in is the TRUE one and not the apparent one, just as in Swiss, and
     * that is not a documentation detail: the refraction at the horizon is worth 34 arcminutes,
     * so feeding in what a theodolite reads shifts the declination by that same amount.
     */
    public function test_the_return_asks_for_the_true_altitude_and_not_the_apparent(): void
    {
        $horizon = new Horizon($this->place('Madrid'));

        /* Looking due SOUTH, an altitude translates into declination one to one: the
           declination is the altitude plus the latitude minus ninety. So what is measured here
           is the refraction and not the geometry of the azimuth. */
        $true = 20.0;
        $apparent = Horizon::apparentAltitude($true);

        $good = $horizon->equatorialFromHorizontal(180.0, $true, 2451545.0);
        $wrong = $horizon->equatorialFromHorizontal(180.0, $apparent, 2451545.0);

        $this->assertEqualsWithDelta(
            $apparent - $true,
            abs($wrong->declination - $good->declination),
            1.0e-6,
            'feeding in the apparent altitude shifts the declination by exactly the refraction'
        );

        /* And passing the apparent through `trueAltitude` gets almost back to where it started,
           but **not all the way, and that is not a bug here**: the two refractions are
           different formulas and only approximately inverse. `apparentAltitude` goes from true
           to apparent and `trueAltitude` the other way, each with its own fit, so the round
           trip leaves a residual. Measured over nine altitudes: 0.56 arcseconds at 20 degrees,
           0.21 at 45 and up to 7.3 at 10, which is where the refraction curve changes fastest.
           Swiss has the same asymmetry, and it is already in this same test's refraction
           table. */
        $corrected = $horizon->equatorialFromHorizontal(180.0, Horizon::trueAltitude($apparent), 2451545.0);

        $this->assertEqualsWithDelta(0.56, abs($corrected->declination - $good->declination) * 3600.0, 0.05);
        $this->assertEqualsWithDelta(7.3, abs(Horizon::trueAltitude(Horizon::apparentAltitude(10.0)) - 10.0) * 3600.0, 0.1);

        /* And right at the horizon, which is where it hurts: the refraction lifts the image
           0.483 degrees from true to apparent. **These are not the 0.567 of the rise
           convention**, and that confusion has a name: those are the refraction evaluated at
           an APPARENT altitude of zero, which is the one known when a body is seen touching the
           horizon; these are the ones that apply to a body that is truly at the geometric
           horizon. Bennett depends on the apparent altitude, so the two numbers are not the
           same from the two sides. */
        $this->assertEqualsWithDelta(0.483, Horizon::apparentAltitude(0.0), 0.001);
        $this->assertEqualsWithDelta(0.574, Horizon::refractionAtHorizon(), 0.001);
    }

    /**
     * The horizon dip that `swe_refrac_extended` returns in its fourth value. Each row:
     * observer's altitude in metres, pressure, temperature, thermal gradient and the altitude
     * at which the horizon is seen, in degrees and negative.
     *
     * The pressures are the ones that go with each altitude under the standard atmosphere,
     * which is how this is measured without comparing apples to oranges: asking for a thousand
     * metres at sea level pressure describes an air that does not exist.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float, 4: float}>
     */
    public static function swissDips(): array
    {
        return [
            [1.0, 1013.25, 15.0, -0.0065, -0.029222369],
            [10.0, 1013.25, 15.0, -0.0065, -0.092409192],
            [100.0, 1001.29, 15.0, -0.0065, -0.292575923],
            [1000.0, 898.71, 15.0, -0.0065, -0.934700864],
            [2000.0, 794.95, 5.0, -0.0065, -1.327735322],
            [3100.0, 697.00, 0.0, -0.0065, -1.665427147],
            [1000.0, 898.71, 15.0, -0.0100, -0.945158991],
            [1000.0, 898.71, 15.0, 0.0000, -0.914961543],
            [1000.0, 898.71, -20.0, -0.0065, -0.909762308],
            [500.0, 954.61, 25.0, -0.0030, -0.654002612],
        ];
    }

    /**
     * The horizon dip, against `swe_refrac_extended`. It is what that function has beyond
     * `swe_refrac`, and what a rise from a summit needs.
     *
     * **The margin is not floating point, it is physics.** Against Swiss it stays at 1.6
     * arcseconds, and the test demands two. But that has to be read next to the other number,
     * which the next test checks: **not knowing the thermal gradient moves the dip by up to 94
     * arcseconds**, sixty times more. Tightening it here would be fake precision.
     */
    public function test_the_horizon_dip_matches_swiss(): void
    {
        foreach (self::swissDips() as [$altitude, $pressure, $temperature, $gradient, $theirs]) {
            $ours = Horizon::horizonDip($altitude, $pressure, $temperature, $gradient);
            $where = sprintf('%.0f m, %.0f mbar, %.0f degrees, gradient %.4f', $altitude, $pressure, $temperature, $gradient);

            $this->assertEqualsWithDelta($theirs, $ours, 2.0 / 3600.0, $where);
        }
    }

    /**
     * What truly limits the horizon dip is not knowing the thermal gradient, and that is why
     * Swiss exposes it as a parameter instead of assuming one. Here that number is fixed so
     * nobody tries to fine tune the formula thinking the error is in it.
     *
     * And along the way what does not depend on the air is fixed too: **the dip runs as the
     * square root of the altitude**, because it is the angle of the tangent to a sphere. Under
     * the standard atmosphere it comes out at 91% of the geometric one.
     */
    public function test_what_limits_the_dip_is_the_gradient_and_not_the_formula(): void
    {
        $standard = Horizon::horizonDip(1000.0, 898.71, 15.0);
        $humid = Horizon::horizonDip(1000.0, 898.71, 15.0, -0.01);
        $isothermal = Horizon::horizonDip(1000.0, 898.71, 15.0, 0.0);

        $this->assertGreaterThan(
            60.0,
            max(abs($standard - $humid), abs($standard - $isothermal)) * 3600.0,
            'the gradient moves the dip far more than the formula does'
        );

        // The square root of the altitude: multiplying the metres by a hundred makes the dip ten times bigger.
        $this->assertEqualsWithDelta(
            10.0,
            Horizon::horizonDip(10000.0) / Horizon::horizonDip(100.0),
            0.01
        );

        // And 91% of the geometric one, which is what the refraction takes off it.
        $radius = Horizon::EQUATORIAL_RADIUS_KM * 1000.0;
        $geometric = rad2deg(acos($radius / ($radius + 1000.0)));

        $this->assertEqualsWithDelta(0.908, abs(Horizon::horizonDip(1000.0)) / $geometric, 0.002);
        $this->assertEqualsWithDelta(0.176, Horizon::refractionCoefficient(), 0.001);
    }

    /**
     * At sea level the horizon sits at zero, and below sea level too: there the formula would
     * ask for the arccosine of a number greater than one, which is a NAN silently propagating.
     *
     * And the refraction coefficient is capped at one: above that the ray would curve more than
     * the Earth does, the horizon would stop dropping and what would come out is the square
     * root of a negative number. It takes a thermal inversion of 129 degrees per kilometre, so
     * it does not happen, and even so it must not return a NAN.
     */
    public function test_the_horizon_does_not_rise_or_return_nan(): void
    {
        $this->assertSame(0.0, Horizon::horizonDip(0.0));
        $this->assertSame(0.0, Horizon::horizonDip(-400.0));

        $ducting = Horizon::horizonDip(1000.0, 1013.25, 15.0, 0.2);

        $this->assertTrue(is_finite($ducting));
        $this->assertSame(-0.0, $ducting);
        $this->assertSame(1.0, Horizon::refractionCoefficient(1013.25, 15.0, 0.2));
    }

    /**
     * And the dip fits into the rise without having to flip any sign: it is passed as is to
     * `horizonAltitude` and the Sun comes up earlier, which is what happens to someone looking
     * from a summit.
     */
    public function test_the_dip_is_passed_to_the_rise_as_is(): void
    {
        $place = $this->place('Madrid');
        $lookout = Horizon::horizonDip(1000.0);

        $atSeaLevel = RiseSet::next(Body::Sun, $place, 2451545.0, Pass::Rise);
        $fromTheSummit = RiseSet::next(Body::Sun, $place, 2451545.0, Pass::Rise, horizonAltitude: $lookout);

        $this->assertLessThan(0.0, $lookout);
        $this->assertNotNull($atSeaLevel);
        $this->assertNotNull($fromTheSummit);

        // From a thousand metres the Sun shows up almost seven minutes earlier: the horizon
        // drops 0.92 degrees and in Madrid in January the Sun climbs diagonally, at about 0.13
        // degrees a minute.
        $this->assertEqualsWithDelta(7.0, ($atSeaLevel->jdUt - $fromTheSummit->jdUt) * 1440.0, 0.6);
    }

    /**
     * What `swe_cotrans_sp` returns from ecliptic to equator. Each row: Julian day, the six
     * numbers that go in (longitude, latitude, distance and their speeds) and the six that come
     * out.
     *
     * **Swiss was given OUR obliquity**, the one `Time::trueObliquity` gives on that date,
     * because otherwise what would be compared is each one's obliquity model and not the spin,
     * which is what this test looks at. And with the sign flipped, which is how Swiss picks the
     * direction: that is not copied here, the direction is named by the method itself.
     *
     * @return list<array{0: float, 1: array{float, float, float, float, float, float}, 2: array{float, float, float, float, float, float}}>
     */
    public static function swissSpinsWithSpeed(): array
    {
        return [
            [2451545.0, [123.456, 12.345, 1.5, 0.987, -0.123, 0.004], [129.097852658, 31.354574242, 1.500000000, 1.054221022, -0.366455610, 0.004000000]],
            [2451545.0, [0, 0, 1, 1, 0, 0], [0.000000000, 0.000000000, 1.000000000, 0.917493188, 0.397751493, 0.000000000]],
            [2451545.0, [300, -60, 30, -0.01, 0.002, -0.0001], [348.069272509, -75.195619787, 30.000000000, -0.018378172, -0.002635855, -0.000100000]],
            [2451545.0, [45, 88, 0.002, 13.2, 0.5, 0.0001], [273.766367651, 67.933715187, 0.002000000, -1.809351853, 0.013402302, 0.000100000]],
            [2451545.0, [210.5, -3.25, 0.99, 0.6, 0.02, -2e-05], [207.213663837, -14.688553988, 0.990000000, 0.586429548, -0.193531305, -0.000020000]],
            [2415020.5, [123.456, 12.345, 1.5, 0.987, -0.123, 0.004], [129.103221623, 31.365414247, 1.500000000, 1.054265289, -0.366617707, 0.004000000]],
            [2415020.5, [0, 0, 1, 1, 0, 0], [0.000000000, 0.000000000, 1.000000000, 0.917396191, 0.397975160, 0.000000000]],
            [2415020.5, [300, -60, 30, -0.01, 0.002, -0.0001], [348.120992734, -75.198501281, 30.000000000, -0.018372028, -0.002640238, -0.000100000]],
            [2415020.5, [45, 88, 0.002, 13.2, 0.5, 0.0001], [273.764105633, 67.919776988, 0.002000000, -1.808268268, 0.013373344, 0.000100000]],
            [2415020.5, [210.5, -3.25, 0.99, 0.6, 0.02, -2e-05], [207.210406810, -14.694941501, 0.990000000, 0.586401830, -0.193658446, -0.000020000]],
            [2488070.0, [123.456, 12.345, 1.5, 0.987, -0.123, 0.004], [129.094389230, 31.347577987, 1.500000000, 1.054192446, -0.366351011, 0.004000000]],
            [2488070.0, [0, 0, 1, 1, 0, 0], [0.000000000, 0.000000000, 1.000000000, 0.917555758, 0.397607132, 0.000000000]],
            [2488070.0, [300, -60, 30, -0.01, 0.002, -0.0001], [348.035904646, -75.193753608, 30.000000000, -0.018382117, -0.002633026, -0.000100000]],
            [2488070.0, [45, 88, 0.002, 13.2, 0.5, 0.0001], [273.767829051, 67.942710467, 0.002000000, -1.810051910, 0.013421009, 0.000100000]],
            [2488070.0, [210.5, -3.25, 0.99, 0.6, 0.02, -2e-05], [207.215764989, -14.684431302, 0.990000000, 0.586447421, -0.193449251, -0.000020000]],
        ];
    }

    /**
     * Spinning the position and the speed together, against `swe_cotrans_sp`.
     *
     * The cases are not innocent: there is one at the Aries point with all the speed in
     * longitude, one at 88 degrees of latitude (where a degree of longitude is almost no
     * distance at all and the speed spikes when changing frame) and one with the speed almost
     * entirely in latitude.
     */
    public function test_the_spin_with_speed_matches_swiss(): void
    {
        foreach (self::swissSpinsWithSpeed() as [$jdTT, $ecliptic, $theirs]) {
            $ours = Horizon::equatorialWithSpeed($ecliptic, $jdTT);
            $where = sprintf('%.1f, longitude %.3f and latitude %.3f', $jdTT, $ecliptic[0], $ecliptic[1]);

            foreach ([0, 1, 3, 4] as $field) {
                $this->assertEqualsWithDelta($theirs[$field], $ours[$field], 1.0e-8, $where.', field '.$field);
            }

            // A spin does not touch the distance or its speed.
            $this->assertSame($ecliptic[2], $ours[2], $where);
            $this->assertSame($ecliptic[5], $ours[5], $where);
        }
    }

    /**
     * The outbound and return spins undo each other at machine precision, which is what
     * guarantees the two are the same spin with the angle's sign flipped and not two similar
     * formulas.
     */
    public function test_the_spin_with_speed_round_trips(): void
    {
        foreach (self::swissSpinsWithSpeed() as [$jdTT, $ecliptic]) {
            $back = Horizon::eclipticWithSpeed(
                Horizon::equatorialWithSpeed($ecliptic, $jdTT),
                $jdTT
            );

            foreach ([0, 1, 3, 4] as $field) {
                $this->assertEqualsWithDelta($ecliptic[$field], $back[$field], 1.0e-9, 'field '.$field);
            }
        }
    }

    /**
     * **The speed is not spun as if it were another position**, and this test exists to pin
     * that trap down with a number: spinning «one degree of longitude a day» as if it were a
     * point in the sky gives something else, and it raises no error at all.
     *
     * The angles are not a vector. What gets spun is the rectangular vector and its derivative,
     * which are vectors, and from there it goes back to angles with the chain rule.
     */
    public function test_the_speed_is_not_spun_like_a_position(): void
    {
        $jdTT = 2451545.0;

        /* At 88 degrees of latitude is where it shows the most, and that is why the case is
           there: there a degree of longitude is almost no distance at all, so the speed in
           longitude is huge and what the spin has to do with it does not look anything like
           spinning a point. Measured: the right one gives -1.81 degrees a day of right
           ascension and treating the speed as a point gives 11.95, that is neither the value
           nor the sign. */
        $right = Horizon::equatorialWithSpeed([45.0, 88.0, 1.0, 13.2, 0.5, 0.0], $jdTT);
        $wrong = Horizon::equatorialWithSpeed([13.2, 0.5, 1.0, 0.0, 0.0, 0.0], $jdTT);

        $this->assertEqualsWithDelta(-1.809, $right[3], 0.001);
        $this->assertEqualsWithDelta(0.0134, $right[4], 0.0001);
        $this->assertEqualsWithDelta(11.949, $wrong[0], 0.001);

        // And with an ordinary latitude the error is smaller and therefore more dangerous: it
        // comes out as a believable number, of the same order and with the latitude's sign
        // flipped.
        $mild = Horizon::equatorialWithSpeed([123.456, 12.345, 1.5, 0.987, -0.123, 0.004], $jdTT);
        $mildWrong = Horizon::equatorialWithSpeed([0.987, -0.123, 1.0, 0.0, 0.0, 0.0], $jdTT);

        $this->assertLessThan(0.0, $mild[4]);
        $this->assertGreaterThan(0.0, $mildWrong[1]);
    }

    /**
     * And the POSITION part of the spin with speed is exactly `equatorial()`, the one the whole
     * engine uses. They are two different paths in the code and they have to give the same
     * number: if they ever diverged, the chart and this door would stop talking about the same
     * sky.
     */
    public function test_the_spin_with_speed_matches_the_usual_one(): void
    {
        $jdTT = 2451545.0;

        foreach ([[123.456, 12.345, 1.5], [0.0, 0.0, 1.0], [300.0, -60.0, 30.0], [45.0, 88.0, 0.002]] as [$longitude, $latitude, $distance]) {
            $usual = Horizon::equatorial(
                new Position(Body::Mars, $longitude, $latitude, $distance, 0.0),
                $jdTT
            );

            $withSpeed = Horizon::equatorialWithSpeed([$longitude, $latitude, $distance, 0.5, 0.1, 0.0], $jdTT);

            $this->assertEqualsWithDelta($usual->rightAscension, $withSpeed[0], 1.0e-11);
            $this->assertEqualsWithDelta($usual->declination, $withSpeed[1], 1.0e-11);
        }
    }

    /**
     * @param float $one
     * @param float $other
     * @return float
     */
    private function differenceInSeconds(float $one, float $other): float
    {
        return abs(fmod($one - $other + 540.0, 360.0) - 180.0) * 3600.0;
    }

    private function place(string $name): Place
    {
        [$latitude, $longitude] = self::PLACES[$name];

        return new Place($name, null, '', '', $latitude, $longitude, 'UTC');
    }

    public function test_the_topocentric_equatorials_match_swiss(): void
    {
        foreach (self::SWISS as [$name, $jdUt, $body, $ra, $declination, $distance]) {
            $horizon = new Horizon($this->place($name));
            $geocentric = Horizon::equatorialOf(Body::from($body), Time::tt($jdUt));
            $topocentric = $horizon->topocentric($geocentric, $jdUt);

            $tolerance = $body === 'moon' ? self::MOON_TOLERANCE : self::TOLERANCE;

            // The difference in right ascension is measured on the sky, that is, by the cosine
            // of the declination: a degree of RA near the pole is not a degree of arc.
            $dRa = (fmod($topocentric->rightAscension - $ra + 540.0, 360.0) - 180.0) * 3600.0 * cos(deg2rad($declination));
            $dDec = ($topocentric->declination - $declination) * 3600.0;

            $this->assertLessThan($tolerance, abs($dRa), "RA of {$body} at {$name} ({$jdUt}): {$dRa} arcseconds");
            $this->assertLessThan($tolerance, abs($dDec), "Declination of {$body} at {$name} ({$jdUt}): {$dDec} arcseconds");

            // The Moon's distance stays some forty kilometres from Swiss's with Moshier, which
            // is a trimmed down lunar theory: one part in ten thousand, and it does not even
            // show in the semidiameter.
            $this->assertEqualsWithDelta($distance * Equatorial::AU_KM, $topocentric->distanceKm, $body === 'moon' ? 60.0 : 5000.0);
        }
    }

    public function test_the_altitude_and_azimuth_match_swiss(): void
    {
        foreach (self::SWISS as [$name, $jdUt, $body, , , , $swissAzimuth, $altitude]) {
            $horizon = new Horizon($this->place($name));
            $horizontal = $horizon->at(Body::from($body), $jdUt);

            $tolerance = $body === 'moon' ? self::MOON_TOLERANCE : self::TOLERANCE;

            $dAltitude = ($horizontal->altitude - $altitude) * 3600.0;
            $dAzimuth = (fmod($horizontal->azimuthFromSouth() - $swissAzimuth + 540.0, 360.0) - 180.0) * 3600.0 * cos(deg2rad($altitude));

            $this->assertLessThan($tolerance, abs($dAltitude), "Altitude of {$body} at {$name} ({$jdUt}): {$dAltitude} arcseconds");
            $this->assertLessThan($tolerance, abs($dAzimuth), "Azimuth of {$body} at {$name} ({$jdUt}): {$dAzimuth} arcseconds");
        }
    }

    /**
     * True to apparent is Sæmundsson and apparent to true is Bennett, the two formulas behind
     * `swe_refrac`. The forward leg matches Swiss to a millionth of a degree. The return leg
     * stays a few arcseconds off, and the difference has an explanation: Bennett's correction
     * term carries a sine whose argument is in degrees, and Swiss passes it to `sin()` in
     * radians. Here it goes in as Bennett published it.
     */
    public function test_the_refraction_matches_swiss(): void
    {
        foreach (self::REFRACTION as [$altitude, $apparent, $true]) {
            $this->assertEqualsWithDelta($apparent, Horizon::apparentAltitude($altitude), 1e-6, "Apparent at {$altitude} degrees");
            $this->assertEqualsWithDelta($true, Horizon::trueAltitude($altitude), 10 / 3600, "True at {$altitude} degrees");
        }

        // At the horizon it is 34 and a half arcminutes, which is more than the Sun's diameter.
        $this->assertEqualsWithDelta(34.46, Horizon::refractionAtHorizon() * 60, 0.02);
    }

    /**
     * The reason everything about the horizon runs on topocentrics: the Moon, seen from the
     * ground, shifts by almost a degree from where the Earth's centre puts it. And Jupiter,
     * four astronomical units further away, barely notices anything.
     */
    public function test_the_moons_parallax_reaches_almost_a_degree(): void
    {
        $horizon = new Horizon($this->place('Madrid'));
        $jdUt = 2461400.2604166665;
        $jdTT = Time::tt($jdUt);

        $moon = Horizon::equatorialOf(Body::Moon, $jdTT);
        $parallax = $moon->separation($horizon->topocentric($moon, $jdUt));

        // At four degrees below the horizon the parallax is almost the whole horizontal one.
        $this->assertGreaterThan(0.85, $parallax);
        $this->assertLessThan(1.05, $parallax);

        $jupiter = Horizon::equatorialOf(Body::Jupiter, $jdTT);
        $this->assertLessThan(2 / 3600, $jupiter->separation($horizon->topocentric($jupiter, $jdUt)));
    }

    public function test_a_direction_with_no_distance_has_no_parallax(): void
    {
        $horizon = new Horizon($this->place('Oslo'));
        $star = Equatorial::direction(101.287, -16.716);

        $this->assertSame($star, $horizon->topocentric($star, 2460390.0));
        $this->assertSame(0.0, Horizon::semidiameter($star, 696000.0));
    }

    /**
     * The azimuth is measured from north towards east: at noon the Sun is due south in Madrid
     * and due north in Ushuaia, and in the morning it is to the east in both.
     */
    public function test_the_azimuth_runs_from_north_to_east(): void
    {
        // 21 June 2024, approximate solar noon at each place.
        $madrid = (new Horizon($this->place('Madrid')))->at(Body::Sun, 2460483.0104);
        $this->assertEqualsWithDelta(180.0, $madrid->azimuth, 3.0);
        $this->assertGreaterThan(70.0, $madrid->altitude);

        $ushuaia = (new Horizon($this->place('Ushuaia')))->at(Body::Sun, 2460483.19);
        $this->assertLessThan(3.0, min($ushuaia->azimuth, 360.0 - $ushuaia->azimuth));

        // Six hours before noon in Madrid the Sun is rising in the northeast.
        $morning = (new Horizon($this->place('Madrid')))->at(Body::Sun, 2460483.0104 - 0.25);
        $this->assertGreaterThan(45.0, $morning->azimuth);
        $this->assertLessThan(90.0, $morning->azimuth);
    }

    /**
     * The observer sits on the WGS84 ellipsoid: at the equator it is one equatorial radius from
     * the centre, and at the pole one polar radius, twenty one kilometres less. And the
     * altitude adds on top.
     */
    public function test_the_observer_sits_on_the_ellipsoid(): void
    {
        $modulus = fn (array $v) => sqrt($v[0] ** 2 + $v[1] ** 2 + $v[2] ** 2);

        $equator = new Horizon(new Place('Equator', null, '', '', 0.0, 0.0, 'UTC'));
        $this->assertEqualsWithDelta(Horizon::EQUATORIAL_RADIUS_KM, $modulus($equator->observer(2460390.0)), 1e-6);

        $pole = new Horizon(new Place('Pole', null, '', '', 90.0, 0.0, 'UTC'));
        $this->assertEqualsWithDelta(Horizon::EQUATORIAL_RADIUS_KM * (1 - Horizon::FLATTENING), $modulus($pole->observer(2460390.0)), 1e-6);

        $mountain = new Horizon(new Place('Equator', null, '', '', 0.0, 0.0, 'UTC'), 3000.0);
        $this->assertEqualsWithDelta(Horizon::EQUATORIAL_RADIUS_KM + 3.0, $modulus($mountain->observer(2460390.0)), 1e-6);

        // And it spins with the Earth: six hours later it is a quarter turn further along.
        $before = $equator->observer(2460390.0);
        $after = $equator->observer(2460390.25);
        $angle = rad2deg(atan2($after[1], $after[0]) - atan2($before[1], $before[0]));
        $this->assertEqualsWithDelta(90.0 + 90.0 / 365.25, fmod($angle + 360.0, 360.0), 0.01);
    }
}
