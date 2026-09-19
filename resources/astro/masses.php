<?php

/*
 * Masses of the solar system · DE440
 *
 * GENERATED. Do not edit by hand: written by `astronomy masses`
 * in this package from the ASCII header of the ephemeris the JPL publishes, and the figures
 * go in exactly as they appear there, with all their digits. Next to each one, the name
 * the header calls it by, so it can be checked at a glance.
 *
 * `gm`: the gravitational parameters GM in AU³/day², the header's unit. What actually
 * gets used are ratios between them, so the unit never enters any calculation. The
 * `earth-moon` one is the SYSTEM's, not Earth's alone: to split the two there is
 * `emrat`, Earth's mass divided by the Moon's. Pluto is the Pluto-Charon system.
 * `au`: kilometers in one astronomical unit, from the same header.
 */

return [
    'ephemeris' => 'DE440',
    'au' => 0.149597870699999988E+09, // AU
    'emrat' => 0.813005682214972154E+02, // EMRAT
    'gm' => [
        'sun' => 0.295912208284119561E-03, // GMS
        'mercury' => 0.491250019488931818E-10, // GM1
        'venus' => 0.724345233264411869E-09, // GM2
        'earth-moon' => 0.899701139294734660E-09, // GMB
        'mars' => 0.954954882972581189E-10, // GM4
        'jupiter' => 0.282534582522579175E-06, // GM5
        'saturn' => 0.845970599337629027E-07, // GM6
        'uranus' => 0.129202656496823994E-07, // GM7
        'neptune' => 0.152435734788519386E-07, // GM8
        'pluto' => 0.217509646489335811E-11, // GM9
    ],
];
