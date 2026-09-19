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

Every public method carries a docblock explaining what it does and, where it matters, why it
is done that way.

## More of what it does

Every example below was run to produce the output shown.

### Fixed stars

**Where a star falls on a date**

The position goes in Terrestrial Time, like the planets, and comes back in both systems that get read: ecliptic longitude of the date for the conjunction, declination of the date for the parallel.
```php
use Astronomy\Stars;
use Astronomy\Time;

[$jdTT] = Time::fromClock(new DateTimeImmutable('1981-05-11 07:15:00', new DateTimeZone('UTC')));

$position = Stars::position(Stars::find('Regulus'), $jdTT);

echo $position->formatted(), "\n";             // longitude on the ecliptic of the date
echo $position->sign()->name(), "\n";
echo $position->formattedDeclination(), "\n";  // declination, for the parallel
printf("%.4f %.4f\n", $position->rightAscension, $position->latitude);
```
```
29° 34' Leo
Leo
+12° 04'
151.8417 0.4643
```

**Mean position versus apparent**

An apparent position carries aberration, which swings up to twenty arcseconds over the year, more than precession advances in that year, so it is not monotonic in time: Regulus runs backwards between March and August 2011 while the mean position only ever moves forward, and by January 2012 the two disagree about which sign the star is in, which is why an ingress is dated with `apparent: false`.
```php
use Astronomy\Stars;
use Astronomy\Time;

$regulus = Stars::find('Regulus');

foreach (['2011-03-01', '2011-08-01', '2012-01-01'] as $day) {
    [$jdTT] = Time::fromClock(new DateTimeImmutable($day, new DateTimeZone('UTC')));

    $apparent = Stars::position($regulus, $jdTT);
    $mean = Stars::position($regulus, $jdTT, apparent: false);

    printf("%s  apparent %.5f %-5s  mean %.5f %s\n", $day,
        $apparent->longitude, $apparent->sign()->name(), $mean->longitude, $mean->sign()->name());
}
```
```
2011-03-01  apparent 149.99514 Leo    mean 149.98433 Leo
2011-08-01  apparent 149.99005 Leo    mean 149.99016 Leo
2012-01-01  apparent 150.00442 Virgo  mean 149.99598 Leo
```

### The horizon

**Rise, culmination and set**

The passes of a body over the horizon of one place on one civil day of that place, returned in both shapes at once: a julian day in Universal Time to keep computing with, and a clock reading already in the time zone of the place. By default it is the upper limb lifted by refraction, which is what an almanac publishes; the centre of the disc with no atmosphere comes four and a half minutes later.
```php
use Astronomy\Body;
use Astronomy\Limb;
use Astronomy\Place;
use Astronomy\RiseSet;

$madrid = new Place('Madrid', null, 'Spain', 'ES', 40.4165, -3.7026, 'Europe/Madrid');
$day = new DateTimeImmutable('2026-09-19', $madrid->timeZone());

$sun = RiseSet::ofTheDay(Body::Sun, $madrid, $day);

echo $sun->rise->date->format('H:i:s T');              // 07:59:19 CEST
echo $sun->upperCulmination->date->format('H:i:s T');  // 14:08:35 CEST
echo $sun->set->date->format('H:i:s T');               // 20:17:12 CEST
echo $sun->rise->jdUt;                                 // 2461302.7495276

// The centre of the disc, with no atmosphere: the geometric instant.
$geometric = RiseSet::ofTheDay(Body::Sun, $madrid, $day, Limb::Center, refraction: false);

echo $sun->rise->secondsTo($geometric->rise);          // 265.05947560072
```

**A horizon that is not at zero**

A ridge delays the rise and standing above the sea brings it forward, and `Horizon::horizonDip()` returns the dip already negative so that it chains into the call without anyone having to reason about the sign. Past 1.8 degrees of dip, which is an observer at 3,100 metres, refraction can no longer be corrected and the call throws instead of returning a plausible time. The instant goes in as a julian day in Universal Time.
```php
use Astronomy\Body;
use Astronomy\Horizon;
use Astronomy\Pass;
use Astronomy\Place;
use Astronomy\RiseSet;
use Astronomy\Time;

$madrid = new Place('Madrid', null, 'Spain', 'ES', 40.4165, -3.7026, 'Europe/Madrid');
$jdUt = Time::julianDay(new DateTimeImmutable('2026-09-19 00:00:00', $madrid->timeZone()));

$dip = Horizon::horizonDip(1000.0);   // -0.92119464062593

echo RiseSet::next(Body::Sun, $madrid, $jdUt, Pass::Rise)->date->format('H:i:s');                          // 07:59:19
echo RiseSet::next(Body::Sun, $madrid, $jdUt, Pass::Rise, horizonAltitude: 3.0)->date->format('H:i:s');    // 08:16:51
echo RiseSet::next(Body::Sun, $madrid, $jdUt, Pass::Rise, horizonAltitude: $dip)->date->format('H:i:s');   // 07:53:14

// From 4,000 metres the dip is 1.84 degrees and this throws:
// «A horizon at -1.84 degrees cannot be corrected for refraction […] Ask for the pass without refraction.»
RiseSet::next(Body::Sun, $madrid, $jdUt, Pass::Rise, horizonAltitude: Horizon::horizonDip(4000.0));
```

### Eclipses and occultations

**The solar eclipses of a stretch of time**

The solar eclipses between two instants, in order. The two julian days go in Universal Time and so do the instants that come back, so `maximum->date` is a UTC `DateTimeImmutable` unless a time zone is asked for.
```php
use Astronomy\Eclipses;
use Astronomy\Time;

$from = Time::julianDay(new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')));
$to = Time::julianDay(new DateTimeImmutable('2027-01-01', new DateTimeZone('UTC')));

foreach (Eclipses::solar($from, $to) as $eclipse) {
    echo $eclipse->maximum->date->format('Y-m-d H:i:s'), ' UT  ',
        $eclipse->type->name(), '  magnitude ', round($eclipse->magnitude, 4),
        '  greatest at ', round($eclipse->maximumLatitude, 2),
        ' / ', round($eclipse->maximumLongitude, 2), PHP_EOL;
}
```
```
2026-02-17 12:12:13 UT  Annular  magnitude 0.9638  greatest at -64.58 / 86.65
2026-08-12 17:46:09 UT  Total  magnitude 1.0395  greatest at 65.11 / -25.19
```

**What one place sees of a solar eclipse**

What a place sees of that eclipse, with the instants in the time zone of the place and `visible` answering phase by phase: from Madrid the eclipse of 12 August 2026 covers 99.9 per cent of the diameter and the Sun sets partway through it, so the fourth contact is computed and not seen.
```php
use Astronomy\Eclipses;
use Astronomy\Place;
use Astronomy\Time;

$jdUt = Time::julianDay(new DateTimeImmutable('2026-08-12', new DateTimeZone('UTC')));
$eclipse = Eclipses::solar($jdUt, $jdUt + 1)[0];
$madrid = new Place('Madrid', null, 'Spain', 'ES', 40.4165, -3.7026, 'Europe/Madrid');

$local = Eclipses::localSolar($eclipse, $madrid);

echo $local->type->name(), ', ', round($local->magnitude * 100, 1), ' per cent of the diameter', PHP_EOL;
echo $local->contact1->date->format('H:i:s T'), ' to ', $local->contact4->date->format('H:i:s T'), PHP_EOL;
echo 'Sun ', round($local->altitude, 1), ' degrees up at maximum', PHP_EOL;
echo 'last contact above the horizon: ', $local->visible['contact4'] ? 'yes' : 'no', PHP_EOL;
```
```
Partial, 99.9 per cent of the diameter
19:36:46 CEST to 21:24:31 CEST
Sun 7.2 degrees up at maximum
last contact above the horizon: no
```

**The central path of a solar eclipse**

The band of a central eclipse, sampled every two minutes, with the width of the band and how long totality lasts for someone standing on the central line; the two ends of the path carry those two numbers as null and not as zero, because there the shadow arrives grazing and runs off the globe, which is where the band is at its widest and not at its narrowest.
```php
use Astronomy\CentralPath;
use Astronomy\Eclipses;
use Astronomy\Time;

$jdUt = Time::julianDay(new DateTimeImmutable('2026-08-12', new DateTimeZone('UTC')));
$path = CentralPath::of(Eclipses::solar($jdUt, $jdUt + 1)[0]);

foreach ([$path->start(), $path->maximum, $path->end()] as $point) {
    echo $point->observation->date->format('H:i'), '  ',
        round($point->latitude, 2), ' / ', round($point->longitude, 2),
        '  Sun ', round($point->sunAltitude, 1), ' degrees up  ',
        $point->hasEdges()
            ? round($point->widthKm).' km wide, totality '.round($point->durationSeconds, 1).' s'
            : 'no band: the shadow is running off the globe', PHP_EOL;
}
```
```
17:00  75.34 / 113.56  Sun 0.3 degrees up  no band: the shadow is running off the globe
17:46  65.11 / -25.19  Sun 25.8 degrees up  294 km wide, totality 138.1 s
18:32  38.77 / 5.08  Sun 0.3 degrees up  no band: the shadow is running off the globe
```

**The Moon passing in front of a planet**

An occultation is the same geometry as a solar eclipse with another body in front of the Moon, so it comes back with the same local circumstances; the instants are in UT for the global event and in the time zone of the place for the local one, and `Occultations::local()` returns null when the occultation does not reach that place at all, which is the usual answer rather than the exceptional one.
```php
use Astronomy\Body;
use Astronomy\Occultations;
use Astronomy\Place;
use Astronomy\Time;

$jdUt = Time::julianDay(new DateTimeImmutable('2026-09-01', new DateTimeZone('UTC')));
$occultation = Occultations::next(Body::Venus, $jdUt);
$madrid = new Place('Madrid', null, 'Spain', 'ES', 40.4165, -3.7026, 'Europe/Madrid');

$local = Occultations::local($occultation, $madrid);

echo $occultation->name, ' ', $occultation->maximum->date->format('Y-m-d H:i:s'), ' UT, ',
    $occultation->type->name(), PHP_EOL;
echo 'from Madrid it comes back out at ', $local->contact3->date->format('H:i:s T'),
    ', ', round($local->altitude, 1), ' degrees up', PHP_EOL;
echo 'disappearance above the horizon: ', $local->visible['contact2'] ? 'yes' : 'no', PHP_EOL;
```
```
Venus 2026-09-14 11:34:38 UT, Total
from Madrid it comes back out at 12:21:32 CEST, 3.2 degrees up
disappearance above the horizon: no
```

### Crossings, retrogrades and orbits

**When a body enters a sign, and why it can enter the same one three times**

A body can enter the same sign three times, because it goes in, retrogrades back out and returns months later, which is why Pluto's entry into Aquarius has three published dates rather than one; the window is given in Terrestrial Time and the instants come back as UTC clock time.
```php
use Astronomy\Body;
use Astronomy\Crossings;
use Astronomy\Time;

[$fromTT] = Time::fromClock(new DateTimeImmutable('2023-01-01', new DateTimeZone('UTC')));
[$toTT] = Time::fromClock(new DateTimeImmutable('2025-01-01', new DateTimeZone('UTC')));

foreach (Crossings::ingresses(Body::Pluto, $fromTT, $toTT) as $ingress) {
    echo Time::toClock($ingress['jd'])->format('Y-m-d H:i'), '  ',
        $ingress['sign']->name(),
        $ingress['retrograde'] ? '  retrograde' : '', PHP_EOL;
}
```
```
2023-03-23 12:21  Aquarius
2023-06-11 09:36  Capricorn  retrograde
2024-01-21 00:55  Aquarius
2024-09-01 23:59  Capricorn  retrograde
2024-11-19 20:38  Aquarius
```

**This year's retrograde stations**

A station is where the speed in longitude changes sign, and that speed already comes signed from the ephemeris, so this is a zero of something the engine had computed anyway; the window goes in Terrestrial Time, and asking for the Sun or the Moon throws instead of returning an empty list, which would read as "it has not gone retrograde this year".
```php
use Astronomy\Body;
use Astronomy\Retrogrades;
use Astronomy\Time;

[$fromTT] = Time::fromClock(new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')));
[$toTT] = Time::fromClock(new DateTimeImmutable('2027-01-01', new DateTimeZone('UTC')));

foreach (Retrogrades::stations(Body::Mercury, $fromTT, $toTT) as $station) {
    echo Time::toClock($station['jd'])->format('Y-m-d H:i'), '  ',
        $station['retrograde'] ? 'turns retrograde' : 'turns direct    ', '  ',
        round($station['longitude'], 2), PHP_EOL;
}
```
```
2026-02-26 06:48  turns retrograde  352.57
2026-03-20 19:33  turns direct      338.49
2026-06-29 17:35  turns retrograde  116.26
2026-07-23 22:57  turns direct      106.32
2026-10-24 07:11  turns retrograde  230.98
2026-11-13 15:54  turns direct      215.03
```

**The orbit of a body: nodes, apsides and period**

The two nodes are half a turn apart but not at the same distance from the Sun, because the orbit is an ellipse and the Sun sits at one focus rather than at the centre; the instant goes in Terrestrial Time and the angles come out in the true ecliptic of date, the same frame in which Ephemeris leaves the planets.
```php
use Astronomy\Body;
use Astronomy\NodesAndApsides;
use Astronomy\Time;

[$jdTT] = Time::fromClock(new DateTimeImmutable('2026-09-19', new DateTimeZone('UTC')));

$orbit = NodesAndApsides::of(Body::Pluto, $jdTT);

printf("ascending node   %.3f degrees, at %.3f AU from the Sun\n", $orbit->ascendingNode, $orbit->ascendingNodeDistance);
printf("descending node  %.3f degrees, at %.3f AU\n", $orbit->descendingNode(), $orbit->descendingNodeDistance);
printf("perihelion       %.3f degrees, at %.3f AU\n", $orbit->perihelion, $orbit->perihelionDistance);
printf("aphelion         %.3f degrees, at %.3f AU\n", $orbit->aphelion(), $orbit->aphelionDistance);
printf("e %.5f  i %.3f  sidereal period %.0f days\n", $orbit->eccentricity, $orbit->inclination, $orbit->siderealPeriod);
```
```
ascending node   110.701 degrees, at 40.900 AU from the Sun
descending node  290.701 degrees, at 33.656 AU
perihelion       224.730 degrees, at 29.590 AU
aphelion         44.730 degrees, at 49.100 AU
e 0.24794  i 17.173  sidereal period 90142 days
```

### Frames and phenomena

**The same body from four origins**

The same Mars at the same instant seen from the Earth, from the Sun, from the barycentre of the solar system and from Jupiter, all four from a julian day in Terrestrial Time: the heliocentric and planetocentric columns are not the geocentric one shifted, because light time and aberration are measured from wherever the observer stands.
```php
use Astronomy\Ephemeris;
use Astronomy\Body;
use Astronomy\Time;

[$jdTT] = Time::fromClock(new DateTimeImmutable("2026-06-21 12:00:00", new DateTimeZone("UTC")));

$seenFrom = [
    "the Earth"      => Ephemeris::position(Body::Mars, $jdTT),
    "the Sun"        => Ephemeris::heliocentric(Body::Mars, $jdTT),
    "the barycentre" => Ephemeris::barycentric(Body::Mars, $jdTT),
    "Jupiter"        => Ephemeris::planetocentric(Body::Mars, Body::Jupiter, $jdTT),
];

foreach ($seenFrom as $origin => $mars) {
    printf("Mars from %-15s %10.4f deg  %8.4f AU\n", $origin, $mars->longitude, $mars->distance);
}
```
```
Mars from the Earth          54.7553 deg    2.1336 AU
Mars from the Sun            30.4901 deg    1.4317 AU
Mars from the barycentre     30.3419 deg    1.4274 AU
Mars from Jupiter           318.3291 deg    5.5329 AU
```

**The Moon's phase and the lunations around it**

The Moon's phase at a julian day in Terrestrial Time, with the two lunations that surround it: the illuminated fraction is measured at the Moon and not derived from the elongation, which is why 85.07 degrees gives 45.8 per cent and not the 45.7 of the formula that circulates.
```php
use Astronomy\MoonPhase;
use Astronomy\Time;

[$jdTT] = Time::fromClock(new DateTimeImmutable("2026-06-21 12:00:00", new DateTimeZone("UTC")));

$phase = MoonPhase::at($jdTT);

printf("%s, elongation %.2f deg, %.1f%% lit\n", $phase->name, $phase->elongationForDisplay(), $phase->illumination * 100);
printf("disc %.2f arcminutes, %+.2f%% of its usual size\n", $phase->apparentDiameter * 60, $phase->relativeToMeanSize());

foreach (MoonPhase::lunations($jdTT) as $when => $lunation) {
    printf("%-8s %s on %s\n", $when, $lunation["type"], Time::toClock($lunation["jd"])->format("Y-m-d H:i"));
}
```
```
first-quarter, elongation 85.07 deg, 45.8% lit
disc 30.95 arcminutes, -0.43% of its usual size
previous new-moon on 2026-06-15 02:54
next     full-moon on 2026-06-29 23:56
```

### The sidereal zodiac, and the houses beyond their cusps

**The sidereal zodiac, with Lahiri**

An ayanamsa is the number subtracted from a tropical longitude to get the sidereal one, it takes a julian day in Terrestrial Time like every ephemeris here, and at twenty three and a half degrees it moves most positions back into the previous sign.
```php
use Astronomy\Ayanamsa;
use Astronomy\Body;
use Astronomy\Ephemeris;
use Astronomy\Time;

// Terrestrial Time, which is what every ephemeris takes.
$jdTT = Time::tt(Time::julianDay(new DateTimeImmutable('1981-05-11 07:15:00', new DateTimeZone('UTC'))));

$sun = Ephemeris::position(Body::Sun, $jdTT);

echo $sun->formatted(), "\n";                                          // tropical
echo $sun->shifted(Ayanamsa::Lahiri->value($jdTT))->formatted(), "\n";  // sidereal
echo Ayanamsa::Lahiri->value($jdTT), "\n";                              // degrees subtracted
```
```
20° 30' 31" Taurus
26° 54' 58" Aries
23.592520152579
```

**A body placed in its house with its latitude**

`housePosition` is `swe_house_pos`, and in the quadrant systems a house is defined over the sky and not over the zodiac, so a body with ecliptic latitude can sit in a house its longitude alone does not reach: this Saturn is a degree and a half short of cusp five by longitude and over it by two and a half degrees of latitude.
```php
use Astronomy\Body;
use Astronomy\Ephemeris;
use Astronomy\Houses;
use Astronomy\HouseSystem;
use Astronomy\Time;

[$jdTT, $jdUt] = Time::fromClock(new DateTimeImmutable('1981-05-11 07:15:00', new DateTimeZone('UTC')));

$houses = Houses::calculate(HouseSystem::Placidus, $jdUt, latitude: 40.4165, geographicLongitude: -3.7026);
$saturn = Ephemeris::position(Body::Saturn, $jdTT);   // the body in Terrestrial Time

echo $houses->houseOf($saturn->longitude), "\n";                              // by longitude alone
echo $houses->houseWithLatitude($saturn->longitude, $saturn->latitude), "\n";
echo $houses->housePosition($saturn->longitude, $saturn->latitude), "\n";
echo $saturn->longitude, ' under a cusp at ', $houses->cusps[5], ', latitude ', $saturn->latitude, "\n";
```
```
4
5
5.0165278277963
183.5071189058 under a cusp at 185.01126338975, latitude 2.589119460589
```

### Time

**Delta T, the leap seconds and the 61st second**

`Time` is the only class in the engine that depends on no other one, and this block is what that buys: no place, no body and no ephemeris file, only scales, delta T and the calendars, which is what everything else hangs from starting with `Ephemeris`. The 61st second exists and is accepted, but only on the twenty seven days that carried one; anywhere else it throws rather than returning a good-looking instant.
```php
use Astronomy\Time;

[$jdTT, $jdUt1] = Time::utcToJulianDay(2026, 9, 19, 12, 0, 0.0);

echo Time::deltaT($jdTT);                                    // 69.197902996366
echo Time::taiMinusUtc(Time::civilJulianDay(2026, 9, 19));   // 37

// The last minute of 31 December 2016 had 61 seconds, and it goes out and comes back.
[$leap] = Time::utcToJulianDay(2016, 12, 31, 23, 59, 60.0);

echo implode(' ', Time::ttToUtc($leap));                     // 2016 12 31 23 59 60
echo Time::taiMinusUtc(Time::civilJulianDay(2016, 12, 31));  // 36, the jump to 37 is the next day

// «2026-09-19 23:59:60.000 does not exist: no leap second was inserted into that minute.»
Time::utcToJulianDay(2026, 9, 19, 23, 59, 60.0);
```

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

## Contributing

Contributions are welcome. Fork the repo, add tests, and open a PR.

Two things this package asks for that most do not, and both come from what it is:

- **A number, not an adjective.** Anything that changes a computed value has to say how much,
  measured against JPL Horizons or against the published source, and the figure goes in the
  docblock. A chart that is wrong does not look wrong, which is why this is the rule.
- **The published source, never Swiss Ephemeris's code.** Swiss is AGPL and staying clear of it is
  the reason this engine exists. Comparing results against it is expected and there are tests that
  do; reading its C to write ours is not.

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
