<?php

namespace Astronomy;

/**
 * The bodies the engine knows how to place.
 *
 * They go here and not in a list of strings so that asking for a planet that is not there is
 * impossible: `Body::from('plutn')` blows up when it is read, not when it is drawn.
 */
enum Body: string implements Translatable
{
    case Sun = 'sun';
    case Moon = 'moon';
    case Mercury = 'mercury';
    case Venus = 'venus';
    case Mars = 'mars';
    case Jupiter = 'jupiter';
    case Saturn = 'saturn';
    case Uranus = 'uranus';
    case Neptune = 'neptune';
    case Pluto = 'pluto';

    /* Chiron and the four major asteroids. They are not planets and they do not show by
       default: they are switched on separately. Chiron was discovered in 1977 and the
       asteroids in the 19th century, so traditional astrology does not know them and a good
       part of modern astrology does not use them either. */
    case Chiron = 'chiron';
    case Ceres = 'ceres';
    case Pallas = 'pallas';
    case Juno = 'juno';
    case Vesta = 'vesta';

    /* Pholus, the other centaur. It is a real body, asteroid 5145, discovered in 1992, and it
       goes down the same tabulated path as Chiron because the same thing happens to it: there
       is no analytical theory of a small body, only numerical integration. It comes out off by
       default and behind the same checkbox as the four asteroids. Mind its uncertainty, which
       is the worst of the six tabulated ones: the JPL declares 14 arcseconds in 1600 (see
       `PositionTables`). */
    case Pholus = 'pholus';

    /* The nodes and Lilith are not bodies: they are elements of the Moon's ORBIT. The nodes,
       where it cuts the ecliptic; Lilith, its apogee. There are two versions of each and they
       do not give the same thing: the mean one advances at a constant rate and the true one is
       that of the instantaneous orbit, which oscillates. The south node is not here on
       purpose: it is always right opposite the north one, so as a separate body it would
       duplicate every aspect and inverted on top of that. */
    case MeanNode = 'mean-node';
    case TrueNode = 'true-node';
    case MeanLilith = 'mean-lilith';
    case TrueLilith = 'true-lilith';

    /* The third Lilith and its opposite. The interpolated one is the apogee the Moon really
       passes through, followed continuously between one passage and the next (Swiss's
       `SE_INTP_APOG`, which Astrodienst takes to be the physically correct one). Priapus is
       the interpolated perigee, and it has a body of its own because it is NOT interpolated
       Lilith plus half a turn: between the apogee and the perigee the Sun moves the orbit. The
       mean perigee and the osculating one ARE their Lilith turned around, and that is why they
       are not here. Both go on request, like the asteroids: neither among the classical ones
       nor in what a chart carries by default. */
    case InterpolatedLilith = 'interpolated-lilith';
    case Priapus = 'priapus';

    /* The eight hypothetical planets of the Hamburg school, and the word hypothetical is meant
       seriously: THEY DO NOT EXIST. Nobody has ever seen them, they are not in the JPL nor in
       any catalogue, and their position is not observed: it is calculated by propagating some
       orbital elements that Alfred Witte and Friedrich Sieggrün postulated in the twenties and
       the thirties. They are here because the midpoints were already here and they are the
       other half of that school. They go off by default, like the asteroids, and behind a
       checkbox that says what they are. See `FictitiousBodies`. */
    case Cupido = 'cupido';
    case Hades = 'hades';
    case Zeus = 'zeus';
    case Kronos = 'kronos';
    case Apollon = 'apollon';
    case Admetos = 'admetos';
    case Vulkanus = 'vulkanus';
    case Poseidon = 'poseidon';

    /* Four hypothetical bodies that do not exist either but that have people who read them.
       Isis or Transpluto is the planet that was placed beyond Pluto, with elements by Charles
       Strubell published in 1952. Vulcano is the intramercurial planet that Le Verrier proposed
       in 1859 to explain Mercury's perihelion and that general relativity put out of work in
       1915. Selena or White Moon is Lilith's luminous counterpart, and like her it is not a
       body: it is an orbit around the EARTH. Proserpina is another trans-Neptunian of
       astrology, with no source signing it.

       * *Vulcano is not Vulkanus**, which is right up here: that one is Sieggrün's fourth, at 77
       astronomical units, and this one orbits inside Mercury and goes around in eighteen days.
       The names look so much alike that confusing them is easy and gives no error at all. */
    case Transpluto = 'isis-transpluto';
    case Vulcan = 'vulcan';
    case Selena = 'selena';
    case Proserpina = 'proserpina';

    /* The four positions that were PREDICTED and came out wrong. They are not hypothetical
       bodies: they are the orbits that Le Verrier and Adams calculated for Neptune before 1846
       and the ones Lowell and Pickering calculated for the trans-Neptunian planet before 1930.
       The first two got the sky right just enough for Galle to find Neptune less than a degree
       away, but the orbit was another one; the last two rested on perturbations that did not
       exist. They are propagated like any other ellipse and give a perfectly believable
       longitude, so wherever they are shown it has to be said what they are. */
    case NeptuneLeverrier = 'neptune-leverrier';
    case NeptuneAdams = 'neptune-adams';
    case PlutoLowell = 'pluto-lowell';
    case PlutoPickering = 'pluto-pickering';

    /* And three that come from pseudoscientific literature or from discarded hypotheses. Nibiru
       is the planet of Zecharia Sitchin's books, with no astronomical basis whatsoever and
       which no astronomer has proposed or searched for. Harrington WAS astronomy, a search for
       planet X published in 1988 and discarded the following year, when Voyager 2 measured
       Neptune's mass and the residuals it rested on disappeared. Waldemath is a second moon of
       the Earth that its author said he had seen in 1898 and that nobody ever confirmed; like
       Selena, it is geocentric. */
    case Nibiru = 'nibiru';
    case Harrington = 'harrington';
    case Waldemath = 'waldemath';

    /* The Earth. In a geocentric chart it is the observer and it does not show, so it is not in
       `classical()`, nor in `all()`, nor in `CodigoCarta`'s bitmap: it only exists in the
       heliocentric and barycentric frames, where it takes the Sun's place (it is Swiss's
       `SE_EARTH`). Asking `Ephemeris::position()` for the geocentric Earth throws, because that
       position is not zero: it does not exist. The value is the name of its VSOP87 table. */
    case Earth = 'earth';

    /**
     * The stable key, which here is the value: it is already a frozen slug.
     *
     * @return string
     */
    public function key(): string
    {
        return $this->value;
    }

    /**
     * What it is called in a chart.
     *
     * @return string
     */
    public function name(): string
    {
        return match ($this) {
            self::Sun => 'Sun',
            self::Moon => 'Moon',
            self::Mercury => 'Mercury',
            self::Venus => 'Venus',
            self::Mars => 'Mars',
            self::Jupiter => 'Jupiter',
            self::Saturn => 'Saturn',
            self::Uranus => 'Uranus',
            self::Neptune => 'Neptune',
            self::Pluto => 'Pluto',
            self::Chiron => 'Chiron',
            self::Ceres => 'Ceres',
            self::Pallas => 'Pallas',
            self::Juno => 'Juno',
            self::Vesta => 'Vesta',
            self::Pholus => 'Pholus',
            self::MeanNode => 'Mean north node',
            self::TrueNode => 'True north node',
            self::MeanLilith => 'Mean Lilith',
            self::TrueLilith => 'True Lilith',
            self::InterpolatedLilith => 'Interpolated Lilith',
            self::Priapus => 'Priapus',
            self::Cupido => 'Cupido',
            self::Hades => 'Hades',
            self::Zeus => 'Zeus',
            self::Kronos => 'Kronos',
            self::Apollon => 'Apollon',
            self::Admetos => 'Admetos',
            self::Vulkanus => 'Vulkanus',
            self::Poseidon => 'Poseidon',
            self::Transpluto => 'Isis-Transpluto',
            self::Vulcan => 'Vulcan',
            self::Selena => 'Selena',
            self::Proserpina => 'Proserpina',
            self::NeptuneLeverrier => "Leverrier's Neptune",
            self::NeptuneAdams => "Adams's Neptune",
            self::PlutoLowell => "Lowell's Pluto",
            self::PlutoPickering => "Pickering's Pluto",
            self::Nibiru => 'Nibiru',
            self::Harrington => 'Harrington',
            self::Waldemath => "Waldemath's Moon",
            self::Earth => 'Earth',
        };
    }

    /**
     * The glyph, which is how a chart is really read.
     *
     * @return string
     */
    public function glyph(): string
    {
        return match ($this) {
            self::Sun => '☉',
            self::Moon => '☽',
            self::Mercury => '☿',
            self::Venus => '♀',
            self::Mars => '♂',
            self::Jupiter => '♃',
            self::Saturn => '♄',
            self::Uranus => '♅',
            self::Neptune => '♆',
            self::Pluto => '♇',
            self::Chiron => '⚷',
            self::Ceres => '⚳',
            self::Pallas => '⚴',
            self::Juno => '⚵',
            self::Vesta => '⚶',
            // Pholus has its own character, U+2BDB PHOLUS, and Noto Sans Symbols 2 carries it.
            self::Pholus => '⯛',
            self::MeanNode, self::TrueNode => '☊',
            self::MeanLilith, self::TrueLilith, self::InterpolatedLilith => '⚸',
            // Priapus has no symbol in Unicode: it is Lilith upside down, and that is how it is drawn.
            self::Priapus => "\u{E000}",
            /* The eight uranians DO have their own character in Unicode, U+2BE0 to U+2BE7, and
               with their name on it: CUPIDO, HADES, ZEUS… They were encoded in 2019 at David
               Faulks's proposal, so they are among the last to arrive and neither of the two
               fonts that `glifos.php` comes out of carries them. Their paths go in
               the outline table of whoever draws them. */
            self::Cupido => '⯠',
            self::Hades => '⯡',
            self::Zeus => '⯢',
            self::Kronos => '⯣',
            self::Apollon => '⯤',
            self::Admetos => '⯥',
            self::Vulkanus => '⯦',
            self::Poseidon => '⯧',
            /* Of the eleven that do not exist, three have their own character in the same block
               of astronomical symbols: TRANSPLUTO, PROSERPINA and WHITE MOON SELENA. The other
               eight do not have one and no invented one is drawn for them, because an invented
               symbol looks just as good as the right one and nobody corrects it by looking:
               they carry their INITIAL, at a code point in the private use area. See
               the outline table of whoever draws them. */
            self::Transpluto => '⯗',
            self::Proserpina => '⯘',
            self::Selena => '⯝',
            self::Vulcan => "\u{E003}",
            self::NeptuneLeverrier => "\u{E005}",
            self::NeptuneAdams => "\u{E006}",
            self::PlutoLowell => "\u{E007}",
            self::PlutoPickering => "\u{E008}",
            self::Nibiru => "\u{E001}",
            self::Harrington => "\u{E002}",
            self::Waldemath => "\u{E004}",
            // Venus upside down: the cross on top and the circle below.
            self::Earth => '♁',
        };
    }

    /**
     * The EQUATORIAL radius in kilometres, which is the one that decides the apparent width of
     * the disc.
     *
     * It is not `radiusKm`, and the difference is not a decimal: the giants spin fast and are
     * flattened, so Saturn measures 60,268 km of radius at the equator and 58,232 of mean
     * radius**, three and a half per cent. What is seen of a planet and what any ephemeris
     * publishes is the equatorial width, because it is the larger one and the one that gets
     * measured.
     *
     * The two numbers coexist on purpose and they must not be merged: `radiusKm` is the one the
     * eclipses and the occultations use, where what matters is the geometry of the shadow and
     * the value is calibrated against Swiss and NASA, and this one is that of the disc that is
     * seen.
     *
     * From the NASA Planetary Fact Sheet and, for the Sun, from the IAU's 2015 nominal radius
     * (695,700 km). There is no need to trust the transcription: `FenomenosTest` compares
     * the apparent diameter of the ten bodies against the one Horizons publishes, so a
     * miscopied figure does not get anywhere.
     *
     * @return float
     */
    public function equatorialRadiusKm(): float
    {
        return match ($this) {
            self::Sun => 695700.0,
            self::Mercury => 2440.5,
            self::Venus => 6051.8,
            self::Mars => 3396.2,
            self::Jupiter => 71492.0,
            self::Saturn => 60268.0,
            self::Uranus => 25559.0,
            self::Neptune => 24764.0,
            /* The small ones, from the JPL's minor body catalogue, which is also where their
               tables come from. Ceres and Vesta are measured and they are real ellipsoids: 482
               against 470 and 285 against 263, that is, here the flattening is not a decimal.

               * *Chiron disagrees by 63% with its `radiusKm`, and that is not shape: it is
               size.** The JPL gives it 166 km of diameter and the one that had been written
               above comes out of an older and larger estimate. The size of a centaur is
               measured by occultation or by infrared and the catalogues do not agree; the JPL's
               is left here, because it is the reference it is verified against, and the other
               one where it was, because at ten astronomical units the difference is seven
               thousandths of an arcsecond and nobody sees it. */
            self::Chiron => 83.0,
            self::Pholus => 95.0,
            self::Ceres => 482.1,
            self::Pallas => 275.0,
            self::Juno => 123.3,
            self::Vesta => 284.6,
            /* The Moon and Pluto are not measurably flattened, so their radius is a single one
               and it is the one the eclipses already use: in this project there is one lunar
               radius, which is the rule that is written down, and splitting it in two over
               seven tenths of a kilometre would be creating the problem that rule avoids. */
            default => $this->radiusKm(),
        };
    }

    /**
     * Physical radius, in kilometres. Zero for the nodes and the Liliths, which are directions
     * without a body, and for the Earth, which is where one looks from and nobody measures its
     * disc.
     *
     * It is used for the apparent semidiameter, which only counts at rising and setting (half
     * an arcminute for Jupiter, nothing for the rest) and in the occultations. Those of the
     * planets and the asteroids are Swiss Ephemeris's (`pla_diam` in `sweph.h`, which are
     * diameters, divided by two), and those in turn the IAU's mean radii; that way the
     * occultations can be compared with theirs to the second.
     *
     * The Sun and the Moon do not carry their own number here but `Horizon`'s, because those
     * two are not plain physical radii: they are the ones used in the eclipses. The Sun's is
     * the one Swiss uses for the eclipses and the Moon's is the IAU's mean limb radius (k times
     * the Earth's equatorial radius), which is bigger than the body's because the mountains at
     * the edge cover too. `Eclipses` reads them there, and a value in a single place is the
     * only way for an eclipse and an occultation to use the same Moon.
     *
     * @return float
     */
    public function radiusKm(): float
    {
        return match ($this) {
            self::Sun => Horizon::SUN_RADIUS_KM,
            self::Moon => Horizon::MOON_RADIUS_KM,
            self::Mercury => 2439.4,
            self::Venus => 6051.8,
            self::Mars => 3389.5,
            self::Jupiter => 69911.0,
            self::Saturn => 58232.0,
            self::Uranus => 25362.0,
            self::Neptune => 24622.0,
            self::Pluto => 1188.3,
            self::Chiron => 135.685,
            /* Pholus measures some 92 km of radius (diameter 185 km, from the JPL's size
               catalogue). Swiss does not list it in `pla_diam` and gives it zero, which is the
               same as saying it does not know: here the measured one is put, and it only counts
               for the apparent semidiameter, which at twenty astronomical units is one
               thousandth of an arcsecond. */
            self::Pholus => 92.5,
            self::Ceres => 469.7,
            self::Pallas => 272.5,
            self::Juno => 123.298,
            self::Vesta => 262.7,
            default => 0.0,
        };
    }

    /**
     * Whether its position comes out of the VSOP87 series.
     *
     * The Sun's does not: its geocentric longitude is the Earth's plus 180 degrees. The Moon's
     * and Pluto's do not either, because VSOP87 does not solve them and each one goes its own
     * way.
     *
     * @return bool
     */
    public function isVsop87Planet(): bool
    {
        return in_array($this, [
            self::Mercury, self::Venus, self::Mars,
            self::Jupiter, self::Saturn, self::Uranus, self::Neptune,
        ], true);
    }

    /**
     * Whether its position comes from a table instead of from a theory.
     *
     * The big planets have analytical theories: series that give their position at any instant.
     * Pluto, Chiron and the asteroids do not. They are small bodies whose orbit is only known
     * by numerical integration, so the positions have to be asked of the JPL, stored and
     * interpolated.
     *
     * @return bool
     */
    public function isTabulated(): bool
    {
        return in_array($this, [
            self::Pluto, self::Chiron, self::Pholus,
            self::Ceres, self::Pallas, self::Juno, self::Vesta,
        ], true);
    }

    /**
     * How this body is asked for in JPL Horizons.
     *
     * It lives here and not in the commands because two of them need it: the one that generates
     * the tables and the one that verifies the positions. In two places, the day a body is
     * added it gets added in one and forgotten in the other, and the verifier stops looking at
     * exactly what is new.
     *
     * The semicolon is not decorative: it tells Horizons to search among the SMALL bodies.
     * Without it, `1` is Mercury (the barycentre of Mercury's system) and not Ceres.
     *
     * @return string|null
     */
    public function horizonsId(): ?string
    {
        return match ($this) {
            self::Sun => '10',
            self::Moon => '301',
            /* The BARYCENTRES of each system, not the centres of the planets, which would be
               199, 299, 499, 599, 699, 799 and 899. It is the same reason as with Pluto and it
               bites more than it seems: the centre of a big planet comes out of the solution of
               its satellites, which is ANOTHER ephemeris, and Uranus's departs from its
               barycentre by 1.27 arcseconds in 1650 and 0.65 in 2190, crossing zero around
               2010. With the engine at one second that could not be seen; with the engine at a
               tenth, `astro:verificar` was attributing to Uranus an arcsecond that was not its
               own. And what VSOP87 and the correction represent is the barycentre, not the
               planet.

               The Earth is the exception and goes through 399: its barycentre with the Moon
               departs 4700 km from the centre of the planet, which is where a chart is cast. */
            self::Mercury => '1',
            self::Venus => '2',
            self::Earth => '399',
            self::Mars => '4',
            self::Jupiter => '5',
            self::Saturn => '6',
            self::Uranus => '7',
            self::Neptune => '8',
            /* The BARYCENTRE of Pluto's system, not the centre of the body (which would be
               999). The 999 comes from the solution of the satellites (PLU060) and Horizons
               only serves it from 1800 to 2199; the barycentre comes from the DE440 planetary
               ephemeris and reaches from 1550 to 2650. The difference between the two is some
               2100 km, which at thirty AU is a tenth of an arcsecond, and Swiss Ephemeris uses
               the barycentre too. */
            self::Pluto => '9',
            self::Chiron => '2060;',
            self::Pholus => '5145;',
            self::Ceres => '1;',
            self::Pallas => '2;',
            self::Juno => '3;',
            self::Vesta => '4;',
            /* The nodes and Lilith are not objects: there is nothing to ask Horizons for. The
               nineteen bodies of elements neither, and for a bigger reason: they do not exist.
               That is why they do not enter `all()`, which is what `astro:verificar` walks
               through. */
            default => null,
        };
    }

    /**
     * The ten classical ones, in the order in which they are read.
     *
     * @return list<self>
     */
    /**
     * The seven of the tradition: the ones that are seen with the naked eye.
     *
     * It is the set on which the essential dignities were fixed, centuries before Uranus,
     * Neptune and Pluto were discovered. The dignities assigned to those three are 20th-century
     * proposals that do not agree between authors, so they do not come in here.
     *
     * @return list<self>
     */
    public static function traditional(): array
    {
        return [
            self::Sun, self::Moon, self::Mercury, self::Venus,
            self::Mars, self::Jupiter, self::Saturn,
        ];
    }

    /**
     * @return list<self>
     */
    public static function classical(): array
    {
        return [
            self::Sun, self::Moon, self::Mercury, self::Venus, self::Mars,
            self::Jupiter, self::Saturn, self::Uranus, self::Neptune, self::Pluto,
        ];
    }

    /**
     * Whether it is an element of the lunar orbit and not a body.
     *
     * These have no position to observe: they are constructed. That is why they do not go
     * through light time nor through aberration, which correct where something IS SEEN with
     * respect to where it is. A node is not seen.
     *
     * @return bool
     */
    public function isLunarPoint(): bool
    {
        return in_array($this, [
            self::MeanNode, self::TrueNode, self::MeanLilith, self::TrueLilith,
            self::InterpolatedLilith, self::Priapus,
        ], true);
    }

    /**
     * The minor bodies that are read in a chart, in the order in which they are usually read.
     *
     * It is called `asteroids` because that is what they are called in the form and in the
     * street, but two of the six are CENTAURS: Chiron (asteroid 2060) and Pholus (5145), which
     * cross the orbits of the giants instead of living in the belt. The six have a minor planet
     * number and the six go by JPL table, so for the engine they are the same thing.
     *
     * @return list<self>
     */
    public static function asteroids(): array
    {
        return [self::Chiron, self::Pholus, self::Ceres, self::Pallas, self::Juno, self::Vesta];
    }

    /**
     * Whether it is one of the eight hypothetical planets of the Hamburg school.
     *
     * It still exists apart from `isFictitious()` because the eight are a group with a name and
     * a checkbox of their own: they are read together, in a specific school and alongside the
     * midpoints. The other eleven have nothing to do with them nor with one another.
     *
     * @return bool
     */
    public function isUranian(): bool
    {
        return in_array($this, self::uranians(), true);
    }

    /**
     * Whether its position comes out of propagating some POSTULATED orbital elements, that is,
     * whether it does not exist.
     *
     * It exists for three places: so that `Ephemeris` knows that its position comes out of an
     * ellipse and not of a series nor of a table, so that the FK5 correction is not applied to
     * it (which is VSOP87's and nobody else's) and so that the view and the summary the
     * interpreter reads can say what they are before showing them.
     *
     * @return bool
     */
    public function isFictitious(): bool
    {
        return in_array($this, self::fictitious(), true);
    }

    /**
     * The four hypothetical ones that have followers: Isis-Transpluto, Vulcano, Selena and
     * Proserpina.
     *
     * @return list<self>
     */
    public static function hypothetical(): array
    {
        return [self::Transpluto, self::Vulcan, self::Selena, self::Proserpina];
    }

    /**
     * The four positions that were predicted for Neptune and for Pluto before finding them, and
     * that turned out to be wrong.
     *
     * @return list<self>
     */
    public static function failedPredictions(): array
    {
        return [
            self::NeptuneLeverrier, self::NeptuneAdams,
            self::PlutoLowell, self::PlutoPickering,
        ];
    }

    /**
     * The three that come out of pseudoscientific literature or of already discarded
     * hypotheses.
     *
     * @return list<self>
     */
    public static function discarded(): array
    {
        return [self::Nibiru, self::Harrington, self::Waldemath];
    }

    /**
     * The eleven that are not the Hamburg school, in the order in which the form shows them:
     * first the hypothetical ones with followers, then the failed predictions and at the end
     * what was discarded.
     *
     * They go together behind a single checkbox because they share the only thing that matters
     * to say, that they are not bodies, but the text of that checkbox separates them into the
     * three groups: a planet that someone postulated and that is read is not the same thing as
     * the position Le Verrier calculated for Neptune, nor as Nibiru.
     *
     * @return list<self>
     */
    public static function nonexistent(): array
    {
        return array_merge(self::hypothetical(), self::failedPredictions(), self::discarded());
    }

    /**
     * The nineteen that are propagated from some elements: the eight of Hamburg and the eleven
     * of `nonexistent()`.
     *
     * They are NOT in `all()` on purpose, and it is not an omission: `all()` is what
     * `astro:verificar` walks through to compare against JPL Horizons, and Horizons cannot be
     * asked for the position of a body that does not exist.
     *
     * @return list<self>
     */
    public static function fictitious(): array
    {
        return array_merge(self::uranians(), self::nonexistent());
    }

    /**
     * The eight uranians, in the order in which the school enumerates them: first Witte's four
     * and then Sieggrün's four, each group from nearest to farthest.
     *
     * They are NOT in `all()`, for what `fictitious()` says.
     *
     * @return list<self>
     */
    public static function uranians(): array
    {
        return [
            self::Cupido, self::Hades, self::Zeus, self::Kronos,
            self::Apollon, self::Admetos, self::Vulkanus, self::Poseidon,
        ];
    }

    /**
     * The six points of the lunar orbit: the two nodes and the three Liliths with Priapus.
     *
     * @return list<self>
     */
    public static function lunarPoints(): array
    {
        return [
            self::MeanNode, self::TrueNode, self::MeanLilith, self::TrueLilith,
            self::InterpolatedLilith, self::Priapus,
        ];
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return array_merge(self::classical(), self::asteroids(), self::lunarPoints());
    }
}
