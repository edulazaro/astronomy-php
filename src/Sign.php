<?php

namespace Astronomy;

/**
 * The twelve signs.
 *
 * A sign is a thirty degree sector of the ecliptic counted from the Aries point, not the
 * constellation of the same name. The constellations are of uneven sizes and have drifted
 * almost a whole sign through the precession of the equinoxes; the tropical zodiac that
 * Western astrology uses is anchored to the equinox, not to the stars.
 *
 * Everything that has a name here is named in English, the same
 * way `Body` does it. Four of the twelve are spelled the same in both languages (Aries, Leo,
 * Virgo and Libra), which is the easy way to believe a translation works when it does not:
 * a test written on one of those four passes either way.
 */
enum Sign: int implements Translatable
{
    case Aries = 0;
    case Taurus = 1;
    case Gemini = 2;
    case Cancer = 3;
    case Leo = 4;
    case Virgo = 5;
    case Libra = 6;
    case Scorpio = 7;
    case Sagittarius = 8;
    case Capricorn = 9;
    case Aquarius = 10;
    case Pisces = 11;

    /**
     * The stable key.
     *
     * It is written out and is not the value, because the value is a number that means something
     * else and would be a poor thing to index a translation by.
     *
     * @return string
     */
    public function key(): string
    {
        return ['aries', 'taurus', 'gemini', 'cancer', 'leo', 'virgo', 'libra', 'scorpio', 'sagittarius', 'capricorn', 'aquarius', 'pisces'][$this->value];
    }

    /**
     * @param float $degrees Ecliptic degrees.
     * @return self
     */
    public static function fromLongitude(float $degrees): self
    {
        $normalized = fmod(fmod($degrees, 360) + 360, 360);

        return self::from((int) floor($normalized / 30));
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return match ($this) {
            self::Aries => 'Aries',
            self::Taurus => 'Taurus',
            self::Gemini => 'Gemini',
            self::Cancer => 'Cancer',
            self::Leo => 'Leo',
            self::Virgo => 'Virgo',
            self::Libra => 'Libra',
            self::Scorpio => 'Scorpio',
            self::Sagittarius => 'Sagittarius',
            self::Capricorn => 'Capricorn',
            self::Aquarius => 'Aquarius',
            self::Pisces => 'Pisces',
        };
    }

    /**
     * The sign's symbol in Unicode.
     *
     * @return string
     */
    public function glyph(): string
    {
        return ['♈', '♉', '♊', '♋', '♌', '♍', '♎', '♏', '♐', '♑', '♒', '♓'][$this->value];
    }

    /**
     * @return string
     */
    public function element(): string
    {
        return ['fire', 'earth', 'air', 'water'][$this->value % 4];
    }

    /**
     * @return string
     */
    public function modality(): string
    {
        return ['cardinal', 'fixed', 'mutable'][$this->value % 3];
    }

    /**
     * @return string
     */
    public function polarity(): string
    {
        $active = $this->value % 2 === 0;

        return ($active ? 'active' : 'receptive');
    }

    /**
     * The planet that rules the sign, in the traditional attribution of seven planets.
     *
     * @return Body
     */
    public function traditionalRuler(): Body
    {
        return match ($this) {
            self::Aries, self::Scorpio => Body::Mars,
            self::Taurus, self::Libra => Body::Venus,
            self::Gemini, self::Virgo => Body::Mercury,
            self::Cancer => Body::Moon,
            self::Leo => Body::Sun,
            self::Sagittarius, self::Pisces => Body::Jupiter,
            self::Capricorn, self::Aquarius => Body::Saturn,
        };
    }

    /**
     * The modern ruler, which assigns the three trans-Saturnian planets discovered since 1781.
     *
     * @return Body
     */
    public function modernRuler(): Body
    {
        return match ($this) {
            self::Scorpio => Body::Pluto,
            self::Aquarius => Body::Uranus,
            self::Pisces => Body::Neptune,
            default => $this->traditionalRuler(),
        };
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
