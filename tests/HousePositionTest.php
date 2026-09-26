<?php

namespace Astronomy\Tests;

use Astronomy\Houses;
use Astronomy\HouseSystem;
use Astronomy\Time;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * The position of a body in its house counting its latitude (`swe_house_pos`).
 *
 * `houseOf()` looks at which cusps the longitude falls between, and that holds for the
 * systems that only exist over the ecliptic. For the ones that divide the sky with circles or
 * arcs, a body with latitude (the Moon up to five degrees, Pluto seventeen) can be above the
 * horizon while its ecliptic degree is below it, and close to a cusp that changes its house.
 * It is what astro.com does, and here it is checked against Swiss, against the cusps and
 * against `houseOf()`.
 */
class HousePositionTest extends TestCase
{
    private const PLACES = [
        'Madrid' => [40.4168, -3.7038],
        'Buenos Aires' => [-34.6037, -58.3816],
        'Singapore' => [1.3521, 103.8198],
        'Oslo' => [59.9139, 10.7522],
        'Ushuaia' => [-54.8019, -68.3030],
    ];

    /** Two of the three polar sites of `HousesTest`, one in each hemisphere. */
    private const POLAR_PLACES = [
        'Longyearbyen' => [78.22, 15.63],
        'Base Belgrano II' => [-77.87, -34.63],
    ];

    /**
     * Two charts with the midheaven below the horizon, one in each hemisphere, and three
     * bodies with real latitude in each one.
     *
     * pyswisseph 2.10.03 with Moshier: the coordinates from `swe.calc_ut(jd, body,
     * FLG_MOSEPH)` and the position from `swe.house_pos(armc, lat, eps, [lon, lat], letter)`.
     * The four systems that divide diurnal arcs are missing, since they throw there. The two
     * deliberate divergences that always come up (Sripati passing 12, Azimuthal in the
     * southern hemisphere) carry their own note.
     */
    private const SWISS_POLAR = [
            '2000-03-20 05:00|Longyearbyen' => [
                'moon' => [180.02939, 4.30756, [
                    'regiomontanus' => 7.70104,
                    'campanus' => 7.14961,
                    'porphyry' => 6.95728,
                    'equal' => 6.95606,
                    'whole-sign' => 7.00098,
                    'vehlow' => 7.45606,
                    'morinus' => 7.04555,
                    'meridian' => 7.10258,
                    'azimuthal' => 7.07359,
                    'krusinski' => 7.08279,
                    'sripati' => 7.45728,
                    'carter' => 7.01689,
                    'apc' => 7.68713,
                    'pullen-sd' => 6.95698,
                    'pullen-sr' => 6.95698,
                    'equal-mc' => 7.04179,
                    'sunshine' => 7.70218,
                ]],
                'pluto' => [252.89563, 11.15559, [
                    'regiomontanus' => 7.04622,
                    'campanus' => 7.00944,
                    'porphyry' => 9.45510,
                    'equal' => 9.38494,
                    'whole-sign' => 9.42985,
                    'vehlow' => 9.88494,
                    'morinus' => 9.51894,
                    'meridian' => 9.47410,
                    'azimuthal' => 9.48450,
                    'krusinski' => 9.88843,
                    'sripati' => 9.95510,
                    'carter' => 9.38840,
                    'apc' => 7.04496,
                    'pullen-sd' => 9.45907,
                    'pullen-sr' => 9.45895,
                    'equal-mc' => 9.47067,
                    'sunshine' => 7.04632,
                ]],
                'venus' => [338.30737, -1.24248, [
                    'regiomontanus' => 1.92316,
                    'campanus' => 1.20388,
                    'porphyry' => 12.25333,
                    'equal' => 12.23200,
                    'whole-sign' => 12.27691,
                    'vehlow' => 12.73200,
                    'morinus' => 12.26314,
                    'meridian' => 12.39181,
                    'azimuthal' => 12.34155,
                    'krusinski' => 10.95392,
                    'sripati' => 12.75333, // Swiss gives 1.00000: when it passes 12 it writes 1 instead of subtracting twelve
                    'carter' => 12.30612,
                    'apc' => 1.94019,
                    'pullen-sd' => 12.24811,
                    'pullen-sr' => 12.24794,
                    'equal-mc' => 12.31773,
                    'sunshine' => 1.92185,
                ]],
            ],
            '2000-03-20 20:00|Base Belgrano II' => [
                'moon' => [188.46055, 4.65509, [
                    'regiomontanus' => 1.65458,
                    'campanus' => 1.14293,
                    'porphyry' => 1.08156,
                    'equal' => 1.07085,
                    'whole-sign' => 1.28202,
                    'vehlow' => 1.57085,
                    'morinus' => 1.50625,
                    'meridian' => 1.51943,
                    'azimuthal' => 1.50212, // Swiss gives 12.49788: in the south it counts the azimuth backwards from its own cusps
                    'krusinski' => 1.60206,
                    'sripati' => 1.58156,
                    'carter' => 1.12628,
                    'apc' => 1.72664,
                    'pullen-sd' => 1.07859,
                    'pullen-sr' => 1.07900,
                    'equal-mc' => 1.46496,
                    'sunshine' => 1.64956,
                ]],
                'pluto' => [252.89383, 11.15845, [
                    'regiomontanus' => 1.52140,
                    'campanus' => 1.11224,
                    'porphyry' => 3.55417,
                    'equal' => 3.21862,
                    'whole-sign' => 3.42979,
                    'vehlow' => 3.71862,
                    'morinus' => 3.67369,
                    'meridian' => 3.62885,
                    'azimuthal' => 3.63607, // Swiss gives 10.36393: in the south it counts the azimuth backwards from its own cusps
                    'krusinski' => 3.91582,
                    'sripati' => 4.05417,
                    'carter' => 3.23570,
                    'apc' => 1.58305,
                    'pullen-sd' => 3.57041,
                    'pullen-sr' => 3.56819,
                    'equal-mc' => 3.61274,
                    'sunshine' => 1.51711,
                ]],
                'venus' => [339.07932, -1.25736, [
                    'regiomontanus' => 7.96863,
                    'campanus' => 7.22199,
                    'porphyry' => 6.19697,
                    'equal' => 6.09147,
                    'whole-sign' => 6.30264,
                    'vehlow' => 6.59147,
                    'morinus' => 6.44531,
                    'meridian' => 6.57097,
                    'azimuthal' => 6.51632, // Swiss gives 7.48368: in the south it counts the azimuth backwards from its own cusps
                    'krusinski' => 4.87224,
                    'sripati' => 6.69697,
                    'carter' => 6.17782,
                    'apc' => 7.90067,
                    'pullen-sd' => 6.17296,
                    'pullen-sr' => 6.16909,
                    'equal-mc' => 6.48559,
                    'sunshine' => 7.97494,
                ]],
            ],
    ];

    /**
     * Bodies with real latitude, and their house positions according to Swiss.
     *
     * pyswisseph 2.10.03 with Moshier. The coordinates of the Moon, Venus and Pluto come from
     * `swe.calc_ut(jd, body, FLG_MOSEPH)`; Chiron's, which Moshier does not carry, come from
     * our own ephemeris and are handed to Swiss as they are, since it only needs the pair.
     * The position is `swe.house_pos(armc, lat, eps, [lon, lat], letter)` with the armc and
     * the eps from `swe.houses_ex` of that chart; for Sunshine, preceded by
     * `swe.houses_armc(armc, lat, eps, b'I', sun_declination)`, which is how Swiss takes the
     * declination. The Gauquelin sectors are `house_pos` with `b'G'`, which gives the same as
     * `swe.gauquelin_sector` with the default method (checked).
     *
     * Three things are not copied as is, and are marked in the table:
     *
     * - **Koch returns 0 for a circumpolar body** (Pluto in Ushuaia in 1985, which does not
     *   come out there). Here that is `null` and an exception is expected.
     * - **Sripati**: Swiss adds half a house to Porphyry and when it passes 12 it writes 1
     *   instead of subtracting twelve, so the whole twelfth house comes out as 1.0. The table
     *   carries Porphyry plus 0.5 with the turn done correctly, which is what Swiss means to
     *   say.
     * - **Azimuthal in the southern hemisphere**: Swiss counts the azimuth with the
     *   northern frame without mirroring it, the opposite of how it calculates its own
     *   cusps (its cusp 2 comes out in house 12). The table carries `14 - p`, which is the
     *   position consistent with those cusps.
     */
    private const SWISS = [
        '1985-06-15 14:30|Madrid' => [
            'moon' => [51.87793, 0.36462, [
                'placidus' => 8.08000,
                'koch' => 8.05570,
                'regiomontanus' => 8.17572,
                'campanus' => 7.94341,
                'porphyry' => 7.95900,
                'alcabitius' => 7.89664,
                'topocentric' => 8.07749,
                'equal' => 7.99858,
                'whole-sign' => 8.72926,
                'vehlow' => 8.49858,
                'morinus' => 7.88787,
                'meridian' => 7.72492,
                'azimuthal' => 6.96739,
                'krusinski' => 8.14053,
                'sripati' => 8.45900,
                'carter' => 7.96985,
                'apc' => 8.23108,
                'pullen-sd' => 7.96860,
                'pullen-sr' => 7.96905,
                'equal-mc' => 7.87476,
                'sunshine' => 8.05886,
                'gauquelin' => 15.75999,
            ]],
            'venus' => [38.70220, -2.73734, [
                'placidus' => 7.59425,
                'koch' => 7.54609,
                'regiomontanus' => 7.64261,
                'campanus' => 7.49706,
                'porphyry' => 7.53722,
                'alcabitius' => 7.52238,
                'topocentric' => 7.59378,
                'equal' => 7.55939,
                'whole-sign' => 8.29007,
                'vehlow' => 8.05939,
                'morinus' => 7.45063,
                'meridian' => 7.32011,
                'azimuthal' => 6.90125,
                'krusinski' => 7.70036,
                'sripati' => 8.03722,
                'carter' => 7.56503,
                'apc' => 7.68471,
                'pullen-sd' => 7.54259,
                'pullen-sr' => 7.54285,
                'equal-mc' => 7.43556,
                'sunshine' => 7.55247,
                'gauquelin' => 17.21724,
            ]],
            'pluto' => [212.11974, 17.04274, [
                'placidus' => 1.16875,
                'koch' => 1.13416,
                'regiomontanus' => 1.16303,
                'campanus' => 1.12425,
                'porphyry' => 1.32650,
                'alcabitius' => 1.47731,
                'topocentric' => 1.16876,
                'equal' => 1.33998,
                'whole-sign' => 2.07066,
                'vehlow' => 1.83998,
                'morinus' => 1.22568,
                'meridian' => 1.27135,
                'azimuthal' => 1.27337,
                'krusinski' => 1.64366,
                'sripati' => 1.82650,
                'carter' => 1.51627,
                'apc' => 1.15155,
                'pullen-sd' => 1.32977,
                'pullen-sr' => 1.32992,
                'equal-mc' => 1.21615,
                'sunshine' => 1.21072,
                'gauquelin' => 36.49375,
            ]],
            'chiron' => [70.09070, -4.17482, [
                'placidus' => 8.62568,
                'koch' => 8.57803,
                'regiomontanus' => 8.71136,
                'campanus' => 8.45275,
                'porphyry' => 8.54203,
                'alcabitius' => 8.50493,
                'topocentric' => 8.62358,
                'equal' => 8.60567,
                'whole-sign' => 9.33636,
                'vehlow' => 9.10567,
                'morinus' => 8.46691,
                'meridian' => 8.38287,
                'azimuthal' => 7.46128,
                'krusinski' => 8.75146,
                'sripati' => 9.04203,
                'carter' => 8.62779,
                'apc' => 8.76312,
                'pullen-sd' => 8.54121,
                'pullen-sr' => 8.54117,
                'equal-mc' => 8.48185,
                'sunshine' => 8.60359,
                'gauquelin' => 14.12296,
            ]],
        ],
        '1985-06-15 14:30|Buenos Aires' => [
            'moon' => [51.87793, 0.36462, [
                'placidus' => 9.46823,
                'koch' => 9.53550,
                'regiomontanus' => 9.41340,
                'campanus' => 9.29753,
                'porphyry' => 9.62797,
                'alcabitius' => 9.59484,
                'topocentric' => 9.46905,
                'equal' => 10.06220,
                'whole-sign' => 10.72926,
                'vehlow' => 10.56220,
                'morinus' => 9.71047,
                'meridian' => 9.54751,
                'azimuthal' => 9.47299, // Swiss gives 4.52701: in the south it counts the azimuth backwards from its own cusps
                'krusinski' => 9.62279,
                'sripati' => 10.12797,
                'carter' => 9.89794,
                'apc' => 9.45517,
                'pullen-sd' => 9.61430,
                'pullen-sr' => 9.61145,
                'equal-mc' => 9.56650,
                'sunshine' => 9.48671,
                'gauquelin' => 11.59532,
            ]],
            'venus' => [38.70220, -2.73734, [
                'placidus' => 9.05567,
                'koch' => 9.25302,
                'regiomontanus' => 9.00552,
                'campanus' => 8.83776,
                'porphyry' => 9.25106,
                'alcabitius' => 9.23237,
                'topocentric' => 9.05597,
                'equal' => 9.62301,
                'whole-sign' => 10.29007,
                'vehlow' => 10.12301,
                'morinus' => 9.27322,
                'meridian' => 9.14270,
                'azimuthal' => 8.91990, // Swiss gives 5.08010: in the south it counts the azimuth backwards from its own cusps
                'krusinski' => 9.30165,
                'sripati' => 9.75106,
                'carter' => 9.49313,
                'apc' => 9.07055,
                'pullen-sd' => 9.22354,
                'pullen-sr' => 9.21781,
                'equal-mc' => 9.12731,
                'sunshine' => 9.12000,
                'gauquelin' => 12.83298,
            ]],
            'pluto' => [212.11974, 17.04274, [
                'placidus' => 3.11978,
                'koch' => 3.63424,
                'regiomontanus' => 3.13256,
                'campanus' => 2.97751,
                'porphyry' => 3.06276,
                'alcabitius' => 3.18871,
                'topocentric' => 3.11977,
                'equal' => 3.40359,
                'whole-sign' => 4.07066,
                'vehlow' => 3.90359,
                'morinus' => 3.04827,
                'meridian' => 3.09394,
                'azimuthal' => 2.48605, // Swiss gives 11.51395: in the south it counts the azimuth backwards from its own cusps
                'krusinski' => 3.26344,
                'sripati' => 3.56276,
                'carter' => 3.44437,
                'apc' => 3.08625,
                'pullen-sd' => 3.02831,
                'pullen-sr' => 3.02114,
                'equal-mc' => 2.90790,
                'sunshine' => 3.06280,
                'gauquelin' => 30.64067,
            ]],
            'chiron' => [70.09070, -4.17482, [
                'placidus' => 10.23960,
                'koch' => 10.14238,
                'regiomontanus' => 10.26385,
                'campanus' => 10.31960,
                'porphyry' => 10.20796,
                'alcabitius' => 10.23263,
                'topocentric' => 10.23928,
                'equal' => 10.66929,
                'whole-sign' => 11.33636,
                'vehlow' => 11.16929,
                'morinus' => 10.28951,
                'meridian' => 10.20546,
                'azimuthal' => 10.24587, // Swiss gives 3.75413: in the south it counts the azimuth backwards from its own cusps
                'krusinski' => 10.18171,
                'sripati' => 10.70796,
                'carter' => 10.55589,
                'apc' => 10.24441,
                'pullen-sd' => 10.19815,
                'pullen-sr' => 10.19981,
                'equal-mc' => 10.17360,
                'sunshine' => 10.22977,
                'gauquelin' => 9.28119,
            ]],
        ],
        '1985-06-15 14:30|Singapore' => [
            'moon' => [51.87793, 0.36462, [
                'placidus' => 4.14151,
                'koch' => 4.14277,
                'regiomontanus' => 4.14193,
                'campanus' => 4.14196,
                'porphyry' => 4.15161,
                'alcabitius' => 4.14145,
                'topocentric' => 4.14151,
                'equal' => 4.32077,
                'whole-sign' => 4.72926,
                'vehlow' => 4.82077,
                'morinus' => 4.30375,
                'meridian' => 4.14080,
                'azimuthal' => 4.38565,
                'krusinski' => 4.14735,
                'sripati' => 4.65161,
                'carter' => 4.15469,
                'apc' => 4.14230,
                'pullen-sd' => 4.14925,
                'pullen-sr' => 4.14940,
                'equal-mc' => 4.14260,
                'sunshine' => 4.14140,
                'gauquelin' => 27.57546,
            ]],
            'venus' => [38.70220, -2.73734, [
                'placidus' => 3.73515,
                'koch' => 3.73921,
                'regiomontanus' => 3.73468,
                'campanus' => 3.73461,
                'porphyry' => 3.72004,
                'alcabitius' => 3.73720,
                'topocentric' => 3.73515,
                'equal' => 3.88157,
                'whole-sign' => 4.29007,
                'vehlow' => 4.38157,
                'morinus' => 3.86651,
                'meridian' => 3.73599,
                'azimuthal' => 2.97780,
                'krusinski' => 3.72425,
                'sripati' => 4.22004,
                'carter' => 3.74988,
                'apc' => 3.73399,
                'pullen-sd' => 3.71606,
                'pullen-sr' => 3.71578,
                'equal-mc' => 3.70341,
                'sunshine' => 3.73566,
                'gauquelin' => 28.79454,
            ]],
            'pluto' => [212.11974, 17.04274, [
                'placidus' => 9.68754,
                'koch' => 9.70272,
                'regiomontanus' => 9.68772,
                'campanus' => 9.68763,
                'porphyry' => 9.51292,
                'alcabitius' => 9.68867,
                'topocentric' => 9.68754,
                'equal' => 9.66216,
                'whole-sign' => 10.07066,
                'vehlow' => 10.16216,
                'morinus' => 9.64156,
                'meridian' => 9.68723,
                'azimuthal' => 6.50195,
                'krusinski' => 9.67346,
                'sripati' => 10.01292,
                'carter' => 9.70112,
                'apc' => 9.68853,
                'pullen-sd' => 9.50600,
                'pullen-sr' => 9.50552,
                'equal-mc' => 9.48400,
                'sunshine' => 9.68658,
                'gauquelin' => 10.93738,
            ]],
            'chiron' => [70.09070, -4.17482, [
                'placidus' => 4.80263,
                'koch' => 4.80310,
                'regiomontanus' => 4.80468,
                'campanus' => 4.80488,
                'porphyry' => 4.79703,
                'alcabitius' => 4.80246,
                'topocentric' => 4.80263,
                'equal' => 4.92786,
                'whole-sign' => 5.33636,
                'vehlow' => 5.42786,
                'morinus' => 4.88279,
                'meridian' => 4.79875,
                'azimuthal' => 5.66002,
                'krusinski' => 4.83356,
                'sripati' => 5.29703,
                'carter' => 4.81264,
                'apc' => 4.80662,
                'pullen-sd' => 4.78464,
                'pullen-sr' => 4.78544,
                'equal-mc' => 4.74970,
                'sunshine' => 4.80192,
                'gauquelin' => 25.59212,
            ]],
        ],
        '1985-06-15 14:30|Oslo' => [
            'moon' => [51.87793, 0.36462, [
                'placidus' => 8.02276,
                'koch' => 8.03712,
                'regiomontanus' => 8.18329,
                'campanus' => 7.65583,
                'porphyry' => 7.74703,
                'alcabitius' => 7.69972,
                'topocentric' => 8.00149,
                'equal' => 7.85932,
                'whole-sign' => 8.72926,
                'vehlow' => 8.35932,
                'morinus' => 7.40601,
                'meridian' => 7.24305,
                'azimuthal' => 6.88692,
                'krusinski' => 8.33633,
                'sripati' => 8.24703,
                'carter' => 7.83863,
                'apc' => 8.33488,
                'pullen-sd' => 7.77226,
                'pullen-sr' => 7.77698,
                'equal-mc' => 7.40838,
                'sunshine' => 8.00580,
                'gauquelin' => 15.93172,
            ]],
            'venus' => [38.70220, -2.73734, [
                'placidus' => 7.43997,
                'koch' => 7.39518,
                'regiomontanus' => 7.51659,
                'campanus' => 7.26378,
                'porphyry' => 7.36523,
                'alcabitius' => 7.36196,
                'topocentric' => 7.43686,
                'equal' => 7.42013,
                'whole-sign' => 8.29007,
                'vehlow' => 7.92013,
                'morinus' => 6.96876,
                'meridian' => 6.83824,
                'azimuthal' => 6.66236,
                'krusinski' => 7.79786,
                'sripati' => 7.86523,
                'carter' => 7.43382,
                'apc' => 7.61667,
                'pullen-sd' => 7.37756,
                'pullen-sr' => 7.37987,
                'equal-mc' => 6.96919,
                'sunshine' => 7.38603,
                'gauquelin' => 17.68008,
            ]],
            'pluto' => [212.11974, 17.04274, [
                'placidus' => 12.59831,
                'koch' => 12.68638,
                'regiomontanus' => 12.57424,
                'campanus' => 12.78389,
                'porphyry' => 1.17448,
                'alcabitius' => 1.32128,
                'topocentric' => 12.59841,
                'equal' => 1.20071,
                'whole-sign' => 2.07066,
                'vehlow' => 1.70071,
                'morinus' => 12.74381,
                'meridian' => 12.78948,
                'azimuthal' => 12.88160,
                'krusinski' => 1.71963,
                'sripati' => 1.67448,
                'carter' => 1.38506,
                'apc' => 12.48777,
                'pullen-sd' => 1.18038,
                'pullen-sr' => 1.18148,
                'equal-mc' => 12.74977,
                'sunshine' => 12.68871,
                'gauquelin' => 2.20506,
            ]],
            'chiron' => [70.09070, -4.17482, [
                'placidus' => 8.47325,
                'koch' => 8.47301,
                'regiomontanus' => 8.61944,
                'campanus' => 7.98696,
                'porphyry' => 8.27479,
                'alcabitius' => 8.24868,
                'topocentric' => 8.45496,
                'equal' => 8.46641,
                'whole-sign' => 9.33636,
                'vehlow' => 8.96641,
                'morinus' => 7.98505,
                'meridian' => 7.90100,
                'azimuthal' => 7.48650,
                'krusinski' => 8.90923,
                'sripati' => 8.77479,
                'carter' => 8.49658,
                'apc' => 8.76452,
                'pullen-sd' => 8.28861,
                'pullen-sr' => 8.29091,
                'equal-mc' => 8.01547,
                'sunshine' => 8.45951,
                'gauquelin' => 14.58025,
            ]],
        ],
        '1985-06-15 14:30|Ushuaia' => [
            'moon' => [51.87793, 0.36462, [
                'placidus' => 9.82185,
                'koch' => 9.86512,
                'regiomontanus' => 9.76763,
                'campanus' => 9.60079,
                'porphyry' => 9.91958,
                'alcabitius' => 9.91302,
                'topocentric' => 9.82513,
                'equal' => 11.20676,
                'whole-sign' => 11.72926,
                'vehlow' => 11.70676,
                'morinus' => 10.04118,
                'meridian' => 9.87823,
                'azimuthal' => 9.87960, // Swiss gives 4.12040: in the south it counts the azimuth backwards from its own cusps
                'krusinski' => 9.91018,
                'sripati' => 10.41958,
                'carter' => 11.07841,
                'apc' => 9.84000,
                'pullen-sd' => 9.91292,
                'pullen-sr' => 9.90817,
                'equal-mc' => 9.88413,
                'sunshine' => 9.84424,
                'gauquelin' => 10.53444,
            ]],
            'venus' => [38.70220, -2.73734, [
                'placidus' => 9.34862,
                'koch' => 9.82023,
                'regiomontanus' => 9.25871,
                'campanus' => 8.82165,
                'porphyry' => 9.61477,
                'alcabitius' => 9.62388,
                'topocentric' => 9.35083,
                'equal' => 10.76757,
                'whole-sign' => 11.29007,
                'vehlow' => 11.26757,
                'morinus' => 9.60393,
                'meridian' => 9.47341,
                'azimuthal' => 9.44298, // Swiss gives 4.55702: in the south it counts the azimuth backwards from its own cusps
                'krusinski' => 9.65798,
                'sripati' => 10.11477,
                'carter' => 10.67359,
                'apc' => 9.47863,
                'pullen-sd' => 9.58287,
                'pullen-sr' => 9.56013,
                'equal-mc' => 9.44494,
                'sunshine' => 9.49183,
                'gauquelin' => 11.95414,
            ]],
            'pluto' => [212.11974, 17.04274, [
                'placidus' => 3.45742,
                'koch' => null, // Swiss returns 0: circumpolar, no position
                'regiomontanus' => 3.47386,
                'campanus' => 3.12921,
                'porphyry' => 3.46250,
                'alcabitius' => 3.58906,
                'topocentric' => 3.45737,
                'equal' => 4.54815,
                'whole-sign' => 5.07066,
                'vehlow' => 5.04815,
                'morinus' => 3.37899,
                'meridian' => 3.42465,
                'azimuthal' => 3.27335, // Swiss gives 10.72665: in the south it counts the azimuth backwards from its own cusps
                'krusinski' => 3.63124,
                'sripati' => 3.96250,
                'carter' => 4.62483,
                'apc' => 3.40513,
                'pullen-sd' => 3.41797,
                'pullen-sr' => 3.38624,
                'equal-mc' => 3.22552,
                'sunshine' => 3.40425,
                'gauquelin' => 29.62775,
            ]],
            'chiron' => [70.09070, -4.17482, [
                'placidus' => 10.76763,
                'koch' => 10.68392,
                'regiomontanus' => 10.95880,
                'campanus' => 11.45339,
                'porphyry' => 10.87856,
                'alcabitius' => 10.89371,
                'topocentric' => 10.75626,
                'equal' => 11.81385,
                'whole-sign' => 12.33636,
                'vehlow' => 12.31385,
                'morinus' => 10.62022,
                'meridian' => 10.53617,
                'azimuthal' => 10.53105, // Swiss gives 3.46895: in the south it counts the azimuth backwards from its own cusps
                'krusinski' => 10.52386,
                'sripati' => 11.37856,
                'carter' => 11.73636,
                'apc' => 10.68517,
                'pullen-sd' => 10.73389,
                'pullen-sr' => 10.79113,
                'equal-mc' => 10.49122,
                'sunshine' => 10.66845,
                'gauquelin' => 7.69711,
            ]],
        ],
        '1930-02-14 03:35|Oslo' => [
            'moon' => [155.88106, 4.33834, [
                'placidus' => 8.72322,
                'koch' => 9.83441,
                'regiomontanus' => 8.83340,
                'campanus' => 8.18673,
                'porphyry' => 8.80777,
                'alcabitius' => 8.92367,
                'topocentric' => 8.71653,
                'equal' => 9.73686,
                'whole-sign' => 10.19604,
                'vehlow' => 10.23686,
                'morinus' => 8.20163,
                'meridian' => 8.37910,
                'azimuthal' => 8.03649,
                'krusinski' => 9.26069,
                'sripati' => 9.30777,
                'carter' => 9.89697,
                'apc' => 9.26897,
                'pullen-sd' => 8.76311,
                'pullen-sr' => 8.73668,
                'equal-mc' => 8.19502,
                'sunshine' => 9.02287,
                'gauquelin' => 13.83034,
            ]],
            'venus' => [326.52422, -1.37761, [
                'placidus' => 2.47457,
                'koch' => 3.45052,
                'regiomontanus' => 2.60015,
                'campanus' => 1.97036,
                'porphyry' => 2.60175,
                'alcabitius' => 2.70076,
                'topocentric' => 2.46627,
                'equal' => 3.42497,
                'whole-sign' => 3.88414,
                'vehlow' => 3.92497,
                'morinus' => 1.87593,
                'meridian' => 2.04340,
                'azimuthal' => 1.69377,
                'krusinski' => 3.15151,
                'sripati' => 3.10175,
                'carter' => 3.56128,
                'apc' => 2.43912,
                'pullen-sd' => 2.58699,
                'pullen-sr' => 2.57825,
                'equal-mc' => 1.88313,
                'sunshine' => 2.47912,
                'gauquelin' => 32.57628,
            ]],
            'pluto' => [107.84115, -0.22001, [
                'placidus' => 7.79724,
                'koch' => 8.54177,
                'regiomontanus' => 7.96766,
                'campanus' => 7.51825,
                'porphyry' => 7.75005,
                'alcabitius' => 7.81676,
                'topocentric' => 7.76039,
                'equal' => 8.13553,
                'whole-sign' => 8.59471,
                'vehlow' => 8.63553,
                'morinus' => 6.61710,
                'meridian' => 6.71213,
                'azimuthal' => 6.37969,
                'krusinski' => 8.60760,
                'sripati' => 8.25005,
                'carter' => 8.23001,
                'apc' => 8.49142,
                'pullen-sd' => 7.81961,
                'pullen-sr' => 7.88259,
                'equal-mc' => 6.59369,
                'sunshine' => 8.17614,
                'gauquelin' => 16.60827,
            ]],
            'chiron' => [39.94156, -1.72617, [
                'placidus' => 4.46019,
                'koch' => 4.57990,
                'regiomontanus' => 4.56295,
                'campanus' => 5.04001,
                'porphyry' => 4.67971,
                'alcabitius' => 4.68505,
                'topocentric' => 4.45561,
                'equal' => 5.87221,
                'whole-sign' => 6.33139,
                'vehlow' => 6.37221,
                'morinus' => 4.48169,
                'meridian' => 4.33844,
                'azimuthal' => 4.34370,
                'krusinski' => 4.32095,
                'sripati' => 5.17971,
                'carter' => 5.85632,
                'apc' => 4.63867,
                'pullen-sd' => 4.53759,
                'pullen-sr' => 4.60121,
                'equal-mc' => 4.33037,
                'sunshine' => 4.62309,
                'gauquelin' => 26.61943,
            ]],
        ],
        '1930-02-14 03:35|Ushuaia' => [
            'moon' => [155.88106, 4.33834, [
                'placidus' => 11.29888,
                'koch' => 11.05282,
                'regiomontanus' => 11.46598,
                'campanus' => 11.97165,
                'porphyry' => 10.67781,
                'alcabitius' => 10.72149,
                'topocentric' => 11.29302,
                'equal' => 9.64390,
                'whole-sign' => 10.19604,
                'vehlow' => 10.14390,
                'morinus' => 10.83680,
                'meridian' => 11.01427,
                'azimuthal' => 11.03441, // Swiss gives 2.96559: in the south it counts the azimuth backwards from its own cusps
                'krusinski' => 10.58872,
                'sripati' => 11.17781,
                'carter' => 9.79688,
                'apc' => 11.62159,
                'pullen-sd' => 10.73437,
                'pullen-sr' => 10.77527,
                'equal-mc' => 10.97959,
                'sunshine' => 11.57235,
                'gauquelin' => 6.10336,
            ]],
            'venus' => [326.52422, -1.37761, [
                'placidus' => 4.88075,
                'koch' => 4.60661,
                'regiomontanus' => 5.02499,
                'campanus' => 5.53020,
                'porphyry' => 4.46200,
                'alcabitius' => 4.48270,
                'topocentric' => 4.87559,
                'equal' => 3.33201,
                'whole-sign' => 3.88414,
                'vehlow' => 3.83201,
                'morinus' => 4.51110,
                'meridian' => 4.67857,
                'azimuthal' => 4.69765, // Swiss gives 9.30235: in the south it counts the azimuth backwards from its own cusps
                'krusinski' => 4.42525,
                'sripati' => 4.96200,
                'carter' => 3.46119,
                'apc' => 4.73122,
                'pullen-sd' => 4.50055,
                'pullen-sr' => 4.52843,
                'equal-mc' => 4.66769,
                'sunshine' => 4.88986,
                'gauquelin' => 25.35775,
            ]],
            'pluto' => [107.84115, -0.22001, [
                'placidus' => 8.93136,
                'koch' => 8.72915,
                'regiomontanus' => 8.58962,
                'campanus' => 8.07807,
                'porphyry' => 8.87928,
                'alcabitius' => 8.90156,
                'topocentric' => 8.96751,
                'equal' => 8.04257,
                'whole-sign' => 8.59471,
                'vehlow' => 8.54257,
                'morinus' => 9.25227,
                'meridian' => 9.34730,
                'azimuthal' => 9.38429, // Swiss gives 4.61571: in the south it counts the azimuth backwards from its own cusps
                'krusinski' => 9.31370,
                'sripati' => 9.37928,
                'carter' => 8.12991,
                'apc' => 8.43675,
                'pullen-sd' => 9.06656,
                'pullen-sr' => 8.98835,
                'equal-mc' => 9.37826,
                'sunshine' => 8.48478,
                'gauquelin' => 13.20591,
            ]],
            'chiron' => [39.94156, -1.72617, [
                'placidus' => 6.44710,
                'koch' => 6.01515,
                'regiomontanus' => 6.36480,
                'campanus' => 6.62465,
                'porphyry' => 6.15533,
                'alcabitius' => 6.11526,
                'topocentric' => 6.44993,
                'equal' => 5.77925,
                'whole-sign' => 6.33139,
                'vehlow' => 6.27925,
                'morinus' => 7.11687,
                'meridian' => 6.97362,
                'azimuthal' => 7.23475, // Swiss gives 6.76525: in the south it counts the azimuth backwards from its own cusps
                'krusinski' => 5.52932,
                'sripati' => 6.65533,
                'carter' => 5.75623,
                'apc' => 6.05957,
                'pullen-sd' => 6.08484,
                'pullen-sr' => 6.03387,
                'equal-mc' => 7.11494,
                'sunshine' => 6.23579,
                'gauquelin' => 20.65871,
            ]],
        ],
    ];

    private function jd(string $date = '1985-06-15 14:30'): float
    {
        return Time::julianDay(new DateTimeImmutable($date, new DateTimeZone('UTC')));
    }

    /**
     * Difference between two house positions, without the wrap from 12 to 1 counting.
     */
    private function difference(float $a, float $b): float
    {
        return abs(fmod(abs($a - $b) + 6, 12) - 6);
    }

    /**
     * The house positions of the twenty one systems and the Gauquelin sectors against Swiss,
     * with four bodies with latitude in seven charts, Oslo included.
     *
     * Measured: the worst difference of the 616 positions is 0.00002 houses (Gauquelin, Pluto
     * in Ushuaia in 1930), which is what our sidereal time is off from theirs.
     */
    public function test_the_positions_with_latitude_agree_with_swiss(): void
    {
        foreach (self::SWISS as $key => $bodies) {
            [$date, $place] = explode('|', $key);
            [$lat, $lon] = self::PLACES[$place];
            $jd = $this->jd($date);

            foreach ($bodies as $body => [$longitude, $latitude, $positions]) {
                foreach ($positions as $value => $expected) {
                    if ($value === 'gauquelin') {
                        $ours = Houses::calculate(HouseSystem::Placidus, $jd, $lat, $lon)->gauquelinSector($longitude, $latitude);
                        $difference = abs(fmod(abs($ours - $expected) + 18, 36) - 18);

                        $this->assertLessThan(0.0005, $difference,
                            sprintf('Gauquelin, %s in %s on %s: ours %.5f, Swiss %.5f', $body, $place, $date, $ours, $expected));

                        continue;
                    }

                    $houses = Houses::calculate(HouseSystem::from($value), $jd, $lat, $lon);

                    if ($expected === null) {
                        try {
                            $houses->housePosition($longitude, $latitude);
                            $this->fail("{$value}, {$body} in {$place} on {$date}: Swiss gives no position and we do");
                        } catch (RuntimeException $e) {
                            $this->assertStringContainsString('circumpolar', $e->getMessage());
                        }

                        continue;
                    }

                    $ours = $houses->housePosition($longitude, $latitude);

                    $this->assertGreaterThanOrEqual(1.0, $ours);
                    $this->assertLessThan(13.0, $ours);
                    $this->assertLessThan(0.0005, $this->difference($ours, $expected),
                        sprintf('%s, %s in %s on %s: ours %.5f, Swiss %.5f', $value, $body, $place, $date, $ours, $expected));
                }
            }
        }
    }

    /**
     * With zero latitude, the position has to fall in the same house that `houseOf()` says
     * in ALL the systems. The two roads are different (one looks at the cusps, the other at
     * the geometry of the system) and can only agree if the cusps really are the boundaries
     * of that geometry.
     */
    public function test_with_zero_latitude_it_falls_in_the_same_house_as_house_of(): void
    {
        foreach (['1985-06-15 14:30', '1930-02-14 03:35'] as $date) {
            $jd = $this->jd($date);

            foreach (self::PLACES as $site => [$lat, $lon]) {
                foreach (HouseSystem::all() as $system) {
                    $houses = Houses::calculate($system, $jd, $lat, $lon);

                    for ($longitude = 2.5; $longitude < 360; $longitude += 5) {
                        // Right against a cusp, rounding decides, and that is not what is being measured.
                        foreach ($houses->cusps as $cusp) {
                            if (abs(fmod($longitude - $cusp + 540, 360) - 180) < 0.02) {
                                continue 2;
                            }
                        }

                        $this->assertSame(
                            $houses->houseOf($longitude),
                            $houses->houseWithLatitude($longitude, 0.0),
                            sprintf('%s in %s on %s, longitude %.1f: houseOf %d, with zero latitude %.4f',
                                $system->name(), $site, $date, $longitude, $houses->houseOf($longitude), $houses->housePosition($longitude, 0.0))
                        );
                    }
                }
            }
        }
    }

    /**
     * A cusp, fed into its own system with zero latitude, has to give an exact integer: it
     * is the boundary. That holds for the twenty three systems and for the thirty six
     * sectors.
     */
    public function test_a_cusp_gives_an_exact_integer_in_its_own_system(): void
    {
        foreach (['1985-06-15 14:30', '1930-02-14 03:35'] as $date) {
            $jd = $this->jd($date);

            foreach (self::PLACES as $site => [$lat, $lon]) {
                foreach (HouseSystem::all() as $system) {
                    $houses = Houses::calculate($system, $jd, $lat, $lon);

                    for ($house = 1; $house <= 12; $house++) {
                        $position = $houses->housePosition($houses->cusps[$house], 0.0);

                        $this->assertLessThan(1e-6, $this->difference($position, $house),
                            sprintf('%s in %s on %s: cusp %d gives position %.8f', $system->name(), $site, $date, $house, $position));
                    }
                }

                $houses = Houses::calculate(HouseSystem::Placidus, $jd, $lat, $lon);

                foreach ($houses->gauquelinSectors() as $sector => $cusp) {
                    $position = $houses->gauquelinSector($cusp, 0.0);

                    $this->assertLessThan(1e-6, abs(fmod(abs($position - $sector) + 18, 36) - 18),
                        sprintf('Gauquelin in %s on %s: sector %d gives position %.8f', $site, $date, $sector, $position));
                }
            }
        }
    }

    /**
     * Latitude changes the house of a body right against a cusp, in the systems that divide
     * the sky and not the ecliptic. That is the reason `housePosition()` exists, and it has
     * to be seen actually happening: in Oslo with Placidus, a body half a degree from a cusp
     * with the Moon's latitude falls in the house next door.
     */
    public function test_latitude_changes_the_house_near_a_cusp(): void
    {
        $houses = Houses::calculate(HouseSystem::Placidus, $this->jd(), 59.9139, 10.7522);
        $changes = 0;

        for ($house = 1; $house <= 12; $house++) {
            foreach ([-0.5, 0.5] as $offset) {
                foreach ([-5.0, 5.0] as $latitude) {
                    $longitude = $houses->cusps[$house] + $offset;

                    if ($houses->houseWithLatitude($longitude, $latitude) !== $houses->houseOf($longitude)) {
                        $changes++;
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $changes, 'Five degrees of latitude have not changed the house of any body right against a cusp');

        // And in an ecliptic system the latitude never counts.
        $equal = Houses::calculate(HouseSystem::Equal, $this->jd(), 59.9139, 10.7522);

        for ($house = 1; $house <= 12; $house++) {
            $longitude = $equal->cusps[$house] + 0.5;

            $this->assertSame($equal->houseOf($longitude), $equal->houseWithLatitude($longitude, 5.0));
            $this->assertSame($equal->houseOf($longitude), $equal->houseWithLatitude($longitude, -5.0));
        }
    }

    /**
     * Placidus inside the polar circle: a body that does not set has no semiarc, and Swiss
     * applies Otto Ludwig's procedure. And Koch cannot do that there, and says so.
     */
    public function test_circumpolar_bodies_follow_swiss_convention(): void
    {
        // Oslo, 15 June 1985 at 14:30 UTC. A point at 0 Cancer with eight degrees of northern
        // latitude has a declination of 31 and at 59.9 latitude it never sets.
        // pyswisseph: house_pos(armc, 59.9139, eps, [90, 8], b'P') = 9.29887, b'G' = 12.10339,
        // b'K' = 0 (no position), and for [270, -8]: P 3.29887, G 30.10339.
        $houses = Houses::calculate(HouseSystem::Placidus, $this->jd(), 59.9139, 10.7522);

        $this->assertLessThan(0.0005, $this->difference($houses->housePosition(90.0, 8.0), 9.29887));
        $this->assertLessThan(0.0005, $this->difference($houses->housePosition(270.0, -8.0), 3.29887));
        $this->assertLessThan(0.0005, abs($houses->gauquelinSector(90.0, 8.0) - 12.10339));
        $this->assertLessThan(0.0005, abs($houses->gauquelinSector(270.0, -8.0) - 30.10339));

        $this->expectException(RuntimeException::class);
        Houses::calculate(HouseSystem::Koch, $this->jd(), 59.9139, 10.7522)->housePosition(90.0, 8.0);
    }

    /**
     * Sripati is Porphyry plus half a house, and when it passes twelve, twelve is
     * subtracted: house twelve exists. Swiss writes 1 there and loses the fraction.
     */
    public function test_sripati_wraps_around_correctly(): void
    {
        $sripati = Houses::calculate(HouseSystem::Sripati, $this->jd(), 40.4168, -3.7038);
        $porphyry = Houses::calculate(HouseSystem::Porphyry, $this->jd(), 40.4168, -3.7038);

        // One degree past Sripati's cusp 12, which is the center of Porphyry's house 11.
        $longitude = $sripati->cusps[12] + 1;

        $this->assertSame(12, $sripati->houseWithLatitude($longitude, 0.0));
        $this->assertSame(12, $sripati->houseOf($longitude));
        $this->assertEqualsWithDelta(
            fmod($porphyry->housePosition($longitude, 0.0) + 0.5 - 1, 12) + 1,
            $sripati->housePosition($longitude, 0.0),
            1e-9
        );
    }

    /**
     * With no latitude or obliquity stored there is no geometry to apply, and that has to be
     * said instead of returning the position by longitude as if nothing were missing.
     */
    public function test_houses_built_by_hand_cannot_place_with_latitude(): void
    {
        $calculated = Houses::calculate(HouseSystem::Placidus, $this->jd(), 40.4168, -3.7038);

        $byHand = new Houses(
            system: $calculated->system,
            cusps: $calculated->cusps,
            ascendant: $calculated->ascendant,
            midheaven: $calculated->midheaven,
            vertex: $calculated->vertex,
            eastPoint: $calculated->eastPoint,
            siderealTime: $calculated->siderealTime,
        );

        // What does not need geometry keeps working.
        $this->assertSame($calculated->houseOf(100.0), $byHand->houseOf(100.0));
        $this->assertNull($byHand->kochCoAscendant);

        $this->expectException(RuntimeException::class);
        $byHand->housePosition(100.0, 2.0);
    }

    /**
     * The Gauquelin sectors run in the direction of the diurnal motion: a body that has just
     * risen is in sector 1 and one about to culminate in sector 9, and the same body in
     * Placidus goes the other way, from house 12 to house 10.
     */
    public function test_the_gauquelin_sectors_run_clockwise(): void
    {
        $houses = Houses::calculate(HouseSystem::Placidus, $this->jd(), 40.4168, -3.7038);

        $justRisen = $houses->cusps[1] - 2;   // two degrees above the eastern horizon
        $aboutToCulminate = $houses->cusps[10] + 2;

        $this->assertSame(1, (int) floor($houses->gauquelinSector($justRisen, 0.0)));
        $this->assertSame(12, $houses->houseWithLatitude($justRisen, 0.0));

        $this->assertSame(9, (int) floor($houses->gauquelinSector($aboutToCulminate, 0.0)));
        $this->assertSame(10, $houses->houseWithLatitude($aboutToCulminate, 0.0));

        for ($longitude = 0; $longitude < 360; $longitude += 15) {
            $sector = $houses->gauquelinSector($longitude, 3.0);

            $this->assertGreaterThanOrEqual(1.0, $sector);
            $this->assertLessThan(37.0, $sector);
        }
    }

    /**
     * And in the polar latitudes, with the midheaven sunk below the horizon.
     *
     * It is the check that pairs up with `HousesTest::test_the_polar_conventions_agree_with_swiss`:
     * moving the cusps is worth nothing if `housePosition()` keeps placing bodies with the
     * old map, because then a planet right on cusp one would say house seven. And no new
     * code is needed to make it fit: most systems place by geometry, which knows nothing of
     * conventions, and the ones that look at the ascendant take it already straightened out
     * from `Houses`.
     *
     * Both charts are in the rare case, one in the north and one in the south, and at the
     * equinox on purpose: there the Sun rises and sets, so Sunshine does not fall into its
     * degeneration and the system is measured rather than the limiting case.
     *
     * Measured over the four polar sites, three dates and a 24 hour sweep with 63 bodies of
     * different latitudes: the worst difference is 1e-8 houses, which is the pinch of a
     * millisecond of arc Swiss adds to its own so that a cusp falls inside its own house. The
     * only two exceptions are the same two deliberate divergences as always, and they are
     * marked in the table just like above.
     */
    public function test_the_polar_positions_agree_with_swiss(): void
    {
        foreach (self::SWISS_POLAR as $key => $bodies) {
            [$date, $place] = explode('|', $key);
            [$lat, $lon] = self::POLAR_PLACES[$place];
            $jd = $this->jd($date);

            foreach ($bodies as $body => [$longitude, $latitude, $positions]) {
                foreach ($positions as $value => $expected) {
                    $houses = Houses::calculate(HouseSystem::from($value), $jd, $lat, $lon);
                    $ours = $houses->housePosition($longitude, $latitude);

                    $this->assertGreaterThanOrEqual(1.0, $ours);
                    $this->assertLessThan(13.0, $ours);
                    $this->assertLessThan(0.0005, $this->difference($ours, $expected),
                        sprintf('%s, %s in %s on %s: ours %.5f, Swiss %.5f', $value, $body, $place, $date, $ours, $expected));

                    // And the whole house has to be the same one the cusps say, which is what
                    // would break if the cusps rotated and the position did not.
                    $this->assertSame($houses->houseOf($longitude), $houses->houseWithLatitude($longitude, 0.0),
                        sprintf('%s, %s in %s: the house by cusps and the house by geometry do not agree', $value, $body, $place));
                }
            }
        }
    }
}
