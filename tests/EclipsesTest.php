<?php

namespace Astronomy\Tests;

use Astronomy\Eclipses;
use Astronomy\Place;
use Astronomy\Sign;
use Astronomy\Time;
use Astronomy\EclipseType;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * Eclipses, against Swiss Ephemeris.
 *
 * **Every solar and lunar eclipse from 2020 to 2030 has to match one by one** against
 * `swe_sol_eclipse_when_glob` and `swe_lun_eclipse_when` in date and type. This is the
 * strong check: a search that skips an eclipse or invents one fails here, and so does
 * one that classifies a hybrid as total. The figures are from Swiss Ephemeris 2.10.03
 * with Moshier, in UT, copied by hand so the suite runs without Swiss.
 *
 * The margins are the ones the task calls for: a minute on the instants and a
 * hundredth on the magnitudes. Measured, the maxima come out under 35 seconds and the
 * contacts under 30, except for a grazing penumbral of 2027 with magnitude 0.001,
 * where the Moon skims the penumbra so obliquely that one arcsecond of geometry moves
 * the contact by half a minute. Part of the difference is delta T: Swiss carries 69
 * seconds in 2026 and our polynomial 75.
 */
class EclipsesTest extends TestCase
{
    /** Margin on the instants, in seconds. */
    private const TOLERANCE = 60.0;

    /** Margin on the magnitudes. */
    private const MAGNITUDE_TOLERANCE = 0.01;

    /**
     * [date, type, central, maximum, start and end of the partial phase, of the
     * central phase, of the central line, NASA magnitude, latitude and longitude of
     * the maximum] according to Swiss.
     */
    private const SOLAR_ECLIPSES = [
        ['2020-06-21', 'annular', true, 2459021.777845, 2459021.656946, 2459021.898647, 2459021.699837, 2459021.855787, 2459021.70032, 2459021.855339, 0.9949, 30.5, 79.67],
        ['2020-12-14', 'total', true, 2459198.176057, 2459198.065228, 2459198.286868, 2459198.105954, 2459198.246075, 2459198.106157, 2459198.245896, 1.0262, -40.29, -67.96],
        ['2021-06-10', 'annular', true, 2459375.945775, 2459375.842017, 2459376.049327, 2459375.909833, 2459375.981636, 2459375.913384, 2459375.978111, 0.9443, 80.77, -67.07],
        ['2021-12-04', 'total', true, 2459552.81497, 2459552.728789, 2459552.900923, 2459552.791857, 2459552.837995, 2459552.793818, 2459552.836038, 1.0376, -76.75, -46.07],
        ['2022-04-30', 'partial', false, 2459700.362176, 2459700.281748, 2459700.442972, 0.0, 0.0, 0.0, 0.0, 0.6403, -62.13, -71.59],
        ['2022-10-25', 'partial', false, 2459877.958394, 2459877.874104, 2459878.04316, 0.0, 0.0, 0.0, 0.0, 0.8617, 61.74, 77.23],
        ['2023-04-20', 'hybrid', true, 2460054.678319, 2460054.565721, 2460054.791216, 2460054.609177, 2460054.747716, 2460054.609197, 2460054.747665, 1.0141, -9.59, 125.77],
        ['2023-10-14', 'annular', true, 2460232.249666, 2460232.127828, 2460232.371694, 2460232.173827, 2460232.325707, 2460232.175384, 2460232.324179, 0.9528, 11.38, -83.1],
        ['2024-04-08', 'total', true, 2460409.26208, 2460409.154402, 2460409.36958, 2460409.19368, 2460409.330272, 2460409.194492, 2460409.329475, 1.0575, 25.29, -104.17],
        ['2024-10-02', 'annular', true, 2460586.281297, 2460586.154888, 2460586.407466, 2460586.201838, 2460586.360527, 2460586.203934, 2460586.35843, 0.9334, -21.95, -114.52],
        ['2025-03-29', 'partial', false, 2460763.949614, 2460763.868613, 2460764.030185, 0.0, 0.0, 0.0, 0.0, 0.9378, 61.18, -77.22],
        ['2025-09-21', 'partial', false, 2460940.320838, 2460940.229024, 2460940.412117, 0.0, 0.0, 0.0, 0.0, 0.8553, -61.03, 153.39],
        ['2026-02-17', 'annular', true, 2461089.008264, 2461088.914441, 2461089.102549, 2461088.988247, 2461089.028531, 2461088.991982, 2461089.024821, 0.9638, -64.68, 87.05],
        ['2026-08-12', 'total', true, 2461265.240248, 2461265.148968, 2461265.331912, 2461265.207014, 2461265.273617, 2461265.208411, 2461265.272238, 1.0395, 65.16, -25.13],
        ['2027-02-06', 'annular', true, 2461443.166439, 2461443.040177, 2461443.292834, 2461443.086178, 2461443.246849, 2461443.08836, 2461443.244687, 0.9289, -31.28, -48.49],
        ['2027-08-02', 'total', true, 2461619.921295, 2461619.812708, 2461620.029957, 2461619.849649, 2461619.993012, 2461619.850782, 2461619.99188, 1.08, 25.49, 33.17],
        ['2028-01-26', 'annular', true, 2461797.130445, 2461797.004682, 2461797.256019, 2461797.052043, 2461797.208661, 2461797.054502, 2461797.206189, 0.9215, 2.96, -51.58],
        ['2028-07-22', 'total', true, 2461974.621878, 2461974.519169, 2461974.724313, 2461974.563008, 2461974.680582, 2461974.564013, 2461974.679553, 1.0569, -15.59, 126.68],
        ['2029-01-14', 'partial', false, 2462151.217143, 2462151.126482, 2462151.307465, 0.0, 0.0, 0.0, 0.0, 0.8719, 63.69, -114.04],
        ['2029-06-12', 'partial', false, 2462299.6701, 2462299.602095, 2462299.738228, 0.0, 0.0, 0.0, 0.0, 0.4576, 66.69, -66.03],
        ['2029-07-11', 'partial', false, 2462329.150028, 2462329.103077, 2462329.196943, 0.0, 0.0, 0.0, 0.0, 0.2306, -64.19, -85.57],
        ['2029-12-05', 'partial', false, 2462476.126989, 2462476.046563, 2462476.207369, 0.0, 0.0, 0.0, 0.0, 0.8906, -67.44, 135.54],
        ['2030-06-01', 'annular', true, 2462653.769469, 2462653.649152, 2462653.889734, 2462653.699556, 2462653.839343, 2462653.701512, 2462653.837396, 0.945, 56.48, 80.04],
        ['2030-11-25', 'total', true, 2462830.785055, 2462830.678344, 2462830.891691, 2462830.718372, 2462830.851691, 2462830.719001, 2462830.851055, 1.0478, -43.62, 71.19],
    ];

    /** [date, type, maximum, P1, U1, U2, U3, U4, P4, umbral magnitude, penumbral] according to Swiss. */
    private const LUNAR_ECLIPSES = [
        ['2020-01-10', 'penumbral', 2458859.298706, 2458859.213735, 0.0, 0.0, 0.0, 0.0, 2458859.383627, 0.0, 0.8955],
        ['2020-06-05', 'penumbral', 2459006.309018, 2459006.240205, 0.0, 0.0, 0.0, 0.0, 2459006.377851, 0.0, 0.5678],
        ['2020-07-05', 'penumbral', 2459035.68742, 2459035.630103, 0.0, 0.0, 0.0, 0.0, 2459035.744736, 0.0, 0.3546],
        ['2020-11-30', 'penumbral', 2459183.90479, 2459183.814093, 0.0, 0.0, 0.0, 0.0, 2459183.995456, 0.0, 0.8292],
        ['2021-05-26', 'total', 2459360.971349, 2459360.86646, 2459360.906236, 2459360.966225, 2459360.976478, 2459361.036464, 2459361.076255, 1.0098, 1.9535],
        ['2021-11-19', 'partial', 2459537.877017, 2459537.751472, 2459537.804674, 0.0, 0.0, 2459537.94936, 2459538.002599, 0.9735, 2.0722],
        ['2022-05-16', 'total', 2459715.674686, 2459715.56398, 2459715.602691, 2459715.645181, 2459715.704186, 2459715.746682, 2459715.785336, 1.4144, 2.3726],
        ['2022-11-08', 'total', 2459891.957756, 2459891.834895, 2459891.881409, 2459891.928258, 2459891.987262, 2459892.0341, 2459892.080719, 1.3589, 2.4147],
        ['2023-05-05', 'penumbral', 2460070.224301, 2460070.134816, 0.0, 0.0, 0.0, 0.0, 2460070.313723, 0.0, 0.9639],
        ['2023-10-28', 'partial', 2460246.343091, 2460246.251225, 2460246.31614, 0.0, 0.0, 2460246.370019, 2460246.43502, 0.1227, 1.1183],
        ['2024-03-25', 'penumbral', 2460394.800616, 2460394.703633, 0.0, 0.0, 0.0, 0.0, 2460394.897578, 0.0, 0.9562],
        ['2024-09-18', 'partial', 2460571.61409, 2460571.528578, 2460571.592278, 0.0, 0.0, 2460571.635908, 2460571.699591, 0.0849, 1.0363],
        ['2025-03-14', 'total', 2460748.790827, 2460748.664906, 2460748.71505, 2460748.768156, 2460748.813507, 2460748.866606, 2460748.916813, 1.1779, 2.2596],
        ['2025-09-07', 'total', 2460926.258202, 2460926.144712, 2460926.185465, 2460926.22968, 2460926.286714, 2460926.330938, 2460926.37161, 1.362, 2.3436],
        ['2026-03-03', 'total', 2461102.981744, 2461102.864187, 2461102.909802, 2461102.961515, 2461103.00199, 2461103.05369, 2461103.099407, 1.1505, 2.1837],
        ['2026-08-28', 'partial', 2461280.675675, 2461280.55831, 2461280.606864, 0.0, 0.0, 2461280.744482, 2461280.79294, 0.93, 1.9646],
        ['2027-02-20', 'penumbral', 2461457.467261, 2461457.383608, 0.0, 0.0, 0.0, 0.0, 2461457.550951, 0.0, 0.9258],
        ['2027-07-18', 'penumbral', 2461605.168761, 2461605.164753, 0.0, 0.0, 0.0, 0.0, 2461605.172761, 0.0, 0.0014],
        ['2027-08-17', 'penumbral', 2461634.801292, 2461634.72536, 0.0, 0.0, 0.0, 0.0, 2461634.877218, 0.0, 0.5457],
        ['2028-01-12', 'partial', 2461782.67579, 2461782.588703, 2461782.656268, 0.0, 0.0, 2461782.695329, 2461782.762844, 0.0667, 1.0467],
        ['2028-07-06', 'partial', 2461959.263691, 2461959.155849, 2461959.214577, 0.0, 0.0, 2461959.312797, 2461959.371616, 0.3887, 1.4261],
        ['2028-12-31', 'total', 2462137.202869, 2462137.086031, 2462137.13034, 2462137.178086, 2462137.227641, 2462137.275397, 2462137.319605, 1.2464, 2.2744],
        ['2029-06-26', 'total', 2462313.64041, 2462313.52406, 2462313.564158, 2462313.605028, 2462313.675812, 2462313.716673, 2462313.75686, 1.8443, 2.8268],
        ['2029-12-20', 'total', 2462491.445872, 2462491.321487, 2462491.371821, 2462491.427282, 2462491.464452, 2462491.519921, 2462491.570171, 1.1168, 2.2007],
        ['2030-06-15', 'partial', 2462668.27318, 2462668.176562, 2462668.222997, 0.0, 0.0, 2462668.323363, 2462668.36983, 0.5033, 1.4479],
        ['2030-12-09', 'penumbral', 2462845.435873, 2462845.338871, 0.0, 0.0, 0.0, 0.0, 2462845.532882, 0.0, 0.9419],
    ];

    /** @var list<\Astronomy\SolarEclipse>|null */
    private static ?array $solar = null;

    /** @var list<\Astronomy\LunarEclipse>|null */
    private static ?array $lunar = null;

    /** Seconds the decade search took, for the performance check. */
    private static float $searchSeconds = 0.0;

    private static function jd(string $date): float
    {
        return Time::julianDay(new DateTimeImmutable($date, new DateTimeZone('UTC')));
    }

    /**
     * The decade is searched only once for the whole class: it is about seven
     * seconds and several tests look at it.
     */
    private static function decade(): void
    {
        if (self::$solar !== null) {
            return;
        }

        $start = microtime(true);
        self::$solar = Eclipses::solar(self::jd('2020-01-01'), self::jd('2031-01-01'));
        self::$lunar = Eclipses::lunar(self::jd('2020-01-01'), self::jd('2031-01-01'));
        self::$searchSeconds = microtime(true) - $start;
    }

    private function seconds(float $jdA, float $jdB): float
    {
        return abs($jdA - $jdB) * 86400.0;
    }

    public function test_solar_eclipses_2020_to_2030_match_swiss(): void
    {
        self::decade();

        $this->assertCount(count(self::SOLAR_ECLIPSES), self::$solar);

        foreach (self::SOLAR_ECLIPSES as $i => [$date, $type, $central, $maximum, $p1, $p4, $u1, $u4, $c1, $c4, $magnitude, $latitude, $longitude]) {
            $eclipse = self::$solar[$i];

            $this->assertSame($date, $eclipse->maximum->date->format('Y-m-d'));
            $this->assertSame(EclipseType::from($type), $eclipse->type, "Eclipse type on {$date}");
            $this->assertSame($central, $eclipse->central, "Centrality of the eclipse on {$date}");

            $this->assertLessThan(self::TOLERANCE, $this->seconds($eclipse->maximum->jdUt, $maximum), "Maximum of {$date}");

            foreach ([
                'partialStart' => $p1, 'partialEnd' => $p4,
                'centralStart' => $u1, 'centralEnd' => $u4,
                'centralLineStart' => $c1, 'centralLineEnd' => $c4,
            ] as $contact => $expected) {
                if ($expected == 0.0) {
                    $this->assertNull($eclipse->$contact, "{$contact} on {$date} should not exist");

                    continue;
                }

                $this->assertNotNull($eclipse->$contact, "{$contact} on {$date}");
                $this->assertLessThan(self::TOLERANCE, $this->seconds($eclipse->$contact->jdUt, $expected), "{$contact} on {$date}");
            }

            $this->assertEqualsWithDelta($magnitude, $eclipse->magnitude, self::MAGNITUDE_TOLERANCE, "Magnitude of {$date}");

            // The point of maximum eclipse, to half a degree: Swiss refines it with a
            // second pass over the flattening and here the ellipsoid is solved directly.
            $this->assertEqualsWithDelta($latitude, $eclipse->maximumLatitude, 0.5, "Latitude of the maximum on {$date}");
            $this->assertEqualsWithDelta($longitude, $eclipse->maximumLongitude, 0.5, "Longitude of the maximum on {$date}");
        }
    }

    public function test_lunar_eclipses_2020_to_2030_match_swiss(): void
    {
        self::decade();

        $this->assertCount(count(self::LUNAR_ECLIPSES), self::$lunar);

        foreach (self::LUNAR_ECLIPSES as $i => [$date, $type, $maximum, $p1, $u1, $u2, $u3, $u4, $p4, $umbral, $penumbral]) {
            $eclipse = self::$lunar[$i];

            $this->assertSame($date, $eclipse->maximum->date->format('Y-m-d'));
            $this->assertSame(EclipseType::from($type), $eclipse->type, "Eclipse type on {$date}");
            $this->assertLessThan(self::TOLERANCE, $this->seconds($eclipse->maximum->jdUt, $maximum), "Maximum of {$date}");

            foreach (['p1' => $p1, 'u1' => $u1, 'u2' => $u2, 'u3' => $u3, 'u4' => $u4, 'p4' => $p4] as $contact => $expected) {
                if ($expected == 0.0) {
                    $this->assertNull($eclipse->$contact, "{$contact} on {$date} should not exist");

                    continue;
                }

                $this->assertNotNull($eclipse->$contact, "{$contact} on {$date}");
                $this->assertLessThan(self::TOLERANCE, $this->seconds($eclipse->$contact->jdUt, $expected), "{$contact} on {$date}");
            }

            $this->assertEqualsWithDelta($umbral, $eclipse->umbralMagnitude, self::MAGNITUDE_TOLERANCE, "Umbral magnitude of {$date}");
            $this->assertEqualsWithDelta($penumbral, $eclipse->penumbralMagnitude, self::MAGNITUDE_TOLERANCE, "Penumbral magnitude of {$date}");
        }
    }

    /**
     * Gamma is what separates a central eclipse from a partial one: with the axis
     * less than one Earth radius from the centre, the umbra or the antumbra touch the
     * surface. And it carries a sign, so both have to occur.
     */
    public function test_gamma_separates_central_from_partial_and_carries_a_sign(): void
    {
        self::decade();

        $signs = [];

        foreach (self::$solar as $eclipse) {
            $this->assertSame($eclipse->central, abs($eclipse->gamma) < 1.0, "Gamma {$eclipse->gamma} of {$eclipse->maximum->date->format('Y-m-d')}");
            $this->assertLessThan(1.6, abs($eclipse->gamma));
            $signs[$eclipse->gamma > 0 ? 'north' : 'south'] = true;
        }

        // In the lunar ones, the umbra measures about 0.72 Earth radii at the Moon's
        // distance and the Moon 0.27: total under 0.45, partial up to one, penumbral
        // up to 1.6. The limits carry margin because the umbra changes with distance.
        foreach (self::$lunar as $eclipse) {
            $gamma = abs($eclipse->gamma);
            $date = $eclipse->maximum->date->format('Y-m-d');

            match ($eclipse->type) {
                EclipseType::Total => $this->assertLessThan(0.5, $gamma, "Total on {$date} with gamma {$gamma}"),
                EclipseType::Partial => $this->assertTrue($gamma > 0.4 && $gamma < 1.05, "Partial on {$date} with gamma {$gamma}"),
                default => $this->assertTrue($gamma > 0.95 && $gamma < 1.7, "Penumbral on {$date} with gamma {$gamma}"),
            };

            $signs[$eclipse->gamma > 0 ? 'north' : 'south'] = true;
        }

        $this->assertCount(2, $signs);
    }

    /**
     * What astrology uses: the zodiac degree. The total of 8 April 2024 fell at 19°
     * Aries and the one of 12 August 2026 falls at 20° Leo.
     */
    public function test_the_zodiac_degree_is_the_suns_at_maximum(): void
    {
        self::decade();

        $april2024 = self::$solar[8];
        $this->assertSame('2024-04-08', $april2024->maximum->date->format('Y-m-d'));
        $this->assertSame(Sign::Aries, $april2024->sign());
        $this->assertEqualsWithDelta(19.4, $april2024->degreesInSign(), 0.1);

        $august2026 = self::$solar[13];
        $this->assertSame(Sign::Leo, $august2026->sign());
        $this->assertEqualsWithDelta(20.0, $august2026->degreesInSign(), 0.1);

        // And in a lunar eclipse it is the Moon, which is opposite the Sun: the total
        // of 14 March 2025 was at 24° Virgo.
        $march2025 = self::$lunar[12];
        $this->assertSame('2025-03-14', $march2025->maximum->date->format('Y-m-d'));
        $this->assertSame(Sign::Virgo, $march2025->sign());
        $this->assertEqualsWithDelta(23.9, $march2025->degreesInSign(), 0.15);
    }

    /**
     * The total of 12 August 2026 from Madrid, against `swe_sol_eclipse_when_loc`.
     *
     * Madrid falls just outside the path of totality (the line runs through Zaragoza
     * and Valencia), so from there it is partial with magnitude 0.9994, and the Sun
     * sets before the last contact. All four of these have to hold.
     */
    public function test_the_total_of_12_august_2026_from_madrid(): void
    {
        self::decade();

        $madrid = new Place('Madrid', null, 'Spain', 'ES', 40.4168, -3.7038, 'Europe/Madrid');
        $local = Eclipses::localSolar(self::$solar[13], $madrid);

        $this->assertNotNull($local);
        $this->assertSame(EclipseType::Partial, $local->type);
        $this->assertNull($local->contact2);
        $this->assertNull($local->contact3);

        // Swiss: maximum, first and fourth contact and sunset, in UT.
        $this->assertLessThan(self::TOLERANCE, $this->seconds($local->maximum->jdUt, 2461265.272482566));
        $this->assertLessThan(self::TOLERANCE, $this->seconds($local->contact1->jdUt, 2461265.233853826));
        $this->assertLessThan(self::TOLERANCE, $this->seconds($local->contact4->jdUt, 2461265.3086800096));
        $this->assertNotNull($local->set);
        $this->assertLessThan(self::TOLERANCE, $this->seconds($local->set->jdUt, 2461265.3011840056));
        $this->assertNull($local->rise);

        $this->assertEqualsWithDelta(0.9993884, $local->magnitude, self::MAGNITUDE_TOLERANCE);
        $this->assertEqualsWithDelta(1.0336606, $local->diameterRatio, self::MAGNITUDE_TOLERANCE);
        $this->assertEqualsWithDelta(0.9998600, $local->obscuration, self::MAGNITUDE_TOLERANCE);
        $this->assertEqualsWithDelta(7.192442, $local->altitude, 0.05);
        $this->assertEqualsWithDelta(103.325941, fmod($local->azimuth + 180.0, 360.0), 0.05);

        // Local time: 20:32 in the evening, with the Sun low in the west.
        $this->assertSame('2026-08-12 20:32', $local->maximum->date->format('Y-m-d H:i'));
        $this->assertSame('Europe/Madrid', $local->maximum->date->getTimezone()->getName());

        $this->assertTrue($local->visible['contact1']);
        $this->assertTrue($local->visible['maximum']);
        $this->assertFalse($local->visible['contact4']);
    }

    /**
     * The annular of 14 October 2023 from Albuquerque, which was inside the path: all
     * four contacts, with the ring between the second and the third.
     */
    public function test_the_annular_of_14_october_2023_from_albuquerque(): void
    {
        self::decade();

        $albuquerque = new Place('Albuquerque', 'New Mexico', 'United States', 'US', 35.0844, -106.6504, 'America/Denver');
        $local = Eclipses::localSolar(self::$solar[7], $albuquerque, 1600.0);

        $this->assertNotNull($local);
        $this->assertSame(EclipseType::Annular, $local->type);

        foreach ([
            'maximum' => 2460232.1923405854,
            'contact1' => 2460232.134198183,
            'contact2' => 2460232.1906673014,
            'contact3' => 2460232.194013293,
            'contact4' => 2460232.2565791085,
        ] as $contact => $expected) {
            $this->assertLessThan(self::TOLERANCE, $this->seconds($local->$contact->jdUt, $expected), $contact);
        }

        $this->assertEqualsWithDelta(0.9704764, $local->magnitude, self::MAGNITUDE_TOLERANCE);
        $this->assertEqualsWithDelta(0.9473169, $local->diameterRatio, self::MAGNITUDE_TOLERANCE);
        $this->assertEqualsWithDelta(0.9473169, $local->nasaMagnitude(), self::MAGNITUDE_TOLERANCE);
        $this->assertEqualsWithDelta(0.8974092, $local->obscuration, self::MAGNITUDE_TOLERANCE);
        $this->assertEqualsWithDelta(36.150605, $local->altitude, 0.05);

        // Four and a half minutes of ring, with the Sun high, everything visible,
        // with neither a rise nor a set in between.
        $this->assertEqualsWithDelta(289.0, $local->centralDuration(), 30.0);
        $this->assertTrue($local->isVisible());
        $this->assertNull($local->rise);
        $this->assertNull($local->set);
        $this->assertSame([], array_keys(array_filter($local->visible, fn (bool $v) => ! $v)));
    }

    public function test_where_an_eclipse_cannot_be_seen_there_are_no_circumstances(): void
    {
        self::decade();

        // The one of 12 August 2026 is at night in Singapore.
        $singapore = new Place('Singapore', null, 'Singapore', 'SG', 1.3521, 103.8198, 'Asia/Singapore');
        $this->assertNull(Eclipses::localSolar(self::$solar[13], $singapore));

        // And the annular of 2023 does not reach Madrid, not even by a whisker.
        $madrid = new Place('Madrid', null, 'Spain', 'ES', 40.4168, -3.7038, 'Europe/Madrid');
        $this->assertNull(Eclipses::localSolar(self::$solar[7], $madrid));
    }

    /**
     * The lunar eclipse of 16 May 2022 from Madrid: the Moon sets halfway through
     * totality, so the entry and the maximum are seen but not the exit. Against
     * `swe_lun_eclipse_when_loc`, which leaves the phases that are not seen at zero
     * and gives the moonset.
     */
    public function test_the_lunar_eclipse_of_may_2022_from_madrid(): void
    {
        self::decade();

        $madrid = new Place('Madrid', null, 'Spain', 'ES', 40.4168, -3.7038, 'Europe/Madrid');
        $local = Eclipses::localLunar(self::$lunar[6], $madrid);

        $this->assertNotNull($local);
        $this->assertSame(
            ['p1' => true, 'u1' => true, 'u2' => true, 'maximum' => true, 'u3' => true, 'u4' => false, 'p4' => false],
            $local->visible
        );
        $this->assertTrue($local->isVisible());
        $this->assertFalse($local->isFullyVisible());

        $this->assertNotNull($local->moonSet);
        $this->assertLessThan(self::TOLERANCE, $this->seconds($local->moonSet->jdUt, 2459715.7073414926));
        $this->assertNull($local->moonRise);
        $this->assertEqualsWithDelta(7.2047, $local->altitude, 0.05);

        // From Singapore the one of 7 September 2025 is seen whole, with the Moon high.
        $singapore = new Place('Singapore', null, 'Singapore', 'SG', 1.3521, 103.8198, 'Asia/Singapore');
        $whole = Eclipses::localLunar(self::$lunar[13], $singapore);
        $this->assertTrue($whole->isFullyVisible());
        $this->assertEqualsWithDelta(71.016, $whole->altitude, 0.05);
        $this->assertNull($whole->moonRise);
        $this->assertNull($whole->moonSet);
    }

    /**
     * A whole decade has to be searched in seconds, not minutes: it is computed by
     * syzygies and by projected motion, which is about forty Moon positions per
     * eclipse. Measured, seven seconds for the two lists; the cap is generous so it
     * does not fail on a slow machine.
     */
    public function test_a_decade_is_searched_in_seconds(): void
    {
        self::decade();

        $this->assertLessThan(40.0, self::$searchSeconds, sprintf('The decade took %.1f seconds', self::$searchSeconds));
    }
}
