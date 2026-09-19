<?php

/**
 * Orbital elements of the nineteen bodies that DO NOT EXIST.
 *
 * The eight planets of the Hamburg school, four hypothetical bodies with a following, the
 * four positions that Leverrier, Adams, Lowell and Pickering predicted before Neptune and
 * Pluto were found, and three that come from pseudoscientific literature or discarded
 * hypotheses. None of them has ever been observed: there is an ellipse someone postulated,
 * and its position is computed by propagating it.
 *
 * **This file is HAND-WRITTEN, and it is the only exception in the whole engine.** Nowhere
 * else is there a single typed-in coefficient: VSOP87, ELP, nutation, delta T, the stars
 * and the JPL tables are all downloaded from their source with a command and converted.
 * Not here, and the reason proved itself while writing this: the address `seorbel.txt` was
 * downloaded from for the eight uranian bodies
 * (`https://www.astro.com/ftp/swisseph/ephe/seorbel.txt`) no longer exists; it answers with
 * a 404 showing a drawing of Pluto. A command that "downloads" a hundred and fifty numbers
 * from a web page is a command that breaks the day the page moves, all to save typing them
 * once in a lifetime.
 *
 * **Where they come from: `seorbel.txt`**, the data file that Astrodienst publishes and
 * ships with Swiss Ephemeris since version 1.52, under the heading "Orbital elements of
 * ficticious planets". **It is a published data table, and it is read from there and NOT
 * from Swiss's source code, which is AGPL**; copying that code is exactly what this engine
 * exists not to do. Since the original address is down, it has been read from two
 * independent mirrors of the file: `mivion/swisseph` (`ephe/seorbel.txt`) and the copy
 * that `astrorigin/pyswisseph`'s documentation includes in full
 * (`docs/programmers_manual/planetary_positions/bodies/seorbel.txt`): they match character
 * for character on the nineteen lines used here, and the second is an older copy that is
 * missing two bodies not included here.
 *
 * Each line of the file carries, in order: epoch of the elements, equinox, mean anomaly,
 * semi-major axis, eccentricity, argument of perihelion, ascending node, inclination and
 * name. That is exactly what is here, plus two things that are markers in the file and
 * fields here: `JDATE` in the equinox (which here is `null`) and the word `geo` at the end
 * of the line (which here is `geocentric`).
 *
 * **Three format traps, all measured against Swiss before trusting them:**
 *
 * 1. **Not all of them are heliocentric.** Selena and Waldemath's moon orbit the EARTH,
 *    and the file marks that with a `geo` at the end of the line. A geocentric body
 *    treated as heliocentric ends up one astronomical unit from where it should be,
 *    looking exactly like a normal planet in some sign. They are the only two of the
 *    nineteen.
 * 2. **Three carry polynomials in time**, not plain numbers: Vulcan, Selena and Waldemath
 *    give the mean anomaly (and the first two also the perihelion and the node) as
 *    `a + b*T`. That is why the three angles are stored as LISTS of coefficients even
 *    though almost all of them have just one.
 * 3. **That `T` is counted from EACH BODY'S OWN EPOCH, not from J2000.** This is measured:
 *    counting T from J2000, Waldemath comes out 177 degrees from Swiss and Vulcan 16. And
 *    it does not show up with just any body, because Selena's epoch IS J2000, so with it
 *    the two readings agree.
 *
 * **The mean motion is not in the file, and that is not an omission.** When the anomaly
 * carries a linear term, that term IS the mean motion (in degrees per Julian century).
 * When it does not, it comes from the semi-major axis via Kepler's third law. That this is
 * correct is confirmed by the file itself: Proserpina's comment gives "170.73 + 51.05 * T",
 * and the third law applied to its semi-major axis of 79.22563 gives 51.05 degrees per
 * century down to the last decimal.
 *
 * @return array<string, array{epoch: float, equinox: float|null, anomaly: list<float>, semiMajorAxis: float, eccentricity: float, perihelion: list<float>, node: list<float>, inclination: float, geocentric: bool}>
 */

/* The epochs are written as the Julian day they are, not as named constants. A `const`
   inside a file that is returned with `return` blows up the SECOND time it gets included
   ("Constant already defined"), and this file is included once per process, but nothing
   guarantees that will stay true. The two that `seorbel.txt` names are J1900 (2415020.0,
   i.e. 1900 January 0.5) and J2000 (2451545.0, 2000 January 1.5), both in Terrestrial
   Time; `B1950` appears in the file for two comets that are not included here. */

return [
    /* ------------------------------------------------------------------------------------
       The eight of the Hamburg school: entries 1 to 8 of `seorbel.txt`, under the heading
       "Witte/Sieggruen planets, refined by James Neely".

       Cupido, Hades, Zeus and Kronos were postulated by Alfred Witte, and Apollon, Admetos,
       Vulkanus and Poseidon by Friedrich Sieggrün, in Hamburg between the twenties and the
       thirties. Their original elements were impossible (perfectly circular orbits with
       zero inclination, which does not happen in a physical system), and the ones used
       today are the revision James Neely published in Matrix Journal VII (1980), which
       gives small eccentricities to Witte's four and leaves Sieggrün's four circular. That
       is the set Swiss uses, and its documentation says so in chapter 2.7.1: "SWISSEPH
       uses James Neely's revised orbital elements, because they agree better with the
       original position tables of Witte and Sieggrün".

       Sieggrün's four carry eccentricity, perihelion, node and inclination at zero: it is
       not that data is missing, it is that the postulated orbit is that.

       **A warning for anyone comparing against another program: there are two Kronoses
       floating around.** The semi-major axis here, 64.81690, is the one from the published
       file and the same one the literature gives (64.816896). Swiss also carries a table of
       elements built into its own code, which is what it falls back to when it cannot find
       `seorbel.txt`, and there Kronos's semi-major axis is 64.81965. Three thousandths of
       an astronomical unit are nothing and at the same time are 77 arcseconds in 2400,
       because they change the mean motion and the error grows with time. The other seven
       agree in both tables, so the mistake shows up as an odd body and not as a missing
       file.
       ------------------------------------------------------------------------------------ */

    'cupido' => [
        'epoch' => 2415020.0,
        'equinox' => 2415020.0,
        'anomaly' => [163.7409],
        'semiMajorAxis' => 40.99837,
        'eccentricity' => 0.00460,
        'perihelion' => [171.4333],
        'node' => [129.8325],
        'inclination' => 1.0833,
        'geocentric' => false,
    ],
    'hades' => [
        'epoch' => 2415020.0,
        'equinox' => 2415020.0,
        'anomaly' => [27.6496],
        'semiMajorAxis' => 50.66744,
        'eccentricity' => 0.00245,
        'perihelion' => [148.1796],
        'node' => [161.3339],
        'inclination' => 1.0500,
        'geocentric' => false,
    ],
    'zeus' => [
        'epoch' => 2415020.0,
        'equinox' => 2415020.0,
        'anomaly' => [165.1232],
        'semiMajorAxis' => 59.21436,
        'eccentricity' => 0.00120,
        'perihelion' => [299.0440],
        'node' => [0.0],
        'inclination' => 0.0,
        'geocentric' => false,
    ],
    'kronos' => [
        'epoch' => 2415020.0,
        'equinox' => 2415020.0,
        'anomaly' => [169.0193],
        'semiMajorAxis' => 64.81690,
        'eccentricity' => 0.00305,
        'perihelion' => [208.8801],
        'node' => [0.0],
        'inclination' => 0.0,
        'geocentric' => false,
    ],
    'apollon' => [
        'epoch' => 2415020.0,
        'equinox' => 2415020.0,
        'anomaly' => [138.0533],
        'semiMajorAxis' => 70.29949,
        'eccentricity' => 0.0,
        'perihelion' => [0.0],
        'node' => [0.0],
        'inclination' => 0.0,
        'geocentric' => false,
    ],
    'admetos' => [
        'epoch' => 2415020.0,
        'equinox' => 2415020.0,
        'anomaly' => [351.3350],
        'semiMajorAxis' => 73.62765,
        'eccentricity' => 0.0,
        'perihelion' => [0.0],
        'node' => [0.0],
        'inclination' => 0.0,
        'geocentric' => false,
    ],
    'vulkanus' => [
        'epoch' => 2415020.0,
        'equinox' => 2415020.0,
        'anomaly' => [55.8983],
        'semiMajorAxis' => 77.25568,
        'eccentricity' => 0.0,
        'perihelion' => [0.0],
        'node' => [0.0],
        'inclination' => 0.0,
        'geocentric' => false,
    ],
    'poseidon' => [
        'epoch' => 2415020.0,
        'equinox' => 2415020.0,
        'anomaly' => [165.5163],
        'semiMajorAxis' => 83.66907,
        'eccentricity' => 0.0,
        'perihelion' => [0.0],
        'node' => [0.0],
        'inclination' => 0.0,
        'geocentric' => false,
    ],

    /* ------------------------------------------------------------------------------------
       The four hypothetical bodies with a following: entries 9, 16, 17 and 18.
       ------------------------------------------------------------------------------------ */

    /* Isis-Transpluto, the planet that Theodor Landscheidt and others placed beyond
       Pluto. Elements by Charles Strubell, published in "Die Sterne" 3/1952, p. 70 and
       following. Its epoch is 1772.76 and it gives no equinox: `seorbel.txt` takes that of
       1945 (Julian day 2431456.5) because that is what the ASTRON ephemeris uses, and
       notes that the choice is odd. It is kept as is: changing it would move the body and
       it would stop matching what any other program reads. */
    'isis-transpluto' => [
        'epoch' => 2368547.66,
        'equinox' => 2431456.5,
        'anomaly' => [0.0],
        'semiMajorAxis' => 77.775,
        'eccentricity' => 0.3,
        'perihelion' => [0.7],
        'node' => [0.0],
        'inclination' => 0.0,
        'geocentric' => false,
    ],

    /* Vulcan, the intramercurial planet. It is the only one on the list that was real
       science: Urbain Le Verrier proposed it in 1859 to explain the advance of Mercury's
       perihelion, it was searched for over half a century, and in 1915 general relativity
       explained that advance without any planet. The elements here are L. H. Weston's,
       which is astrology and not that.

       **Watch out, the confusion is easy to make: this is not Vulkanus**, Sieggrün's
       fourth, which is further up with a semi-major axis of 77 astronomical units. This one
       orbits at 0.137, that is, inside Mercury, and goes around in eighteen days. */
    'vulcan' => [
        'epoch' => 2415020.0,
        'equinox' => null,
        'anomaly' => [252.8987988, 707550.7341],
        'semiMajorAxis' => 0.13744,
        'eccentricity' => 0.019,
        'perihelion' => [322.212069, 1670.056],
        'node' => [47.787931, -1670.056],
        'inclination' => 7.5,
        'geocentric' => false,
    ],

    /* Selena or White Moon, Lilith's luminous counterpart in the astrology that uses her.
       **It is GEOCENTRIC**: its elements describe an orbit around the Earth, with a radius
       of 0.0528 astronomical units and a period of seven years. A perfectly circular orbit,
       in the plane of the ecliptic and with the anomaly given as a polynomial, so Kepler's
       third law plays no part here at all. */
    'selena' => [
        'epoch' => 2451545.0,
        'equinox' => null,
        'anomaly' => [242.2205555, 5143.5418158],
        'semiMajorAxis' => 0.05280098949,
        'eccentricity' => 0.0,
        'perihelion' => [0.0],
        'node' => [0.0],
        'inclination' => 0.0,
        'geocentric' => true,
    ],

    /* Proserpina, a hypothetical trans-Neptunian of astrology. `seorbel.txt` gives no
       source beyond a personal page that has since vanished, and that is all there is: no
       author is credited because none is recorded. Its comment in the file, "170.73 +
       51.05 * T", is what confirms that the mean motion comes from Kepler's third law when
       the anomaly carries no linear term: over its semi-major axis, the law gives 51.05
       degrees per century. */
    'proserpina' => [
        'epoch' => 2415020.0,
        'equinox' => null,
        'anomaly' => [170.73],
        'semiMajorAxis' => 79.225630,
        'eccentricity' => 0.0,
        'perihelion' => [0.0],
        'node' => [0.0],
        'inclination' => 0.0,
        'geocentric' => false,
    ],

    /* ------------------------------------------------------------------------------------
       The four predictions that came out wrong: entries 12 to 15, all from W. G. Hoyt,
       "Planets X and Pluto", Tucson 1980, p. 63.

       These four are not hypothetical bodies: they are the positions that four astronomers
       CALCULATED for a planet that had not yet been seen. Leverrier and Adams got Neptune
       right just enough for Galle to find it in 1846 less than a degree from what was
       predicted, but their orbits were different: the semi-major axis they give, 36 and 37
       astronomical units, is not Neptune's, which is 30. And Lowell's and Pickering's for
       the trans-Neptunian planet are simply wrong: Pluto was found in 1930 near where
       Lowell said, and by chance, because Pluto is too small to produce the perturbations
       Lowell believed he was measuring.

       All four are propagated just like any ellipse, and the longitude they give is a
       perfectly believable figure. That is why the form's checkbox and the summary the
       interpreter reads say what they are.
       ------------------------------------------------------------------------------------ */

    'neptune-leverrier' => [
        'epoch' => 2395662.5,
        'equinox' => 2395662.5,
        'anomaly' => [34.05],
        'semiMajorAxis' => 36.15,
        'eccentricity' => 0.10761,
        'perihelion' => [284.75],
        'node' => [0.0],
        'inclination' => 0.0,
        'geocentric' => false,
    ],
    'neptune-adams' => [
        'epoch' => 2395662.5,
        'equinox' => 2395662.5,
        'anomaly' => [24.28],
        'semiMajorAxis' => 37.25,
        'eccentricity' => 0.12062,
        'perihelion' => [299.11],
        'node' => [0.0],
        'inclination' => 0.0,
        'geocentric' => false,
    ],
    'pluto-lowell' => [
        'epoch' => 2425977.5,
        'equinox' => 2425977.5,
        'anomaly' => [281.0],
        'semiMajorAxis' => 43.0,
        'eccentricity' => 0.202,
        'perihelion' => [204.9],
        'node' => [0.0],
        'inclination' => 0.0,
        'geocentric' => false,
    ],
    'pluto-pickering' => [
        'epoch' => 2425977.5,
        'equinox' => 2425977.5,
        'anomaly' => [48.95],
        'semiMajorAxis' => 55.1,
        'eccentricity' => 0.31,
        'perihelion' => [280.1],
        'node' => [100.0],
        'inclination' => 15.0,
        'geocentric' => false,
    ],

    /* ------------------------------------------------------------------------------------
       The three that come from pseudoscience or from discarded hypotheses: entries 10, 11
       and 19.
       ------------------------------------------------------------------------------------ */

    /* Nibiru. It is the planet from Zecharia Sitchin's writings, with no astronomical
       basis whatsoever: no astronomer has ever proposed or searched for it. The elements
       are from Christian Wöltge (Hannover) and describe an almost parabolic orbit, with
       eccentricity 0.981 and retrograde (inclination 158 degrees), reaching out to 465
       astronomical units.

       Its eccentricity is the reason the Kepler equation in `FictitiousBodies` carries a
       bisection safeguard: at 0.98, Newton starting from the mean anomaly runs off and
       never comes back. The other eighteen converge in two turns.

       The node is NEGATIVE, exactly as published. It gets normalized when it is used, not
       when it is stored: an element is typed in the way its source states it. */
    'nibiru' => [
        'epoch' => 1856113.380954,
        'equinox' => 1856113.380954,
        'anomaly' => [0.0],
        'semiMajorAxis' => 234.8921,
        'eccentricity' => 0.981092,
        'perihelion' => [103.966],
        'node' => [-44.567],
        'inclination' => 158.708,
        'geocentric' => false,
    ],

    /* Harrington. Robert S. Harrington published a search for Planet X with these
       elements in 1988 (Astronomical Journal 96(4), October 1988). It was real astronomy,
       not astrology, and it was discarded: the leftover residuals in the orbits of Uranus
       and Neptune it rested on disappeared once Voyager 2 measured Neptune's mass in
       1989. */
    'harrington' => [
        'epoch' => 2374696.5,
        'equinox' => 2451545.0,
        'anomaly' => [0.0],
        'semiMajorAxis' => 101.2,
        'eccentricity' => 0.411,
        'perihelion' => [208.5],
        'node' => [275.4],
        'inclination' => 32.4,
        'geocentric' => false,
    ],

    /* Waldemath's second moon. Georg Waldemath announced in 1898 that he had seen a
       second satellite of the Earth; nobody ever confirmed it and its existence has been
       discounted for over a century.

       **It is GEOCENTRIC**, like Selena: at 0.00684 astronomical units from here, that is,
       three times the distance of the Moon, going around in four months. And it is the one
       that strays furthest if treated as heliocentric, because its vector is tiny and what
       would be added on top of it is a whole astronomical unit.

       `seorbel.txt` warns that these elements were derived by Dieter Koch from Waldemath's
       originals as given in David Walters's book on Vulcan, and that they do not match
       Solar Fire's, which interprets the mean longitude Waldemath published as an observed
       true longitude. What is here is what is in the published file, and that is why it
       matches Swiss and not Solar Fire. */
    'waldemath' => [
        'epoch' => 2414290.95827875,
        'equinox' => 2414290.95827875,
        'anomaly' => [70.3407215, 109023.2634989],
        'semiMajorAxis' => 0.0068400705250028,
        'eccentricity' => 0.1587,
        'perihelion' => [8.14049594, 2393.47417444],
        'node' => [136.24878256, -1131.71719709],
        'inclination' => 2.5,
        'geocentric' => true,
    ],
];
