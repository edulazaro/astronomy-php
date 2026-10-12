<?php

namespace Astronomy\Tests;

use Astronomy\Ayanamsa;
use Astronomy\Houses;
use Astronomy\HouseSystem;
use Astronomy\Time;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * The house cusps, against their own definition and against Swiss Ephemeris.
 *
 * Each system says what it divides into twelve, and that can be checked on its own: if Placidus
 * shares out the time each degree takes to rise, that time is computed by another route and the
 * cusp has to be where it should. A house system implemented wrong gives a chart that looks fine
 * with the planets one house over, so measuring the definition is the only defence.
 *
 * **And against Swiss Ephemeris, because checking the definition cannot catch a definition that
 * is wrong.** That is the half `HousesTest` says it needs and did not have: its own docblock
 * tells how Koch was caught taking the ascensional difference of the ascendant instead of that
 * of the midheaven, with its eight intermediate cusps up to 35 degrees out while the four angles
 * looked perfectly normal, and the comparison that caught it lived outside this package. The
 * Swiss figures are copied in as constants, each with a note of where it comes from.
 */
final class HouseCuspsTest extends TestCase
{
    /** Places spread over both hemispheres and over very different latitudes. */
    private const PLACES = [
        'Madrid' => [40.4168, -3.7038],
        'Buenos Aires' => [-34.6037, -58.3816],
        'Singapore' => [1.3521, 103.8198],
        'Oslo' => [59.9139, 10.7522],
        'Ushuaia' => [-54.8019, -68.3030],
    ];

    /**
     * Three inhabited places past the polar circle, two north and one south.
     *
     * Esperanza base, which is the one people have heard of, sits at 63.4 degrees and is NOT past
     * the antarctic circle: there the midheaven never sinks and it would be of no use. Belgrano II,
     * at 77.87, is Longyearbyen's mirror.
     */
    private const POLAR_PLACES = [
        'Tromso' => [69.65, 18.96],
        'Longyearbyen' => [78.22, 15.63],
        'Base Belgrano II' => [-77.87, -34.63],
    ];

    /**
     * Six polar charts with their cusps according to Swiss, copied by hand.
     *
     * pyswisseph 2.10.03 with Moshier, `swe.houses_ex(jd, lat, lon, letter)`. The four that share
     * out diurnal arcs are missing because there they do not exist and throw; Swiss falls back to
     * Porphyry in their place and that is not a cusp to compare against.
     */
    private const SWISS_POLAR_CUSPS = [
            // midheaven BELOW the horizon, the Sun does not set
            '2000-06-21 23:00|Tromso' => [
                'regiomontanus' => [334.0457, 286.9035, 278.8093, 274.0380, 268.7608, 256.8056, 154.0457, 106.9035, 98.8093, 94.0380, 88.7608, 76.8056],
                'campanus' => [334.0457, 279.0044, 275.7545, 274.0380, 272.2601, 268.5212, 154.0457, 99.0044, 95.7545, 94.0380, 92.2601, 88.5212],
                'savard-a' => [334.0457, 280.1424, 276.4611, 274.0380, 271.4906, 267.0819, 154.0457, 100.1424, 96.4611, 94.0380, 91.4906, 87.0819],
                'porphyry' => [334.0457, 14.0432, 54.0406, 94.0380, 114.0406, 134.0432, 154.0457, 194.0432, 234.0406, 274.0380, 294.0406, 314.0432],
                'equal' => [334.0457, 4.0457, 34.0457, 64.0457, 94.0457, 124.0457, 154.0457, 184.0457, 214.0457, 244.0457, 274.0457, 304.0457],
                'whole-sign' => [330.0000, 0.0000, 30.0000, 60.0000, 90.0000, 120.0000, 150.0000, 180.0000, 210.0000, 240.0000, 270.0000, 300.0000],
                'vehlow' => [319.0457, 349.0457, 19.0457, 49.0457, 79.0457, 109.0457, 139.0457, 169.0457, 199.0457, 229.0457, 259.0457, 289.0457],
                'morinus' => [4.0380, 32.1376, 62.4259, 94.7937, 126.7334, 156.2701, 184.0380, 212.1376, 242.4259, 274.7937, 306.7334, 336.2701],
                'meridian' => [4.7937, 36.7334, 66.2701, 94.0380, 122.1376, 152.4259, 184.7937, 216.7334, 246.2701, 274.0380, 302.1376, 332.4259],
                'azimuthal' => [4.1305, 34.1303, 64.0842, 94.0380, 124.0381, 154.0843, 184.1305, 214.1303, 244.0842, 274.0380, 304.0381, 334.0843],
                'krusinski' => [334.0457, 346.0534, 14.2682, 94.0380, 130.8774, 144.3549, 154.0457, 166.0534, 194.2682, 274.0380, 310.8774, 324.3549],
                'sripati' => [324.0445, 354.0445, 34.0419, 74.0393, 104.0393, 124.0419, 144.0445, 174.0445, 214.0419, 254.0393, 284.0393, 304.0419],
                'carter' => [334.0457, 6.4646, 38.3087, 67.7193, 95.4486, 123.6244, 154.0457, 186.4646, 218.3087, 247.7193, 275.4486, 303.6244],
                'apc' => [334.0457, 285.2243, 278.2738, 274.0380, 269.4079, 259.6650, 154.0457, 110.5937, 100.2281, 94.0380, 86.9705, 69.6879],
                'pullen-sd' => [334.0457, 11.5438, 56.5400, 94.0380, 116.5400, 131.5438, 154.0457, 191.5438, 236.5400, 274.0380, 296.5400, 311.5438],
                'pullen-sr' => [334.0457, 10.4452, 57.6385, 94.0380, 115.6914, 132.3924, 154.0457, 190.4452, 237.6385, 274.0380, 295.6914, 312.3924],
                'equal-mc' => [4.0380, 34.0380, 64.0380, 94.0380, 124.0380, 154.0380, 184.0380, 214.0380, 244.0380, 274.0380, 304.0380, 334.0380],
                'sunshine' => [334.0457, 274.0380, 274.0380, 274.0380, 274.0380, 274.0380, 154.0457, 104.0563, 98.3498, 94.0380, 89.3170, 81.5264],
            ],
            // midheaven BELOW the horizon, the Sun rises and sets
            '2000-03-20 05:00|Longyearbyen' => [
                'regiomontanus' => [1.3475, 330.4671, 299.1567, 268.7756, 239.6612, 210.9239, 181.3475, 150.4671, 119.1567, 88.7756, 59.6612, 30.9239],
                'campanus' => [1.3475, 288.3424, 275.4618, 268.7756, 262.1584, 249.7702, 181.3475, 108.3424, 95.4618, 88.7756, 82.1584, 69.7702],
                'savard-a' => [1.3475, 291.0385, 277.2517, 268.7756, 260.4099, 247.2289, 181.3475, 111.0385, 97.2517, 88.7756, 80.4099, 67.2289],
                'porphyry' => [1.3475, 30.4902, 59.6329, 88.7756, 119.6329, 150.4902, 181.3475, 210.4902, 239.6329, 268.7756, 299.6329, 330.4902],
                'equal' => [1.3475, 31.3475, 61.3475, 91.3475, 121.3475, 151.3475, 181.3475, 211.3475, 241.3475, 271.3475, 301.3475, 331.3475],
                'whole-sign' => [0.0000, 30.0000, 60.0000, 90.0000, 120.0000, 150.0000, 180.0000, 210.0000, 240.0000, 270.0000, 300.0000, 330.0000],
                'vehlow' => [346.3475, 16.3475, 46.3475, 76.3475, 106.3475, 136.3475, 166.3475, 196.3475, 226.3475, 256.3475, 286.3475, 316.3475],
                'morinus' => [358.7756, 26.6381, 56.4323, 88.5456, 120.7893, 150.8123, 178.7756, 206.6381, 236.4323, 268.5456, 300.7893, 330.8123],
                'meridian' => [358.5456, 30.7893, 60.8123, 88.7756, 116.6381, 146.4323, 178.5456, 210.7893, 240.8123, 268.7756, 296.6381, 326.4323],
                'azimuthal' => [358.6662, 29.2126, 59.2620, 88.7756, 118.2362, 148.1762, 178.6662, 209.2126, 239.2620, 268.7756, 298.2362, 328.1762],
                'krusinski' => [1.3475, 8.6190, 22.0770, 88.7756, 159.9535, 173.9926, 181.3475, 188.6190, 202.0770, 268.7756, 339.9535, 353.9926],
                'sripati' => [345.9189, 15.9189, 45.0616, 74.2043, 104.2043, 135.0616, 165.9189, 195.9189, 225.0616, 254.2043, 284.2043, 315.0616],
                'carter' => [1.3475, 33.4659, 63.2685, 91.1344, 119.0937, 149.1082, 181.3475, 213.4659, 243.2685, 271.1344, 299.0937, 329.1082],
                'apc' => [1.3475, 331.0143, 299.6174, 268.7756, 239.2350, 210.4175, 181.3475, 149.9436, 118.7306, 88.7756, 60.0557, 31.4078],
                'pullen-sd' => [1.3475, 30.7045, 59.4186, 88.7756, 119.4186, 150.7045, 181.3475, 210.7045, 239.4186, 268.7756, 299.4186, 330.7045],
                'pullen-sr' => [1.3475, 30.6977, 59.4254, 88.7756, 119.4116, 150.7115, 181.3475, 210.6977, 239.4254, 268.7756, 299.4116, 330.7115],
                'equal-mc' => [358.7756, 28.7756, 58.7756, 88.7756, 118.7756, 148.7756, 178.7756, 208.7756, 238.7756, 268.7756, 298.7756, 328.7756],
                'sunshine' => [1.3475, 330.4247, 299.1217, 268.7756, 239.6937, 210.9631, 181.3475, 150.5096, 119.1920, 88.7756, 59.6286, 30.8845],
            ],
            // midheaven above the horizon, the Sun rises and sets
            '2000-03-20 16:00|Longyearbyen' => [
                'regiomontanus' => [174.3973, 185.4818, 203.3525, 255.3692, 320.5231, 342.5970, 354.3973, 5.4818, 23.3525, 75.3692, 140.5231, 162.5970],
                'campanus' => [174.3973, 214.9535, 238.0419, 255.3692, 274.4713, 304.7869, 354.3973, 34.9535, 58.0419, 75.3692, 94.4713, 124.7869],
                'savard-a' => [174.3973, 211.5650, 234.0224, 255.3692, 279.4178, 309.4322, 354.3973, 31.5650, 54.0224, 75.3692, 99.4178, 129.4322],
                'porphyry' => [174.3973, 201.3879, 228.3786, 255.3692, 288.3786, 321.3879, 354.3973, 21.3879, 48.3786, 75.3692, 108.3786, 141.3879],
                'equal' => [174.3973, 204.3973, 234.3973, 264.3973, 294.3973, 324.3973, 354.3973, 24.3973, 54.3973, 84.3973, 114.3973, 144.3973],
                'whole-sign' => [150.0000, 180.0000, 210.0000, 240.0000, 270.0000, 300.0000, 330.0000, 0.0000, 30.0000, 60.0000, 90.0000, 120.0000],
                'vehlow' => [159.3973, 189.3973, 219.3973, 249.3973, 279.3973, 309.3973, 339.3973, 9.3973, 39.3973, 69.3973, 99.3973, 129.3973],
                'morinus' => [165.3692, 192.9937, 221.6578, 252.7703, 285.3294, 316.5833, 345.3692, 12.9937, 41.6578, 72.7703, 105.3294, 136.5833],
                'meridian' => [162.7703, 195.3294, 226.5833, 255.3692, 282.9937, 311.6578, 342.7703, 15.3294, 46.5833, 75.3692, 102.9937, 131.6578],
                'azimuthal' => [161.1042, 197.2142, 228.9466, 255.3692, 280.1949, 307.7633, 341.1042, 17.2142, 48.9466, 75.3692, 100.1949, 127.7633],
                'krusinski' => [174.3973, 181.4459, 194.0394, 255.3692, 332.4690, 347.0635, 354.3973, 1.4459, 14.0394, 75.3692, 152.4690, 167.0635],
                'sripati' => [157.8926, 187.8926, 214.8833, 241.8739, 271.8739, 304.8833, 337.8926, 7.8926, 34.8833, 61.8739, 91.8739, 124.8833],
                'carter' => [174.3973, 206.7908, 237.1435, 265.2794, 293.0278, 322.5032, 354.3973, 26.7908, 57.1435, 85.2794, 113.0278, 142.5032],
                'apc' => [174.3973, 184.5101, 201.5440, 255.3692, 322.9071, 343.6906, 354.3973, 6.3125, 24.7456, 75.3692, 138.6693, 161.6529],
                'pullen-sd' => [174.3973, 202.1403, 227.6262, 255.3692, 287.6262, 322.1403, 354.3973, 22.1403, 47.6262, 75.3692, 107.6262, 142.1403],
                'pullen-sr' => [174.3973, 202.0583, 227.7082, 255.3692, 287.5378, 322.2287, 354.3973, 22.0583, 47.7082, 75.3692, 107.5378, 142.2287],
                'equal-mc' => [165.3692, 195.3692, 225.3692, 255.3692, 285.3692, 315.3692, 345.3692, 15.3692, 45.3692, 75.3692, 105.3692, 135.3692],
                'sunshine' => [174.3973, 185.4256, 203.2529, 255.3692, 320.6550, 342.6605, 354.3973, 5.5374, 23.4505, 75.3692, 140.3932, 162.5340],
            ],
            // midheaven BELOW the horizon, the Sun does not rise
            '2000-12-21 10:00|Longyearbyen' => [
                'regiomontanus' => [13.4575, 340.3914, 293.6986, 257.0307, 233.8660, 215.0246, 193.4575, 160.3914, 113.6986, 77.0307, 53.8660, 35.0246],
                'campanus' => [13.4575, 278.7727, 263.7276, 257.0307, 251.0283, 241.0918, 193.4575, 98.7727, 83.7276, 77.0307, 71.0283, 61.0918],
                'savard-a' => [13.4575, 282.3063, 265.6418, 257.0307, 249.5324, 239.2156, 193.4575, 102.3063, 85.6418, 77.0307, 69.5324, 59.2156],
                'porphyry' => [13.4575, 34.6485, 55.8396, 77.0307, 115.8396, 154.6485, 193.4575, 214.6485, 235.8396, 257.0307, 295.8396, 334.6485],
                'equal' => [13.4575, 43.4575, 73.4575, 103.4575, 133.4575, 163.4575, 193.4575, 223.4575, 253.4575, 283.4575, 313.4575, 343.4575],
                'whole-sign' => [0.0000, 30.0000, 60.0000, 90.0000, 120.0000, 150.0000, 180.0000, 210.0000, 240.0000, 270.0000, 300.0000, 330.0000],
                'vehlow' => [358.4575, 28.4575, 58.4575, 88.4575, 118.4575, 148.4575, 178.4575, 208.4575, 238.4575, 268.4575, 298.4575, 328.4575],
                'morinus' => [347.0307, 14.6552, 43.4429, 74.6989, 107.2580, 138.3684, 167.0307, 194.6552, 223.4429, 254.6989, 287.2580, 318.3684],
                'meridian' => [344.6989, 17.2580, 48.3684, 77.0307, 104.6552, 133.4429, 164.6989, 197.2580, 228.3684, 257.0307, 284.6552, 313.4429],
                'azimuthal' => [345.9497, 16.5655, 47.1080, 77.0307, 106.4240, 135.8856, 165.9497, 196.5655, 227.1080, 257.0307, 286.4240, 315.8856],
                'krusinski' => [13.4575, 19.6407, 29.8101, 77.0307, 170.9568, 186.5349, 193.4575, 199.6407, 209.8101, 257.0307, 350.9568, 6.5349],
                'sripati' => [354.0530, 24.0530, 45.2441, 66.4352, 96.4352, 135.2441, 174.0530, 204.0530, 225.2441, 246.4352, 276.4352, 315.2441],
                'carter' => [13.4575, 44.8461, 73.7566, 101.3889, 129.9387, 160.9089, 193.4575, 224.8461, 253.7566, 281.3889, 309.9387, 340.9089],
                'apc' => [13.4575, 349.0078, 303.9716, 257.0307, 229.6213, 210.8453, 193.4575, 154.5971, 109.3827, 77.0307, 55.7893, 37.5621],
                'pullen-sd' => [13.4575, 36.8508, 53.6374, 77.0307, 113.6374, 156.8508, 193.4575, 216.8508, 233.6374, 257.0307, 293.6374, 336.8508],
                'pullen-sr' => [13.4575, 36.1859, 54.3023, 77.0307, 112.8040, 157.6842, 193.4575, 216.1859, 234.3023, 257.0307, 292.8040, 337.6842],
                'equal-mc' => [347.0307, 17.0307, 47.0307, 77.0307, 107.0307, 137.0307, 167.0307, 197.0307, 227.0307, 257.0307, 287.0307, 317.0307],
                'sunshine' => [13.4575, 291.8114, 277.5871, 257.0307, 241.7515, 234.6941, 193.4575, 77.0307, 77.0307, 77.0307, 77.0307, 77.0307],
            ],
            // midheaven BELOW the horizon, the Sun does not rise
            '2000-06-21 14:00|Base Belgrano II' => [
                'regiomontanus' => [184.8546, 150.9752, 116.1791, 85.8150, 59.5346, 33.8081, 4.8546, 330.9752, 296.1791, 265.8150, 239.5346, 213.8081],
                'campanus' => [184.8546, 105.4625, 92.3562, 85.8150, 79.5021, 68.0392, 4.8546, 285.4625, 272.3562, 265.8150, 259.5021, 248.0392],
                'savard-a' => [184.8546, 108.3690, 94.1823, 85.8150, 77.8169, 65.6699, 4.8546, 288.3690, 274.1823, 265.8150, 257.8169, 245.6699],
                'porphyry' => [184.8546, 211.8414, 238.8282, 265.8150, 298.8282, 331.8414, 4.8546, 31.8414, 58.8282, 85.8150, 118.8282, 151.8414],
                'equal' => [184.8546, 214.8546, 244.8546, 274.8546, 304.8546, 334.8546, 4.8546, 34.8546, 64.8546, 94.8546, 124.8546, 154.8546],
                'whole-sign' => [180.0000, 210.0000, 240.0000, 270.0000, 300.0000, 330.0000, 0.0000, 30.0000, 60.0000, 90.0000, 120.0000, 150.0000],
                'vehlow' => [169.8546, 199.8546, 229.8546, 259.8546, 289.8546, 319.8546, 349.8546, 19.8546, 49.8546, 79.8546, 109.8546, 139.8546],
                'morinus' => [175.8150, 203.5787, 233.1021, 265.0321, 297.4055, 327.7077, 355.8150, 23.5787, 53.1021, 85.0321, 117.4055, 147.7077],
                'meridian' => [175.0321, 207.4055, 237.7077, 265.8150, 293.5787, 323.1021, 355.0321, 27.4055, 57.7077, 85.8150, 113.5787, 143.1021],
                'azimuthal' => [175.4549, 206.0172, 236.1933, 265.8150, 295.2617, 325.0778, 355.4549, 26.0172, 56.1933, 85.8150, 115.2617, 145.0778],
                'krusinski' => [184.8546, 192.1412, 205.0885, 265.8150, 342.1903, 357.2624, 4.8546, 12.1412, 25.0885, 85.8150, 162.1903, 177.2624],
                'sripati' => [168.3480, 198.3480, 225.3348, 252.3216, 282.3216, 315.3348, 348.3480, 18.3480, 45.3348, 72.3216, 102.3216, 135.3348],
                'carter' => [184.8546, 216.7909, 246.3229, 274.0894, 302.1917, 332.4848, 4.8546, 36.7909, 66.3229, 94.0894, 122.1917, 152.4848],
                'apc' => [184.8546, 153.2651, 118.0823, 85.8150, 58.0817, 32.0720, 4.8546, 329.0159, 294.7423, 265.8150, 240.6413, 215.2797],
                'pullen-sd' => [184.8546, 212.5947, 238.0749, 265.8150, 298.0749, 332.5947, 4.8546, 32.5947, 58.0749, 85.8150, 118.0749, 152.5947],
                'pullen-sr' => [184.8546, 212.5125, 238.1571, 265.8150, 297.9863, 332.6833, 4.8546, 32.5125, 58.1571, 85.8150, 117.9863, 152.6833],
                'equal-mc' => [175.8150, 205.8150, 235.8150, 265.8150, 295.8150, 325.8150, 355.8150, 25.8150, 55.8150, 85.8150, 115.8150, 145.8150],
                'sunshine' => [184.8546, 115.8730, 104.4117, 85.8150, 68.9092, 59.7697, 4.8546, 265.8150, 265.8150, 265.8150, 265.8150, 265.8150],
            ],
            // midheaven BELOW the horizon, the Sun rises and sets
            '2000-03-20 20:00|Base Belgrano II' => [
                'regiomontanus' => [186.3351, 151.9713, 115.5191, 84.5117, 58.8614, 34.3301, 6.3351, 331.9713, 295.5191, 264.5117, 238.8614, 214.3301],
                'campanus' => [186.3351, 104.4145, 91.0650, 84.5117, 78.2566, 67.0588, 6.3351, 284.4145, 271.0650, 264.5117, 258.2566, 247.0588],
                'savard-a' => [186.3351, 107.4115, 92.9077, 84.5117, 76.5979, 64.7669, 6.3351, 287.4115, 272.9077, 264.5117, 256.5979, 244.7669],
                'porphyry' => [186.3351, 212.3940, 238.4528, 264.5117, 298.4528, 332.3940, 6.3351, 32.3940, 58.4528, 84.5117, 118.4528, 152.3940],
                'equal' => [186.3351, 216.3351, 246.3351, 276.3351, 306.3351, 336.3351, 6.3351, 36.3351, 66.3351, 96.3351, 126.3351, 156.3351],
                'whole-sign' => [180.0000, 210.0000, 240.0000, 270.0000, 300.0000, 330.0000, 0.0000, 30.0000, 60.0000, 90.0000, 120.0000, 150.0000],
                'vehlow' => [171.3351, 201.3351, 231.3351, 261.3351, 291.3351, 321.3351, 351.3351, 21.3351, 51.3351, 81.3351, 111.3351, 141.3351],
                'morinus' => [174.5117, 202.2400, 231.6470, 263.4883, 295.9086, 326.3337, 354.5117, 22.2400, 51.6470, 83.4883, 115.9086, 146.3337],
                'meridian' => [173.4883, 205.9086, 236.3337, 264.5117, 292.2400, 321.6470, 353.4883, 25.9086, 56.3337, 84.5117, 112.2400, 141.6470],
                'azimuthal' => [174.0419, 204.6187, 234.8502, 264.5117, 293.9447, 323.7065, 354.0419, 24.6187, 54.8502, 84.5117, 113.9447, 143.7065],
                'krusinski' => [186.3351, 193.5111, 206.0731, 264.5117, 343.4443, 358.7643, 6.3351, 13.5111, 26.0731, 84.5117, 163.4443, 178.7643],
                'sripati' => [169.3645, 199.3645, 225.4234, 251.4822, 281.4822, 315.4234, 349.3645, 19.3645, 45.4234, 71.4822, 101.4822, 135.4234],
                'carter' => [186.3351, 218.1868, 247.6071, 275.3391, 303.5088, 333.9199, 6.3351, 38.1868, 67.6071, 95.3391, 123.5088, 153.9199],
                'apc' => [186.3351, 155.1485, 118.2329, 84.5117, 56.9618, 32.1118, 6.3351, 329.3733, 293.6458, 264.5117, 240.1928, 216.1144],
                'pullen-sd' => [186.3351, 213.3792, 237.4675, 264.5117, 297.4675, 333.3792, 6.3351, 33.3792, 57.4675, 84.5117, 117.4675, 153.3792],
                'pullen-sr' => [186.3351, 213.2400, 237.6067, 264.5117, 297.3138, 333.5329, 6.3351, 33.2400, 57.6067, 84.5117, 117.3138, 153.5329],
                'equal-mc' => [174.5117, 204.5117, 234.5117, 264.5117, 294.5117, 324.5117, 354.5117, 24.5117, 54.5117, 84.5117, 114.5117, 144.5117],
                'sunshine' => [186.3351, 151.7401, 115.3399, 84.5117, 58.9880, 34.4900, 6.3351, 332.2063, 295.7037, 264.5117, 238.7311, 214.1675],
            ],
    ];

    private function jd(string $date = '1985-06-15 14:30'): float
    {
        return Time::julianDay(new DateTimeImmutable($date, new DateTimeZone('UTC')));
    }

    private function obliquity(float $jd): float
    {
        return Time::trueObliquity(Time::centuries(Time::tt($jd)));
    }

    /**
     * Angular difference in arcseconds, with the wrap not counting.
     */
    private function arcseconds(float $a, float $b): float
    {
        return abs(fmod(abs($a - $b) + 180, 360) - 180) * 3600;
    }

    /**
     * Altitude of a degree of the ecliptic above the horizon, computed by a route that touches
     * nothing in `Houses`.
     */
    private function altitude(float $longitude, float $lst, float $latitude, float $eps): float
    {
        $l = deg2rad($longitude);
        $declination = asin(sin($eps) * sin($l));
        $ra = atan2(sin($l) * cos($eps), cos($l));
        $hourAngle = deg2rad($lst) - $ra;

        return rad2deg(asin(
            sin(deg2rad($latitude)) * sin($declination)
            + cos(deg2rad($latitude)) * cos($declination) * cos($hourAngle)
        ));
    }

    /**
     * Azimuth of a degree of the ecliptic, counted from the north towards the east, by a route
     * that touches nothing in `Houses` either.
     */
    private function azimuth(float $longitude, float $lst, float $latitude, float $eps): float
    {
        $l = deg2rad($longitude);
        $declination = asin(sin($eps) * sin($l));
        $ra = atan2(sin($l) * cos($eps), cos($l));
        $hourAngle = deg2rad($lst) - $ra;
        $phi = deg2rad($latitude);

        return fmod(rad2deg(atan2(
            -cos($declination) * sin($hourAngle),
            sin($declination) * cos($phi) - cos($declination) * sin($phi) * cos($hourAngle)
        )) + 360, 360);
    }

    /**
     * Right ascension of a degree of the ecliptic, in degrees.
     */
    private function rightAscension(float $longitude, float $eps): float
    {
        $l = deg2rad($longitude);

        return fmod(rad2deg(atan2(sin($l) * cos($eps), cos($l))) + 360, 360);
    }

    /**
     * Unit equatorial vector of a point given by right ascension and declination.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function vector(float $ra, float $declination): array
    {
        $a = deg2rad($ra);
        $d = deg2rad($declination);

        return [cos($d) * cos($a), cos($d) * sin($a), sin($d)];
    }

    /**
     * Whether three vectors are coplanar (their triple product is zero).
     *
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @param array{0: float, 1: float, 2: float} $c
     */
    private function tripleProduct(array $a, array $b, array $c): float
    {
        return $a[0] * ($b[1] * $c[2] - $b[2] * $c[1])
            + $a[1] * ($b[2] * $c[0] - $b[0] * $c[2])
            + $a[2] * ($b[0] * $c[1] - $b[1] * $c[0]);
    }

    /**
     * Savard-A, Swiss's `j`: Campanus with the prime vertical cut into thirds of declination
     * instead of into equal arcs. It comes from John Savard's description, and here it is checked
     * against Swiss in the two things the Madrid cusps do not show.
     *
     * - **The equator**, where the arithmetic is zero over zero and its limit has to be taken.
     *   And there it does NOT collapse onto the quadrant systems, which is the sign that it is
     *   not Campanus under another name.
     * - **The house position**, which Swiss shares out in proportion to the arc of the prime
     *   vertical between the two cusp circles: fifteen bodies in Madrid, Ushuaia and Tromsø, with
     *   latitudes of up to seventeen degrees, against `swe_house_pos` with the `j`.
     */
    public function test_savard_a_agrees_with_swiss_at_the_equator_and_in_the_position(): void
    {
        $jd = Time::julianDay(new DateTimeImmutable('1981-05-11 01:30', new DateTimeZone('UTC')));

        // pyswisseph 2.10.03, `swe.houses(jd, 0, -3.7038, b'j')`.
        $equator = [335.7541, 356.7526, 20.9548, 69.2376, 113.8604, 135.6161, 155.7541, 176.7526, 200.9548, 249.2376, 293.8604, 315.6161];
        $ours = array_values(Houses::calculate(HouseSystem::SavardA, $jd, 0.0, -3.7038)->cusps);

        foreach ($equator as $i => $expected) {
            $this->assertLessThan(1.0, $this->arcseconds($ours[$i], $expected), 'cusp '.($i + 1).' at the equator');
        }

        $campanus = Houses::calculate(HouseSystem::Campanus, $jd, 0.0, -3.7038)->cusps;

        $this->assertGreaterThan(1.0, abs(fmod($ours[10] - $campanus[11] + 540, 360) - 180), 'En el equator Savard-A no colapsa con Campano');

        $swiss = [
            // [place, JD in UT, latitude, longitude, body's longitude, its latitude, swe_house_pos con la j]
            // Madrid, 1981-05-11 01:30 UT
            ['Madrid', 2444735.562500, 40.4168, -3.7038, 50.276705, 0.000061, 3.26487988],
            ['Madrid', 2444735.562500, 40.4168, -3.7038, 141.822777, 1.471718, 7.03308154],
            ['Madrid', 2444735.562500, 40.4168, -3.7038, 41.727925, -0.258798, 3.04551316],
            ['Madrid', 2444735.562500, 40.4168, -3.7038, 180.863353, 1.501349, 7.91410994],
            ['Madrid', 2444735.562500, 40.4168, -3.7038, 202.213842, 17.630543, 9.03178013],
            // Ushuaia, 1985-06-10 18:00 UT
            ['Ushuaia', 2446227.250000, -54.8019, -68.3030, 79.780988, -0.000173, 8.17213971],
            ['Ushuaia', 2446227.250000, -54.8019, -68.3030, 354.279303, -4.205590, 6.85696958],
            ['Ushuaia', 2446227.250000, -54.8019, -68.3030, 90.869967, 0.855482, 9.06463295],
            ['Ushuaia', 2446227.250000, -54.8019, -68.3030, 316.909899, -0.673717, 6.18579583],
            ['Ushuaia', 2446227.250000, -54.8019, -68.3030, 212.194554, 17.080811, 1.81089627],
            // Tromsø, 2000-03-20 05:00 UT, with the midheaven sunk
            ['Tromsø', 2451623.708333, 69.6496, 18.9560, 359.892192, 0.000062, 12.97236474],
            ['Tromsø', 2451623.708333, 69.6496, 18.9560, 180.019358, 4.307088, 7.14514379],
            ['Tromsø', 2451623.708333, 69.6496, 18.9560, 27.900811, -0.063245, 12.91056599],
            ['Tromsø', 2451623.708333, 69.6496, 18.9560, 36.576800, -0.949252, 12.92886831],
            ['Tromsø', 2451623.708333, 69.6496, 18.9560, 252.895628, 11.155582, 7.94181812],
        ];

        foreach ($swiss as [$location, $jdUt, $latitude, $longitude, $l, $b, $expected]) {
            $houses = Houses::calculate(HouseSystem::SavardA, $jdUt, $latitude, $longitude);

            $this->assertEqualsWithDelta($expected, $houses->housePosition($l, $b), 1e-4, "{$location}, longitude {$l}");
        }
    }

    /**
     * Equal from Aries, Swiss's `n`: twelve houses of thirty degrees from zero degrees of Aries,
     * so house equals sign, with no hour and no place.
     *
     * It has no geometry to check, so what is checked is that it is NOT affected by what the
     * others are: four latitudes including two polar ones, and the cusps stay on the multiples of
     * thirty. The ascendant is still the one of the place, because the chart shows it even though
     * the system does not use it.
     *
     * And the one with any substance is the sidereal case, where it nails itself to the multiples
     * of thirty of the SIDEREAL zodiac, not the tropical ones: the house is the sidereal sign.
     * `swe_house_pos` does not look at the body's latitude here, which is why the positions are
     * compared with a latitude of zero.
     */
    public function test_equal_from_aries_are_the_signs(): void
    {
        $jd = $this->jd();

        foreach ([[40.4168, -3.7038], [69.6496, 18.9560], [-54.8019, -68.3030], [78.22, 15.63]] as [$latitude, $longitude]) {
            $houses = Houses::calculate(HouseSystem::EqualAries, $jd, $latitude, $longitude);

            foreach ($houses->cusps as $house => $cusp) {
                $this->assertEqualsWithDelta(($house - 1) * 30.0, $cusp, 1e-12, "cusp {$house} at latitude {$latitude}");
            }

            // The ascendant is still the one of the place: the system does not use it, but the chart shows it.
            $this->assertSame(Houses::calculate(HouseSystem::Equal, $jd, $latitude, $longitude)->ascendant, $houses->ascendant);
        }

        $madrid = Houses::calculate(HouseSystem::EqualAries, $jd, 40.4168, -3.7038);

        // [longitude, latitude, swe_house_pos with 'n'], pyswisseph 2.10.03.
        foreach ([[50.276705, 0.000061, 2.67589018], [141.822777, 1.471718, 5.72742591], [202.213842, 17.630543, 7.74046140]] as [$l, $b, $expected]) {
            $this->assertEqualsWithDelta($expected, $madrid->housePosition($l, $b), 1e-7, "longitude {$l}");
        }

        $sidereal = Houses::calculate(HouseSystem::EqualAries, $jd, 40.4168, -3.7038)
            ->sidereal(Ayanamsa::Lahiri->value(Time::tt($jd)));

        foreach ($sidereal->cusps as $house => $cusp) {
            $this->assertEqualsWithDelta(($house - 1) * 30.0, $cusp, 1e-9, "sidereal cusp {$house}");
        }
    }

    /**
     * The twenty three systems against Swiss Ephemeris, which is the industry standard.
     *
     * **This test exists because the others were not enough.** The others check each system
     * against its own definition, which is right and is strong: at the equator the seven quadrant
     * systems collapse into one, Regiomontanus is computed by two routes and they agree, the
     * ascendant lands on the horizon to 1e-15 degrees. But none of them can catch a definition
     * that is WRONG, and that is exactly what Koch had.
     *
     * Koch took the ascensional difference of the ascendant instead of that of the midheaven. Its
     * eight intermediate cusps came out up to thirty five degrees off and nobody saw it: the four
     * angles were right, so the wheel looked normal, and at the equator (where it was being
     * checked) that difference is zero for any point, so the fault was hiding inside the
     * strongest test there was.
     *
     * Against a third party no definition of your own is worth anything: either you agree or you
     * do not.
     */
    public function test_the_twenty_three_systems_agree_with_swiss_ephemeris(): void
    {
        // Swiss Ephemeris 2.10.03, 11 May 1981 at 07:15 UTC, Madrid. Los doce
        // primeros de swetest; los once nuevos de pyswisseph con Moshier
        // (`swe.houses_ex(jd, 40.4165, -3.7026, letra, FLG_MOSEPH)`).
        $swiss = [
            'placidus' => [85.9962, 106.3765, 127.1492, 152.0429, 185.0140, 226.3976, 265.9962, 286.3765, 307.1492, 332.0429, 5.0140, 46.3976],
            'koch' => [85.9962, 109.4823, 130.9419, 152.0429, 197.4876, 236.9600, 265.9962, 289.4823, 310.9419, 332.0429, 17.4876, 56.9600],
            'regiomontanus' => [85.9962, 109.7395, 129.4911, 152.0429, 185.3913, 230.1639, 265.9962, 289.7395, 309.4911, 332.0429, 5.3913, 50.1639],
            'campanus' => [85.9962, 114.5602, 133.7423, 152.0429, 177.1393, 219.4782, 265.9962, 294.5602, 313.7423, 332.0429, 357.1393, 39.4782],
            'porphyry' => [85.9962, 108.0118, 130.0273, 152.0429, 190.0273, 228.0118, 265.9962, 288.0118, 310.0273, 332.0429, 10.0273, 48.0118],
            'alcabitius' => [85.9962, 107.0069, 128.8071, 152.0429, 192.2182, 230.8712, 265.9962, 287.0069, 308.8071, 332.0429, 12.2182, 50.8712],
            'topocentric' => [85.9962, 106.2319, 127.0874, 152.0429, 185.0139, 226.3219, 265.9962, 286.2319, 307.0874, 332.0429, 5.0139, 46.3219],
            'equal' => [85.9962, 115.9962, 145.9962, 175.9962, 205.9962, 235.9962, 265.9962, 295.9962, 325.9962, 355.9962, 25.9962, 55.9962],
            'whole-sign' => [60.0000, 90.0000, 120.0000, 150.0000, 180.0000, 210.0000, 240.0000, 270.0000, 300.0000, 330.0000, 0.0000, 30.0000],
            'vehlow' => [70.9962, 100.9962, 130.9962, 160.9962, 190.9962, 220.9962, 250.9962, 280.9962, 310.9962, 340.9962, 10.9962, 40.9962],
            'morinus' => [62.0429, 94.3979, 126.3598, 155.9266, 183.7041, 211.7861, 242.0429, 274.3979, 306.3598, 335.9266, 3.7041, 31.7861],
            'meridian' => [65.9266, 93.7041, 121.7861, 152.0429, 184.3979, 216.3598, 245.9266, 273.7041, 301.7861, 332.0429, 4.3979, 36.3598],
            'azimuthal' => [45.9808, 84.0539, 122.8333, 152.0429, 174.9681, 197.6191, 225.9808, 264.0539, 302.8333, 332.0429, 354.9681, 17.6191],
            'krusinski' => [85.9962, 105.0832, 124.4450, 152.0429, 196.3499, 239.4476, 265.9962, 285.0832, 304.4450, 332.0429, 16.3499, 59.4476],
            'sripati' => [67.0040, 97.0040, 119.0195, 141.0351, 171.0351, 209.0195, 247.0040, 277.0040, 299.0195, 321.0351, 351.0351, 29.0195],
            'carter' => [85.9962, 113.7648, 143.3044, 175.2468, 207.6137, 237.8988, 265.9962, 293.7648, 323.3044, 355.2468, 27.6137, 57.8988],
            'apc' => [85.9962, 106.1813, 126.1970, 152.0429, 192.4218, 237.3630, 265.9962, 292.0999, 311.2126, 332.0429, 1.9318, 45.0466],
            'pullen-sd' => [85.9962, 110.0079, 128.0312, 152.0429, 188.0312, 230.0079, 265.9962, 290.0079, 308.0312, 332.0429, 8.0312, 50.0079],
            'pullen-sr' => [85.9962, 109.4579, 128.5812, 152.0429, 187.3569, 230.6822, 265.9962, 289.4579, 308.5812, 332.0429, 7.3569, 50.6822],
            'equal-mc' => [62.0429, 92.0429, 122.0429, 152.0429, 182.0429, 212.0429, 242.0429, 272.0429, 302.0429, 332.0429, 2.0429, 32.0429],
            'equal-aries' => [0.0000, 30.0000, 60.0000, 90.0000, 120.0000, 150.0000, 180.0000, 210.0000, 240.0000, 270.0000, 300.0000, 330.0000],
            'savard-a' => [85.9962, 107.5449, 124.2778, 152.0429, 196.7303, 234.6825, 265.9962, 287.5449, 304.2778, 332.0429, 16.7303, 54.6825],
            'sunshine' => [85.9962, 107.2335, 127.2534, 152.0429, 190.1125, 235.3036, 265.9962, 291.5951, 310.8828, 332.0429, 2.5825, 46.1614],
        ];

        $jd = Time::julianDay(new DateTimeImmutable('1981-05-11 07:15', new DateTimeZone('UTC')));

        foreach (HouseSystem::cases() as $system) {
            $this->assertArrayHasKey($system->value, $swiss, "Falta {$system->value} en la tabla de Swiss");

            $ours = array_values(Houses::calculate($system, $jd, 40.4165, -3.7026)->cusps);

            foreach ($swiss[$system->value] as $i => $expected) {
                $difference = $this->arcseconds($ours[$i], $expected);

                // One arcsecond. Measured, the worst of the 276 cusps stays at 0,33.
                $this->assertLessThan(
                    1.0, $difference,
                    sprintf('%s, cusp %d: ours %.4f, Swiss %.4f (%.2f arcseconds)',
                        $system->value, $i + 1, $ours[$i], $expected, $difference)
                );
            }
        }
    }

    /**
     * The nine newer systems against Swiss in the five places and on two dates.
     *
     * These are the systems with the most room to pick the wrong branch: APC and Sunshine have
     * twelve independent cusps, Azimuthal changes orientation with the hemisphere and Krusinski
     * projects through hour circles. One date and one place are not enough to catch a flipped
     * sign that only shows up in the south or before dawn.
     *
     * Measured over the 1,080 cusps: the worst stays at 0.93 arcseconds (APC, Ushuaia, 1930), and
     * almost all of that is the difference between our sidereal time and Swiss's, which is
     * already there in the ascendant.
     */
    public function test_the_newer_systems_agree_with_swiss_in_five_places_and_two_dates(): void
    {
        // pyswisseph 2.10.03 with Moshier: swe.houses_ex(jd, lat, lon, letter, FLG_MOSEPH).
        // Letras: H azimutal, U krusinski, S sripati, F carter, Y apc, L pullen-sd,
        // Q pullen-sr, D igual-mc, I sunshine.
        $swiss = [
            '1985-06-15 14:30' => [
                'Madrid' => [
                    'azimuthal' => [233.2741, 271.5032, 285.5970, 295.6353, 307.8951, 335.7987, 53.2741, 91.5032, 105.5970, 115.6353, 127.8951, 155.7987],
                    'krusinski' => [201.9205, 227.7797, 259.1214, 295.6353, 329.6706, 357.4077, 21.9205, 47.7797, 79.1214, 115.6353, 149.6706, 177.4077],
                    'sripati' => [187.5396, 217.5396, 248.7779, 280.0161, 310.0161, 338.7779, 7.5396, 37.5396, 68.7779, 100.0161, 130.0161, 158.7779],
                    'carter' => [201.9205, 232.6686, 261.0540, 288.7124, 317.8213, 349.4071, 21.9205, 52.6686, 81.0540, 108.7124, 137.8213, 169.4071],
                    'apc' => [201.9205, 228.3574, 259.5460, 295.6353, 329.2947, 356.8854, 21.9205, 45.6757, 76.6692, 115.6353, 151.8368, 179.3172],
                    'pullen-sd' => [201.9205, 232.8492, 264.7066, 295.6353, 324.7066, 352.8492, 21.9205, 52.8492, 84.7066, 115.6353, 144.7066, 172.8492],
                    'pullen-sr' => [201.9205, 232.8346, 264.7212, 295.6353, 324.6924, 352.8633, 21.9205, 52.8346, 84.7212, 115.6353, 144.6924, 172.8633],
                    'equal-mc' => [205.6353, 235.6353, 265.6353, 295.6353, 325.6353, 355.6353, 25.6353, 55.6353, 85.6353, 115.6353, 145.6353, 175.6353],
                    'sunshine' => [201.9205, 222.5167, 252.5572, 295.6353, 335.4574, 2.2071, 21.9205, 50.3210, 81.2815, 115.6353, 147.7561, 175.1159],
                ],
                'Buenos Aires' => [
                    'azimuthal' => [161.9178, 193.9113, 220.2910, 244.8828, 272.1739, 305.4686, 341.9178, 13.9113, 40.2910, 64.8828, 92.1739, 125.4686],
                    'krusinski' => [140.0119, 169.5392, 207.6454, 244.8828, 273.3182, 296.4824, 320.0119, 349.5392, 27.6454, 64.8828, 93.3182, 116.4824],
                    'sripati' => [127.4904, 157.4904, 192.4474, 227.4043, 257.4043, 282.4474, 307.4904, 337.4904, 12.4474, 47.4043, 77.4043, 102.4474],
                    'carter' => [140.0119, 171.7484, 204.2146, 234.7781, 263.0404, 290.7340, 320.0119, 351.7484, 24.2146, 54.7781, 83.0404, 110.7340],
                    'apc' => [140.0119, 187.0556, 222.0956, 244.8828, 263.8389, 285.6430, 320.0119, 0.9594, 38.6539, 64.8828, 86.2140, 109.3026],
                    'pullen-sd' => [140.0119, 173.7296, 211.1651, 244.8828, 271.1651, 293.7296, 320.0119, 353.7296, 31.1651, 64.8828, 91.1651, 113.7296],
                    'pullen-sr' => [140.0119, 173.4827, 211.4121, 244.8828, 270.9471, 293.9476, 320.0119, 353.4827, 31.4121, 64.8828, 90.9471, 113.9476],
                    'equal-mc' => [154.8828, 184.8828, 214.8828, 244.8828, 274.8828, 304.8828, 334.8828, 4.8828, 34.8828, 64.8828, 94.8828, 124.8828],
                    'sunshine' => [140.0119, 188.5063, 222.7673, 244.8828, 263.3646, 284.7811, 320.0119, 358.4616, 36.9477, 64.8828, 87.3599, 110.8286],
                ],
                'Singapore' => [
                    'azimuthal' => [357.6924, 26.5185, 38.2877, 47.5998, 60.3626, 95.2862, 177.6924, 206.5185, 218.2877, 227.5998, 240.3626, 275.2862],
                    'krusinski' => [312.2550, 344.7538, 17.5109, 47.5998, 75.1980, 102.6067, 132.2550, 164.7538, 197.5109, 227.5998, 255.1980, 282.6067],
                    'sripati' => [298.1458, 328.1458, 359.9274, 31.7090, 61.7090, 89.9274, 118.1458, 148.1458, 179.9274, 211.7090, 241.7090, 269.9274],
                    'carter' => [312.2550, 343.4165, 15.9779, 47.1846, 75.9279, 103.5505, 132.2550, 163.4165, 195.9779, 227.1846, 255.9279, 283.5505],
                    'apc' => [312.2550, 343.8135, 16.5838, 47.5998, 75.9885, 103.4048, 132.2550, 163.6329, 196.4339, 227.5998, 256.1160, 283.5584],
                    'pullen-sd' => [312.2550, 343.5912, 16.2636, 47.5998, 76.2636, 103.5912, 132.2550, 163.5912, 196.2636, 227.5998, 256.2636, 283.5912],
                    'pullen-sr' => [312.2550, 343.5607, 16.2941, 47.5998, 76.2345, 103.6203, 132.2550, 163.5607, 196.2941, 227.5998, 256.2345, 283.6203],
                    'equal-mc' => [317.5998, 347.5998, 17.5998, 47.5998, 77.5998, 107.5998, 137.5998, 167.5998, 197.5998, 227.5998, 257.5998, 287.5998],
                    'sunshine' => [312.2550, 343.5965, 16.4035, 47.5998, 76.1419, 103.5893, 132.2550, 163.8493, 196.6132, 227.5998, 255.9634, 283.3744],
                ],
                'Oslo' => [
                    'azimuthal' => [236.0787, 268.0130, 289.8865, 309.6265, 334.1082, 11.4716, 56.0787, 88.0130, 109.8865, 129.6265, 154.1082, 191.4716],
                    'krusinski' => [206.0984, 223.7521, 254.5257, 309.6265, 349.9355, 10.6696, 26.0984, 43.7521, 74.5257, 129.6265, 169.9355, 190.6696],
                    'sripati' => [193.3531, 223.3531, 257.8624, 292.3718, 322.3718, 347.8624, 13.3531, 43.3531, 77.8624, 112.3718, 142.3718, 167.8624],
                    'carter' => [206.0984, 236.5081, 264.6762, 292.4078, 321.8291, 353.6826, 26.0984, 56.5081, 84.6762, 112.4078, 141.8291, 173.6826],
                    'apc' => [206.0984, 229.9459, 264.5356, 309.6265, 343.7079, 6.0711, 26.0984, 43.9323, 75.6865, 129.6265, 169.2138, 190.5311],
                    'pullen-sd' => [206.0984, 239.4804, 276.2445, 309.6265, 336.2445, 359.4804, 26.0984, 59.4804, 96.2445, 129.6265, 156.2445, 179.4804],
                    'pullen-sr' => [206.0984, 239.2775, 276.4474, 309.6265, 336.0633, 359.6616, 26.0984, 59.2775, 96.4474, 129.6265, 156.0633, 179.6616],
                    'equal-mc' => [219.6265, 249.6265, 279.6265, 309.6265, 339.6265, 9.6265, 39.6265, 69.6265, 99.6265, 129.6265, 159.6265, 189.6265],
                    'sunshine' => [206.0984, 216.9248, 239.8943, 309.6265, 359.2464, 16.1653, 26.0984, 52.0715, 86.3035, 129.6265, 162.5984, 184.5621],
                ],
                'Ushuaia' => [
                    'azimuthal' => [149.3045, 178.4782, 206.5589, 235.3541, 266.0982, 298.1339, 329.3045, 358.4782, 26.5589, 55.3541, 86.0982, 118.1339],
                    'krusinski' => [105.6752, 124.9492, 173.8651, 235.3541, 259.7746, 273.1971, 285.6752, 304.9492, 353.8651, 55.3541, 79.7746, 93.1971],
                    'sripati' => [97.2883, 127.2883, 170.5146, 213.7409, 243.7409, 260.5146, 277.2883, 307.2883, 350.5146, 33.7409, 63.7409, 80.5146],
                    'carter' => [105.6752, 134.5408, 165.8831, 198.4376, 229.4587, 258.0474, 285.6752, 314.5408, 345.8831, 18.4376, 49.4587, 78.0474],
                    'apc' => [105.6752, 175.2881, 217.9287, 235.3541, 247.0155, 259.4591, 285.6752, 321.3615, 22.4162, 55.3541, 72.6943, 87.5310],
                    'pullen-sd' => [105.6752, 145.5949, 195.4344, 235.3541, 255.4344, 265.5949, 285.6752, 325.5949, 15.4344, 55.3541, 75.4344, 85.5949],
                    'pullen-sr' => [105.6752, 143.5312, 197.4981, 235.3541, 253.9814, 267.0479, 285.6752, 323.5312, 17.4981, 55.3541, 73.9814, 87.0479],
                    'equal-mc' => [145.3541, 175.3541, 205.3541, 235.3541, 265.3541, 295.3541, 325.3541, 355.3541, 25.3541, 55.3541, 85.3541, 115.3541],
                    'sunshine' => [105.6752, 175.5239, 217.9670, 235.3541, 246.9981, 259.4067, 285.6752, 320.1301, 21.2959, 55.3541, 73.0272, 87.8860],
                ],
            ],
            '1930-02-14 03:35' => [
                'Madrid' => [
                    'azimuthal' => [305.0112, 332.6017, 353.4912, 14.6451, 43.1438, 84.5184, 125.0112, 152.6017, 173.4912, 194.6451, 223.1438, 264.5184],
                    'krusinski' => [262.6745, 288.8189, 330.3744, 14.6451, 43.2646, 63.3027, 82.6745, 108.8189, 150.3744, 194.6451, 223.2646, 243.3027],
                    'sripati' => [251.3363, 281.3363, 318.6598, 355.9834, 25.9834, 48.6598, 71.3363, 101.3363, 138.6598, 175.9834, 205.9834, 228.6598],
                    'carter' => [262.6745, 290.3592, 319.6050, 351.3155, 23.7933, 54.3913, 82.6745, 110.3592, 139.6050, 171.3155, 203.7933, 234.3913],
                    'apc' => [262.6745, 299.5879, 341.8031, 14.6451, 37.6434, 57.6087, 82.6745, 108.5345, 150.9886, 194.6451, 222.9749, 243.4642],
                    'pullen-sd' => [262.6745, 298.1672, 339.1525, 14.6451, 39.1525, 58.1672, 82.6745, 118.1672, 159.1525, 194.6451, 219.1525, 238.1672],
                    'pullen-sr' => [262.6745, 297.6065, 339.7131, 14.6451, 38.6871, 58.6325, 82.6745, 117.6065, 159.7131, 194.6451, 218.6871, 238.6325],
                    'equal-mc' => [284.6451, 314.6451, 344.6451, 14.6451, 44.6451, 74.6451, 104.6451, 134.6451, 164.6451, 194.6451, 224.6451, 254.6451],
                    'sunshine' => [262.6745, 297.7149, 340.4833, 14.6451, 38.3233, 58.5491, 82.6745, 111.6842, 154.8548, 194.6451, 221.1244, 241.7120],
                ],
                'Buenos Aires' => [
                    'azimuthal' => [212.5020, 251.3763, 288.5085, 316.3448, 339.0788, 2.5197, 32.5020, 71.3763, 108.5085, 136.3448, 159.0788, 182.5197],
                    'krusinski' => [246.3406, 268.0083, 289.0957, 316.3448, 355.8257, 37.1401, 66.3406, 88.0083, 109.0957, 136.3448, 175.8257, 217.1401],
                    'sripati' => [228.0080, 258.0080, 281.3427, 304.6774, 334.6774, 11.3427, 48.0080, 78.0080, 101.3427, 124.6774, 154.6774, 191.3427],
                    'carter' => [246.3406, 274.1042, 302.2052, 332.5001, 4.8731, 36.8105, 66.3406, 94.1042, 122.2052, 152.5001, 184.8731, 216.8105],
                    'apc' => [246.3406, 270.7320, 292.3351, 316.3448, 349.7334, 32.2514, 66.3406, 95.5099, 115.9743, 136.3448, 163.3120, 203.1104],
                    'pullen-sd' => [246.3406, 271.3417, 291.3438, 316.3448, 351.3438, 31.3417, 66.3406, 91.3417, 111.3438, 136.3448, 171.3438, 211.3417],
                    'pullen-sr' => [246.3406, 270.9539, 291.7315, 316.3448, 350.8846, 31.8008, 66.3406, 90.9539, 111.7315, 136.3448, 170.8846, 211.8008],
                    'equal-mc' => [226.3448, 256.3448, 286.3448, 316.3448, 346.3448, 16.3448, 46.3448, 76.3448, 106.3448, 136.3448, 166.3448, 196.3448],
                    'sunshine' => [246.3406, 271.9247, 293.3573, 316.3448, 347.8816, 30.0313, 66.3406, 94.7785, 115.5005, 136.3448, 164.1204, 204.5489],
                ],
                'Singapore' => [
                    'azimuthal' => [1.6720, 74.1904, 105.0380, 118.8699, 129.9864, 144.9947, 181.6720, 254.1904, 285.0380, 298.8699, 309.9864, 324.9947],
                    'krusinski' => [33.5448, 63.8474, 91.5440, 118.8699, 148.3105, 180.6752, 213.5448, 243.8474, 271.5440, 298.8699, 328.3105, 0.6752],
                    'sripati' => [17.7656, 47.7656, 76.2073, 104.6490, 134.6490, 166.2073, 197.7656, 227.7656, 256.2073, 284.6490, 314.6490, 346.2073],
                    'carter' => [33.5448, 63.3408, 91.2018, 119.1619, 149.1827, 181.4279, 213.5448, 243.3408, 271.2018, 299.1619, 329.1827, 1.4279],
                    'apc' => [33.5448, 63.4174, 91.1448, 118.8699, 148.7686, 181.1724, 213.5448, 243.5331, 271.2371, 298.8699, 328.6625, 1.0387],
                    'pullen-sd' => [33.5448, 62.3761, 90.0386, 118.8699, 150.0386, 182.3761, 213.5448, 242.3761, 270.0386, 298.8699, 330.0386, 2.3761],
                    'pullen-sr' => [33.5448, 62.3537, 90.0609, 118.8699, 150.0154, 182.3993, 213.5448, 242.3537, 270.0609, 298.8699, 330.0154, 2.3993],
                    'equal-mc' => [28.8699, 58.8699, 88.8699, 118.8699, 148.8699, 178.8699, 208.8699, 238.8699, 268.8699, 298.8699, 328.8699, 358.8699],
                    'sunshine' => [33.5448, 63.5358, 91.2393, 118.8699, 148.6600, 181.0356, 213.5448, 243.4146, 271.1425, 298.8699, 328.7711, 1.1756],
                ],
                'Oslo' => [
                    'azimuthal' => [306.7775, 334.5808, 1.3645, 30.0303, 62.1567, 95.7090, 126.7775, 154.5808, 181.3645, 210.0303, 242.1567, 275.7090],
                    'krusinski' => [253.7752, 268.3282, 313.3422, 30.0303, 53.2924, 64.2216, 73.7752, 88.3282, 133.3422, 210.0303, 233.2924, 244.2216],
                    'sripati' => [246.4844, 276.4844, 321.9028, 7.3212, 37.3212, 51.9028, 66.4844, 96.4844, 141.9028, 187.3212, 217.3212, 231.9028],
                    'carter' => [253.7752, 281.4049, 309.9543, 340.9270, 13.4786, 44.8673, 73.7752, 101.4049, 129.9543, 160.9270, 193.4786, 224.8673],
                    'apc' => [253.7752, 299.8138, 2.2481, 30.0303, 44.1082, 55.6447, 73.7752, 89.8156, 142.3603, 210.0303, 231.8290, 243.5931],
                    'pullen-sd' => [253.7752, 295.3390, 348.4666, 30.0303, 48.4666, 55.3390, 73.7752, 115.3390, 168.4666, 210.0303, 228.4666, 235.3390],
                    'pullen-sr' => [253.7752, 292.3729, 351.4326, 30.0303, 46.5158, 57.2897, 73.7752, 112.3729, 171.4326, 210.0303, 226.5158, 237.2897],
                    'equal-mc' => [300.0303, 330.0303, 0.0303, 30.0303, 60.0303, 90.0303, 120.0303, 150.0303, 180.0303, 210.0303, 240.0303, 270.0303],
                    'sunshine' => [253.7752, 296.5464, 1.1258, 30.0303, 44.4091, 56.2700, 73.7752, 99.0701, 164.9811, 210.0303, 227.9747, 240.3999],
                ],
                'Ushuaia' => [
                    'azimuthal' => [212.2508, 243.4692, 275.6608, 306.4935, 335.2309, 3.1742, 32.2508, 63.4692, 95.6608, 126.4935, 155.2309, 183.1742],
                    'krusinski' => [256.5639, 268.9290, 282.2267, 306.4935, 8.3114, 57.4104, 76.5639, 88.9290, 102.2267, 126.4935, 188.3114, 237.4104],
                    'sripati' => [234.8855, 264.8855, 281.5287, 298.1719, 328.1719, 11.5287, 54.8855, 84.8855, 101.5287, 118.1719, 148.1719, 191.5287],
                    'carter' => [256.5639, 284.1851, 312.9361, 344.1533, 16.7163, 47.8688, 76.5639, 104.1851, 132.9361, 164.1533, 196.7163, 227.8688],
                    'apc' => [256.5639, 274.0418, 288.7578, 306.4935, 341.1914, 42.7587, 76.5639, 102.1251, 114.5884, 126.4935, 144.5859, 188.7792],
                    'pullen-sd' => [256.5639, 276.5463, 286.5111, 306.4935, 346.5111, 36.5463, 76.5639, 96.5463, 106.5111, 126.4935, 166.5111, 216.5463],
                    'pullen-sr' => [256.5639, 275.0657, 287.9917, 306.4935, 344.3998, 38.6576, 76.5639, 95.0657, 107.9917, 126.4935, 164.3998, 218.6576],
                    'equal-mc' => [216.4935, 246.4935, 276.4935, 306.4935, 336.4935, 6.4935, 36.4935, 66.4935, 96.4935, 126.4935, 156.4935, 186.4935],
                    'sunshine' => [256.5639, 277.0833, 291.3974, 306.4935, 332.7774, 31.2610, 76.5639, 101.2957, 114.2074, 126.4935, 145.4622, 192.6037],
                ],
            ],
        ];

        foreach ($swiss as $date => $places) {
            $jd = $this->jd($date);

            foreach ($places as $place => $systems) {
                [$lat, $lon] = self::PLACES[$place];

                foreach ($systems as $value => $expected) {
                    $houses = Houses::calculate(HouseSystem::from($value), $jd, $lat, $lon);

                    foreach ($expected as $i => $expected) {
                        $difference = $this->arcseconds($houses->cusps[$i + 1], $expected);

                        $this->assertLessThan(
                            1.0, $difference,
                            sprintf('%s in %s on %s, cusp %d: ours %.4f, Swiss %.4f (%.2f arcseconds)',
                                $value, $place, $date, $i + 1, $houses->cusps[$i + 1], $expected, $difference)
                        );
                    }
                }
            }
        }
    }

    /**
     * The thirty six Gauquelin sectors against Swiss (`hsys = 'G'`).
     */
    public function test_the_gauquelin_sectors_agree_with_swiss(): void
    {
        // pyswisseph 2.10.03 with Moshier, swe.houses_ex(..., b'G', FLG_MOSEPH): los 36.
        $swiss = [
            '1985-06-15 14:30|Madrid' => [
                201.9205, 194.4322, 186.4434, 177.9113, 168.8094, 159.1335, 148.9070, 138.1845, 127.0535, 115.6353, 104.0833, 92.5713,
                81.2647, 70.2897, 59.7210, 49.5920, 39.9144, 30.6909, 21.9205, 14.4322, 6.4434, 357.9113, 348.8094, 339.1335,
                328.9070, 318.1845, 307.0535, 295.6353, 284.0833, 272.5713, 261.2647, 250.2897, 239.7210, 229.5920, 219.9144, 210.6909,
            ],
            '1930-02-14 03:35|Oslo' => [
                253.7752, 250.2616, 246.6275, 242.8037, 238.7134, 234.2655, 229.3464, 223.8094, 217.4592, 210.0303, 201.1597, 190.3571,
                177.0047, 160.4842, 140.6955, 119.3280, 100.2705, 85.4632, 73.7752, 70.2616, 66.6275, 62.8037, 58.7134, 54.2655,
                49.3464, 43.8094, 37.4592, 30.0303, 21.1597, 10.3571, 357.0047, 340.4842, 320.6955, 299.3280, 280.2705, 265.4632,
            ],
        ];

        foreach ($swiss as $key => $expected) {
            [$date, $place] = explode('|', $key);
            [$lat, $lon] = self::PLACES[$place];

            $sectors = Houses::calculate(HouseSystem::Placidus, $this->jd($date), $lat, $lon)->gauquelinSectors();

            $this->assertCount(36, $sectors);

            foreach ($expected as $i => $expected) {
                $difference = $this->arcseconds($sectors[$i + 1], $expected);

                $this->assertLessThan(1.0, $difference,
                    sprintf('Gauquelin in %s on %s, sector %d: ours %.4f, Swiss %.4f (%.2f arcseconds)',
                        $place, $date, $i + 1, $sectors[$i + 1], $expected, $difference));
            }
        }
    }

    /**
     * The three angles of Munkasey and Koch against `ascmc[5]`, `[6]` and `[7]` of Swiss.
     */
    public function test_the_co_ascendants_and_the_polar_ascendant_agree_with_swiss(): void
    {
        // pyswisseph 2.10.03 with Moshier: ascmc[5] Koch's co-ascendant, ascmc[6]
        // co-ascendente de Munkasey, ascmc[7] ascendente polar de Munkasey.
        $swiss = [
            '1985-06-15 14:30|Madrid' => [224.3459, 199.9029, 44.3459],
            '1985-06-15 14:30|Buenos Aires' => [157.3694, 117.8459, 337.3694],
            '1985-06-15 14:30|Singapore' => [313.0796, 182.4926, 133.0796],
            '1985-06-15 14:30|Oslo' => [270.4793, 216.3163, 90.4793],
            '1985-06-15 14:30|Ushuaia' => [155.1121, 126.9300, 335.1121],
            '1930-02-14 03:35|Madrid' => [299.6151, 255.3922, 119.6151],
            '1930-02-14 03:35|Buenos Aires' => [220.5709, 267.9109, 40.5709],
            '1930-02-14 03:35|Singapore' => [32.9173, 178.1645, 212.9173],
            '1930-02-14 03:35|Oslo' => [321.6515, 282.7097, 141.6515],
            '1930-02-14 03:35|Ushuaia' => [206.1535, 235.3755, 26.1535],
        ];

        foreach ($swiss as $key => [$koch, $munkasey, $polar]) {
            [$date, $place] = explode('|', $key);
            [$lat, $lon] = self::PLACES[$place];

            $houses = Houses::calculate(HouseSystem::Equal, $this->jd($date), $lat, $lon);

            $this->assertLessThan(1.0, $this->arcseconds($houses->kochCoAscendant, $koch), "Co-ascendente de Koch en {$key}");
            $this->assertLessThan(1.0, $this->arcseconds($houses->munkaseyCoAscendant, $munkasey), "Co-ascendente de Munkasey en {$key}");
            $this->assertLessThan(1.0, $this->arcseconds($houses->polarAscendant, $polar), "Ascendente polar en {$key}");

            // And by definition the polar ascendant is the opposite of the co-ascendant de Koch.
            $this->assertEqualsWithDelta(0.0,
                fmod($houses->kochCoAscendant - $houses->polarAscendant - 180 + 540, 360) - 180, 1e-9);
        }

        // At the equator Munkasey's co-ascendant is the ascendant of the pole, que Swiss
        // nailed to 0 Libra: there is no formula to compute there.
        $this->assertSame(180.0, Houses::calculate(HouseSystem::Equal, $this->jd(), 0.0, 0.0)->munkaseyCoAscendant);
    }

    public function test_the_ascendant_is_on_the_horizon_and_is_rising(): void
    {
        $jd = $this->jd();
        $eps = $this->obliquity($jd);

        foreach (self::PLACES as $location => [$lat, $lon]) {
            $houses = Houses::calculate(HouseSystem::Equal, $jd, $lat, $lon);

            $this->assertEqualsWithDelta(
                0.0,
                $this->altitude($houses->ascendant, $houses->siderealTime, $lat, $eps),
                1e-9,
                "The ascendant of {$location} is not on the horizon"
            );

            // And it is the rising one and not the setting one: its hour angle is still negativo,
            // meaning it has not crossed the meridian yet.
            $l = deg2rad($houses->ascendant);
            $ra = atan2(sin($l) * cos($eps), cos($l));

            $this->assertLessThan(
                0,
                sin(deg2rad($houses->siderealTime) - $ra),
                "The ascendant of {$location} is setting: that is the descendant"
            );
        }
    }

    public function test_the_midheaven_is_on_the_meridian(): void
    {
        $jd = $this->jd();
        $eps = $this->obliquity($jd);
        $houses = Houses::calculate(HouseSystem::Equal, $jd, 40.4168, -3.7038);

        $l = deg2rad($houses->midheaven);
        $ra = rad2deg(atan2(sin($l) * cos($eps), cos($l)));

        $this->assertEqualsWithDelta(
            0.0,
            fmod($houses->siderealTime - $ra + 540, 360) - 180,
            1e-9
        );
    }

    /**
     * Placidus says cusp 11 is the degree that has covered one third of ITS OWN diurnal arc. That
     * is measured without using the code that computed the cusps again.
     */
    public function test_placidus_meets_its_definition(): void
    {
        $jd = $this->jd();
        $eps = $this->obliquity($jd);

        // house => [fixed degrees to add, fraction of its own diurnal semiarc]
        $definition = [11 => [0.0, 1 / 3], 12 => [0.0, 2 / 3], 1 => [0.0, 1.0], 2 => [60.0, 2 / 3], 3 => [120.0, 1 / 3]];

        foreach (self::PLACES as $location => [$lat, $lon]) {
            $houses = Houses::calculate(HouseSystem::Placidus, $jd, $lat, $lon);

            foreach ($definition as $house => [$fixed, $fraction]) {
                $l = deg2rad($houses->cusps[$house]);
                $declination = asin(sin($eps) * sin($l));
                $ra = rad2deg(atan2(sin($l) * cos($eps), cos($l)));

                $semiarc = rad2deg(acos(max(-1.0, min(1.0, -tan(deg2rad($lat)) * tan($declination)))));
                $expected = $houses->siderealTime + $fixed + $fraction * $semiarc;

                $this->assertEqualsWithDelta(
                    0.0,
                    fmod($ra - $expected + 540, 360) - 180,
                    1e-6,
                    "Placidus in {$location}: house {$house} has not covered its fraction of the arc"
                );
            }
        }
    }

    /**
     * Regiomontanus is computed by cutting great circles with vectors. There is also a classic
     * formula with «poles» that arrives at the same place by another route. That the two agree to
     * machine precision validates them against each other.
     */
    public function test_regiomontanus_agrees_with_the_classic_formula_of_poles(): void
    {
        $jd = $this->jd();
        $eps = $this->obliquity($jd);

        foreach (self::PLACES as $location => [$lat, $lon]) {
            $houses = Houses::calculate(HouseSystem::Regiomontanus, $jd, $lat, $lon);

            foreach ([10 => 0, 11 => 1, 12 => 2, 1 => 3, 2 => 4, 3 => 5] as $house => $step) {
                $polo = rad2deg(atan(tan(deg2rad($lat)) * sin(deg2rad(30 * $step))));
                $classic = Houses::eclipticPointUnderPole($houses->siderealTime - 90 + 30 * $step, $polo, $eps);

                $this->assertEqualsWithDelta(
                    0.0,
                    fmod($houses->cusps[$house] - $classic + 540, 360) - 180,
                    1e-9,
                    "Regiomontano en {$location}, house {$house}: vectores y polos no coinciden"
                );
            }
        }
    }

    /**
     * Sripati says its cusps are the midpoints of Porphyry's houses, and Equal from the midheaven
     * says the midheaven is cusp 10 and the rest follow every thirty degrees. Both are checked
     * against Porphyry's cusps and against the midheaven, not against the code that produced
     * them.
     */
    public function test_sripati_and_equal_from_the_midheaven_meet_their_definition(): void
    {
        foreach (['1985-06-15 14:30', '1930-02-14 03:35'] as $date) {
            $jd = $this->jd($date);

            foreach (self::PLACES as $location => [$lat, $lon]) {
                $porphyry = Houses::calculate(HouseSystem::Porphyry, $jd, $lat, $lon)->cusps;
                $sripati = Houses::calculate(HouseSystem::Sripati, $jd, $lat, $lon)->cusps;

                for ($house = 1; $house <= 12; $house++) {
                    $previous = $porphyry[$house === 1 ? 12 : $house - 1];
                    $middle = fmod($previous + fmod($porphyry[$house] - $previous + 360, 360) / 2, 360);

                    $this->assertEqualsWithDelta(0.0, fmod($sripati[$house] - $middle + 540, 360) - 180, 1e-9,
                        "Sripati in {$location}: cusp {$house} is not the midpoint of house ".($house === 1 ? 12 : $house - 1).' of Porphyry');
                }

                $fromMc = Houses::calculate(HouseSystem::EqualMidheaven, $jd, $lat, $lon);

                $this->assertEqualsWithDelta(0.0, fmod($fromMc->cusps[10] - $fromMc->midheaven + 540, 360) - 180, 1e-9,
                    "Iguales desde el middle cielo en {$location}: la house 10 no es el middle cielo");
                $this->assertEqualsWithDelta(30.0, fmod($fromMc->cusps[2] - $fromMc->cusps[1] + 360, 360), 1e-9);
            }
        }
    }

    /**
     * The azimuthal cusps sit on verticals at multiples of thirty degrees of azimuth, which is
     * what defines the system, and that is measured with an azimuth computed apart.
     *
     * The houses run from the midheaven towards the east, and where that lies depends on which
     * side of the zenith the midheaven is: to the south in Madrid and Oslo, to the north in
     * Buenos Aires and Ushuaia. In Singapore it depends on the hour. What is checked is that 11
     * and 12 fall BETWEEN the midheaven and the east, which is the orientation, and not a fixed
     * azimuth per cusp.
     */
    public function test_the_azimuthal_cusps_sit_on_verticals_thirty_degrees_apart(): void
    {
        foreach (['1985-06-15 14:30', '1930-02-14 03:35'] as $date) {
            $jd = $this->jd($date);
            $eps = $this->obliquity($jd);

            foreach (self::PLACES as $location => [$lat, $lon]) {
                $houses = Houses::calculate(HouseSystem::Azimuthal, $jd, $lat, $lon);
                $azimuthMc = $this->azimuth($houses->midheaven, $houses->siderealTime, $lat, $eps);
                $direction = abs($azimuthMc - 180) < 1e-6 ? -1 : 1;

                $this->assertTrue(abs($azimuthMc) < 1e-6 || abs($azimuthMc - 180) < 1e-6 || abs($azimuthMc - 360) < 1e-6,
                    "In {$location} the midheaven is not on the meridian");

                for ($house = 1; $house <= 12; $house++) {
                    $azimuth = $this->azimuth($houses->cusps[$house], $houses->siderealTime, $lat, $eps);
                    $expected = fmod($azimuthMc + $direction * 30 * ($house - 10) + 720, 360);

                    $this->assertEqualsWithDelta(0.0, fmod($azimuth - $expected + 540, 360) - 180, 1e-6,
                        "Azimuthal in {$location} on {$date}: cusp {$house} looks at {$azimuth} and should look at {$expected}");
                }

                $this->assertEqualsWithDelta(90.0, $this->azimuth($houses->cusps[1], $houses->siderealTime, $lat, $eps), 1e-6,
                    "Azimutal en {$location}: la house 1 no empieza en el este");
                $this->assertEqualsWithDelta(0.0,
                    fmod($houses->cusps[10] - $houses->midheaven + 540, 360) - 180, 1e-9,
                    "Azimutal en {$location}: la house 10 no es el middle cielo");
            }
        }
    }

    /**
     * Carter says its cusps have a right ascension equal to the ascendant's plus thirty degrees
     * per house. It is measured with the right ascension computed apart.
     */
    public function test_carters_cusps_run_thirty_degrees_of_equator_from_the_ascendant(): void
    {
        foreach (['1985-06-15 14:30', '1930-02-14 03:35'] as $date) {
            $jd = $this->jd($date);
            $eps = $this->obliquity($jd);

            foreach (self::PLACES as $location => [$lat, $lon]) {
                $houses = Houses::calculate(HouseSystem::Carter, $jd, $lat, $lon);
                $base = $this->rightAscension($houses->ascendant, $eps);

                for ($house = 1; $house <= 12; $house++) {
                    $ra = $this->rightAscension($houses->cusps[$house], $eps);

                    $this->assertEqualsWithDelta(0.0,
                        fmod($ra - $base - 30 * ($house - 1) + 900, 360) - 180,
                        1e-9,
                        "Carter in {$location}: cusp {$house} is not ".(30 * ($house - 1)).' degrees of equator from the ascendant');
                }
            }
        }
    }

    /**
     * APC and Sunshine say each intermediate cusp lies on the circle through the north and south
     * points of the horizon and the point that divides the parallel: the ascendant's parallel in
     * APC, the Sun's in Sunshine. Three points on a great circle are three coplanar vectors, so
     * the triple product has to vanish.
     */
    public function test_apc_and_sunshine_draw_their_circles_through_the_thirds_of_the_parallel(): void
    {
        foreach (['1985-06-15 14:30', '1930-02-14 03:35'] as $date) {
            $jd = $this->jd($date);
            $eps = $this->obliquity($jd);

            foreach (self::PLACES as $location => [$lat, $lon]) {
                foreach ([HouseSystem::Apc, HouseSystem::Sunshine] as $system) {
                    $houses = Houses::calculate($system, $jd, $lat, $lon);
                    $lst = $houses->siderealTime;

                    $declination = $system === HouseSystem::Apc
                        ? rad2deg(asin(sin($eps) * sin(deg2rad($houses->ascendant))))
                        : $houses->sunDeclination;

                    $this->assertNotNull($declination);

                    $ad = rad2deg(asin(tan(deg2rad($lat)) * tan(deg2rad($declination))));
                    $diurnal = 90 + $ad;
                    $nocturnal = 90 - $ad;

                    // El punto norte del horizonte, en ecuatoriales.
                    $phi = deg2rad($lat);
                    $t = deg2rad($lst);
                    $north = [-sin($phi) * cos($t), -sin($phi) * sin($t), cos($phi)];

                    $divisions = [
                        11 => $lst + $diurnal / 3, 12 => $lst + 2 * $diurnal / 3,
                        9 => $lst - $diurnal / 3, 8 => $lst - 2 * $diurnal / 3,
                        2 => $lst + 180 - 2 * $nocturnal / 3, 3 => $lst + 180 - $nocturnal / 3,
                        5 => $lst + 180 + $nocturnal / 3, 6 => $lst + 180 + 2 * $nocturnal / 3,
                    ];

                    foreach ($divisions as $house => $ra) {
                        $point = $this->vector($ra, $declination);
                        $cusp = $this->vector(
                            $this->rightAscension($houses->cusps[$house], $eps),
                            rad2deg(asin(sin($eps) * sin(deg2rad($houses->cusps[$house]))))
                        );

                        $this->assertEqualsWithDelta(0.0, $this->tripleProduct($north, $point, $cusp), 1e-9,
                            "{$system->name()} in {$location} on {$date}: cusp {$house} is not on the circle of its dividing point");

                        // And on the point's side, not the antipode's: its altitude carries the
                        // sign of the dividing point (diurnal ones above, nocturnal below).
                        $altitude = $this->altitude($houses->cusps[$house], $lst, $lat, $eps);
                        $this->assertSame(in_array($house, [11, 12, 9, 8], true), $altitude > 0,
                            "{$system->name()} in {$location}: cusp {$house} is on the wrong side of the horizon");
                    }
                }
            }
        }
    }

    /**
     * Pullen SD: las casas de un cuadrante miden x + n, x, x + n con n un cuarto de lo que el
     * cuadrante se aparta de noventa. Pullen SR: miden rx, x, rx en el cuadrante estrecho y
     * r³x, r⁴x, r³x en el ancho. Las dos cosas se comprueban con los anchos, no con r.
     */
    public function test_both_pullens_divide_the_quadrant_as_they_say(): void
    {
        $jd = $this->jd();

        foreach (self::PLACES as $location => [$lat, $lon]) {
            $width = fn (array $c, int $house) => fmod($c[$house === 12 ? 1 : $house + 1] - $c[$house] + 360, 360);

            $sd = Houses::calculate(HouseSystem::PullenSD, $jd, $lat, $lon)->cusps;

            foreach ([[10, 11, 12], [1, 2, 3]] as [$first, $mean, $last]) {
                $quadrant = $width($sd, $first) + $width($sd, $mean) + $width($sd, $last);

                $this->assertEqualsWithDelta($width($sd, $first), $width($sd, $last), 1e-9, "Pullen SD en {$location}");
                $this->assertEqualsWithDelta(30 + ($quadrant - 90) / 2, $width($sd, $mean), 1e-9,
                    "Pullen SD in {$location}: house {$mean} does not absorb half the deviation");
            }

            $sr = Houses::calculate(HouseSystem::PullenSR, $jd, $lat, $lon)->cusps;
            $high = $width($sr, 10) + $width($sr, 11) + $width($sr, 12);
            [$narrow, $width2] = $high < 90 ? [[10, 11, 12], [1, 2, 3]] : [[1, 2, 3], [10, 11, 12]];

            $x = $width($sr, $narrow[1]);
            $r = $width($sr, $narrow[0]) / $x;

            $this->assertGreaterThanOrEqual(1.0, $r);
            $this->assertEqualsWithDelta($r * $x, $width($sr, $narrow[2]), 1e-9, "Pullen SR en {$location}");
            $this->assertEqualsWithDelta($r ** 3 * $x, $width($sr, $width2[0]), 1e-9, "Pullen SR en {$location}: r³x");
            $this->assertEqualsWithDelta($r ** 4 * $x, $width($sr, $width2[1]), 1e-9, "Pullen SR en {$location}: r⁴x");
            $this->assertEqualsWithDelta($r ** 3 * $x, $width($sr, $width2[2]), 1e-9, "Pullen SR en {$location}: r³x");
        }
    }

    public function test_the_systems_that_do_not_look_at_the_horizon(): void
    {
        $jd = $this->jd();
        $houses = fn (HouseSystem $s) => Houses::calculate($s, $jd, 40.4168, -3.7038);

        $equal = $houses(HouseSystem::Equal);
        $this->assertEqualsWithDelta($equal->ascendant, $equal->cusps[1], 1e-9);
        $this->assertEqualsWithDelta(30.0, fmod($equal->cusps[2] - $equal->cusps[1] + 360, 360), 1e-9);

        // Whole signs: every cusp falls on a zero degree, and the ascendant lives in the
        // casa uno aunque no la empiece.
        $wholeSigns = $houses(HouseSystem::WholeSign);
        foreach ($wholeSigns->cusps as $cusp) {
            $this->assertEqualsWithDelta(0.0, fmod($cusp, 30), 1e-9);
        }
        $this->assertSame(1, $wholeSigns->houseOf($wholeSigns->ascendant));

        // Vehlow: el ascendente queda en mitad de la casa uno, no al principio.
        $vehlow = $houses(HouseSystem::Vehlow);
        $this->assertEqualsWithDelta(15.0, fmod($vehlow->ascendant - $vehlow->cusps[1] + 360, 360), 1e-9);

        // Meridiano: su casa uno es el punto este, no el ascendente.
        $meridian = $houses(HouseSystem::Meridian);
        $this->assertEqualsWithDelta(0.0, fmod($meridian->cusps[1] - $meridian->eastPoint + 540, 360) - 180, 1e-9);

        // Azimutal: su casa uno es el antivertex y la siete el vertex.
        $azimuthal = $houses(HouseSystem::Azimuthal);
        $this->assertEqualsWithDelta(0.0, fmod($azimuthal->cusps[1] - $azimuthal->antivertex() + 540, 360) - 180, 1e-9);
        $this->assertEqualsWithDelta(0.0, fmod($azimuthal->cusps[7] - $azimuthal->vertex + 540, 360) - 180, 1e-9);

        // Carter: la casa uno es el ascendente y la diez NO es el medio cielo.
        $carter = $houses(HouseSystem::Carter);
        $this->assertEqualsWithDelta(0.0, fmod($carter->cusps[1] - $carter->ascendant + 540, 360) - 180, 1e-9);
        $this->assertGreaterThan(0.1, abs(fmod($carter->cusps[10] - $carter->midheaven + 540, 360) - 180));
    }

    /**
     * The vertex lies on the prime vertical and to the WEST.
     *
     * The prime vertical is the circle through the east point, the zenith and the west point, so
     * it only has points at azimuth ninety and two hundred and seventy: measuring the vertex's
     * azimuth checks at once that it is on the circle and which half it is in. The opposite one,
     * the antivertex, comes out at ninety by construction.
     */
    public function test_the_vertex_falls_west_on_the_prime_vertical(): void
    {
        foreach (['1985-06-15 14:30', '1930-02-14 03:35', '2025-10-03 18:35'] as $date) {
            $jd = $this->jd($date);
            $eps = $this->obliquity($jd);

            foreach (self::PLACES as $name => [$latitude, $longitude]) {
                $houses = Houses::calculate(HouseSystem::Equal, $jd, $latitude, $longitude);

                $this->assertNotNull($houses->vertex, "{$name} should have a vertex");

                $azimuth = $this->azimuth($houses->vertex, $houses->siderealTime, $latitude, $eps);
                $this->assertEqualsWithDelta(270.0, $azimuth, 1e-6,
                    "In {$name} on {$date} the vertex should look west, and it looks at {$azimuth} degrees");

                $antivertex = fmod($houses->vertex + 180.0, 360.0);
                $this->assertEqualsWithDelta(90.0,
                    $this->azimuth($antivertex, $houses->siderealTime, $latitude, $eps), 1e-6);
            }
        }
    }

    public function test_knowing_which_house_a_degree_falls_in(): void
    {
        $jd = $this->jd();
        $houses = Houses::calculate(HouseSystem::Placidus, $jd, 40.4168, -3.7038);

        // Every cusp opens its own house.
        for ($house = 1; $house <= 12; $house++) {
            $this->assertSame($house, $houses->houseOf($houses->cusps[$house] + 0.001));
        }

        // And right before a cusp the previous house still rules. It matters because the
        // houses are not the same size: looking for «the nearest cusp» would put half the
        // planets in the wrong house when one house is four times wider than its neighbour.
        for ($house = 1; $house <= 12; $house++) {
            $previous = $house === 1 ? 12 : $house - 1;

            $this->assertSame($previous, $houses->houseOf($houses->cusps[$house] - 0.001));
        }
    }

    /**
     * The polar convention: when the midheaven sinks below the horizon.
     *
     * Past the polar circle there are hours when the degree on the upper meridian lies BELOW the
     * horizon, and then the ascendant formula returns the point that sets instead of the one that
     * rises. Swiss straightens the ascendant in all twenty three systems and, in five of them
     * (the ones that hang from the meridian), it also moves the midheaven to the lower meridian
     * and the twelve cusps with it, leaving the wheel numbered clockwise. Without that, somebody
     * born in Tromsø compares their chart against any other program and not one cusp matches.
     *
     * **It is the only place in the engine with no definition of its own to hold on to**: which
     * system flips and which does not is Swiss's convention and not geometry, so the only
     * possible test here is a third party's table. The six charts cover both hemispheres, both
     * latitudes, the odd case and the normal one INSIDE the polar circle (which is what proves
     * the convention does not fire on its own), and Sunshine's two degeneracies of the Sun: it
     * not setting and it not rising.
     *
     * Measured with `swe.houses_armc` (that is, handing it our sidereal time and our obliquity,
     * to separate the algorithm from the ephemeris): the 1,224 cusps of the seventeen systems in
     * four polar places, three dates and a sweep of 24 hours agree with Swiss to ZERO. What is
     * left against `houses_ex`, up to 0.42 arcseconds, is our sidereal time against theirs,
     * amplified because near the polar circle the cusps run fast.
     */
    public function test_the_polar_conventions_agree_with_swiss(): void
    {
        foreach (self::SWISS_POLAR_CUSPS as $key => $systems) {
            [$date, $place] = explode('|', $key);
            [$lat, $lon] = self::POLAR_PLACES[$place];
            $jd = $this->jd($date);

            foreach ($systems as $value => $expected) {
                $system = HouseSystem::from($value);
                $ours = array_values(Houses::calculate($system, $jd, $lat, $lon)->cusps);

                foreach ($expected as $i => $expected) {
                    $difference = $this->arcseconds($ours[$i], $expected);

                    $this->assertLessThan(
                        1.0, $difference,
                        sprintf('%s in %s on %s, cusp %d: ours %.4f, Swiss %.4f (%.2f arcseconds)',
                            $value, $place, $date, $i + 1, $ours[$i], $expected, $difference)
                    );
                }
            }
        }
    }

    /**
     * And the four that share out diurnal arcs still say so there, which is right: Swiss's
     * convention changes their ascendant, but that does not hand them back an arc they do not
     * have. Swiss falls back to Porphyry in their place; here it throws, which is the same thing
     * said out loud.
     */
    public function test_the_polar_convention_does_not_revive_the_arc_systems(): void
    {
        foreach (['2000-06-21 23:00', '2000-03-20 05:00', '2000-12-21 10:00'] as $date) {
            foreach (self::POLAR_PLACES as [$lat, $lon]) {
                foreach (HouseSystem::all() as $system) {
                    if (! $system->failsAtThePoles()) {
                        continue;
                    }

                    try {
                        Houses::calculate($system, $this->jd($date), $lat, $lon);
                        $this->fail("{$system->name()} should still say so at latitude {$lat}");
                    } catch (RuntimeException $e) {
                        $this->assertStringContainsString($system->name(), $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * The trigger of the convention is NEVER met outside the polar circle, and that is not an
     * observation: it is arithmetic. The altitude of a point at culmination is
     * `90 - |latitude - declination|`, and the declination of the midheaven never exceeds the
     * obliquity, so below 66.5 degrees there is no way for the midheaven to sink. That is the
     * guard that this has moved none of the usual charts.
     *
     * Measured with a one minute step: Tromsø spends 17% of the day in the odd case, Longyearbyen
     * and Belgrano II base 34%, and Oslo, Madrid, Ushuaia and a point right on the polar circle,
     * 0%. Just inside, at 66.6 degrees, it is half an hour a day.
     */
    public function test_the_midheaven_only_sinks_past_the_polar_circle(): void
    {
        $locations = [
            'Tromso' => [69.65, 18.96, true],
            'Longyearbyen' => [78.22, 15.63, true],
            'Base Belgrano II' => [-77.87, -34.63, true],
            'Oslo' => [59.9139, 10.7522, false],
            'Madrid' => [40.4168, -3.7038, false],
            'Ushuaia' => [-54.8019, -68.3030, false],
            'Justo fuera del circulo polar' => [66.5, 0.0, false],
        ];

        foreach (['2000-06-21', '2000-12-21'] as $day) {
            foreach ($locations as $name => [$lat, $lon, $expectsSunk]) {
                $sunk = 0;

                for ($minute = 0; $minute < 1440; $minute += 3) {
                    $houses = Houses::calculate(
                        HouseSystem::Campanus,
                        $this->jd(sprintf('%s %02d:%02d', $day, intdiv($minute, 60), $minute % 60)),
                        $lat,
                        $lon
                    );

                    if ($this->midheavenSunk($houses)) {
                        $sunk++;
                    }
                }

                if (! $expectsSunk) {
                    $this->assertSame(0, $sunk, "{$name} should not have a single minute with the midheaven sunk");

                    continue;
                }

                // Between three and nine hours out of twenty four: 17% in Tromsø and 34% in
                // the two places at almost eighty degrees.
                $this->assertGreaterThan(60, $sunk * 3, "{$name} el {$day}");
                $this->assertLessThan(600, $sunk * 3, "{$name} el {$day}");
            }
        }
    }

    /**
     * With the midheaven sunk, the five that flip put house one on the point that RISES and house
     * ten on the one that culminates above the horizon, at the price of numbering the wheel
     * backwards. The rest keep the true midheaven and go on growing in longitude.
     *
     * That the houses run backwards is no ornament: `houseOf` assumes they grow, and assuming it
     * reads a house of thirty degrees as one of three hundred and thirty, with the whole chart
     * falling into house one.
     */
    public function test_the_polar_wheel_runs_backwards_and_the_house_lookup_knows_it(): void
    {
        $jd = $this->jd('2000-03-20 05:00');

        foreach (HouseSystem::all() as $system) {
            if ($system->failsAtThePoles()) {
                continue;
            }

            $houses = Houses::calculate($system, $jd, 78.22, 15.63);

            $this->assertTrue($this->midheavenSunk($houses) === $system->turnsWithTheMidheaven(),
                "{$system->name()}: el middle cielo tiene que estar movido solo si el system gira");

            // Every cusp opens its own house, whichever way the wheel runs. It is looked at a
            // hair inside, in the direction the wheel goes.
            $direction = fmod(fmod($houses->cusps[4] - $houses->cusps[1], 360) + 360, 360) < 180 ? 1 : -1;

            $this->assertSame($system->turnsWithTheMidheaven() ? -1 : 1, $direction,
                "{$system->name()}: el direction de la rueda no es el que toca");

            for ($house = 1; $house <= 12; $house++) {
                // The two Pullens leave houses of zero width inside the polar circle, and a
                // casa sin anchura no puede contener a nadie.
                $next = $houses->cusps[$house === 12 ? 1 : $house + 1];

                if ($this->arcseconds($houses->cusps[$house], $next) < 3600) {
                    continue;
                }

                $this->assertSame($house, $houses->houseOf($houses->cusps[$house] + $direction * 0.001),
                    "{$system->name()}, cusp {$house}");
            }
        }
    }

    /**
     * Whether the midheaven of a set of houses is the opposite of the one really on the meridian.
     */
    private function midheavenSunk(Houses $houses): bool
    {
        $actual = Houses::midheaven($houses->siderealTime, deg2rad($houses->obliquity));

        return abs(fmod(abs($houses->midheaven - $actual) + 180, 360) - 180) > 90;
    }
}
