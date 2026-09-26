<?php

namespace Astronomy\Tests;

use Astronomy\Ayanamsa;
use Astronomy\Body;
use Astronomy\Ephemeris;
use Astronomy\Stars;
use Astronomy\Time;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * The ayanamsas, against Swiss Ephemeris and against their own definition.
 *
 * The reference values come from `swe_get_ayanamsa_ex` (Swiss Ephemeris 2.10.03 through
 * pyswisseph, with the Moshier ephemeris and `sefstars.txt` for the ones anchored to a
 * star), with nutation, computed separately and copied in here as constants so the suite
 * runs with no network and no Swiss, exactly as `StarsTest` does. There are nine dates from
 * 1700 to 2300, 1 January at 12:00 TT.
 *
 * **What is compared is not the bare value, and that is the thing to understand about this
 * test.** Swiss precesses with the Vondrák (2011) model and this engine with the IAU 1976
 * one, which is the one VSOP87 and the JPL use. The two run 0.3 arcseconds a century apart,
 * and an ayanamsa is accumulated precession: J2000, which has no definition beyond «zero at
 * J2000», comes out 0.84 seconds below Swiss in 1700 and 0.95 above in 2300. That is not a
 * fault of the ayanamsa: Swiss's own documentation gives its chapter 2.8.11 to explaining
 * that an ayanamsa has to carry the same precession model as the tropical longitudes it is
 * subtracted from, and that changing the model changes the ayanamsa and does not change the
 * sidereal longitude. `test_the_sidereal_sun_agrees_with_swiss_even_though_the_ayanamsa_does_not`
 * shows it.
 *
 * So the test discounts the model and looks at what is left, which is the definition. The
 * difference by model at a date t is that of the J2000 ayanamsa at t, and for an ayanamsa
 * anchored at t0 the one at t0 has to be subtracted too, because that is where the value was
 * fixed. `SWISS_PRECESSION` carries Swiss's J2000 at each date and at each epoch. With that,
 * the epoch based ones come out under 0.03 arcseconds of Swiss, which is the rounding of the
 * published values, and the star based ones under 0.02, because the catalogue is the same
 * Hipparcos-2 that Swiss's own file carries.
 *
 * Measured, worst case per ayanamsa (raw, and with the model discounted), in arcseconds:
 * Fagan-Bradley 1.09 and 0.011; Lahiri 1.08 and 0.008; Lahiri 1940, Krishnamurti, J1900 1.25
 * and 0.000; Lahiri VP285 1.77 and 0.000; Krishnamurti VP291 1.80 and 0.001; Raman 1.26 and
 * 0.016; Usha-Shashi 1.25 and 0.004; Yukteshwar 1.25 and 0.001; Bhasin 1.25 and 0.002; Djwhal
 * Khul 1.27 and 0.021; De Luce and Britton 1.82 and 0.016; the three of Kugler and Huber 2.69
 * and 0.030; Aldebaran 1.51 and 0.554; Hipparchus 2.98 and 0.006; Sassanian 2.72 and 0.008;
 * the six of Suryasiddhanta and Aryabhata 2.55 and 0.000; Aryabhata 522 2.61 and 0.000; J2000
 * and Fiorenza 0.95 and 0.000; B1950 1.10 and 0.000; Skydram 0.96 and 0.000; true Citra and
 * Mula 0.97 and 0.015; true Revati 0.97 and 0.012; true Pushya 0.94 and 0.018; the three of
 * the galactic centre 0.89 and 0.158; Wilhelm 0.93 and 0.127; IAU 1958 galactic equator 1.12
 * and 0.174; galactic equator and at Mula 0.95 and 0.008. Revati and Pushya stood at 0.34 and
 * 0.35 when the catalogue was SIMBAD's, which gives Gaia for ζ Piscium and δ Cancri; with
 * Hipparcos-2 they dropped to a hundredth.
 *
 * The raw figure grows with the distance between t0 and 2000, not with the date: Hipparchus,
 * anchored at -128, carries the two seconds that separate the two models over twenty one
 * centuries, and its sidereal longitudes against Swiss's carry the same, because «zero at the
 * equinox of -128» means something different under each model. Swiss says as much of its own.
 *
 * The ones Swiss has and this one does not: Lahiri ICRC, because Swiss applies a 0.28 second
 * offset its documentation does not explain; Eta Piscium, which at the published value
 * (-5°04'46" in -129) comes out at 0.92 seconds; Vettius Valens, which has no numeric
 * definition; and Sheoran, anchored in 4174 BC, out of the series' reach.
 */
class SiderealZodiacTest extends TestCase
{
    /**
     * Tolerance for the epoch based ones, with the model discounted. What is left is the
     * rounding of the published value: 0.01" in Fagan-Bradley, which is defined to
     * hundredths; 0.03" in the Babylonian ones, which are defined to the minute.
     */
    private const TOLERANCE_EPOCH = 0.05;

    /**
     * For the ones anchored to a star from the catalogue (Citra, Revati, Pushya and Mula).
     * There the catalogue is what governs and it is the same Hipparcos-2 on both sides: they
     * come out at 0.02", which is the rounding of Swiss's file and the nutation.
     */
    private const TOLERANCE_STAR = 0.05;

    /**
     * For the ones anchored to the galaxy, which do not come out of the catalogue: here the
     * galactic centre carries the proper motion of Reid and Brunthaler against what Swiss
     * wrote into its file, and that leaves 0.17" in 1700.
     */
    private const TOLERANCE_GALACTIC = 0.25;

    /**
     * For Aldebaran. It is epoch based, but its a0 comes from the star twenty one centuries
     * back, and there the inclination of each model's plane leaves a constant 0.55".
     */
    private const TOLERANCE_ALDEBARAN = 0.6;

    /**
     * The raw difference against Swiss, with nothing discounted, does not go past three
     * arcseconds in any ayanamsa or any date. It is the ceiling of the model: two seconds
     * for the twenty one centuries back to Hipparchus and one for the three centuries out
     * to 1700 or 2300.
     */
    private const TOLERANCE_RAW = 3.1;

    /** The nine dates: 1 January at 12:00 TT, in Julian day. */
    private const DATES = [
        1700 => 2341972.0,
        1800 => 2378497.0,
        1900 => 2415021.0,
        1950 => 2433283.0,
        2000 => 2451545.0,
        2026 => 2461042.0,
        2100 => 2488070.0,
        2200 => 2524594.0,
        2300 => 2561118.0,
    ];

    /**
     * Swiss's true ayanamsa by key, in the order of DATES. `swe_get_ayanamsa_ex` with
     * `SEFLG_MOSEPH`, without `SEFLG_NONUT`.
     *
     * @var array<string, list<float>>
     */
    private const SWISS = [
        'fagan-bradley' => [20.5514743, 21.9454526, 23.3486236, 24.0410503, 24.7364301, 25.1050648, 26.1384040, 27.5383574, 28.9289738],
        'lahiri' => [19.6682667, 21.0622450, 22.4654160, 23.1578427, 23.8532225, 24.2218572, 25.2551963, 26.6551498, 28.0457661],
        'de-luce' => [23.6269452, 25.0209199, 26.4240849, 27.1165077, 27.8118829, 28.1805151, 29.2138460, 30.6137863, 32.0043871],
        'raman' => [18.2219647, 19.6159433, 21.0191145, 21.7115413, 22.4069211, 22.7755559, 23.8088951, 25.2088486, 26.5994649],
        'usha-shashi' => [15.8687147, 17.2626933, 18.6658645, 19.3582913, 20.0536711, 20.4223059, 21.4556451, 22.8555986, 24.2462149],
        'krishnamurti' => [19.5714137, 20.9653923, 22.3685635, 23.0609903, 23.7563701, 24.1250049, 25.1583441, 26.5582976, 27.9489139],
        'djwhal-khul' => [24.1708523, 25.5648309, 26.9680021, 27.6604289, 28.3558087, 28.7244435, 29.7577827, 31.1577362, 32.5483525],
        'yukteshwar' => [18.2899767, 19.6839553, 21.0871265, 21.7795533, 22.4749331, 22.8435679, 23.8769071, 25.2768606, 26.6674769],
        'jn-bhasin' => [18.5733107, 19.9672893, 21.3704605, 22.0628873, 22.7582671, 23.1269019, 24.1602411, 25.5601946, 26.9508109],
        'kugler-1' => [19.3448356, 20.7388093, 22.1419732, 22.8343954, 23.5297700, 23.8984019, 24.9317318, 26.3316706, 27.7222699],
        'kugler-2' => [20.7448356, 22.1388093, 23.5419732, 24.2343954, 24.9297700, 25.2984019, 26.3317318, 27.7316706, 29.1222699],
        'kugler-3' => [21.5948356, 22.9888093, 24.3919732, 25.0843954, 25.7797700, 26.1484019, 27.1817318, 28.5816706, 29.9722699],
        'huber' => [20.5448356, 21.9388093, 23.3419732, 24.0343954, 24.7297700, 25.0984019, 26.1317318, 27.5316706, 28.9222699],
        'aldebaran-15-taurus' => [20.5701196, 21.9640933, 23.3672572, 24.0596794, 24.7550541, 25.1236859, 26.1570158, 27.5569546, 28.9475539],
        'hipparchus' => [16.0589848, 17.4529582, 18.8561218, 19.5485438, 20.2439183, 20.6125500, 21.6458796, 23.0458180, 24.4364169],
        'sassanian' => [15.8041377, 17.1981164, 18.6012861, 19.2937115, 19.9890895, 20.3577232, 21.3910587, 22.7910057, 24.1816140],
        'galactic-centre-0-sagittarius' => [22.6574652, 24.0513674, 25.4544448, 26.1468361, 26.8421759, 27.2108159, 28.2441134, 29.6440202, 31.0346032],
        'j2000' => [355.8111749, 357.2051530, 358.6083238, 359.3007504, -0.0038699, 0.3647648, 1.3981039, 2.7980573, 4.1886737],
        'j1900' => [357.2077547, 358.6017333, 0.0049045, 0.6973313, 1.3927111, 1.7613459, 2.7946851, 4.1946386, 5.5852549],
        'b1950' => [356.5095444, 357.9035228, 359.3066938, -0.0008795, 0.6945003, 1.0631350, 2.0964742, 3.4964276, 4.8870439],
        'suryasiddhanta' => [16.7062384, 18.1002168, 19.5033860, 20.1958112, 20.8911890, 21.2598225, 22.2931576, 23.6931039, 25.0837115],
        'suryasiddhanta-mean-sun' => [16.4916045, 17.8855828, 19.2887521, 19.9811772, 20.6765550, 21.0451886, 22.0785236, 23.4784700, 24.8690775],
        'aryabhata' => [16.7062393, 18.1002177, 19.5033869, 20.1958121, 20.8911899, 21.2598234, 22.2931585, 23.6931048, 25.0837123],
        'aryabhata-mean-sun' => [16.4686069, 17.8625853, 19.2657545, 19.9581797, 20.6535575, 21.0221910, 22.0555261, 23.4554725, 24.8460800],
        'suryasiddhanta-revati' => [15.9145680, 17.3085463, 18.7117156, 19.4041407, 20.0995185, 20.4681521, 21.5014871, 22.9014335, 24.2920410],
        'suryasiddhanta-citra' => [18.8169429, 20.2109212, 21.6140905, 22.3065156, 23.0018934, 23.3705270, 24.4038620, 25.8038084, 27.1944159],
        'true-citra' => [19.6551456, 21.0478720, 22.4496898, 23.1414435, 23.8361451, 24.2045080, 25.2368698, 26.6354792, 28.0247531],
        'true-revati' => [15.8463660, 17.2436015, 18.6501312, 19.3442341, 20.0412948, 20.4107221, 21.4465226, 22.8498266, 24.2437926],
        'true-pushya' => [18.5348209, 19.9299818, 21.3342881, 22.0272766, 22.7232217, 23.0921841, 24.1263678, 25.5274435, 26.9191796],
        'galactic-centre-gil-brand' => [18.2805242, 19.6744264, 21.0775038, 21.7698950, 22.4652349, 22.8338749, 23.8671724, 25.2670792, 26.6576622],
        'galactic-equator-iau-1958' => [25.8119233, 27.2133925, 28.6240380, 29.3201958, 30.0193026, 30.3898739, 31.4287180, 32.8360955, 34.2341184],
        'galactic-equator' => [25.8648493, 27.2663165, 28.6769599, 29.3731166, 30.0722223, 30.4427930, 31.4816355, 32.8890107, 34.2870313],
        'galactic-equator-mula' => [19.1981827, 20.5996498, 22.0102932, 22.7064500, 23.4055556, 23.7761263, 24.8149688, 26.2223440, 27.6203646],
        'skydram' => [25.8289688, 27.2229469, 28.6261177, 29.3185443, 30.0139240, 30.3825587, 31.4158978, 32.8158512, 34.2064676],
        'true-mula' => [20.3920515, 21.7857207, 23.1885768, 23.8808637, 24.5761030, 24.9446958, 25.9778561, 27.3775907, 28.7680176],
        'galactic-centre-wilhelm' => [15.6671976, 17.1218341, 18.5860757, 19.3091989, 20.0353632, 20.4200694, 21.4992102, 22.9613310, 24.4143925],
        'aryabhata-522' => [16.3870261, 17.7810046, 19.1841740, 19.8765992, 20.5719771, 20.9406107, 21.9739459, 23.3738925, 24.7645003],
        'britton' => [20.4269452, 21.8209199, 23.2240849, 23.9165077, 24.6118829, 24.9805151, 26.0138460, 27.4137863, 28.8043871],
        'galactic-centre-cochrane' => [352.6574652, 354.0513674, 355.4544448, 356.1468361, 356.8421759, 357.2108159, 358.2441134, 359.6440202, 1.0346032],
        'fiorenza' => [20.8111940, 22.2051721, 23.6083429, 24.3007695, 24.9961492, 25.3647839, 26.3981230, 27.7980764, 29.1886928],
        'lahiri-1940' => [19.6534970, 21.0474755, 22.4506467, 23.1430735, 23.8384534, 24.2070881, 25.2404273, 26.6403808, 28.0309971],
        'lahiri-vp285' => [19.6746656, 21.0686426, 22.4718102, 23.1642345, 23.8596113, 24.2282443, 25.2615778, 26.6615218, 28.0521267],
        'krishnamurti-vp291' => [19.5915492, 20.9855262, 22.3886939, 23.0811182, 23.7764951, 24.1451281, 25.1784616, 26.5784057, 27.9690106],
    ];

    /**
     * Swiss's J2000 ayanamsa WITHOUT nutation, by Julian day: at the nine dates and at the
     * epoch of each ayanamsa. It is Swiss's accumulated precession, and against our own it
     * measures the model. The keys carry six decimals so they can be looked up.
     *
     * @var array<string, float>
     */
    private const SWISS_PRECESSION = [
        '1674484.000000' => 330.4191735,
        '1684532.500000' => 330.7999730,
        '1721057.500000' => 332.1844995,
        '1825235.245856' => 336.1366970,
        '1827424.757280' => 336.2198120,
        '1903396.855706' => 339.1050697,
        '1903396.879039' => 339.1050706,
        '1911797.804334' => 339.4242777,
        '1927135.874779' => 340.0071547,
        '2341972.000000' => 355.8121764,
        '2378497.000000' => 357.2075291,
        '2415020.000000' => 358.6034193,
        '2415021.000000' => 358.6034575,
        '2433282.423459' => 359.3016299,
        '2433282.500000' => 359.3016329,
        '2433283.000000' => 359.3016520,
        '2435553.500000' => 359.3884687,
        '2451079.734761' => 359.9822061,
        '2451544.500000' => 359.9999809,
        '2451545.000000' => 0.0000000,
        '2461042.000000' => 0.3632307,
        '2488070.000000' => 1.3971950,
        '2524594.000000' => 2.7949658,
        '2561118.000000' => 4.1933506,
    ];

    /**
     * The Sun's sidereal longitude according to `swe_calc` with `SEFLG_SIDEREAL`, by
     * ayanamsa and year. NOTHING is discounted here: this is the check that the model
     * cancels out.
     */
    private const SWISS_SIDEREAL_SUN = [
        'lahiri' => [1700 => 260.5370375, 1900 => 258.1978963, 2000 => 256.5149441, 2100 => 255.8575926, 2300 => 252.5033515],
        'fagan-bradley' => [1700 => 259.6538299, 1900 => 257.3146887, 2000 => 255.6317365, 2100 => 254.9743850, 2300 => 251.6201438],
        'true-citra' => [1700 => 260.5501585, 1900 => 258.2136225, 2000 => 256.5320215, 2100 => 255.8759192, 2300 => 252.5243645],
        'galactic-centre-0-sagittarius' => [1700 => 257.5478390, 1900 => 255.2088675, 2000 => 253.5259906, 2100 => 252.8686756, 2300 => 249.5145144],
    ];

    /**
     * Each ayanamsa at the nine dates against Swiss, with the precession model discounted.
     */
    public function test_each_ayanamsa_agrees_with_swiss_with_the_precession_model_discounted(): void
    {
        $this->assertCount(count(Ayanamsa::all()), self::SWISS, 'there are ayanamsas with no Swiss reference, or the other way round');

        $worstRaw = 0.0;

        foreach (Ayanamsa::all() as $ayanamsa) {
            $references = self::SWISS[$ayanamsa->value] ?? $this->fail("{$ayanamsa->value} has no Swiss reference");

            $isEpochBased = $ayanamsa->epoch() !== null && $ayanamsa !== Ayanamsa::Aldebaran15Taurus;
            $tolerance = $this->toleranceFor($ayanamsa);

            // The model at the epoch: it only counts for the ones that fix a value at t0.
            // Aldebaran fixes its own from the star, which is computed in the J2000 frame,
            // so for it the epoch of the model is J2000, where the offset is zero.
            $offsetAtEpoch = $isEpochBased ? $this->modelOffset($ayanamsa->epoch()) : 0.0;

            foreach (array_values(self::DATES) as $i => $jd) {
                $swiss = $references[$i];
                $raw = $this->difference($ayanamsa->value($jd), $swiss);
                $residual = $raw - ($this->modelOffset($jd) - $offsetAtEpoch);
                $worstRaw = max($worstRaw, abs($raw));

                $this->assertLessThan($tolerance, abs($residual), sprintf(
                    '%s at JD %.1f: %.7f, Swiss says %.7f (%.3f" raw, %.3f" with the model discounted)',
                    $ayanamsa->name(), $jd, $ayanamsa->value($jd), $swiss, $raw, $residual
                ));

                $this->assertLessThan(self::TOLERANCE_RAW, abs($raw), sprintf(
                    '%s at JD %.1f is %.2f" off Swiss, more than the model explains', $ayanamsa->name(), $jd, $raw
                ));
            }
        }

        // And that the engine is not being compared against itself: the model has to show.
        $this->assertGreaterThan(0.5, $worstRaw, 'the worst raw case is suspiciously good');
    }

    /**
     * At its epoch, each epoch based ayanamsa equals exactly its initial value. That is
     * what defines it, and it checks that going to J2000 and back to the same date is the
     * identity.
     */
    public function test_at_its_epoch_each_ayanamsa_equals_its_initial_value(): void
    {
        $counted = 0;

        foreach (Ayanamsa::all() as $ayanamsa) {
            $epoch = $ayanamsa->epoch();

            if ($epoch === null) {
                $this->assertNull($ayanamsa->initialValue(), "{$ayanamsa->value} has no epoch and does have an initial value");

                continue;
            }

            $counted++;
            $this->assertNotNull($ayanamsa->initialValue(), "{$ayanamsa->value} has an epoch and no initial value");
            $this->assertEqualsWithDelta($ayanamsa->initialValue(), $ayanamsa->mean($epoch), 1e-9, $ayanamsa->name());
        }

        $this->assertSame(32, $counted);

        // The published values, as they stand, for the three cases that carry a
        // correction: the correction shows up by asking for the value at t0 and undoing it.
        $this->assertEqualsWithDelta(24 + 2 / 60 + 31.36 / 3600, Ayanamsa::FaganBradley->mean(2433282.5) + 0.41256 / 3600, 1e-9, 'Fagan-Bradley: 24°02\'31,36" on 1 January 1950');
        $this->assertEqualsWithDelta(23 + 15 / 60 + 0.658 / 3600, Ayanamsa::Lahiri->value(2435553.5) - 0.13036 / 3600, 1e-9, 'Lahiri: 23°15\'00,658" true on 21 March 1956');
        $this->assertEqualsWithDelta(22.363889, Ayanamsa::Krishnamurti->mean(2415020.0) + 0.828 / 3600, 1e-9, 'Krishnamurti: 22,363889° at J1900');

        // And the ones that are zero at their own date, are.
        foreach ([Ayanamsa::J2000, Ayanamsa::Sassanian, Ayanamsa::Suryasiddhanta, Ayanamsa::LahiriVP285, Ayanamsa::DeLuce] as $ayanamsa) {
            $this->assertEqualsWithDelta(0.0, $ayanamsa->mean($ayanamsa->epoch()), 1e-9, $ayanamsa->name());
        }
    }

    /**
     * An ayanamsa anchored to a star leaves the star where it says, at any date: sidereal
     * Spica with true Citra is worth 180.000 degrees on 1 January of 1700 and of 2300, with
     * its proper motion, its aberration and its nutation folded in.
     */
    public function test_the_anchor_star_stays_at_its_sidereal_longitude_at_any_date(): void
    {
        $where = [
            'true-citra' => 180.0,
            'true-revati' => 359 + 50 / 60,
            'true-pushya' => 106.0,
            'true-mula' => 240.0,
            'galactic-centre-0-sagittarius' => 240.0,
            'galactic-centre-gil-brand' => 210 + 90 * (3 - sqrt(5)) / 2,
            'galactic-centre-cochrane' => 270.0,
        ];

        foreach ($where as $key => $siderealLongitude) {
            $ayanamsa = Ayanamsa::from($key);
            $star = $ayanamsa->star();
            $this->assertNotNull($star, $key);

            foreach (self::DATES as $year => $jd) {
                $tropical = Stars::position($star, $jd)->longitude;

                $this->assertEqualsWithDelta($siderealLongitude, $ayanamsa->sidereal($tropical, $jd), 1e-6, "{$key} in {$year}");
            }
        }

        // And with Lahiri, which is epoch based, Spica does NOT stay put: in 2000 it sits
        // at 179°58'58", which is what the table in Swiss's documentation gives for this
        // ayanamsa, and that one minute is exactly what true Citra corrects.
        $spica = Stars::find('spica');
        $at2000 = Ayanamsa::Lahiri->sidereal(Stars::position($spica, Time::J2000)->longitude, Time::J2000);
        $this->assertEqualsWithDelta(179 + 58 / 60 + 58 / 3600, $at2000, 2 / 3600, 'Spica with Lahiri in 2000: 179°58\'58"');
    }

    /**
     * The ayanamsa grows at the rate of general precession, 50.29 arcseconds a year in
     * the epoch based ones and almost the same in the star based ones, where the proper
     * motion rides along too.
     */
    public function test_the_ayanamsa_grows_about_fifty_arcseconds_a_year(): void
    {
        foreach ([Ayanamsa::Lahiri, Ayanamsa::FaganBradley, Ayanamsa::J2000, Ayanamsa::Hipparchus] as $ayanamsa) {
            $rate = ($ayanamsa->mean(self::DATES[2100]) - $ayanamsa->mean(self::DATES[2000])) * 3600 / 100;

            $this->assertEqualsWithDelta(50.29, $rate, 0.05, $ayanamsa->name());
        }

        // Spica drifts back half an arcsecond a year in longitude, and true Citra carries
        // that along: it grows a touch slower than precession.
        $citraRate = (Ayanamsa::TrueCitra->mean(self::DATES[2100]) - Ayanamsa::TrueCitra->mean(self::DATES[2000])) * 3600 / 100;
        $this->assertLessThan(50.29, $citraRate);
        $this->assertGreaterThan(50.20, $citraRate);
    }

    /**
     * The check anyone can make with an almanac: Lahiri sits today around 24 degrees,
     * Fagan-Bradley almost a degree further on, and Kugler's Babylonian ones were negative
     * in the year -100, which is what it means for the equinox to not yet have reached its
     * own zero.
     */
    public function test_lahiri_today_sits_around_twenty_four_degrees(): void
    {
        $today = Time::tt(Time::julianDay(new DateTimeImmutable('2026-09-07 12:00', new DateTimeZone('UTC'))));

        $this->assertEqualsWithDelta(24.23, Ayanamsa::Lahiri->value($today), 0.02);
        $this->assertEqualsWithDelta(25.11, Ayanamsa::FaganBradley->value($today), 0.02);
        $this->assertLessThan(0, Ayanamsa::Kugler1->mean(Ayanamsa::Kugler1->epoch()));

        // And the order the literature gives: Fagan-Bradley runs almost a degree ahead of
        // Lahiri, and Raman more than a degree behind.
        $this->assertEqualsWithDelta(0.883, Ayanamsa::FaganBradley->value($today) - Ayanamsa::Lahiri->value($today), 0.002);
        $this->assertEqualsWithDelta(-1.446, Ayanamsa::Raman->value($today) - Ayanamsa::Lahiri->value($today), 0.002);
    }

    /**
     * `sidereal()` subtracts the true ayanamsa and leaves the longitude in [0, 360).
     */
    public function test_sidereal_subtracts_the_ayanamsa_and_normalizes(): void
    {
        $jd = self::DATES[2026];
        $ayanamsa = Ayanamsa::Lahiri->value($jd);

        $this->assertEqualsWithDelta(100 - $ayanamsa, Ayanamsa::Lahiri->sidereal(100, $jd), 1e-12);

        // Crossing zero: 10° tropical is 345 and some sidereal, not -14.
        $sidereal = Ayanamsa::Lahiri->sidereal(10, $jd);
        $this->assertGreaterThanOrEqual(0, $sidereal);
        $this->assertLessThan(360, $sidereal);
        $this->assertEqualsWithDelta(370 - $ayanamsa, $sidereal, 1e-12);

        // value() is centred: J2000 in 1700 is -4°, not 356°.
        $this->assertLessThan(0, Ayanamsa::J2000->value(self::DATES[1700]));
        $this->assertGreaterThan(-180, Ayanamsa::J2000->value(self::DATES[1700]));

        // And the true one is the mean plus the nutation in longitude, no more and no less.
        $nutation = rad2deg(Time::nutation(Time::centuries($jd))[0]);
        $this->assertEqualsWithDelta(Ayanamsa::Lahiri->mean($jd) + $nutation, Ayanamsa::Lahiri->value($jd), 1e-12);
    }

    /**
     * The demonstration that the precession model cancels out: the Sun's SIDEREAL
     * longitude agrees with Swiss's to two tenths of an arcsecond between 1700 and 2300,
     * while the bare ayanamsa drifts almost a full second at the extremes. The Sun's
     * tropical longitude carries the same model difference as the ayanamsa, and
     * subtracting makes it vanish.
     *
     * That is why the ayanamsa is computed with `Precession` and not with Swiss's model:
     * with Swiss's model the ayanamsa would agree and the sidereal Sun would stop agreeing.
     */
    public function test_the_sidereal_sun_agrees_with_swiss_even_though_the_ayanamsa_does_not(): void
    {
        $worstSun = 0.0;
        $worstAyanamsa = 0.0;

        foreach (self::SWISS_SIDEREAL_SUN as $key => $byYear) {
            $ayanamsa = Ayanamsa::from($key);

            foreach ($byYear as $year => $expected) {
                $jd = self::DATES[$year];
                $tropical = Ephemeris::position(Body::Sun, $jd)->longitude;
                $sidereal = $ayanamsa->sidereal($tropical, $jd);

                $sunError = $this->difference($sidereal, $expected);
                $worstSun = max($worstSun, abs($sunError));
                $worstAyanamsa = max($worstAyanamsa, abs($this->difference($ayanamsa->value($jd), self::SWISS[$key][array_search($year, array_keys(self::DATES), true)])));

                $this->assertLessThan(0.3, abs($sunError), sprintf(
                    'sidereal Sun with %s in %d: %.7f, Swiss says %.7f (%.3f")', $ayanamsa->name(), $year, $sidereal, $expected, $sunError
                ));
            }
        }

        $this->assertLessThan($worstAyanamsa, $worstSun, 'the sidereal Sun should agree better than the ayanamsa, and it does not');
        $this->assertGreaterThan(0.8, $worstAyanamsa, 'the ayanamsa agrees too well: the model is not showing');
    }

    /**
     * Each one has a name, a one line description with no long dashes, and one anchor of
     * a single kind: an epoch with its initial value, a star, or the galaxy, which is
     * neither of the two.
     */
    public function test_all_have_a_name_a_description_and_one_anchor(): void
    {
        $this->assertCount(43, Ayanamsa::all());

        $keys = array_map(fn (Ayanamsa $a) => $a->value, Ayanamsa::all());
        $this->assertSame($keys, array_unique($keys));

        $names = array_map(fn (Ayanamsa $a) => $a->name(), Ayanamsa::all());
        $this->assertSame($names, array_unique($names), 'there are repeated names');

        $epochBased = 0;
        $starBased = 0;
        $galactic = 0;

        foreach (Ayanamsa::all() as $ayanamsa) {
            $this->assertNotSame('', $ayanamsa->name());
            $this->assertNotSame('', $ayanamsa->description());
            $this->assertStringNotContainsString("\n", $ayanamsa->description());
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $ayanamsa->name().$ayanamsa->description(), "{$ayanamsa->value} carries a long dash");

            if ($ayanamsa->epoch() !== null) {
                $epochBased++;
                $this->assertNull($ayanamsa->star(), "{$ayanamsa->value} has both an epoch and a star");
            } elseif ($ayanamsa->star() !== null) {
                $starBased++;
            } else {
                $galactic++;
                $this->assertStringStartsWith('galactic-equator', $ayanamsa->value);
            }

            // A sensible value at the nine dates: between -30 and 40 degrees.
            foreach (self::DATES as $jd) {
                $this->assertGreaterThan(-30, $ayanamsa->value($jd), $ayanamsa->value);
                $this->assertLessThan(40, $ayanamsa->value($jd), $ayanamsa->value);
            }
        }

        $this->assertSame([32, 8, 3], [$epochBased, $starBased, $galactic]);
    }

    /**
     * The tolerance that fits each ayanamsa depending on where its residual comes from:
     * the rounding of a published value, the star catalogue, the galactic centre, or the
     * model over twenty one centuries.
     */
    private function toleranceFor(Ayanamsa $ayanamsa): float
    {
        return match (true) {
            $ayanamsa === Ayanamsa::Aldebaran15Taurus => self::TOLERANCE_ALDEBARAN,
            str_contains($ayanamsa->value, 'galactic') => self::TOLERANCE_GALACTIC,
            $ayanamsa->star() !== null => self::TOLERANCE_STAR,
            default => self::TOLERANCE_EPOCH,
        };
    }

    /**
     * How far our own J2000 ayanamsa drifts from Swiss's at an instant: the difference
     * between the two precession models, in arcseconds, with no definition in between.
     * Swiss's J2000 is copied into `SWISS_PRECESSION` for each date and epoch.
     */
    private function modelOffset(float $jd): float
    {
        /* The keys are the JDs in TT that Swiss was asked for, and the TT of a civil date
           depends on delta T, which switched from polynomials to observed tables after this
           table was copied: the same civil instant now falls a few seconds further along.
           The nearest key within two hours is looked up (in the year 500 the two delta Ts
           run two minutes apart, and in -500, seven): the J2000 ayanamsa advances fifty
           arcseconds a YEAR, so in two hours it moves a hundred thousandth of a second. */
        $nearest = null;

        foreach (array_keys(self::SWISS_PRECESSION) as $key) {
            if (abs((float) $key - $jd) < 2 / 24 && ($nearest === null || abs((float) $key - $jd) < abs((float) $nearest - $jd))) {
                $nearest = $key;
            }
        }

        $this->assertNotNull($nearest, sprintf('there is no Swiss J2000 for JD %.6f', $jd));

        return $this->difference(Ayanamsa::J2000->mean($jd), self::SWISS_PRECESSION[$nearest]);
    }

    /**
     * Difference of two angles in arcseconds, by the short arc: subtracting plainly fails
     * between 359 and 1 degree.
     */
    private function difference(float $a, float $b): float
    {
        return (fmod(fmod($a - $b + 180, 360) + 360, 360) - 180) * 3600;
    }
}
