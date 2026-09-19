<?php

namespace Astronomy;

/**
 * The twenty seven nakshatras: the sidereal zodiac cut into lunar mansions of 13° 20′.
 *
 * It is the part of `swe_split_deg` with `SE_SPLIT_DEG_NAKSHATRA`, which only divides the
 * degrees, plus what a package has to be able to say about each one: its name and the planet that
 * rules it in the Vimshottari system. Every nakshatra is further cut into four padas of 3° 20′,
 * so the whole turn comes to 108.
 *
 * They are measured in the SIDEREAL zodiac, and without that they are not nakshatras. With
 * today's tropical longitude you land almost two mansions further on, because the ayanamsa is
 * past twenty four degrees. This class does not choose an ayanamsa: it receives the longitude
 * already sidereal, and whoever calls it decides which one.
 *
 * Neither the names nor the rulers are written from memory, and the order of the rulers is not
 * even written down: it is counted. The names, in IAST transliteration, are those of the
 * Wikipedia table («Nakshatra»). The order of the rulers is Parāśara's as cited by Sanjay Rath
 * («Ketu, Venus, Sun, Moon, Mars, Rāhu, Jupiter, Saturn & Mercury are the lords of the nine
 * constellation as reckoned from Aswini»), and the two sources agree on all twenty seven. Nine
 * rulers for twenty seven mansions are three turns, so each one's comes out of the remainder of
 * dividing by nine: writing them by hand would be twenty seven places to get it wrong.
 *
 * The deities and the meanings are not here, on purpose. They change from one source to
 * another (Viśākhā's is Indra, Agni or both depending on who you read), and an invented meaning
 * reads exactly as well as the right one. It is the same rule by which fixed stars without a
 * source are left with no planetary nature.
 *
 * Abhijit, the twenty eighth of the old system, is not a case either: in the system of twenty
 * seven it is not a mansion but a stretch of sidereal Capricorn overlapping Uttarāṣāḍha and
 * Śravaṇa.
 */
enum Nakshatra: int implements Translatable
{
    case Ashvini = 1;
    case Bharani = 2;
    case Krittika = 3;
    case Rohini = 4;
    case Mrigashira = 5;
    case Ardra = 6;
    case Punarvasu = 7;
    case Pushya = 8;
    case Ashlesha = 9;
    case Magha = 10;
    case PurvaPhalguni = 11;
    case UttaraPhalguni = 12;
    case Hasta = 13;
    case Chitra = 14;
    case Swati = 15;
    case Vishakha = 16;
    case Anuradha = 17;
    case Jyeshtha = 18;
    case Mula = 19;
    case PurvaAshadha = 20;
    case UttaraAshadha = 21;
    case Shravana = 22;
    case Dhanishta = 23;
    case Shatabhisha = 24;
    case PurvaBhadrapada = 25;
    case UttaraBhadrapada = 26;
    case Revati = 27;

    /**
     * The stable key.
     *
     * Written out and not derived from the name of the case: a key that is computed from an
     * identifier moves the day the identifier is renamed, which is the whole thing it exists to
     * survive. The value is not usable either, because it is the ordinal of the mansion.
     *
     * @return string
     */
    public function key(): string
    {
        return [
            'ashvini',
            'bharani',
            'krittika',
            'rohini',
            'mrigashira',
            'ardra',
            'punarvasu',
            'pushya',
            'ashlesha',
            'magha',
            'purva-phalguni',
            'uttara-phalguni',
            'hasta',
            'chitra',
            'swati',
            'vishakha',
            'anuradha',
            'jyeshtha',
            'mula',
            'purva-ashadha',
            'uttara-ashadha',
            'shravana',
            'dhanishta',
            'shatabhisha',
            'purva-bhadrapada',
            'uttara-bhadrapada',
            'revati',
        ][$this->value - 1];
    }

    /** How wide each nakshatra is, in degrees: the turn divided by twenty seven, 13° 20′. */
    public const WIDTH = 360 / 27;

    /** How wide each pada is, in degrees: 3° 20′. */
    public const PADA = 360 / 108;

    /**
     * The nakshatra a sidereal longitude falls in.
     *
     * @param float $siderealLongitude Grados.
     * @return self
     */
    public static function fromLongitude(float $siderealLongitude): self
    {
        return self::from(min(27, (int) floor(self::normalise($siderealLongitude) * 27 / 360) + 1));
    }

    /**
     * The pada of a sidereal longitude, from 1 to 4.
     *
     * @param float $siderealLongitude Grados.
     * @return int
     */
    public static function padaOf(float $siderealLongitude): int
    {
        return min(4, (int) floor(self::degreesInside($siderealLongitude) / self::PADA) + 1);
    }

    /**
     * How many degrees it has covered inside its nakshatra, which is what `swe_split_deg` gives.
     *
     * With a floor at zero: right on a boundary, the nakshatra comes out of a division and its
     * start out of a multiplication, and the two roundings can leave a subtraction of minus one
     * trillionth, which would make the pada a zero.
     *
     * @param float $siderealLongitude Degrees.
     * @return float
     */
    public static function degreesInside(float $siderealLongitude): float
    {
        $longitude = self::normalise($siderealLongitude);

        return max(0.0, $longitude - self::fromLongitude($longitude)->start());
    }

    /**
     * The name in the plain transliteration, the one written in Spanish without diacritics.
     *
     * @return string
     */
    public function name(): string
    {
        return match ($this) {
            self::Ashvini => 'Ashvini',
            self::Bharani => 'Bharani',
            self::Krittika => 'Krittika',
            self::Rohini => 'Rohini',
            self::Mrigashira => 'Mrigashira',
            self::Ardra => 'Ardra',
            self::Punarvasu => 'Punarvasu',
            self::Pushya => 'Pushya',
            self::Ashlesha => 'Ashlesha',
            self::Magha => 'Magha',
            self::PurvaPhalguni => 'Purva Phalguni',
            self::UttaraPhalguni => 'Uttara Phalguni',
            self::Hasta => 'Hasta',
            self::Chitra => 'Chitra',
            self::Swati => 'Swati',
            self::Vishakha => 'Vishakha',
            self::Anuradha => 'Anuradha',
            self::Jyeshtha => 'Jyeshtha',
            self::Mula => 'Mula',
            self::PurvaAshadha => 'Purva Ashadha',
            self::UttaraAshadha => 'Uttara Ashadha',
            self::Shravana => 'Shravana',
            self::Dhanishta => 'Dhanishta',
            self::Shatabhisha => 'Shatabhisha',
            self::PurvaBhadrapada => 'Purva Bhadrapada',
            self::UttaraBhadrapada => 'Uttara Bhadrapada',
            self::Revati => 'Revati',
        };
    }

    /**
     * The name in IAST, with its diacritics, exactly as the Wikipedia table gives it.
     *
     * @return string
     */
    public function iast(): string
    {
        return match ($this) {
            self::Ashvini => 'Aśvinī',
            self::Bharani => 'Bharaṇī',
            self::Krittika => 'Kṛttikā',
            self::Rohini => 'Rohiṇī',
            self::Mrigashira => 'Mṛgaśīrṣā',
            self::Ardra => 'Ārdrā',
            self::Punarvasu => 'Punarvasu',
            self::Pushya => 'Puṣya',
            self::Ashlesha => 'Āśleṣā',
            self::Magha => 'Maghā',
            self::PurvaPhalguni => 'Pūrvaphalgunī',
            self::UttaraPhalguni => 'Uttaraphalgunī',
            self::Hasta => 'Hasta',
            self::Chitra => 'Citrā',
            self::Swati => 'Svātī',
            self::Vishakha => 'Viśākhā',
            self::Anuradha => 'Anurādhā',
            self::Jyeshtha => 'Jyeṣṭhā',
            self::Mula => 'Mūla',
            self::PurvaAshadha => 'Pūrvāṣāḍha',
            self::UttaraAshadha => 'Uttarāṣāḍha',
            self::Shravana => 'Śravaṇa',
            self::Dhanishta => 'Dhaniṣṭhā',
            self::Shatabhisha => 'Śatabhiṣaj',
            self::PurvaBhadrapada => 'Pūrvabhādrapada',
            self::UttaraBhadrapada => 'Uttarabhādrapada',
            self::Revati => 'Revatī',
        };
    }

    /**
     * The planet ruling it in the Vimshottari system. Rahu is the north node of the Moon and Ketu
     * the south one, which in vedic astrology count as planets.
     *
     * @return string
     */
    public function ruler(): string
    {
        return ['Ketu', 'Venus', 'Sun', 'Moon', 'Mars', 'Rahu', 'Jupiter', 'Saturn', 'Mercury'][($this->value - 1) % 9];
    }

    /**
     * The same ruler as a stable key, which is what a translation is indexed by.
     *
     * Seven of the nine are bodies and their key is the one `Body` uses (`sun`, `moon`, `mars`),
     * so a table that already translates the bodies covers them without a single row of its own.
     * The two that are not, Rāhu and Ketu, are the lunar nodes as the Indian tradition names
     * them, and they keep their Sanskrit name because that is what they are called in every
     * language.
     *
     * @return string
     */
    public function rulerKey(): string
    {
        return ['ketu', 'venus', 'sun', 'moon', 'mars', 'rahu', 'jupiter', 'saturn', 'mercury'][($this->value - 1) % 9];
    }

    /**
     * Where it starts, in degrees of sidereal longitude.
     *
     * @return float
     */
    public function start(): float
    {
        return ($this->value - 1) * 360 / 27;
    }

    /**
     * Where it ends, in degrees of sidereal longitude. Revatī's ends at 360, not at zero.
     *
     * @return float
     */
    public function end(): float
    {
        return $this->value * 360 / 27;
    }

    /**
     * The next one along, wrapping from Revatī to Aśvinī.
     *
     * @return self
     */
    public function next(): self
    {
        return self::from($this->value % 27 + 1);
    }

    /**
     * @param float $degrees
     * @return float
     */
    private static function normalise(float $degrees): float
    {
        return fmod(fmod($degrees, 360) + 360, 360);
    }
}
