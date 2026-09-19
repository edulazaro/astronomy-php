<?php

/*
 * Mean elements of the eight planets · Simon et al. (1994)
 *
 * GENERATED. Do not edit by hand: written by `php artisan astro:elementos-medios` in the
 * Tarotian application from ERFA's plan94.c, the reference implementation (BSD license)
 * of the paper by Simon, Bretagnon, Chapront, Chapront-Touzé, Francou and Laskar,
 * A&A 282, 663-683 (1994).
 *
 * Time `t` is in Julian MILLENNIA TT from J2000, the paper's unit and not the rest of
 * the engine's. The angles are referred to the J2000 ecliptic and equinox, a FIXED
 * plane: that is why Earth's inclination is zero at J2000 and grows from there.
 *
 * `a` (AU) and `e` are ordinary polynomials:        a0 + (a1 + a2·t)·t
 * `l`, `pi`, `i` and `om` are degrees plus seconds: (3600·a0 + (a1 + a2·t)·t) / 3600 degrees
 *
 * `l` is the mean longitude, `pi` the longitude of perihelion ϖ (the broken angle from
 * the textbooks, not the ecliptic longitude of the point) and `om` the ascending node's.
 *
 * The trigonometric terms from plan94.c are NOT here: those are the periodic corrections
 * that function adds to compute a POSITION, which is exactly what a mean element lacks.
 */

return [
    'source' => 'Simon, Bretagnon, Chapront, Chapront-Touzé, Francou and Laskar (1994), A&A 282, 663-683, through ERFA plan94.c',
    'bodies' => [
        'mercury' => [
            'a' => [0.3870983098, 0.0, 0.0], // a
            'l' => [252.25090552, 5381016286.88982, -1.92789],
            'e' => [0.2056317526, 0.0002040653, -28349e-10],
            'pi' => [77.45611904, 5719.11590, -4.83016],
            'i' => [7.00498625, -214.25629, 0.28977],
            'om' => [48.33089304, -4515.21727, -31.79892],
        ],
        'venus' => [
            'a' => [0.7233298200, 0.0, 0.0], // a
            'l' => [181.97980085, 2106641364.33548, 0.59381],
            'e' => [0.0067719164, -0.0004776521, 98127e-10],
            'pi' => [131.56370300, 175.48640, -498.48184],
            'i' => [3.39466189, -30.84437, -11.67836],
            'om' => [76.67992019, -10008.48154, -51.32614],
        ],
        'earth' => [
            'a' => [1.0000010178, 0.0, 0.0], // a
            'l' => [100.46645683, 1295977422.83429, -2.04411],
            'e' => [0.0167086342, -0.0004203654, -0.0000126734],
            'pi' => [102.93734808, 11612.35290, 53.27577],
            'i' => [0.0, 469.97289, -3.35053],
            'om' => [174.87317577, -8679.27034, 15.34191],
        ],
        'mars' => [
            'a' => [1.5236793419, 3e-10, 0.0], // a
            'l' => [355.43299958, 689050774.93988, 0.94264],
            'e' => [0.0934006477, 0.0009048438, -80641e-10],
            'pi' => [336.06023395, 15980.45908, -62.32800],
            'i' => [1.84972648, -293.31722, -8.11830],
            'om' => [49.55809321, -10620.90088, -230.57416],
        ],
        'jupiter' => [
            'a' => [5.2026032092, 19132e-10, -39e-10], // a
            'l' => [34.35151874, 109256603.77991, -30.60378],
            'e' => [0.0484979255, 0.0016322542, -0.0000471366],
            'pi' => [14.33120687, 7758.75163, 259.95938],
            'i' => [1.30326698, -71.55890, 11.95297],
            'om' => [100.46440702, 6362.03561, 326.52178],
        ],
        'saturn' => [
            'a' => [9.5549091915, -0.0000213896, 444e-10], // a
            'l' => [50.07744430, 43996098.55732, 75.61614],
            'e' => [0.0555481426, -0.0034664062, -0.0000643639],
            'pi' => [93.05723748, 20395.49439, 190.25952],
            'i' => [2.48887878, 91.85195, -17.66225],
            'om' => [113.66550252, -9240.19942, -66.23743],
        ],
        'uranus' => [
            'a' => [19.2184460618, -3716e-10, 979e-10], // a
            'l' => [314.05500511, 15424811.93933, -1.75083],
            'e' => [0.0463812221, -0.0002729293, 0.0000078913],
            'pi' => [173.00529106, 3215.56238, -34.09288],
            'i' => [0.77319689, -60.72723, 1.25759],
            'om' => [74.00595701, 2669.15033, 145.93964],
        ],
        'neptune' => [
            'a' => [30.1103868694, -16635e-10, 686e-10], // a
            'l' => [304.34866548, 7865503.20744, 0.21103],
            'e' => [0.0094557470, 0.0000603263, 0.0],
            'pi' => [48.12027554, 1050.71912, 27.39717],
            'i' => [1.76995259, 8.12333, 0.08135],
            'om' => [131.78405702, -221.94322, -0.78728],
        ],
    ],
];
