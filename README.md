![Astronomy](art/banner.png)

# Astronomy

<p align="center">
    <a href="https://github.com/edulazaro/astronomy/actions/workflows/tests.yml"><img src="https://github.com/edulazaro/astronomy/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
    <a href="https://packagist.org/packages/edulazaro/astronomy"><img src="https://img.shields.io/packagist/v/edulazaro/astronomy" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/edulazaro/astronomy"><img src="https://img.shields.io/packagist/dt/edulazaro/astronomy" alt="Total Downloads"></a>
    <a href="https://packagist.org/packages/edulazaro/astronomy"><img src="https://img.shields.io/packagist/php-v/edulazaro/astronomy" alt="PHP Version"></a>
    <a href="https://github.com/edulazaro/astronomy/blob/main/LICENSE.md"><img src="https://img.shields.io/packagist/l/edulazaro/astronomy" alt="License"></a>
</p>

A pure PHP astronomical engine. Planetary and lunar positions, houses, fixed stars, eclipses,
occultations, rise and set times, orbital elements and heliacal phenomena, verified against JPL
Horizons and Swiss Ephemeris.

No framework. No extensions beyond the PHP standard library. No network calls at runtime: the
data ships with the package, **and the package knows how to rebuild it**. The series, the tables
and the corrections are generated from their original sources by its own command line, so the day
Strasbourg moves a file or the IERS publishes another year, the answer is in here and not in
somebody's checkout.

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
| Bodies that do not exist | the nineteen Swiss carries elements for, from the Hamburg school to Nibiru, each one labelled with what it actually is |
| Other | retrograde stations, longitude crossings, moon phases and lunations, the equation of time, saros numbers, heliacal phenomena, nakshatras |

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
use Astronomy\Asteroid;
use Astronomy\Downloader;
use Astronomy\MissingData;

$eris = Asteroid::number(136199);          // or Asteroid::designation('2024 YR4')

try {
    $position = Ephemeris::position($eris, $jdTT);
} catch (MissingData $missing) {
    // It carries the body and the file that is not there, so the caller can decide.
    Downloader::download($missing->body, $jdTT);
}
```

Satellites are cases of an enum (`Satellite::Io`) and comets are built the same way as asteroids
(`Comet::designation('1P')`). None of the three takes a name: turning «Eris» into 136199 means
asking the JPL, and there is an asteroid 1181 Lilith, a 763 Cupido and a 5731 Zeus that are not
what a chart calls by those names.

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

The 10 MB in `resources/astro` are generated, and **the package generates them itself**:

```bash
vendor/bin/astronomy vsop87 --data=resources/astro    # from CDS Strasbourg
vendor/bin/astronomy delta-t --data=resources/astro   # from the IERS
vendor/bin/astronomy tables pluto --data=resources/astro
```

| Command | Where the numbers come from | What it writes |
|---|---|---|
| `astronomy vsop87` | VSOP87D (Bretagnon and Francou, CDS Strasbourg) | `vsop87/` |
| `astronomy elp2000` | ELP 2000-82B (Chapront-Touzé and Chapront, CDS) | `elp2000/` |
| `astronomy delta-t` | the SMH 2016 spline, the USNO and the IERS | `deltat.php` |
| `astronomy nutation` | ERFA, the IAU's reference implementation (BSD) | `nutation.php` |
| `astronomy mean-elements` | ERFA's `plan94.c`: Simon et al. (1994) | `mean-elements.php` |
| `astronomy masses` | the ASCII header of DE440 | `masses.php` |
| `astronomy magnitudes` | fitted against JPL Horizons | `magnitudes.php` |
| `astronomy tables <body>` | JPL Horizons | `positions/<body>.php` |
| `astronomy moon-correction` | JPL Horizons | `correction/moon.bin` |
| `astronomy planet-correction` | JPL Horizons | `correction/<body>.bin` |
| `astronomy stars` | SIMBAD and Hipparcos-2 through VizieR | `stars.php` |
| `astronomy satellite-list` | the JPL major bodies list | the `Satellite` enum |

And one that rebuilds nothing and instead checks the engine, `astronomy check`, which asks JPL
Horizons for every body live and prints how far apart the two are. **It is the proof of what this
README claims** and that is why it ships here: a badly computed chart does not look wrong, the
wheel comes out just as pretty with Mars three degrees off, and a library that says «verified
against JPL Horizons» has to carry the thing that demonstrates it. Run today, 1900 to 2050:

```
body                 dates   worst long    worst lat   verdict
mercury                 16     0.118"      0.007"   ok
moon                    16     0.122"      0.006"   ok
ceres                   16     0.135"      0.006"   ok
…
Worst difference: 0.135 arcseconds (tolerance 1.000").
```

**Whoever installs the package never runs any of it**: the data is already inside, and that is
what gets deployed. They are here because **an engine that cannot rebuild its own data is a
folder of numbers with some code next to it**. Until recently they lived as console commands
inside one particular application, which meant that regenerating VSOP87 needed that application
checked out; the day Strasbourg moves a file or the IERS publishes another year, this is what has
to answer.

`satellite-list` is named apart from `satellites` on purpose: one fetches a moon's positions and
the other rewrites this package's own source.

**They are checked by what they write.** Every one of them was run against the network and its
output compared byte for byte with the file that ships, which is the only check that means
anything for a generator, and it earns its keep: it caught the VSOP87 and ELP truncation
threshold defaulting to 1e-7 when every series in the repository was built at 1e-8. Running it
with no options rewrote the Earth with 213 terms where it has 621 and failed at nothing. A chart
drawn from that is not wrong in any way a person sees; it is just less true.

## What Swiss Ephemeris has and this does not

Counted by CAPABILITY, not by family of names, and that distinction is the whole of it. The first
count here grouped each function with its sibling (`azalt` with `azalt_rev`, `cotrans` with
`cotrans_sp`, `rise_trans` with `rise_trans_true_hor`) and called the pair covered, which gave a
comfortable «95 of 101» and hid a whole function that has no sibling. A function that also converts
velocities, or that accepts a horizon behind a mountain, does something more.

And counting functions is not enough either: inside each one there are flags, modes, letters and
body numbers, and Swiss carries things there that no count of 101 can see. This list comes from
enumerating `swisseph`'s constants by family (`FLG_`, `SIDM_`, `SIDBIT_`, `NODBIT_`, `BIT_`,
`ECL_`, `HELFLAG_`, `SPLIT_DEG_`, the letters of `house_name`) and measuring each one.

| Missing | What it is | Where it stands |
|---|---|---|
| `swe_vis_limit_mag` | the limiting visual magnitude anywhere in the sky | **Possible, and a decision rather than an obstruction.** Schaefer 1993 (*Vistas in Astronomy* 36, 311) carries the whole chain with its constants, so it can be written from the literature; Swiss's `swehel.c` is AGPL and need not be opened. Roughly 250-300 lines in three classes, no data to download. What it needs is the OBSERVER: measured against Swiss, the Snellen ratio alone is worth **3.01 magnitudes** and age 20 to 80 another **0.96**. This package does not invent an observer, so it would take one as a required parameter, the way `Horizon::horizonDip` takes pressure and lapse rate. `ArcusVisionis::limitMagnitude` gives what can be stated without one, with its four conditions written |

Everything else that used to be on this list has been done, and two of the entries turned out to be
wrong about themselves rather than hard:

- **`swe_heliacal_pheno_ut`** is `HeliacalPhenomena::detailsAt()`. The reason it was not done said
  Swiss evaluates the crescent width at an instant that cannot be identified from outside; the
  instant is the function's first argument. Twenty-five of the thirty values are computed, from
  Yallop 1997 (NAO Technical Note 69) rather than from Swiss: against Yallop's own Table 4 the
  equations return his ARCL to 0.063°, his crescent width to 0.008′ and his q to 0.005, and the
  Yallop class comes out identical on all five crescents tested. **One departure from Swiss is
  deliberate and measured**: Yallop's (3.8) takes the HORIZONTAL parallax, which gives a
  semi-diameter of 16.1539′ against a true 16.1502′, where Swiss's parallax in altitude gives
  16.1094′; Yallop's own Table 4 column 14 runs 54′-61′, which is the horizontal parallax range.
  Five values need Schaefer's contrast model and return null saying so.
- **`swe_utc_time_zone`** is `Time::utcToLocal()` and `localToUtc()`. It is not a time-zone
  resolver, which is what its absence was once justified by: it is a label shift that can carry
  the sixtieth second across an offset, which `DateTimeImmutable` cannot hold. Fifty thousand
  random instants between 1600 and 2400 across 51 offsets return the same label as Swiss in fifty
  thousand of fifty thousand.
- **`SE_SIDBIT_SSY_PLANE`** is `Ayanamsa::projectedOnSolarSystemPlane()`, with the plane from
  Souami and Souchay (Journées 2011, SYRTE). **Positions only: the houses are deliberately not
  there** and that is a real limitation, not an oversight — measured, the flag moves the ascendant
  3.13° and the worst cusp 9.69° in whole signs.

Two more that had never been looked at are now in, and measuring them was worth more than having
them:

- **The speed of the house cusps** (`swe_houses_ex2`) is `Houses::speeds()`, two calls to
  `fromArmc` at ARMC ± 1e-3 and a divide, costing 0.015 ms in Regiomontanus and 0.104 in Placidus.
  **Differencing the cusps turns out to be a test of the cusp formulas and not only a feature**,
  because a derivative amplifies a formulation difference that agrees at the sampled points. Over
  23 systems, 5 latitudes and a full ARMC sweep at one degree — 21,600 cusps per system — these
  speeds reproduce the numerical derivative of **Swiss's own cusps** to 1.2e-5 °/day, and sixteen
  systems match Swiss's published speed to 5.3e-5. **In six of the seven that differ, it is Swiss
  that disagrees with its own cusps**: Porphyry by 2662 °/day (its cusp-11 speed is anchored to
  the ascendant where its own cusp is anchored to the midheaven, in 1,800 charts of 1,800),
  Krusinski by 2243, Koch by 586, Placidus by 424. Krusinski, whole signs and equal-from-Aries
  fill only four of their twelve and return exactly 0.0 for the other eight. The seventh is
  azimuthal, and there the difference is **ours**: a deliberate divergence already documented in
  the class, and only at Singapore.
- **Gauquelin sector methods 2 to 5** (`swe_gauquelin_sector`) are
  `Houses::gauquelinSectorByRiseAndSet()`: they divide the day by the body's actual rising and
  setting rather than by its position, which differs from method 0 by up to half a sector.
  Measured against Swiss with a matching atmosphere, **0.00019 sectors over 1,440 comparisons**,
  and the 24 cases with no arc are the same 24 Swiss refuses. They cost ten to sixteen times
  method 0, not the four orders of magnitude a first count suggested, and they come with
  `RiseSet::solvedOfTheDay()`, which finds a pass by iterating instead of sampling: three times
  cheaper, and within 0.038 s of the sampling tracker over 360 passes.

Three things were measured and **decided against**, which is different from missing: the sidereal
zodiac on the invariable plane as a thing to read (it takes the Sun 0.74° off the ecliptic: it is
not more precision, it is another zodiac), `SEFLG_CENTER_BODY` (0.1″ in Pluto, and Swiss needs
separate files for it), and choosing a precession, nutation or delta T model, which is settled and
measured in the classes that carry them.

And one thing Swiss does not have either, which was on this list by mistake: **acronychal risings
and settings**. Swiss 2.10.03 rejects them outright with «acronychal rising (event type 5) is not
provided», matching its own documentation. The geometric half of the event — both bodies on the
horizon at once, which needs no observer — this engine already computes.

## Tests

```bash
composer install
composer test
```

147 tests and 3,168 assertions, and **the suite runs with no network**: the values it checks against
JPL Horizons and Swiss Ephemeris are written into the tests by hand, so a build does not fail
because Pasadena is down.

The one check that does go to the network is deliberately not in the suite, and it is
`astronomy check`: it asks JPL Horizons for every body live and prints how far apart the two are.
That is the number this README opens with, and it is here so that anybody can reproduce it rather
than take it on trust.

## Sponsors

Astronomy is supported by the following sponsors. Thank you for keeping it growing:

<p>
  <a href="https://kenodo.com"><img src="art/logo-kenodo.png" width="24" alt="Kenodo"></a>&nbsp;<a href="https://kenodo.com">Kenodo</a>&nbsp;&nbsp;&nbsp;&nbsp;
  <a href="https://andorradev.com"><img src="art/logo-andorradev.png" width="24" alt="AndorraDev"></a>&nbsp;<a href="https://andorradev.com">AndorraDev</a>
</p>

## Author

Created by [Edu Lazaro](https://edulazaro.com)

## License

Astronomy is open-sourced software licensed under the [MIT license](LICENSE.md).
