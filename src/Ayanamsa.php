<?php

namespace Astronomy;

/**
 * The sidereal zodiac: the ayanamsas.
 *
 * The tropical zodiac used by Western astrology starts at the first point of Aries, which is
 * where the Sun sits at the spring equinox. The sidereal one, used by Indian astrology and by
 * part of Western astrology, starts at a point fixed with respect to the stars. Since the
 * equinox moves backwards fifty arcseconds a year, the two zodiacs drift apart: today they are
 * some twenty-four degrees apart, and around the year 285 they coincided. That distance is the
 * ayanamsa, and the sidereal longitude is the tropical one minus it.
 *
 * What there is no agreement on is WHERE the fixed point is. Every school anchors it its own
 * way, and that is where the forty-odd ayanamsas Swiss Ephemeris offers come from. Seen up
 * close they are only three families:
 *
 * - **Anchored to an epoch**: "at instant t0 the ayanamsa was a0". These are the majority, and
 *   it is a definition about the equinox of t0, not about any star. To carry it to another date
 *   it is not enough to add the general precession in longitude: take the vernal point of the
 *   date, carry it through J2000 to the ecliptic of t0 with the same rotations that are applied
 *   to Pluto (`Precession::toJ2000` and `toDate`), and the ayanamsa is a0 minus its longitude
 *   there. This is what Swiss calls the traditional algorithm, and what separates the two
 *   computations is hundredths of an arcsecond over this range of dates.
 * - **Anchored to a star**: "such and such a star is always at such and such a sidereal
 *   longitude". The ayanamsa is the apparent tropical longitude of the star (`Stars::position`,
 *   with proper motion, precession, aberration and nutation) minus that fixed longitude. The
 *   star moves, so these ayanamsas do not grow exactly at the rate of precession.
 * - **Anchored to the galaxy**: to the galactic centre as if it were a star, or to the node of
 *   the galactic equator with the ecliptic, which is an intersection of two planes and is
 *   computed as such.
 *
 * **With nutation.** `value()` returns the true ayanamsa, that is with the nutation in
 * longitude inside, because that is what has to be subtracted from a tropical longitude of this
 * engine, which is referred to the true equinox of date. `mean()` is the same one without
 * nutation, which is the one to compare against the literature, where almost everything goes in
 * mean values. It is the same pair Swiss offers with and without `SEFLG_NONUT`.
 *
 * **The numbers come from the Swiss Ephemeris documentation and from the literature**, not from
 * its source code, which is AGPL and is precisely the licence this engine exists in order not
 * to have. Each case says where its pair (t0, a0) or its star comes from. And each one is
 * verified against pyswisseph in `AyanamsaTest`: an ayanamsa with a wrong number drifts
 * arcseconds away from Swiss and there it shows.
 *
 * **What does not match Swiss, and why it is right that it does not.** Swiss computes
 * precession with the Vondrák model (2011) and this engine with the IAU 1976 one in longitude,
 * which is VSOP87's and the one the JPL uses. They differ by 0.3 arcseconds per century: the
 * same ayanamsa comes out 0.8 arcseconds apart in 1700 and in 2300. But the SIDEREAL longitude,
 * which is what matters, does match, because the tropical longitude carries exactly the same
 * difference and the two cancel on subtraction. The Swiss documentation devotes a chapter to
 * this: an ayanamsa has to be computed with the same precession model as the positions it is
 * subtracted from, or the arithmetic is inconsistent. `AyanamsaTest` checks it with the Sun:
 * the ayanamsa drifts almost a whole arcsecond and the sidereal Sun stays within two tenths.
 *
 * The ones Swiss has and are not here, with the reason, are in the test report: Lahiri ICRC
 * (Swiss applies a 0.28 arcsecond offset its documentation does not explain), Eta Piscium
 * (0.9 arcseconds), Vettius Valens (no numerical definition) and Sheoran (anchored six thousand
 * years back, beyond the reach of the series).
 */
use LogicException;

enum Ayanamsa: string implements Translatable
{
    /**
     * The ayanamsa of Western sidereal astrology. Cyril Fagan brought the Babylonian zodiac back
     * around 1950, and Donald Bradley fixed the "synetic vernal point" from Spica at 29°06'05"
     * Virgo with no proper motion. Defined as 24°02'31.36" on 1 January 1950 (Swiss Ephemeris
     * documentation, 2.8.2). Since version 2.09 Swiss subtracts from it the 0.41256" that
     * separate Newcomb's precession, the one it was defined with, from the modern one at that
     * date; the same is done here so that the sidereal longitudes come out the same.
     */
    case FaganBradley = 'fagan-bradley';

    /**
     * India's official ayanamsa, the one almost all present-day Vedic astrology uses. It was
     * decreed by the Calendar Reform Committee in 1955 (Saha and Lahiri, Report of the Calendar
     * Reform Committee, C.S.I.R., 1955) with the value 23°15'00" on 21 March 1956 at 0:00
     * ephemeris time, and the 1985 Indian Astronomical Ephemeris corrected it to 23°15'00.658".
     * That value is TRUE, with the nutation inside: Swiss deduces this from the tables of the IAE
     * itself (appendix E of its documentation), so to get the mean one the nutation of that day,
     * 16.78", has to be subtracted. And since version 2.09 Swiss adds 0.13036" to it, the
     * difference between the IAU 1976 precession it was defined with and its own.
     */
    case Lahiri = 'lahiri';

    /**
     * The ayanamsa Lahiri published in 1940 in his Panchanga Darpan, before the committee fixed
     * the official one, with Spica almost exactly at 180°: 22°26'45.50" at J1900.0 (Swiss
     * documentation, 2.8.5). Swiss subtracts 0.828" from it for Newcomb's precession.
     */
    case Lahiri1940 = 'lahiri-1940';

    /**
     * The definition Lahiri himself gave in 1972 in his Tables of the Sun and in 1980 in his
     * Indian Ephemeris: ayanamsa zero at the mean equinox of 285, when Spica was at 180°. Swiss
     * computes that instant with the formulae of Simon et al. (1994): 22 March 285 at 17:54:02 TT
     * (Swiss documentation, 2.8.5).
     */
    case LahiriVP285 = 'lahiri-vp285';

    /**
     * K. S. Krishnamurti's (1908-1972), used by the KP school. Krishnamurti left no exact
     * definition, only a table of values from 1840 to 2001 (Reader 1, p. 58); this is the one
     * that reproduces it best, 22°21'50" (22.363889°) at J1900.0, and it reached Swiss through
     * Solar Fire and Nova (Swiss documentation, 2.8.6). Swiss subtracts 0.828" for Newcomb.
     */
    case Krishnamurti = 'krishnamurti';

    /**
     * The other reading of Krishnamurti, proposed by D. Senthilathiban (Study of KP Ayanamsa with
     * Modern Precession Theories, 2019): Krishnamurti said the ayanamsa was zero in the year 291,
     * and Senthilathiban assumes it was at the equinox. 21 March 291 at 6:10:29 TT according to
     * Swiss (2.8.6). It differs by one arcminute from the previous one.
     */
    case KrishnamurtiVP291 = 'krishnamurti-vp291';

    /**
     * B. V. Raman's (1912-1998), starting from Bhaskara II's statement that the ayanamsa was 11°
     * in 1183; Raman gives 389 as the zero year (Hindu Predictive Astrology, pp. 378-379). Swiss
     * inherits it from Solar Fire and Nova without stating its value; the one that reproduces
     * Swiss is 21°00'52" at J1900.0, which falls within two hundredths of a whole arcsecond and
     * is therefore taken as the original. Swiss subtracts 0.828" for Newcomb.
     */
    case Raman = 'raman';

    /**
     * The Usha-Shashi ayanamsa, anchored close to Revati (ζ Piscium), which is the
     * Greek-Arabic-Hindu zero point: 18°39'39.46" on 1 January 1900 (Swiss documentation, 2.8.3).
     */
    case UshaShashi = 'usha-shashi';

    /**
     * The one attributed to Swami Sri Yukteswar (The Holy Science, 1894). His own definition
     * cannot be reproduced: he used the year 499 as zero and a precession rate of 54" a year,
     * which is off by 4" per year. What Swiss offers comes from Solar Fire, and the value that
     * reproduces it is 21°04'56" at J1900.0, another whole arcsecond.
     */
    case Yukteshwar = 'yukteshwar';

    /**
     * The one J. N. Bhasin (1908-1983) used. Swiss does not give its value; the one that
     * reproduces it is 21°21'56" at J1900.0, a whole arcsecond.
     */
    case JnBhasin = 'jn-bhasin';

    /**
     * The "Djwhal Khul" ayanamsa of the Ageless Wisdom, which assumes the Age of Aquarius starts
     * in 2117 (Swiss documentation, 2.8.9). Graham Dawson fixed it at 30° around the middle of
     * that year with the precession of the time, and the value that reproduces Swiss is 26°57'48"
     * at J1900.0, a whole arcsecond, with Newcomb's 0.828" subtracted as in Raman.
     */
    case DjwhalKhul = 'djwhal-khul';

    /**
     * Robert DeLuce's (Constellational Astrology According to the Hindu System, 1963), fixed at
     * the birth of Jesus. DeLuce used 26°24'47" in 1900, which with Newcomb's precession
     * corresponds to zero in the year 1 BC (Swiss documentation, 2.8.9). Swiss computes it as
     * zero on 1 January of astronomical year 0, which is 1 BC, and this was checked by measuring
     * where it vanishes; the 26°24'47" is recovered when computing it with Newcomb's precession,
     * and with the modern one it comes out twenty-two arcseconds larger.
     */
    case DeLuce = 'de-luce';

    /**
     * The three reconstructions of the Babylonian zero that F. X. Kugler made around 1900 from
     * the cuneiform tablets: the positions clustered into three families. Referred to the year
     * -100 (Swiss documentation, 2.8.2).
     */
    case Kugler1 = 'kugler-1';
    case Kugler2 = 'kugler-2';
    case Kugler3 = 'kugler-3';

    /**
     * Peter Huber's 1958 revision ("Über den Nullpunkt der babylonischen Ekliptik", Centaurus 5):
     * -4°28' ± 20' in the year -100, with Spica at 29°07'59" Virgo. It differs by less than one
     * arcminute from Fagan-Bradley.
     */
    case Huber = 'huber';

    /**
     * John P. Britton's (2010, "Studies in Babylonian lunar theory III", Arch. Hist. Exact Sci.
     * 64), which corrected Huber by seven arcminutes: -3.2° ± 0.09° on 1 January of the year 0.
     */
    case Britton = 'britton';

    /**
     * The Babylonian zodiac anchored to Aldebaran at 15° Taurus, with Antares almost exactly
     * opposite at 15° Scorpio. Bradley went so far as to write that it made more sense than Spica
     * (Swiss documentation, 2.8.2). It is anchored in the year -100: the mean longitude of
     * Aldebaran then minus 45°, and from there it is carried like an epoch ayanamsa. It differs
     * by 1'06" from Fagan-Bradley.
     */
    case Aldebaran15Taurus = 'aldebaran-15-taurus';

    /**
     * Hipparchus's, according to Raymond Mercier ("Studies in the Medieval Conception of
     * Precession", 1976-1977): Greek and Arab astronomers put the zero between 10' and 22' east
     * of ζ Piscium. -9°20' on 27 June -128, Julian day 1674484 (Swiss documentation, 2.8.3).
     */
    case Hipparchus = 'hipparchus';

    /**
     * The Sasanian ayanamsa: in 564, with the reform of the Persian astronomical tables under
     * Khosrow I, the spring equinox fell within 10' of ζ Piscium and was taken as zero. The
     * instant is 18 March 564 at 7:53:23 UT, Julian day 1927135.8747793 (Swiss documentation,
     * 2.8.3, after Mercier).
     */
    case Sassanian = 'sassanian';

    /**
     * From the Suryasiddhanta: the mean Sun returns to the start of the sidereal zodiac when 3600
     * years of the Kaliyuga era are complete, on 21 March 499 at 7:30:31.57 UT (noon at Ujjain),
     * and there the ayanamsa is zero over the mean equinox of date (Swiss documentation, 2.8.4).
     */
    case Suryasiddhanta = 'suryasiddhanta';

    /** The same, but with the zero at the true position of the mean Sun that day: -0.21463395°. */
    case SuryasiddhantaMeanSun = 'suryasiddhanta-mean-sun';

    /**
     * From Aryabhata, who counts the Kaliyuga era from sunrise and not from midnight: the same day
     * of 499 at 6:56:55.57 UT (Swiss documentation, 2.8.4).
     */
    case Aryabhata = 'aryabhata';

    /** Aryabhata with the zero at the true position of the mean Sun: -0.23763238°. */
    case AryabhataMeanSun = 'aryabhata-mean-sun';

    /**
     * According to Govindasvamin (850), Aryabhata and his disciples taught that the vernal point
     * was at the beginning of Aries in 522 (Shaka 444), most likely from a misreading of
     * Aryabhata himself. Zero on 21 March 522 at 5:46:44 UT (Swiss documentation, 2.8.4).
     */
    case Aryabhata522 = 'aryabhata-522';

    /**
     * The Suryasiddhanta puts Revati (ζ Piscium) at POLAR longitude 359°50', that is projected
     * along the meridian. With the star where it was in 499 that gives -0.79167046° at the epoch
     * of the Suryasiddhanta (Swiss documentation, 2.8.4).
     */
    case SuryasiddhantaRevati = 'suryasiddhanta-revati';

    /**
     * The same with Citra (Spica) at polar longitude 180°, which is the other position the
     * Suryasiddhanta gives and is incompatible with Revati's: 2.11070444° in 499 (Swiss
     * documentation, 2.8.5).
     */
    case SuryasiddhantaCitra = 'suryasiddhanta-citra';

    /** Zero at J2000.0: the mean equinox of 1 January 2000 at 12:00 TT. */
    case J2000 = 'j2000';

    /** Zero at J1900.0, 31 December 1899 at 12:00 TT. */
    case J1900 = 'j1900';

    /** Zero at B1950.0, the Besselian epoch 1950.0: Julian day 2433282.42345905. */
    case B1950 = 'b1950';

    /**
     * Raymond Mardyks's "Skydram" or galactic alignment ayanamsa (The Mountain Astrologer, 1991):
     * it was 30° at the autumn equinox of 1998, when the galactic pole pointed at the equinoctial
     * point. The equinox was on 23 September 1998 at 5:37 UT (Astronomical Almanac 1998).
     */
    case Skydram = 'skydram';

    /**
     * Nick Anthony Fiorenza's (The Star Chart, 2001): exactly 25° on 1 January 2000, with the
     * vernal point at 5° Pisces (Swiss documentation, 2.8.8).
     */
    case Fiorenza = 'fiorenza';

    /**
     * True Chitrapaksha: Spica always exactly at 180°, at 0° Libra. It is the Lahiri ayanamsa made
     * exact: the official one leaves Spica one arcminute off 180° because the star has proper
     * motion and the epoch definition does not follow it (Swiss documentation, 2.8.5).
     */
    case TrueCitra = 'true-citra';

    /**
     * True Revati: ζ Piscium always at 359°50', at 29°50' Pisces, which is where the
     * Suryasiddhanta puts it and where the Greek-Arabic tradition put the zero (Swiss
     * documentation, 2.8.4). Swiss had it wrong until 2.05, with the star at 0°.
     */
    case TrueRevati = 'true-revati';

    /**
     * True Pushya, by P. V. R. Narasimha Rao (2013): δ Cancri (Asellus Australis) always at 106°,
     * at 16° Cancer, because in Kalapurusha theory Cancer is the heart (Swiss documentation,
     * 2.8.4).
     */
    case TruePushya = 'true-pushya';

    /**
     * True Mula, by K. Chandra Hari: λ Scorpii (Shaula) always at 240°, at 0° Sagittarius,
     * because the Mula mansion is the root and lies next to the galactic centre. Very close to
     * Fagan-Bradley (Swiss documentation, 2.8.9).
     */
    case TrueMula = 'true-mula';

    /**
     * The galactic centre at 0° Sagittarius, which is also the start of the Mula mansion. Dieter
     * Koch added it to Swiss in 1999 with no basis other than the philosophical one: it corrects
     * Fagan-Bradley by two degrees (Swiss documentation, 2.8.7).
     */
    case GalacticCentre0Sagittarius = 'galactic-centre-0-sagittarius';

    /**
     * Rafael Gil Brand's (Himmlische Matrix, 2014): the galactic centre at the golden section
     * between 0° Scorpio and 0° Aquarius, that is at 90°·(3-√5)/2 from Scorpio, 4°22'37"
     * Sagittarius. It ends up within arcseconds of Raman's (Swiss documentation, 2.8.7).
     */
    case GalacticCentreGilBrand = 'galactic-centre-gil-brand';

    /** David Cochrane's (2017): the galactic centre at 0° Capricorn. */
    case GalacticCentreCochrane = 'galactic-centre-cochrane';

    /**
     * Ernst Wilhelm's "Dhruva" (2004): the galactic centre projected onto the ecliptic along its
     * hour circle, that is along the meridian that passes through the celestial pole (dhruva),
     * falls at the middle of the Mula mansion, 6°40' Sagittarius. With that, Revati ends up almost
     * where the Suryasiddhanta puts it (Swiss documentation, 2.8.7).
     */
    case GalacticCentreWilhelm = 'galactic-centre-wilhelm';

    /**
     * The node of the galactic equator with the ecliptic at 0° Sagittarius, with the galactic pole
     * the IAU defined in 1958. It differs by 19" from Skydram (Swiss documentation, 2.8.8). The
     * pole goes in the ICRS after Liu, Zhu and Zhang (2011, A&A 526, A16, equation 19).
     */
    case GalacticEquatorIau1958 = 'galactic-equator-iau-1958';

    /**
     * The same with the galactic pole revised by Liu, Zhu and Zhang in 2011 (A&A 526, A16,
     * equation 22), which corrects the node by 3'11" and brings the galactic alignment forward to
     * 1994 (Swiss documentation, 2.8.8).
     */
    case GalacticEquator = 'galactic-equator';

    /**
     * Ernst Wilhelm's "Ardra galactic plane" (2004): the galactic equator cuts the ecliptic at the
     * middle of Mula, 6°40' Sagittarius, and opposite at the start of Ardra. With the 2011 pole
     * (Swiss documentation, 2.8.8).
     */
    case GalacticEquatorMula = 'galactic-equator-mula';

    /**
     * The stable key, which here is the value: it is already a frozen slug.
     *
     * @return string
     */
    public function key(): string
    {
        return $this->value;
    }

    /** J1900.0: 31 December 1899 at 12:00 TT. */
    private const EPOCH_J1900 = 2415020.0;

    /** B1950.0, the Besselian epoch, in Julian day. */
    private const EPOCH_B1950 = 2433282.42345905;

    /** 21 March 1956 at 0:00 TT, Lahiri's epoch. */
    private const LAHIRI_1956 = 2435553.5;

    /**
     * The galactic centre, Sgr A*, in the ICRS: SIMBAD (CDS), queried on 7 September 2026, right
     * ascension 266.41681662° and declination -29.00782497°. It does not go in the star catalogue
     * because it is not one: SIMBAD gives it no magnitude and no proper motion, and the catalogue
     * requires whatever has no magnitude to be a cluster.
     *
     * The proper motion is the reflex of the Sun's orbit around the galaxy and was measured by
     * Reid and Brunthaler (2004, ApJ 616, 872): -3.151 ± 0.018 and -5.547 ± 0.026 milliarcseconds
     * per year in right ascension and declination, already as displacement on the sky, which is
     * the catalogue's convention. That is six milliarcseconds a year, or two arcseconds in three
     * centuries: without them the galactic centre ayanamsa drifts away from Swiss. The parallax is
     * 0.125 milliarcseconds (eight kiloparsecs) and does not count.
     */
    private const GALACTIC_CENTRE = [266.41681662499997, -29.00782497222222, -3.151, -5.547, 0.125];

    /**
     * The IAU 1958 north galactic pole, carried to the ICRS by Liu, Zhu and Zhang (2011, A&A 526,
     * A16, equation 19): 12h 51m 26.27469s, +27° 07' 41.7087". The original definition was
     * 12h 49m, +27.4° in B1950, and the step to J2000 is not just a precession, because FK4
     * carries the E-terms of aberration inside; that is why the rigorously transformed value is
     * taken and the conversion is not done here.
     */
    private const GALACTIC_POLE_1958 = [(12 + 51 / 60 + 26.27469 / 3600) * 15, 27 + 7 / 60 + 41.7087 / 3600];

    /**
     * The galactic pole revised by Liu, Zhu and Zhang (2011, equation 22), defined in the ICRS by
     * the plane containing the galactic centre and the Sun: 12h 51m 36.7151981s,
     * +27° 06' 11.193172".
     */
    private const GALACTIC_POLE_2011 = [(12 + 51 / 60 + 36.7151981 / 3600) * 15, 27 + 6 / 60 + 11.193172 / 3600];

    /** Mean obliquity of J2000 in arcseconds (IAU 2006), the one that separates the ICRS from the J2000 ecliptic. */
    private const OBLICUIDAD_J2000 = 84381.406;

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * @return string
     */
    public function name(): string
    {
return match ($this) {
            self::FaganBradley => 'Fagan-Bradley',
            self::Lahiri => 'Lahiri',
            self::Lahiri1940 => 'Lahiri 1940',
            self::LahiriVP285 => 'Lahiri, equinox of 285',
            self::Krishnamurti => 'Krishnamurti',
            self::KrishnamurtiVP291 => 'Krishnamurti-Senthilathiban',
            self::Raman => 'Raman',
            self::UshaShashi => 'Usha-Shashi',
            self::Yukteshwar => 'Yukteshwar',
            self::JnBhasin => 'J. N. Bhasin',
            self::DjwhalKhul => 'Djwhal Khul',
            self::DeLuce => 'De Luce',
            self::Kugler1 => 'Babylonian, Kugler 1',
            self::Kugler2 => 'Babylonian, Kugler 2',
            self::Kugler3 => 'Babylonian, Kugler 3',
            self::Huber => 'Babylonian, Huber',
            self::Britton => 'Babylonian, Britton',
            self::Aldebaran15Taurus => 'Aldebaran at 15° Taurus',
            self::Hipparchus => 'Hipparchus',
            self::Sassanian => 'Sassanian',
            self::Suryasiddhanta => 'Suryasiddhanta',
            self::SuryasiddhantaMeanSun => 'Suryasiddhanta, mean Sun',
            self::Aryabhata => 'Aryabhata',
            self::AryabhataMeanSun => 'Aryabhata, mean Sun',
            self::Aryabhata522 => 'Aryabhata 522',
            self::SuryasiddhantaRevati => 'Suryasiddhanta, Revati',
            self::SuryasiddhantaCitra => 'Suryasiddhanta, Citra',
            self::J2000 => 'J2000',
            self::J1900 => 'J1900',
            self::B1950 => 'B1950',
            self::Skydram => 'Skydram (Mardyks)',
            self::Fiorenza => 'Galactic equator (Fiorenza)',
            self::TrueCitra => 'True Citra',
            self::TrueRevati => 'True Revati',
            self::TruePushya => 'True Pushya',
            self::TrueMula => 'True Mula',
            self::GalacticCentre0Sagittarius => 'Galactic centre at 0° Sagittarius',
            self::GalacticCentreGilBrand => 'Galactic centre (Gil Brand)',
            self::GalacticCentreCochrane => 'Galactic centre at 0° Capricorn (Cochrane)',
            self::GalacticCentreWilhelm => 'Galactic centre at mid-Mula (Wilhelm)',
            self::GalacticEquatorIau1958 => 'Galactic equator (IAU 1958)',
            self::GalacticEquator => 'Galactic equator',
            self::GalacticEquatorMula => 'Galactic equator at mid-Mula',
        };
    }

    /**
     * Who defined it and for which school, in one line.
     *
     * @return string
     */
    /**
     * @return string
     */
    public function description(): string
    {
return match ($this) {
            self::FaganBradley => 'Cyril Fagan and Donald Bradley, around 1950: the one of Western sidereal astrology, with Spica at 29° Virgo.',
            self::Lahiri => 'Calendar Reform Committee of India, 1955: the official Indian one and that of nearly all Vedic astrology, with Spica at 0° Libra.',
            self::Lahiri1940 => 'N. C. Lahiri, Panchanga Darpan, 1940: his first version, with Spica almost exactly at 180°.',
            self::LahiriVP285 => 'N. C. Lahiri, 1972 and 1980: zero at the mean equinox of 285, as he meant to define it.',
            self::Krishnamurti => 'K. S. Krishnamurti: the one of the KP school, fitted to his table from 1840 to 2001.',
            self::KrishnamurtiVP291 => 'D. Senthilathiban, 2019: Krishnamurti read as zero at the equinox of 291.',
            self::Raman => 'B. V. Raman: the one of his school, with the year 389 as zero.',
            self::UshaShashi => 'Usha-Shashi ayanamsa: the Greek-Arab-Hindu zero, 10 minutes from Revati.',
            self::Yukteshwar => 'Attributed to Swami Sri Yukteswar, The Holy Science, 1894, in the form that circulates in the programs.',
            self::JnBhasin => 'J. N. Bhasin: the one of his school of Indian astrology.',
            self::DjwhalKhul => 'Ageless Wisdom, from a channelled date: the age of Aquarius begins in 2117.',
            self::DeLuce => 'Robert DeLuce, 1963: zero at the birth of Jesus, for his constellational astrology.',
            self::Kugler1 => 'F. X. Kugler, around 1900: first reconstruction of the Babylonian zero from the tablets.',
            self::Kugler2 => 'F. X. Kugler, around 1900: second reconstruction of the Babylonian zero.',
            self::Kugler3 => 'F. X. Kugler, around 1900: third reconstruction of the Babylonian zero.',
            self::Huber => 'Peter Huber, 1958: the Babylonian zero revised, less than a minute from Fagan-Bradley.',
            self::Britton => 'John P. Britton, 2010: the Babylonian zero corrected by seven minutes over Huber.',
            self::Aldebaran15Taurus => 'The Babylonian zodiac anchored to Aldebaran at 15° Taurus and Antares at 15° Scorpio.',
            self::Hipparchus => 'Hipparchus and Ptolemy after Mercier: the zero of Greek astronomy, near ζ Piscium.',
            self::Sassanian => 'The Persian reform of 564: the equinox of that year taken as the zero of the zodiac.',
            self::Suryasiddhanta => 'The Suryasiddhanta: the mean Sun at the sidereal zero 3600 years into the Kaliyuga era.',
            self::SuryasiddhantaMeanSun => 'The Suryasiddhanta with zero at the true position of the mean Sun of 499.',
            self::Aryabhata => 'Aryabhata: like the Suryasiddhanta, counting the era from sunrise.',
            self::AryabhataMeanSun => 'Aryabhata with zero at the true position of the mean Sun of 499.',
            self::Aryabhata522 => 'The school of Aryabhata after Govindasvamin: zero at the equinox of 522.',
            self::SuryasiddhantaRevati => 'The Suryasiddhanta with Revati at polar longitude 359°50\'.',
            self::SuryasiddhantaCitra => 'The Suryasiddhanta with Citra at polar longitude 180°.',
            self::J2000 => 'Astronomical reference: zero at the equinox of J2000.',
            self::J1900 => 'Astronomical reference: zero at the equinox of J1900.',
            self::B1950 => 'Astronomical reference: zero at the equinox of B1950.',
            self::Skydram => 'Raymond Mardyks, 1991: 30° at the autumn equinox of 1998, the galactic alignment.',
            self::Fiorenza => 'Nick Anthony Fiorenza, 2001: exactly 25° on 1 January 2000.',
            self::TrueCitra => 'True Chitrapaksha: Spica always at 0° Libra, Lahiri made exact.',
            self::TrueRevati => 'True Revati: ζ Piscium always at 29°50\' Pisces, as in the Suryasiddhanta.',
            self::TruePushya => 'P. V. R. Narasimha Rao, 2013: δ Cancri always at 16° Cancer.',
            self::TrueMula => 'K. Chandra Hari: λ Scorpii always at 0° Sagittarius.',
            self::GalacticCentre0Sagittarius => 'Dieter Koch, 1999: the galactic centre at 0° Sagittarius.',
            self::GalacticCentreGilBrand => 'Rafael Gil Brand, 2014: the galactic centre at the golden section between Scorpio and Aquarius.',
            self::GalacticCentreCochrane => 'David Cochrane, 2017: the galactic centre at 0° Capricorn.',
            self::GalacticCentreWilhelm => 'Ernst Wilhelm, 2004: the galactic centre, projected by the meridian, at mid-Mula.',
            self::GalacticEquatorIau1958 => 'The node of the IAU 1958 galactic equator with the ecliptic at 0° Sagittarius.',
            self::GalacticEquator => 'The node of the 2011 galactic equator with the ecliptic at 0° Sagittarius.',
            self::GalacticEquatorMula => 'Ernst Wilhelm, 2004: the galactic equator crossing the ecliptic at mid-Mula.',
        };
    }

    /**
     * The true ayanamsa in degrees: what has to be SUBTRACTED from a tropical longitude of this
     * engine to get the sidereal one. It carries the nutation in longitude, because tropical
     * longitudes carry it. It goes in (-180, 180]: the Babylonian ones are negative before their
     * zero, and they read better as -5°40' than as 354°20'.
     *
     * @param float $jdTT Julian day in Terrestrial Time.
     * @return float
     */
    public function value(float $jdTT): float
    {
        return self::center($this->mean($jdTT) + rad2deg(Time::nutation(Time::centuries($jdTT))[0]));
    }

    /**
     * The mean ayanamsa: without nutation. It is the one the tables and the literature give, and
     * the one to use when comparing with them. To subtract from a longitude of this engine,
     * `value()` is the one.
     *
     * @param float $jdTT
     * @return float
     */
    public function mean(float $jdTT): float
    {
        $epoch = $this->epoch();

        if ($epoch !== null) {
            return self::fromEpoch($epoch, $this->initialValue(), $jdTT);
        }

        return self::center(match ($this) {
            self::TrueCitra => self::fromStar(Stars::find('spica'), 180.0, $jdTT),
            self::TrueRevati => self::fromStar(Stars::find('revati'), 359 + 50 / 60, $jdTT),
            self::TruePushya => self::fromStar(Stars::find('asellus-australis'), 106.0, $jdTT),
            self::TrueMula => self::fromStar(Stars::find('shaula'), 240.0, $jdTT),
            self::GalacticCentre0Sagittarius => self::fromStar(self::galacticCenter(), 240.0, $jdTT),
            // The golden section between 0° Scorpio and 0° Aquarius, at 90°·(3-√5)/2 from Scorpio.
            self::GalacticCentreGilBrand => self::fromStar(self::galacticCenter(), 210 + 90 * (3 - sqrt(5)) / 2, $jdTT),
            self::GalacticCentreCochrane => self::fromStar(self::galacticCenter(), 270.0, $jdTT),
            self::GalacticCentreWilhelm => self::fromPolarProjection(self::galacticCenter(), 246 + 40 / 60, $jdTT),
            self::GalacticEquatorIau1958 => self::fromGalacticNode(self::GALACTIC_POLE_1958, 240.0, $jdTT),
            self::GalacticEquator => self::fromGalacticNode(self::GALACTIC_POLE_2011, 240.0, $jdTT),
            self::GalacticEquatorMula => self::fromGalacticNode(self::GALACTIC_POLE_2011, 246 + 40 / 60, $jdTT),
        });
    }

    /**
     * The sidereal longitude of a tropical longitude, in [0, 360). It is what the chart uses.
     *
     * @param float $tropicalLongitude Degrees, referred to the true equinox of date.
     * @param float $jdTT
     * @return float
     */
    public function sidereal(float $tropicalLongitude, float $jdTT): float
    {
        return self::normalize($tropicalLongitude - $this->value($jdTT));
    }

    /**
     * The epoch t0 of the ayanamsas defined by "at t0 it was a0", in Julian day TT. Null for the
     * ones anchored to a star or to the galaxy, which have no epoch: they are recomputed at every
     * date from the sky.
     *
     * Civil dates before 1582 go in the Julian calendar, which is the one the sources use, and the
     * ones the documentation gives in UT are converted to TT with our delta T. Swiss uses a
     * different delta T in the sixth century, and the difference is two minutes of clock, which in
     * precession is two ten-thousandths of an arcsecond.
     *
     * @return float|null
     */
    public function epoch(): ?float
    {
        return match ($this) {
            self::FaganBradley => 2433282.5,
            self::Lahiri => self::LAHIRI_1956,
            self::Lahiri1940, self::Krishnamurti, self::Raman, self::UshaShashi,
            self::Yukteshwar, self::JnBhasin, self::DjwhalKhul, self::J1900 => self::EPOCH_J1900,
            self::LahiriVP285 => self::julianInstant(285, 3, 22, 17, 54, 2),
            self::KrishnamurtiVP291 => self::julianInstant(291, 3, 21, 6, 10, 29),
            self::DeLuce, self::Britton => self::julianInstant(0, 1, 1),
            self::Kugler1, self::Kugler2, self::Kugler3, self::Huber, self::Aldebaran15Taurus => self::julianInstant(-100, 1, 1),
            self::Hipparchus => 1674484.0,
            self::Sassanian => 1927135.8747793,
            self::Suryasiddhanta, self::SuryasiddhantaMeanSun,
            self::SuryasiddhantaRevati, self::SuryasiddhantaCitra => Time::tt(self::julianInstant(499, 3, 21, 7, 30, 31.57)),
            self::Aryabhata, self::AryabhataMeanSun => Time::tt(self::julianInstant(499, 3, 21, 6, 56, 55.57)),
            self::Aryabhata522 => Time::tt(self::julianInstant(522, 3, 21, 5, 46, 44)),
            self::J2000 => Time::J2000,
            self::B1950 => self::EPOCH_B1950,
            self::Skydram => Time::tt(Time::civilJulianDay(1998, 9, 23 + (5 + 37 / 60) / 24)),
            self::Fiorenza => 2451544.5,
            default => null,
        };
    }

    /**
     * The a0 of the epoch ayanamsas: the MEAN ayanamsa at `epoch()`, in degrees. Here are the
     * numbers, with what has been done to the published ones and why.
     *
     * The Newcomb and IAU 1976 corrections are the ones Swiss applies since version 2.09
     * (documentation, 2.8.11) and they are measured against it: they are the difference, at the
     * epoch of each one, between the precession it was defined with and the modern one, and they
     * serve to keep the SIDEREAL longitudes from changing when the precession model changes.
     * Lahiri's is added and the rest are subtracted because the Swiss table lists them with that
     * sign: "corrected by -0.13036"" in one case and "by 0.828"" in the others.
     *
     * @return float|null
     */
    public function initialValue(): ?float
    {
        return match ($this) {
            self::FaganBradley => self::fromDms(24, 2, 31.36) - 0.41256 / 3600,
            self::Lahiri => self::fromDms(23, 15, 0.658) - self::nutationAt(self::LAHIRI_1956) + 0.13036 / 3600,
            self::Lahiri1940 => self::fromDms(22, 26, 45.50) - 0.828 / 3600,
            self::Krishnamurti => 22.363889 - 0.828 / 3600,
            self::Raman => self::fromDms(21, 0, 52) - 0.828 / 3600,
            self::DjwhalKhul => self::fromDms(26, 57, 48) - 0.828 / 3600,
            self::UshaShashi => self::fromDms(18, 39, 39.46),
            self::Yukteshwar => self::fromDms(21, 4, 56),
            self::JnBhasin => self::fromDms(21, 21, 56),
            self::Kugler1 => -self::fromDms(5, 40, 0),
            self::Kugler2 => -self::fromDms(4, 16, 0),
            self::Kugler3 => -self::fromDms(3, 25, 0),
            self::Huber => -self::fromDms(4, 28, 0),
            self::Britton => -3.2,
            self::Hipparchus => -self::fromDms(9, 20, 0),
            self::Aldebaran15Taurus => self::aldebaranAtFifteenTaurus(),
            self::SuryasiddhantaMeanSun => -0.21463395,
            self::AryabhataMeanSun => -0.23763238,
            self::SuryasiddhantaRevati => -0.79167046,
            self::SuryasiddhantaCitra => 2.11070444,
            self::Skydram => 30.0,
            self::Fiorenza => 25.0,
            self::LahiriVP285, self::KrishnamurtiVP291, self::DeLuce, self::Sassanian,
            self::Suryasiddhanta, self::Aryabhata, self::Aryabhata522,
            self::J2000, self::J1900, self::B1950 => 0.0,
            default => null,
        };
    }

    /**
     * The star that anchors the "true" ayanamsas, and the galactic centre in its own ones. Null
     * for the epoch ones and for the galactic equator ones, which are not anchored to a point.
     *
     * @return Star|null
     */
    public function star(): ?Star
    {
        return match ($this) {
            self::TrueCitra => Stars::find('spica'),
            self::TrueRevati => Stars::find('revati'),
            self::TruePushya => Stars::find('asellus-australis'),
            self::TrueMula => Stars::find('shaula'),
            self::GalacticCentre0Sagittarius, self::GalacticCentreGilBrand,
            self::GalacticCentreCochrane, self::GalacticCentreWilhelm => self::galacticCenter(),
            default => null,
        };
    }

    /**
     * The sidereal position measured ON THE ECLIPTIC OF t0, longitude and latitude. It is Swiss's
     * `SE_SIDBIT_ECL_T0`.
     *
     * The usual thing, and what the chart does, is to subtract the ayanamsa from the longitude and
     * leave the plane still: the sidereal zero was fixed on the ecliptic of t0 and it is
     * subtracted from longitudes referred to the ecliptic of date. The Swiss documentation
     * (2.8.12) acknowledges that this mixes two planes and keeps it by tradition. This is the
     * other one: carrying the whole position to the ecliptic of t0 and measuring it there.
     *
     * **That is why it returns both coordinates and not an offset**: besides the longitude, the
     * latitude changes too, and that is what does not fit into the chart's path.
     *
     * What it is really used for, which is what justifies having it: it is the piece behind
     * precession-corrected transits.
     *
     * How much it is worth, measured against Swiss with Lahiri on 10 June 1985: Pluto moves −3.4
     * arcseconds in longitude and +8.3 in latitude, and the Sun's longitude does not change by so
     * much as a millionth of a degree, because it is on the ecliptic and turning the plane barely
     * moves it. **What it measures is the distance between the date and t0**, not the date: with
     * Hipparchus's ayanamsa, anchored twenty-one centuries back, it is −263 arcseconds.
     *
     * @param float $longitude TROPICAL longitude in the true ecliptic of date, as `Ephemeris` gives it.
     * @param float $latitude Likewise.
     * @param float $jdTT
     * @return array{0: float, 1: float} Sidereal longitude in [0, 360) and latitude, both in the ecliptic of t0.
     *
     * @throws LogicException If the ayanamsa has no epoch, which is the case of the ones anchored to a star.
     */
    public function projected(float $longitude, float $latitude, float $jdTT): array
    {
        $epoch = $this->epoch();

        if ($epoch === null) {
            throw new LogicException(sprintf(
                '%s is not anchored to an epoch but to the sky, so there is no t0 ecliptic to project onto.',
                $this->name()
            ));
        }

        return self::onEclipticOfT0($longitude, $latitude, $jdTT, $epoch, (float) $this->initialValue());
    }

    /**
     * The arithmetic of `projected()`, with the pair (t0, a0) passed loose so that
     * `CustomAyanamsa` can use it too.
     *
     * **It starts from the MEAN ecliptic of date**: the nutation belongs to the true equinox and
     * the rotation to J2000 comes out of the mean one, just as in `Ephemeris::inEcliptic`. Without
     * removing it first, a longitude that is not in the ecliptic the rotation starts from would be
     * precessed.
     *
     * @internal
     *
     * @param float $longitude
     * @param float $latitude
     * @param float $jdTT
     * @param float $t0
     * @param float $a0
     * @return array{0: float, 1: float}
     */
    public static function onEclipticOfT0(float $longitude, float $latitude, float $jdTT, float $t0, float $a0): array
    {
        $centuries = Time::centuries($jdTT);
        $l = deg2rad($longitude - rad2deg(Time::nutation($centuries)[0]));
        $b = deg2rad($latitude);

        $atT0 = Precession::toDate(
            Precession::toJ2000([cos($b) * cos($l), cos($b) * sin($l), sin($b)], $centuries),
            Time::centuries($t0)
        );

        return [
            self::normalize(rad2deg(atan2($atT0[1], $atT0[0])) - $a0),
            rad2deg(atan2($atT0[2], sqrt($atT0[0] ** 2 + $atT0[1] ** 2))),
        ];
    }

    /**
     * An epoch ayanamsa carried to another date: the traditional algorithm, which is the one Swiss
     * applies by default.
     *
     * Take the vernal point of the date, which in its own ecliptic is the x axis, carry it to
     * J2000 and from there to the ecliptic of t0, and measure its longitude there, which is how
     * far it has moved back since t0. The ayanamsa is a0 minus that longitude. It is "the ayanamsa
     * measured on the ecliptic of t0 subtracted from positions referred to the ecliptic of date",
     * which the Swiss documentation acknowledges as not entirely consistent (2.8.12) and keeps by
     * tradition; the consistent variant differs from this one by less than three hundredths of an
     * arcsecond between 1700 and 2300.
     *
     * **It is not the same as adding `Time::generalPrecession`.** The general precession is an
     * angle on an ecliptic that tilts; measured in the frame of t0 it comes out as something else,
     * and the difference grows with the square of time. With the two rotations, the J2000 ayanamsa
     * matches Swiss's in everything but the precession model.
     *
     * It is public, and `@internal`, because `CustomAyanamsa` uses it too: an ayanamsa someone
     * defines with their own pair (t0, a0) is this very thing with other numbers, and having it
     * written twice would mean having two algorithms that one day diverge.
     *
     * @internal
     *
     * @param float $t0
     * @param float $a0 Degrees.
     * @param float $jdTT
     * @return float Degrees, in (-180, 180].
     */
    public static function fromEpoch(float $t0, float $a0, float $jdTT): float
    {
        $vernal = Precession::toDate(Precession::toJ2000([1.0, 0.0, 0.0], Time::centuries($jdTT)), Time::centuries($t0));

        return self::center($a0 - self::longitudeOf($vernal));
    }

    /**
     * An ayanamsa anchored to a star: its apparent longitude minus the fixed sidereal one.
     *
     * The apparent position carries aberration and nutation; the nutation is removed here because
     * `value()` puts it in once for all of them. It is what Swiss does with `SEFLG_NONUT`: the
     * star with aberration and without nutation.
     *
     * @param Star $star
     * @param float $siderealLongitude Degrees.
     * @param float $jdTT
     * @return float
     */
    private static function fromStar(Star $star, float $siderealLongitude, float $jdTT): float
    {
        return Stars::position($star, $jdTT)->longitude - self::nutationAt($jdTT) - $siderealLongitude;
    }

    /**
     * The galactic centre projected onto the ecliptic along its hour circle, that is the point of
     * the ecliptic that has the same right ascension, minus the fixed longitude.
     *
     * With which right ascension, and this is measured against Swiss: the one of the position with
     * aberration and WITHOUT nutation, converted with the mean obliquity, and the nutation is
     * added afterwards to the longitude, like for everything else. Projecting the whole apparent
     * position, with the nutation inside and the true obliquity, gave one arcsecond of noise that
     * came and went with the year.
     *
     * With zero latitude, tan α = tan λ · cos ε, and from there λ.
     *
     * @param Star $star
     * @param float $siderealLongitude
     * @param float $jdTT
     * @return float
     */
    private static function fromPolarProjection(Star $star, float $siderealLongitude, float $jdTT): float
    {
        $position = Stars::position($star, $jdTT);
        $eps = Time::meanObliquity(Time::centuries($jdTT));
        $lambda = deg2rad($position->longitude - self::nutationAt($jdTT));
        $beta = deg2rad($position->latitude);

        $alpha = atan2(sin($lambda) * cos($eps) - tan($beta) * sin($eps), cos($lambda));

        return rad2deg(atan2(sin($alpha), cos($alpha) * cos($eps))) - $siderealLongitude;
    }

    /**
     * The node of the galactic equator with the ecliptic of date, minus the fixed longitude.
     *
     * The galactic pole is a fixed direction in the ICRS, with no proper motion and no aberration
     * (it is not a source of light: it is an axis). It is taken to the J2000 ecliptic, precessed to
     * the date the way Pluto is precessed, and the node is the intersection of the two planes: the
     * cross product of the ecliptic pole with the galactic one. Of the two intersections, the one
     * less than 90° from 270°, which is the one that falls next to the galactic centre and the one
     * all these definitions use; the other one is in Gemini.
     *
     * @param array{0: float, 1: float} $pole ICRS right ascension and declination, in degrees.
     * @param float $siderealLongitude
     * @param float $jdTT
     * @return float
     */
    private static function fromGalacticNode(array $pole, float $siderealLongitude, float $jdTT): float
    {
        [$ra, $dec] = [deg2rad($pole[0]), deg2rad($pole[1])];
        $eps = deg2rad(self::OBLICUIDAD_J2000 / 3600);

        $x = cos($dec) * cos($ra);
        $y = cos($dec) * sin($ra);
        $z = sin($dec);

        $galactic = Precession::toDate([
            $x,
            cos($eps) * $y + sin($eps) * $z,
            -sin($eps) * $y + cos($eps) * $z,
        ], Time::centuries($jdTT));

        // z_ecliptic × galactic pole: a vector in the plane of the ecliptic, along the line of
        // nodes.
        $node = self::longitudeOf([-$galactic[1], $galactic[0], 0.0]);

        if (abs(self::center($node - 270)) > 90) {
            $node = self::normalize($node + 180);
        }

        return $node - $siderealLongitude;
    }

    /**
     * The a0 of the Aldebaran ayanamsa: the mean longitude of the star on 1 January of the year
     * -100 minus 45°, computed once and kept.
     *
     * To first order it does not matter which day of the year -100 it is anchored to: moving t0
     * moves a0 and the precession between t0 and the date by the same amount and with the opposite
     * sign. What does weigh is the latitude of the star, 5.5° below the ecliptic: over twenty-one
     * centuries, the tilt of the plane with which each precession model carries the star there
     * differs, and that leaves half an arcsecond of constant offset against Swiss that no other
     * epoch ayanamsa has.
     *
     * @return float
     */
    private static function aldebaranAtFifteenTaurus(): float
    {
        // An enum cannot have properties, not even static ones; the memo lives in the function.
        static $a0 = null;

        return $a0 ??= self::center(
            Stars::position(Stars::find('aldebaran'), self::julianInstant(-100, 1, 1), apparent: false)->longitude - 45
        );
    }

    /**
     * The galactic centre as a catalogue object, so that it goes through the same path as a star:
     * proper motion, precession, aberration and nutation.
     *
     * @return Star
     */
    private static function galacticCenter(): Star
    {
        static $centre = null;

        [$ra, $dec, $pmRa, $pmDec, $parallax] = self::GALACTIC_CENTRE;

        return $centre ??= new Star(
            key: 'centro-galactico',
            name: 'Galactic Center',
            designation: 'Sgr A*',
            simbad: 'NAME Sgr A*',
            aliases: ['Galactic Centre', 'SgrA*'],
            hip: null,
            constellation: 'Sagittarius',
            rightAscension: $ra,
            declination: $dec,
            pmRa: $pmRa,
            pmDec: $pmDec,
            parallax: $parallax,
            radialVelocity: null,
            magnitude: null,
        );
    }

    /**
     * Julian day of a date and time in the Julian calendar, which is the one of every source
     * earlier than 1582. The scale (UT or TT) is whichever the source says; the caller knows it.
     */
    private static function julianInstant(int $year, int $month, int $day, int $hour = 0, int $minute = 0, float $second = 0.0): float
    {
        return Time::civilJulianDay($year, $month, $day + ($hour + $minute / 60 + $second / 3600) / 24, false);
    }

    /** Degrees, arcminutes and arcseconds to degrees. For negatives, the whole result is negated. */
    private static function fromDms(int $degrees, int $minutes, float $seconds): float
    {
        return $degrees + $minutes / 60 + $seconds / 3600;
    }

    /** Nutation in longitude at an instant, in degrees. */
    private static function nutationAt(float $jdTT): float
    {
        return rad2deg(Time::nutation(Time::centuries($jdTT))[0]);
    }

    /**
     * @param array{0: float, 1: float, 2: float} $vector
     * @return float Degrees in [0, 360).
     */
    private static function longitudeOf(array $vector): float
    {
        return self::normalize(rad2deg(atan2($vector[1], $vector[0])));
    }

    /** To [0, 360). */
    private static function normalize(float $degrees): float
    {
        return fmod(fmod($degrees, 360) + 360, 360);
    }

    /** To (-180, 180]. */
    public static function center(float $degrees): float
    {
        $normalised = self::normalize($degrees);

        return $normalised > 180 ? $normalised - 360 : $normalised;
    }
}
