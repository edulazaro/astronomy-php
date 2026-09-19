![Astronomy](art/banner.png)

# Astronomy

<p align="center">
    <a href="https://github.com/edulazaro/astronomy/actions/workflows/tests.yml"><img src="https://github.com/edulazaro/astronomy/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
    <a href="https://packagist.org/packages/edulazaro/astronomy"><img src="https://img.shields.io/packagist/v/edulazaro/astronomy" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/edulazaro/astronomy"><img src="https://img.shields.io/packagist/dt/edulazaro/astronomy" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/edulazaro/astronomy"><img src="https://img.shields.io/packagist/php-v/edulazaro/astronomy" alt="PHP Version"></a>
    <a href="https://github.com/edulazaro/astronomy/blob/main/LICENSE"><img src="https://img.shields.io/packagist/l/edulazaro/astronomy" alt="License"></a>
</p>

A pure PHP astronomical engine. Planetary and lunar positions, houses, fixed stars, eclipses,
occultations, rise and set times, orbital elements and heliacal phenomena, verified against JPL
Horizons and Swiss Ephemeris.

No framework. No extensions beyond the PHP standard library. No network calls at runtime: the
data ships with the package.

## Why it exists

Swiss Ephemeris is the standard of the trade and it is dual licensed: AGPL, or a paid
professional licence. The AGPL is triggered by serving the software over a network, not by
charging for it, so any web application that uses it has to publish its own source. This engine
exists so that a PHP application does not have to make that choice.

It is not a port of Swiss Ephemeris. Nothing is copied from its source: the series come from
their original publications (VSOP87 by Bretagnon and Francou, ELP 2000-82B by Chapront-Touzé and
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

Fixed stars, eclipses and rise times follow the same shape. Every public method carries a
docblock explaining what it does and, where it matters, why it is done that way.

## What it covers

| Area | What is there |
|---|---|
| Ephemerides | Sun, Moon, the eight planets, Pluto, Chiron, Pholus, Ceres, Pallas, Juno, Vesta |
| Frames | geocentric, heliocentric, barycentric, topocentric and planetocentric |
| Houses | twenty three systems plus the thirty six Gauquelin sectors, and house position with latitude |
| Fixed stars | the whole catalogue, 1,099 objects from Hipparcos-2 and SIMBAD, with proper motion, aberration and nutation |
| Eclipses | solar and lunar, global and local circumstances, and the central path of a solar eclipse |
| Occultations | of planets and stars by the Moon |
| Horizon | rise, set, culminations, twilights, refraction and horizon dip |
| Time | delta T from observed values, leap seconds, UTC, UT1, TT, sidereal time, julian and gregorian calendars |
| Orbits | osculating and mean elements, nodes, apsides, periods and extreme distances |
| Phenomena | phase, illuminated fraction, elongation, apparent diameter and visual magnitude |
| Sidereal | 43 ayanamsas, plus a user defined one |
| Other | retrograde stations, longitude crossings, saros numbers, heliacal phenomena, nakshatras |

## Names and languages

The package is in English and carries no translations. A table of names is not astronomy, and a
library that ships one language has to answer for the next ten.

What it does give you is the pair you need to translate it yourself. Everything meant to be read
by a person implements `Astronomy\Translatable`:

```php
interface Translatable
{
    public function key(): string;   // stable identifier, lower case with hyphens, never changes
    public function name(): string;  // English, for whoever does not translate
}
```

So a translation is a file indexed by the key, and nothing else:

```php
$names = ['sun' => 'Sol', 'moon' => 'Luna', 'mercury' => 'Mercurio', …];

echo $names[$body->key()] ?? $body->name();
```

`key()` is frozen: it does not move when a case is renamed, and where the enum is backed by a
slug it IS that value, which is also what a shared link or a saved row carries. `name()` is
English and may be reworded, so do not index anything by it.

It covers `Body`, `Sign`, `HouseSystem`, `Ayanamsa`, `EclipseType`, `Twilight`, `HeliacalEvent`,
`Nakshatra`, `Pass`, `Limb`, `PositionType`, `ReferenceEcliptic` and `DownloadableGroup`. What is
not an enum keeps the same promise: `Star` carries `key` and `name`, `MoonPhase::$name` is one of
eight frozen keys (`full-moon`, `waxing-gibbous`…), and `Sign::element()`, `modality()` and
`polarity()` return keys too (`fire`, `cardinal`, `active`).

## Downloading bodies that do not ship

Asteroids by number, satellites and comets do not fit in a package: a million and a half, four
hundred and fifty eight and four thousand of them. They are fetched from JPL Horizons and written
into the data folder.

**The package never goes to the network on its own, and has no opinion on what may be fetched.**
When a file is missing it throws `MissingData`, which carries the body and the file, and the
caller decides:

```php
try {
    $position = Ephemeris::position($eris, $jdTT);
} catch (MissingData $missing) {
    Downloader::download($missing->body, $jdTT);
}
```

That decision belongs to the application because it depends on what only the application knows:
whether it is serving a web request, where waiting seconds for the JPL is unacceptable, or running
a script, where it is the whole point.

## Precision

Measured against JPL Horizons over 1600 to 2400:

| | Worst case |
|---|---|
| All bodies, 1600 to 2400 | 0.18″ |
| All bodies, twentieth century | 0.11″ |
| Median, twentieth century | 0.002″ |
| 144 house cusps against Swiss Ephemeris | 0.095″ |
| Ascendant and midheaven | 0.078″ |
| Fixed stars, median in 2000 | 0.04″ |

A chart is read in degrees and minutes. A minute is sixty arcseconds.

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

## Commands

The package ships a command line, because there is one thing an application needs to do that
the library cannot do by itself: fetch what does not ship inside it. It takes what you want and
the names of the ones you want, and everything else is an option.

```bash
vendor/bin/astronomy asteroids 136199 --from=1980 --to=1989
vendor/bin/astronomy satellites Io Titan --data=/var/data/astro
vendor/bin/astronomy comets 1P --years=1
vendor/bin/astronomy stars                    # the whole catalogue, 1,099 of them
vendor/bin/astronomy stars Regulus Aldebaran  # only those
```

Bodies go in groups and the group is the first word: `asteroids`, `satellites`, `comets`. Stars
are not a group of bodies and do not come from the same place, which the command does not make
you care about: **which** stars there are ships with the package and **where** each one is comes
from SIMBAD and Hipparcos-2.

| Option | What it does |
|---|---|
| `--data=PATH` | Where the data folder is. Without it, `astro/` under the current directory |
| `--from`, `--to` | Years to cover. Default 1600 to 2400 |
| `--years=N` | Years per file for that group, instead of its default |
| `--refresh` | Download again what is already on disk |

**Where it writes is asked for and not guessed**, and that is deliberate: the series the engine
reads live inside the package, and writing there is wrong twice over. In an application the
package is a copy that Composer replaces on the next update, and what is downloaded belongs to
whoever downloaded it, not to the engine.

Everything the command does lives in `Downloader`, so an application wraps the same call in
whatever its own console looks like. Two thin wrappers over one implementation, never two
implementations.

### Maintenance, which is not for whoever installs this

The files in `resources/astro` are generated by commands that only the maintainer of this
package runs, once in a long while, and whose output is committed: VSOP87 and ELP 2000-82B, delta
T, the IAU 2000B nutation, the DE440 masses, the magnitudes fitted against Horizons, the mean
elements from ERFA, the star catalogue from SIMBAD and Hipparcos-2, the tabulated positions of
Pluto, Chiron, Pholus and the asteroids, and the two correction tables towards the JPL.

**Whoever installs the package never runs any of that**: the data is already inside. They are
listed here as a development reference and nothing else. `astronomy satellite-list`, which
rewrites the cases of the `Satellite` enum from the JPL major bodies list, is one of them, and
it is named apart from `astronomy satellites` on purpose: one fetches a moon's positions and the
other rewrites this package's own source.

## Tests

```bash
composer install
composer test
```

The suite runs with no network: the values it checks against JPL Horizons and Swiss Ephemeris
are written into the tests.

## Sponsors

Astronomy is supported by the following sponsors. Thank you for keeping it growing:

<p>
  <a href="https://kenodo.com"><img src="art/logo-kenodo.png" width="24" alt="Kenodo"></a>&nbsp;<a href="https://kenodo.com">Kenodo</a>&nbsp;&nbsp;&nbsp;&nbsp;
  <a href="https://andorradev.com"><img src="art/logo-andorradev.png" width="24" alt="AndorraDev"></a>&nbsp;<a href="https://andorradev.com">AndorraDev</a>
</p>

## Author

Created by [Edu Lazaro](https://edulazaro.com)

## License

Astronomy is open-sourced software licensed under the [MIT license](LICENSE).
