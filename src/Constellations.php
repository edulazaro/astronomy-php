<?php

namespace Astronomy;

/**
 * The eighty-eight constellations, by the abbreviation the IAU gives them.
 *
 * It is here so that the catalogue does not have to write down which constellation each star is
 * in: the Bayer designation carries it inside («* alf Leo» is in Leo), so it is deduced and
 * not copied. Writing it star by star would be a thousand chances to put one in the wrong sky,
 * and nobody catches that by reading.
 *
 * The names are the Latin ones in the nominative, which is what the IAU publishes and the only
 * thing that can be checked against a catalogue. Translating them is the job of whoever shows
 * them.
 *
 * All eighty-eight are here and not only the ones the list happens to reach, which is what
 * it held while the catalogue had a hundred and seventy-four stars: forty-nine, «the zodiac and
 * its neighbours». The complete catalogue walks into forty more, and they came out with an empty
 * constellation rather than with the failure this file's own docblock promised: two hundred and
 * eighty-five stars of Apus, Octans, Lupus and thirty-seven others, with no sky written down and
 * nothing said. A table that holds only what has been needed so far is a table that is wrong the
 * first time something new arrives, and eighty-eight is a published, closed list.
 */
class Constellations
{
    /** @var array<string, string> */
    private const NAMES = [
        'And' => 'Andromeda',
        'Ant' => 'Antlia',
        'Aps' => 'Apus',
        'Aql' => 'Aquila',
        'Aqr' => 'Aquarius',
        'Ara' => 'Ara',
        'Ari' => 'Aries',
        'Aur' => 'Auriga',
        'Boo' => 'Bootes',
        'CMa' => 'Canis Major',
        'CMi' => 'Canis Minor',
        'CVn' => 'Canes Venatici',
        'Cae' => 'Caelum',
        'Cam' => 'Camelopardalis',
        'Cap' => 'Capricornus',
        'Car' => 'Carina',
        'Cas' => 'Cassiopeia',
        'Cen' => 'Centaurus',
        'Cep' => 'Cepheus',
        'Cet' => 'Cetus',
        'Cha' => 'Chamaeleon',
        'Cir' => 'Circinus',
        'Cnc' => 'Cancer',
        'Col' => 'Columba',
        'Com' => 'Coma Berenices',
        'CrA' => 'Corona Australis',
        'CrB' => 'Corona Borealis',
        'Crt' => 'Crater',
        'Cru' => 'Crux',
        'Crv' => 'Corvus',
        'Cyg' => 'Cygnus',
        'Del' => 'Delphinus',
        'Dor' => 'Dorado',
        'Dra' => 'Draco',
        'Equ' => 'Equuleus',
        'Eri' => 'Eridanus',
        'For' => 'Fornax',
        'Gem' => 'Gemini',
        'Gru' => 'Grus',
        'Her' => 'Hercules',
        'Hor' => 'Horologium',
        'Hya' => 'Hydra',
        'Hyi' => 'Hydrus',
        'Ind' => 'Indus',
        'LMi' => 'Leo Minor',
        'Lac' => 'Lacerta',
        'Leo' => 'Leo',
        'Lep' => 'Lepus',
        'Lib' => 'Libra',
        'Lup' => 'Lupus',
        'Lyn' => 'Lynx',
        'Lyr' => 'Lyra',
        'Men' => 'Mensa',
        'Mic' => 'Microscopium',
        'Mon' => 'Monoceros',
        'Mus' => 'Musca',
        'Nor' => 'Norma',
        'Oct' => 'Octans',
        'Oph' => 'Ophiuchus',
        'Ori' => 'Orion',
        'Pav' => 'Pavo',
        'Peg' => 'Pegasus',
        'Per' => 'Perseus',
        'Phe' => 'Phoenix',
        'Pic' => 'Pictor',
        'PsA' => 'Piscis Austrinus',
        'Psc' => 'Pisces',
        'Pup' => 'Puppis',
        'Pyx' => 'Pyxis',
        'Ret' => 'Reticulum',
        'Scl' => 'Sculptor',
        'Sco' => 'Scorpius',
        'Sct' => 'Scutum',
        'Ser' => 'Serpens',
        'Sex' => 'Sextans',
        'Sge' => 'Sagitta',
        'Sgr' => 'Sagittarius',
        'Tau' => 'Taurus',
        'Tel' => 'Telescopium',
        'TrA' => 'Triangulum Australe',
        'Tri' => 'Triangulum',
        'Tuc' => 'Tucana',
        'UMa' => 'Ursa Major',
        'UMi' => 'Ursa Minor',
        'Vel' => 'Vela',
        'Vir' => 'Virgo',
        'Vol' => 'Volans',
        'Vul' => 'Vulpecula',
    ];

    /**
     * The Latin name of a constellation, from its abbreviation or from the name itself.
     *
     * It takes the name too, and that is not convenience: `Star::$constellation` already holds
     * the resolved name, so composing the two is the natural thing to write and it used to return
     * null for 1,068 of the 1,099 stars. It worked on Regulus, because `Leo` is one of the only
     * two abbreviations that equal their own name, and on Ara. A reader tried it there, saw it
     * work, and got null everywhere else.
     *
     * @param string $constellation The three letters of the designation, or the Latin name.
     * @return string|null Null if it is neither.
     */
    public static function name(string $constellation): ?string
    {
        if (isset(self::NAMES[$constellation])) {
            return self::NAMES[$constellation];
        }

        return in_array($constellation, self::NAMES, true) ? $constellation : null;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::NAMES;
    }
}
