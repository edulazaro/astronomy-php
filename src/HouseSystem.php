<?php

namespace Astronomy;

/**
 * The house systems.
 *
 * Twelve sectors of the sky, and twenty three different ways of drawing them. It is not a
 * decorative argument: the same chart can have the Sun in house nine with Placidus and in house
 * ten with Whole Signs, and that changes the whole reading.
 *
 * What separates them from each other is WHAT gets divided into twelve equal parts. Whole Signs
 * divides the zodiac; Campanus divides the prime vertical; Regiomontanus, the equator; Placidus
 * does not divide a circle but the TIME each degree takes to travel its arc. Hence Placidus has
 * no closed formula and has to be solved by iterating.
 *
 * The last eleven are the ones Swiss Ephemeris carries besides the twelve classical ones, with
 * the same letter as there so that they can be checked against it. Sunshine is only here in
 * Treindl's solution (Swiss's `I`): Makransky's (`i`) is the same system computed by another
 * route, gives the same cusps at middle latitudes and in Oslo goes forty degrees off on two of
 * them, so it contributes nothing worth a case of its own.
 *
 * None of them needs a licence from anyone: they are published spherical trigonometry, some of it
 * since the fifteenth century.
 */
enum HouseSystem: string implements Translatable
{
    case Placidus = 'placidus';
    case Koch = 'koch';
    case Regiomontanus = 'regiomontanus';
    case Campanus = 'campanus';
    case Porphyry = 'porphyry';
    case Alcabitius = 'alcabitius';
    case Topocentric = 'topocentric';
    case Equal = 'equal';
    case WholeSign = 'whole-sign';
    case Vehlow = 'vehlow';
    case Morinus = 'morinus';
    case Meridian = 'meridian';
    case Azimuthal = 'azimuthal';
    case Krusinski = 'krusinski';
    case Sripati = 'sripati';
    case Carter = 'carter';
    case Apc = 'apc';
    case PullenSD = 'pullen-sd';
    case PullenSR = 'pullen-sr';
    case EqualMidheaven = 'equal-mc';
    case EqualAries = 'equal-aries';
    case SavardA = 'savard-a';
    case Sunshine = 'sunshine';

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
     * The name of the system, as the literature writes it.
     *
     * @return string
     */
    public function name(): string
    {
        return match ($this) {
            self::Placidus => 'Placidus',
            self::Koch => 'Koch',
            self::Regiomontanus => 'Regiomontanus',
            self::Campanus => 'Campanus',
            self::Porphyry => 'Porphyry',
            self::Alcabitius => 'Alcabitius',
            self::Topocentric => 'Topocentric',
            self::Equal => 'Equal houses',
            self::WholeSign => 'Whole sign',
            self::Vehlow => 'Vehlow',
            self::Morinus => 'Morinus',
            self::Meridian => 'Meridian',
            self::Azimuthal => 'Azimuthal',
            self::Krusinski => 'Krusinski',
            self::Sripati => 'Sripati',
            self::Carter => 'Carter',
            self::Apc => 'APC',
            self::PullenSD => 'Pullen SD',
            self::PullenSR => 'Pullen SR',
            self::EqualMidheaven => 'Equal from the midheaven',
            self::EqualAries => 'Equal from Aries',
            self::SavardA => 'Savard-A',
            self::Sunshine => 'Sunshine',
        };
    }

    /**
     * What it divides into twelve, said in one line.
     *
     * @return string
     */
    public function description(): string
    {
        return match ($this) {
            self::Placidus => 'Divides the time each degree takes to climb from the horizon to noon. The most widely used in western astrology.',
            self::Koch => 'Like Placidus, but measuring the time of the degree that was rising at birth instead of that of each cusp.',
            self::Regiomontanus => 'Divides the celestial equator into twelve equal parts. It was the standard before Placidus.',
            self::Campanus => 'Divides the prime vertical, the circle running from east to west over your head.',
            self::Porphyry => 'Cuts each quadrant of the zodiac in three. The simplest of the ones that respect the angles.',
            self::Alcabitius => 'Divides the diurnal arc of the ascendant. Medieval, of Arabic origin.',
            self::Topocentric => 'Very close to Placidus, built from the rotation axis of the exact place.',
            self::Equal => 'Twelve sectors of thirty degrees from the ascendant. The midheaven is left floating.',
            self::WholeSign => 'Every sign is a house. The oldest one known and the one hellenistic astrology uses.',
            self::Vehlow => 'Equal houses, but with the ascendant in the middle of house one instead of at its start.',
            self::Morinus => 'Divides the equator and projects onto the ecliptic without taking the horizon into account.',
            self::Meridian => 'Divides the equator from the midheaven. Used in uranian astrology.',
            self::Azimuthal => 'Divides the horizon into twelve and draws verticals through the zenith. House one starts at due east, not at the ascendant.',
            self::Krusinski => 'Divides into twelve the circle through the ascendant and the zenith, and carries each point to the ecliptic by its hour circle. Published by Krusinski, Pisa and Goelzer separately.',
            self::Sripati => 'The Porphyry cusps become the centre of each house, and the boundary with the next one falls halfway between two. Traditional in India.',
            self::Carter => 'Divides the equator into twelve from the right ascension of the ascendant. House ten is not the midheaven.',
            self::Apc => 'Divides the diurnal arc of the ascendant into six and the nocturnal one into six, and draws position circles through those points. From the Dutch school of Knegt.',
            self::PullenSD => 'Like Porphyry, but the three houses of each quadrant follow a wave: the middle one absorbs half of however far the quadrant departs from ninety degrees.',
            self::PullenSR => 'Like Pullen SD, but the houses of the quadrant keep a ratio between them rather than a difference, so none of them is left empty.',
            self::EqualMidheaven => 'Twelve sectors of thirty degrees from the midheaven. The ascendant is left floating.',
            self::EqualAries => 'Twelve sectors of thirty degrees from zero degrees of Aries: each house is a sign, one Aries and twelve Pisces. It depends on neither the time nor the place.',
            self::SavardA => 'Like Campanus, but the prime vertical is not cut into equal arcs: the declination from the east point to the zenith is cut in thirds. John Savard described it as the houses of Albategnius.',
            self::Sunshine => 'Divides in three the diurnal and nocturnal arcs of the Sun of that day and draws position circles through the points. From Bob Makransky, 1988.',
        };
    }

    /**
     * Whether the system stops existing near the poles.
     *
     * The ones dividing time or diurnal arcs need the degree in question to rise and to set. Past
     * the polar circle there are degrees of the zodiac that do neither: they are up all day or
     * down all day, their diurnal arc does not exist and there is nothing to cut in three. It is
     * not a limit of the program, it is that the definition does not reach there.
     *
     * Not one of the nine new ones fails, and that is not a decision of ours: it is what Swiss
     * does with each of them. APC and Sunshine also divide arcs, but they are those of the
     * ascendant (which by definition rises, being on the horizon) and those of the Sun, and when
     * the Sun neither rises nor sets Swiss gives by convention an arc of 180 or of 0 and the
     * intermediate cusps fall on the meridian. The Gauquelin sectors do fail like Placidus, but
     * they are not a case of the enum: they live in `Houses::gauquelinSectors()`.
     *
     * @return bool
     */
    public function failsAtThePoles(): bool
    {
        return in_array($this, [self::Placidus, self::Koch, self::Alcabitius, self::Topocentric], true);
    }

    /**
     * Whether, when the midheaven sinks below the horizon, this system turns as a WHOLE.
     *
     * Past the polar circle there are hours when the degree of the ecliptic on the upper meridian
     * sits below the horizon, and then the point the ascendant formula returns is the one that
     * sets and not the one that rises. Every system straightens the ascendant (`Houses::calculate`
     * does that for all of them alike), but a few also move the midheaven to the imum coeli, and
     * with it all twelve cusps: that way house one is again the one that rises and house ten the
     * one culminating ABOVE the horizon, at the price of the houses ending up numbered clockwise.
     *
     * Which ones turn and which do not is Swiss Ephemeris's business, not a deduction: in
     * `swehouse.c` the ones that turn carry the block «within polar circle, when mc sinks below
     * horizon... all cusps must be added 180 degrees» (Campanus, Regiomontanus, Topocentric, APC
     * and Sunshine) and the rest only «we swap AC/DC if AC is on wrong side». Savard-A, which is
     * Campanus with other cuts, turns too: measured in Tromsø, its four angles are those of
     * Campanus. And it makes sense: the ones that turn draw circles hanging from the meridian,
     * and the ones that do not divide the ecliptic between the ascendant and the midheaven, where
     * straightening the ascendant already leaves the quadrant the right way round.
     *
     * Topocentric is on the list for fidelity even though it never gets used here: that one
     * throws earlier through `failsAtThePoles()`.
     *
     * @return bool
     */
    public function turnsWithTheMidheaven(): bool
    {
        return in_array($this, [
            self::Campanus,
            self::Regiomontanus,
            self::Topocentric,
            self::Apc,
            self::Sunshine,
            self::SavardA,
        ], true);
    }

    /**
     * Whether its cusps are nailed to the boundaries of the signs.
     *
     * Whole signs and Equal from Aries. House one of the first is the WHOLE sign the ascendant
     * falls in and that of the second is Aries, so both start at zero degrees of a sign by
     * definition. The rest hang from an angle (Equal, Vehlow) or from a geometric construction,
     * and those carry over from one zodiac to another by subtracting the ayanamsa.
     *
     * It matters when taking a chart to the sidereal zodiac: these two have to be rebuilt over
     * the sidereal signs, because subtracting the ayanamsa from cusps that sat at zero degrees of
     * a tropical sign leaves them half a dozen degrees away from any sign. See
     * `Houses::sidereal`.
     *
     * @return bool
     */
    public function restsOnTheSigns(): bool
    {
        return $this === self::WholeSign || $this === self::EqualAries;
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
