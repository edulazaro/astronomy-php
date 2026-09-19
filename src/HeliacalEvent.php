<?php

namespace Astronomy;

/**
 * The four heliacal phenomena: when a body becomes visible again and when it stops
 * being seen.
 *
 * A body disappears when it gets too close to the Sun and comes back weeks later, low on
 * the horizon, just before dawn or just after nightfall. That is what Babylonian and
 * Hellenistic astronomy called the "phasis", and it is where the dates the tablets are
 * written with come from: the whole calendar rested on when a planet could be seen again.
 *
 * The four are two pairs, and what separates them is not the body but which side of the
 * Sun it is on. On the morning side the body rises BEFORE the Sun, so it is seen for
 * a while in the east before dawn; on the evening side it sets AFTER the Sun, so it
 * is seen in the west after nightfall. On each side there is a first day and a last one:
 *
 * - Heliacal rising (`mfirst` in the literature): the first day it is seen again in
 * the morning, rising before the Sun. It is the one that dates the Egyptian year with
 * Sirius.
 * - Heliacal setting (`elast`): the last day it is seen in the evening before the Sun
 * swallows it.
 * - Evening first visibility (`efirst`): the first day it is seen again in the
 * evening.
 * - Morning last visibility (`mlast`): the last day it is seen in the morning.
 *
 * All four exist only for Mercury and Venus, which pass both in front of the Sun and
 * behind it, so they make two apparitions per cycle. An outer planet or a star has only
 * one: it appears in the morning (heliacal rising), crosses the sky for months and
 * disappears in the evening (heliacal setting). Asking Jupiter for an "evening first" is
 * asking for the day after its opposition, when it has been in full view for half a year:
 * the arithmetic works, but it is not a phenomenon. That is why `forObject()` exists.
 *
 * The English abbreviations are Schoch's (1924) and the ones the whole literature uses
 * (Swiss Ephemeris calls them `SE_HELIACAL_RISING`, `SE_HELIACAL_SETTING`,
 * `SE_EVENING_FIRST` and `SE_MORNING_LAST`). They are here so they can be cross-checked
 * without translating.
 */
enum HeliacalEvent: string implements Translatable
{
    case HeliacalRising = 'heliacal-rising';
    case HeliacalSetting = 'heliacal-setting';
    case FirstEveningVisibility = 'first-evening-visibility';
    case LastMorningVisibility = 'last-morning-visibility';

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
     * @return string
     */
    public function name(): string
    {
return match ($this) {
            self::HeliacalRising => 'Heliacal rising',
            self::HeliacalSetting => 'Heliacal setting',
            self::FirstEveningVisibility => 'First evening visibility',
            self::LastMorningVisibility => 'Last morning visibility',
        };
    }

    /**
     * Schoch's abbreviation, which is how it is written in the literature.
     *
     * @return string
     */
    public function abbreviation(): string
    {
        return match ($this) {
            self::HeliacalRising => 'mfirst',
            self::HeliacalSetting => 'elast',
            self::FirstEveningVisibility => 'efirst',
            self::LastMorningVisibility => 'mlast',
        };
    }

    /**
     * Whether the event happens on the morning side, that is, whether the arc is
     * measured when the object RISES. False means the evening side, and it is measured
     * when it sets.
     *
     * @return bool
     */
    public function isMorning(): bool
    {
        return $this === self::HeliacalRising || $this === self::LastMorningVisibility;
    }

    /**
     * Whether it is the FIRST day on which the criterion is met. False means the last one.
     *
     * @return bool
     */
    public function isFirst(): bool
    {
        return $this === self::HeliacalRising || $this === self::FirstEveningVisibility;
    }

    /**
     * The pass of the object at which the arc is measured.
     *
     * @return Pass
     */
    public function pass(): Pass
    {
        return $this->isMorning() ? Pass::Rise : Pass::Set;
    }

    /**
     * Which events make sense for a given object.
     *
     * Mercury and Venus make both apparitions per cycle and have all four. Everything else
     * (outer planets and stars) has two: it appears in the morning and disappears in the
     * evening.
     *
     * @param Body|Star $object
     * @return list<self>
     */
    public static function forObject(Body|Star $object): array
    {
        $inner = $object === Body::Mercury || $object === Body::Venus;

        return $inner
            ? [self::HeliacalRising, self::FirstEveningVisibility, self::HeliacalSetting, self::LastMorningVisibility]
            : [self::HeliacalRising, self::HeliacalSetting];
    }
}
