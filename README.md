![Astronomy](art/banner.png)

# Astronomy

<p align="center">
    <a href="https://github.com/edulazaro/astronomy-php/actions/workflows/tests.yml"><img src="https://github.com/edulazaro/astronomy-php/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
    <a href="https://packagist.org/packages/edulazaro/astronomy"><img src="https://img.shields.io/packagist/v/edulazaro/astronomy" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/edulazaro/astronomy"><img src="https://img.shields.io/packagist/dt/edulazaro/astronomy" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/edulazaro/astronomy"><img src="https://img.shields.io/packagist/php-v/edulazaro/astronomy" alt="PHP Version"></a>
    <a href="https://github.com/edulazaro/astronomy-php/blob/main/LICENSE.md"><img src="https://img.shields.io/packagist/l/edulazaro/astronomy" alt="License"></a>
</p>

A pure PHP astronomical engine. Planetary and lunar positions, houses, fixed stars, eclipses,
occultations, rise and set times, orbital elements and heliacal phenomena, verified against JPL
Horizons.

No framework. No Composer dependencies, and no extension beyond `mbstring`. No network calls at runtime: the
data ships with the package, **and the package knows how to rebuild it**. The series, the tables
and the corrections are generated from their original sources by its own command line, so the day
Strasbourg moves a file or the IERS publishes another year, the answer is in here and not in
somebody's checkout.


## Why it exists

PHP could not do this.

A position good to better than an arcsecond, over several centuries, is not a formula you write
in an afternoon. It is fifty thousand coefficients, a correction towards a numerical integration,
two time scales that must not be confused, precession, nutation, light time, aberration and the
bending of light near the Sun. Leave out the last one and a planet sitting half a degree from the
Sun comes out almost a full arcsecond wrong.

What the language had were two options. One was a binding to a C library, which means compiling
an extension on every machine you deploy to and ruling out most shared hosting. The other was one
of the truncated series implementations, honest about being good to arcminutes. An arcminute is
sixty arcseconds: for plenty of uses that is fine, and for anything that has to agree with a
published table it is not.

This computes the positions itself, in PHP, and checks them against the Jet Propulsion
Laboratory's own ephemeris system. Nothing is copied from anybody's source: the series come from
their original publications (VSOP87 by Bretagnon and Francou, ELP 2000-82B by Chapront-Touze and
Chapront), the nutation from ERFA, the mean elements from Simon et al. (1994), and the rest is
spherical trigonometry that has been published for centuries.

## Install

```bash
composer require edulazaro/astronomy
```

Requires PHP 8.2 or newer.

## Use

```php
use Astronomy\Ephemeris;
use Astronomy\Body;
use Astronomy\Time;

$jdTT = Time::tt(Time::julianDay(new DateTimeImmutable('1981-05-11 07:15:00', new DateTimeZone('UTC'))));

$mars = Ephemeris::position(Body::Mars, $jdTT);

echo $mars->longitude;   // apparent ecliptic longitude of the date, in degrees
echo $mars->latitude;
echo $mars->speed;       // degrees per day, signed: negative means retrograde
```

Houses, from a julian day in Universal Time:

```php
use Astronomy\Houses;
use Astronomy\HouseSystem;

$houses = Houses::calculate(HouseSystem::Placidus, $jdUt, latitude: 40.4165, geographicLongitude: -3.7026);

echo $houses->ascendant;
echo $houses->midheaven;
echo $houses->cusps[1];
```

The same houses without a clock, for anyone who already has their sidereal time:

```php
$houses = Houses::fromArmc(HouseSystem::Regiomontanus, armc: 123.456, latitude: 40.4165, obliquity: 23.4392);
```

Every public method carries a docblock explaining what it does and, where it matters, why it
is done that way.


## Documentation

The full documentation is at **[edulazaro.com/portfolio/astronomy](https://edulazaro.com/portfolio/astronomy)**:
eighteen chapters, and every example in them was executed to produce the output it shows.

| | |
|---|---|
| [Positions](https://edulazaro.com/portfolio/astronomy/positions) | bodies, speeds, position types, rectangular coordinates |
| [Time and calendars](https://edulazaro.com/portfolio/astronomy/time) | UTC, UT1, TT, delta T, leap seconds, the Julian calendar |
| [Reference frames](https://edulazaro.com/portfolio/astronomy/frames) | geocentric, heliocentric, barycentric, topocentric, planetocentric |
| [Houses](https://edulazaro.com/portfolio/astronomy/houses) | twenty three systems, cusp speeds, house position with latitude |
| [Fixed stars](https://edulazaro.com/portfolio/astronomy/fixed-stars) | the catalogue, proper motion, the IAU constellations |
| [Eclipses and occultations](https://edulazaro.com/portfolio/astronomy/eclipses) | global and local circumstances, the central path, saros |
| [Rise, set and twilight](https://edulazaro.com/portfolio/astronomy/rise-and-set) | passes, refraction, a horizon that is not at zero |
| [Orbits](https://edulazaro.com/portfolio/astronomy/orbits) | nodes, apsides, osculating and mean elements |
| [Phenomena](https://edulazaro.com/portfolio/astronomy/phenomena) | phase, diameter, magnitude, lunations, heliacal events |
| [Crossings and retrogrades](https://edulazaro.com/portfolio/astronomy/crossings) | when a body reaches a longitude, and where it turns |
| [The sidereal zodiac](https://edulazaro.com/portfolio/astronomy/sidereal) | ayanamsas and the lunar mansions |
| [Downloadable bodies](https://edulazaro.com/portfolio/astronomy/downloadable-bodies) | any asteroid, satellite or comet the JPL has |
| [The data](https://edulazaro.com/portfolio/astronomy/the-data) | where every number came from and how to rebuild it |
| [Precision](https://edulazaro.com/portfolio/astronomy/precision) | how all of it is checked, and what is left |
| [Reference](https://edulazaro.com/portfolio/astronomy/reference) | every public class, enum and interface |

## What it covers

| Area | What is there |
|---|---|
| Ephemerides | Sun, Moon, the eight planets, Pluto, Chiron, Pholus, Ceres, Pallas, Juno, Vesta |
| Frames | geocentric, heliocentric, barycentric, topocentric and planetocentric |
| Houses | twenty three systems plus the thirty six Gauquelin sectors, house position with latitude, the daily motion of every cusp and angle, and the Gauquelin sector taken either from the position or from the body's own rising and setting |
| Fixed stars | the whole catalogue, 1,099 objects from Hipparcos-2 and SIMBAD, with proper motion, aberration and nutation |
| Eclipses | solar and lunar, global and local circumstances, and the central path of a solar eclipse |
| Occultations | of planets and stars by the Moon |
| Horizon | rise, set, culminations, twilights, refraction and horizon dip, tracked across the day or solved by fixed point |
| Time | delta T from observed values, leap seconds, UTC, UT1, TT, sidereal time, julian and gregorian calendars, and clock offsets that carry the 60th second |
| Orbits | osculating and mean elements, nodes, apsides, periods and extreme distances |
| Phenomena | phase, illuminated fraction, elongation, apparent diameter and visual magnitude |
| Lunar points | the nodes and Lilith, mean, true and interpolated, with Priapus opposite |
| Sidereal | 43 ayanamsas, plus a user defined one, and the sidereal position measured on the ecliptic of t0 |
| Positions with options | apparent, astrometric, geometric, without aberration or without deflection; J2000 or of the date; spherical or rectangular, with the three speeds |
| Bodies that do not exist | the nineteen the published tables carry elements for, from the Hamburg school to Nibiru, each one labelled with what it actually is |
| Other | retrograde stations, longitude crossings, moon phases and lunations, the equation of time, saros numbers, heliacal phenomena, nakshatras |


## Precision

Measured against JPL Horizons over 1600 to 2400:

| | Worst case |
|---|---|
| All bodies, 1600 to 2400 | 0.18″ |
| All bodies, twentieth century | 0.11″ |
| Median, twentieth century | 0.002″ |
| 144 house cusps against an independent implementation | 0.095″ |
| Ascendant and midheaven | 0.078″ |
| Fixed stars, median in 2000 | 0.04″ |

A chart is read in degrees and minutes. A minute is sixty arcseconds.

**And it is reproducible rather than asserted.** `astronomy check` asks JPL Horizons for every
body live and prints the difference; run over 1900 to 2050 it gives 0.135″ worst case, the worst
being Ceres. The engine's floor of about 0.13″ is not the ephemerides and not the tables: it is
the precession model, measured by taking the apparent vector Horizons itself publishes, rotating
it with this engine's precession and nutation, and comparing against the longitude Horizons also
publishes.

## The data

The series and tables live in `resources/astro` and ship with the package: an application does
not depend on anybody's server being up on the day it deploys. Every file is generated from its
original source by a command, and not one of their fifty thousand coefficients is typed by hand:
one number copied wrong gives a position that is off and that nobody catches by looking at a
wheel.

**There is exactly one exception, and the file says so in capitals at the top**:
`resources/astro/fictitious.php`, the orbital elements of the nineteen bodies that do not exist.
There is nothing to download for those. They were never observed, so there is no observation to
fetch: what there is is a published table of elements, and it is read from there. A command that
downloads a hundred and fifty numbers off a web page is a command that breaks when the page
moves, and that one already has: the address the file came from answers a 404 today.

`Astronomy\DataFolder` decides where they are read from. By default it is the folder inside this
package, deduced from where the class itself sits, so nothing has to be configured. An
application that keeps them somewhere else calls `DataFolder::useFolder()` once at boot, before
anything is read.


## Rebuilding the data

The package ships its own command line, and it is how every table in `resources/astro` was
written:

```bash
vendor/bin/astronomy            # the list of commands
vendor/bin/astronomy check      # compare every body against JPL Horizons, live
```

Twelve of those commands download a published source (CDS, ERFA, the IERS, the JPL, SIMBAD and
VizieR) and rewrite a table. Rerunning one reproduces its file byte for byte, and that is the
check: a generator is only as good as what it writes. The whole list, with what each one
downloads and what it weighs, is in
**[The data](https://edulazaro.com/portfolio/astronomy/the-data)**.

## Tests

```bash
composer install
composer test
```

147 tests and 3,168 assertions, and **the suite runs with no network**: the reference values,
from JPL Horizons and from other implementations, are written into the tests by hand, so a build
does not fail because Pasadena is down.

The one check that does go to the network is deliberately not in the suite, and it is
`astronomy check`: it asks JPL Horizons for every body live and prints how far apart the two are.
That is the number this README opens with, and it is here so that anybody can reproduce it rather
than take it on trust.

## Contributing

Contributions are welcome. Fork the repo, add tests, and open a PR.

Two things this package asks for that most do not, and both come from what it is:

- **A number, not an adjective.** Anything that changes a computed value has to say how much,
  measured against JPL Horizons or against the published source, and the figure goes in the
  docblock. A chart that is wrong does not look wrong, which is why this is the rule.
- **Published sources only, never another implementation's source code.** Whatever its licence,
  reading somebody else's C to write ours is not acceptable here. Comparing results against another
  implementation is expected and there are tests that do exactly that; taking its code is not.

If you are adding something the engine already has data for, say so in the PR: several of the
capabilities added recently turned out to need no new astronomy at all, only a door onto what was
already there.

## Sponsors

Astronomy is supported by the following sponsors. Thank you for keeping it growing:

<p>
  <a href="https://kenodo.com"><img src="art/logo-kenodo.png" width="24" alt="Kenodo"></a>&nbsp;<a href="https://kenodo.com">Kenodo</a>&nbsp;&nbsp;&nbsp;&nbsp;
  <a href="https://andorradev.com"><img src="art/logo-andorradev.png" width="24" alt="AndorraDev"></a>&nbsp;<a href="https://andorradev.com">AndorraDev</a>&nbsp;&nbsp;&nbsp;&nbsp;
  <a href="https://tarotian.com"><img src="art/logo-tarotian.png" width="24" alt="Tarotian"></a>&nbsp;<a href="https://tarotian.com">Tarotian</a>
</p>

## Author

Created by [Edu Lazaro](https://edulazaro.com)

## License

Astronomy is open-sourced software licensed under the [MIT license](LICENSE.md).
