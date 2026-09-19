<?php

namespace Astronomy;

use LogicException;

/**
 * The satellites of the planets that have an ephemeris in JPL Horizons, from Mars to Pluto.
 *
 * **The cases are not written by hand: `SatelliteList::regenerate()` writes them**, which in
 * it is regenerated from the Horizons major body list, and between the two
 * markers below. What lies outside the markers is code and the command does not touch it. They
 * have to be regenerated when the JPL adds satellites and also when a provisional one is given a
 * name, because that changes its identifier: S/2004 S 33 was 65075 and today is Thiazzi, 663. The
 * command warns about it by comparing with what was there before.
 *
 * They go in an enum, unlike asteroids and comets, because they fit: there are four hundred odd
 * and not a million and a half. And that way a misspelled `Satellite::Io` blows up when it is
 * read, not when it is requested.
 *
 * **The value is the JPL identifier and the names are the IAU ones exactly as the list brings
 * them**: `Ganymede` and not Ganímedes. Translating them would mean writing four hundred names
 * from memory, and only a few have a settled Spanish form.
 *
 * Our Moon is not here: it is `Body::Moon`, it comes from ELP and it is not downloaded.
 */
enum Satellite: int implements DownloadableBody
{
    // <cases> SatelliteList::regenerate() writes them: they are not edited by hand.
    // Mars
    case Phobos = 401;
    case Deimos = 402;

    // Jupiter
    case Io = 501;
    case Europa = 502;
    case Ganymede = 503;
    case Callisto = 504;
    case Amalthea = 505;
    case Himalia = 506;
    case Elara = 507;
    case Pasiphae = 508;
    case Sinope = 509;
    case Lysithea = 510;
    case Carme = 511;
    case Ananke = 512;
    case Leda = 513;
    case Thebe = 514;
    case Adrastea = 515;
    case Metis = 516;
    case Callirrhoe = 517;
    case Themisto = 518;
    case Megaclite = 519;
    case Taygete = 520;
    case Chaldene = 521;
    case Harpalyke = 522;
    case Kalyke = 523;
    case Iocaste = 524;
    case Erinome = 525;
    case Isonoe = 526;
    case Praxidike = 527;
    case Autonoe = 528;
    case Thyone = 529;
    case Hermippe = 530;
    case Aitne = 531;
    case Eurydome = 532;
    case Euanthe = 533;
    case Euporie = 534;
    case Orthosie = 535;
    case Sponde = 536;
    case Kale = 537;
    case Pasithee = 538;
    case Hegemone = 539;
    case Mneme = 540;
    case Aoede = 541;
    case Thelxinoe = 542;
    case Arche = 543;
    case Kallichore = 544;
    case Helike = 545;
    case Carpo = 546;
    case Eukelade = 547;
    case Cyllene = 548;
    case Kore = 549;
    case Herse = 550;
    case S2010_J1 = 551;
    case S2010_J2 = 552;
    case Dia = 553;
    case S2016_J1 = 554;
    case S2003_J18 = 555;
    case S2011_J2 = 556;
    case Eirene = 557;
    case Philophrosyne = 558;
    case S2017_J1 = 559;
    case Eupheme = 560;
    case S2003_J19 = 561;
    case Valetudo = 562;
    case S2017_J2 = 563;
    case S2017_J3 = 564;
    case Pandia = 565;
    case S2017_J5 = 566;
    case S2017_J6 = 567;
    case S2017_J7 = 568;
    case S2017_J8 = 569;
    case S2017_J9 = 570;
    case Ersa = 571;
    case S2011_J1 = 572;
    case S2003_J2 = 55501;
    case S2003_J4 = 55502;
    case S2003_J9 = 55503;
    case S2003_J10 = 55504;
    case S2003_J12 = 55505;
    case S2003_J16 = 55506;
    case S2003_J23 = 55507;
    case S2003_J24 = 55508;
    case S2011_J3 = 55509;
    case S2018_J2 = 55510;
    case S2018_J3 = 55511;
    case S2021_J1 = 55512;
    case S2021_J2 = 55513;
    case S2021_J3 = 55514;
    case S2021_J4 = 55515;
    case S2021_J5 = 55516;
    case S2021_J6 = 55517;
    case S2016_J3 = 55518;
    case S2016_J4 = 55519;
    case S2018_J4 = 55520;
    case S2022_J1 = 55521;
    case S2022_J2 = 55522;
    case S2022_J3 = 55523;
    case S2017_J10 = 55525;
    case S2017_J11 = 55526;
    case S2011_J4 = 55527;
    case S2018_J5 = 55528;
    case S2024_J1 = 55529;
    case S2011_J5 = 55530;
    case S2010_J3 = 55531;
    case S2010_J4 = 55532;
    case S2010_J5 = 55533;
    case S2010_J6 = 55534;
    case S2011_J6 = 55535;
    case S2017_J12 = 55536;
    case S2017_J13 = 55537;
    case S2017_J14 = 55538;
    case S2017_J15 = 55539;
    case S2017_J16 = 55540;
    case S2017_J17 = 55541;
    case S2017_J18 = 55542;
    case S2021_J7 = 55543;
    case S2021_J8 = 55544;

    // Saturn
    case Mimas = 601;
    case Enceladus = 602;
    case Tethys = 603;
    case Dione = 604;
    case Rhea = 605;
    case Titan = 606;
    case Hyperion = 607;
    case Iapetus = 608;
    case Phoebe = 609;
    case Janus = 610;
    case Epimetheus = 611;
    case Helene = 612;
    case Telesto = 613;
    case Calypso = 614;
    case Atlas = 615;
    case Prometheus = 616;
    case Pandora = 617;
    case Pan = 618;
    case Ymir = 619;
    case Paaliaq = 620;
    case Tarvos = 621;
    case Ijiraq = 622;
    case Suttungr = 623;
    case Kiviuq = 624;
    case Mundilfari = 625;
    case Albiorix = 626;
    case Skathi = 627;
    case Erriapus = 628;
    case Siarnaq = 629;
    case Thrymr = 630;
    case Narvi = 631;
    case Methone = 632;
    case Pallene = 633;
    case Polydeuces = 634;
    case Daphnis = 635;
    case Aegir = 636;
    case Bebhionn = 637;
    case Bergelmir = 638;
    case Bestla = 639;
    case Farbauti = 640;
    case Fenrir = 641;
    case Fornjot = 642;
    case Hati = 643;
    case Hyrrokkin = 644;
    case Kari = 645;
    case Loge = 646;
    case Skoll = 647;
    case Surtur = 648;
    case Anthe = 649;
    case Jarnsaxa = 650;
    case Greip = 651;
    case Tarqeq = 652;
    case Aegaeon = 653;
    case Gridr = 654;
    case Angrboda = 655;
    case Skrymir = 656;
    case Gerd = 657;
    case S2004_S26 = 658;
    case Eggther = 659;
    case S2004_S29 = 660;
    case Beli = 661;
    case Gunnlod = 662;
    case Thiazzi = 663;
    case S2004_S34 = 664;
    case Alvaldi = 665;
    case Geirrod = 666;
    case S2004_S31 = 65067;
    case S2004_S24 = 65070;
    case S2004_S28 = 65077;
    case S2004_S21 = 65079;
    case S2004_S36 = 65081;
    case S2004_S37 = 65082;
    case S2004_S39 = 65084;
    case S2004_S7 = 65085;
    case S2004_S12 = 65086;
    case S2004_S13 = 65087;
    case S2004_S17 = 65088;
    case S2006_S1 = 65089;
    case S2006_S3 = 65090;
    case S2007_S2 = 65091;
    case S2007_S3 = 65092;
    case S2019_S1 = 65093;
    case S2019_S2 = 65094;
    case S2019_S3 = 65095;
    case S2020_S1 = 65096;
    case S2020_S2 = 65097;
    case S2004_S40 = 65098;
    case S2006_S9 = 65100;
    case S2007_S5 = 65101;
    case S2020_S3 = 65102;
    case S2019_S4 = 65103;
    case S2004_S41 = 65104;
    case S2020_S4 = 65105;
    case S2020_S5 = 65106;
    case S2007_S6 = 65107;
    case S2004_S42 = 65108;
    case S2006_S10 = 65109;
    case S2019_S5 = 65110;
    case S2004_S43 = 65111;
    case S2004_S44 = 65112;
    case S2004_S45 = 65113;
    case S2006_S11 = 65114;
    case S2006_S12 = 65115;
    case S2019_S6 = 65116;
    case S2006_S13 = 65117;
    case S2019_S7 = 65118;
    case S2019_S8 = 65119;
    case S2019_S9 = 65120;
    case S2004_S46 = 65121;
    case S2019_S10 = 65122;
    case S2004_S47 = 65123;
    case S2019_S11 = 65124;
    case S2006_S14 = 65125;
    case S2019_S12 = 65126;
    case S2020_S6 = 65127;
    case S2019_S13 = 65128;
    case S2005_S4 = 65129;
    case S2007_S7 = 65130;
    case S2007_S8 = 65131;
    case S2020_S7 = 65132;
    case S2019_S14 = 65133;
    case S2019_S15 = 65134;
    case S2005_S5 = 65135;
    case S2006_S15 = 65136;
    case S2006_S16 = 65137;
    case S2006_S17 = 65138;
    case S2004_S48 = 65139;
    case S2020_S8 = 65140;
    case S2004_S49 = 65141;
    case S2004_S50 = 65142;
    case S2006_S18 = 65143;
    case S2019_S16 = 65144;
    case S2019_S17 = 65145;
    case S2019_S18 = 65146;
    case S2019_S19 = 65147;
    case S2019_S20 = 65148;
    case S2006_S19 = 65149;
    case S2004_S51 = 65150;
    case S2020_S9 = 65151;
    case S2004_S52 = 65152;
    case S2007_S9 = 65153;
    case S2004_S53 = 65154;
    case S2020_S10 = 65155;
    case S2019_S21 = 65156;
    case S2006_S20 = 65157;
    case S2004_S54 = 65158;
    case S2004_S55 = 65159;
    case S2004_S56 = 65160;
    case S2004_S57 = 65161;
    case S2004_S58 = 65162;
    case S2004_S59 = 65163;
    case S2004_S60 = 65164;
    case S2004_S61 = 65165;
    case S2005_S06 = 65166;
    case S2005_S07 = 65167;
    case S2006_S21 = 65168;
    case S2006_S22 = 65169;
    case S2006_S23 = 65170;
    case S2006_S24 = 65171;
    case S2006_S25 = 65172;
    case S2006_S26 = 65173;
    case S2006_S27 = 65174;
    case S2006_S28 = 65175;
    case S2006_S29 = 65176;
    case S2007_S10 = 65177;
    case S2007_S11 = 65178;
    case S2019_S22 = 65179;
    case S2019_S23 = 65180;
    case S2019_S24 = 65181;
    case S2019_S25 = 65182;
    case S2019_S26 = 65183;
    case S2019_S27 = 65184;
    case S2019_S28 = 65185;
    case S2019_S29 = 65186;
    case S2019_S30 = 65187;
    case S2019_S31 = 65188;
    case S2019_S32 = 65189;
    case S2019_S33 = 65190;
    case S2019_S34 = 65191;
    case S2019_S35 = 65192;
    case S2019_S36 = 65193;
    case S2019_S37 = 65194;
    case S2019_S38 = 65195;
    case S2019_S39 = 65196;
    case S2019_S40 = 65197;
    case S2019_S41 = 65198;
    case S2019_S42 = 65199;
    case S2019_S43 = 65200;
    case S2019_S44 = 65201;
    case S2020_S11 = 65202;
    case S2020_S12 = 65203;
    case S2020_S13 = 65204;
    case S2020_S14 = 65205;
    case S2020_S15 = 65206;
    case S2020_S16 = 65207;
    case S2020_S17 = 65208;
    case S2020_S18 = 65209;
    case S2020_S19 = 65210;
    case S2020_S20 = 65211;
    case S2020_S21 = 65212;
    case S2020_S22 = 65213;
    case S2020_S23 = 65214;
    case S2020_S24 = 65215;
    case S2020_S25 = 65216;
    case S2020_S26 = 65217;
    case S2020_S27 = 65218;
    case S2020_S28 = 65219;
    case S2020_S29 = 65220;
    case S2020_S30 = 65221;
    case S2020_S31 = 65222;
    case S2020_S32 = 65223;
    case S2020_S33 = 65224;
    case S2020_S34 = 65225;
    case S2020_S35 = 65226;
    case S2020_S36 = 65227;
    case S2020_S37 = 65228;
    case S2020_S38 = 65229;
    case S2020_S39 = 65230;
    case S2020_S40 = 65231;
    case S2020_S41 = 65232;
    case S2020_S42 = 65233;
    case S2020_S43 = 65234;
    case S2020_S44 = 65235;
    case S2023_S01 = 65236;
    case S2023_S02 = 65237;
    case S2023_S03 = 65238;
    case S2023_S04 = 65239;
    case S2023_S05 = 65240;
    case S2023_S06 = 65241;
    case S2023_S07 = 65242;
    case S2023_S08 = 65243;
    case S2023_S09 = 65244;
    case S2023_S10 = 65245;
    case S2023_S11 = 65246;
    case S2023_S12 = 65247;
    case S2023_S13 = 65248;
    case S2023_S14 = 65249;
    case S2023_S15 = 65250;
    case S2023_S16 = 65251;
    case S2023_S17 = 65252;
    case S2023_S18 = 65253;
    case S2023_S19 = 65254;
    case S2023_S20 = 65255;
    case S2023_S21 = 65256;
    case S2023_S22 = 65257;
    case S2023_S23 = 65258;
    case S2023_S24 = 65259;
    case S2023_S25 = 65260;
    case S2023_S26 = 65261;
    case S2023_S27 = 65262;
    case S2023_S28 = 65263;
    case S2023_S29 = 65264;
    case S2023_S30 = 65265;
    case S2023_S31 = 65266;
    case S2023_S32 = 65267;
    case S2023_S33 = 65268;
    case S2023_S34 = 65269;
    case S2023_S35 = 65270;
    case S2023_S36 = 65271;
    case S2023_S37 = 65272;
    case S2023_S38 = 65273;
    case S2023_S39 = 65274;
    case S2023_S40 = 65275;
    case S2023_S41 = 65276;
    case S2023_S42 = 65277;
    case S2023_S43 = 65278;
    case S2023_S44 = 65279;
    case S2023_S45 = 65280;
    case S2023_S46 = 65281;
    case S2023_S47 = 65282;
    case S2023_S48 = 65283;
    case S2023_S49 = 65284;
    case S2023_S50 = 65285;
    case S2020_S45 = 65286;
    case S2020_S46 = 65287;
    case S2020_S47 = 65288;
    case S2020_S48 = 65289;
    case S2023_S51 = 65290;
    case S2023_S52 = 65291;
    case S2023_S53 = 65292;
    case S2023_S54 = 65293;
    case S2023_S55 = 65294;
    case S2023_S56 = 65295;
    case S2023_S57 = 65296;
    case S2020_S49 = 65297;
    case S2023_S58 = 65298;
    case S2023_S59 = 65299;
    case S2023_S60 = 65300;
    case S2023_S61 = 65301;
    case S2023_S62 = 65302;
    case S2023_S63 = 65303;
    case S2009_S2 = 65304;

    // Uranus
    case Ariel = 701;
    case Umbriel = 702;
    case Titania = 703;
    case Oberon = 704;
    case Miranda = 705;
    case Cordelia = 706;
    case Ophelia = 707;
    case Bianca = 708;
    case Cressida = 709;
    case Desdemona = 710;
    case Juliet = 711;
    case Portia = 712;
    case Rosalind = 713;
    case Belinda = 714;
    case Puck = 715;
    case Caliban = 716;
    case Sycorax = 717;
    case Prospero = 718;
    case Setebos = 719;
    case Stephano = 720;
    case Trinculo = 721;
    case Francisco = 722;
    case Margaret = 723;
    case Ferdinand = 724;
    case Perdita = 725;
    case Mab = 726;
    case Cupid = 727;
    case S2023_U1 = 75051;

    // Neptune
    case Triton = 801;
    case Nereid = 802;
    case Naiad = 803;
    case Thalassa = 804;
    case Despina = 805;
    case Galatea = 806;
    case Larissa = 807;
    case Proteus = 808;
    case Halimede = 809;
    case Psamathe = 810;
    case Sao = 811;
    case Laomedeia = 812;
    case Neso = 813;
    case Hippocamp = 814;
    case S2002_N5 = 85051;
    case S2021_N1 = 85052;

    // Pluto
    case Charon = 901;
    case Nix = 902;
    case Hydra = 903;
    case Kerberos = 904;
    case Styx = 905;
    // </cases>

    /**
     * @return string
     */
    public function horizonsId(): string
    {
        return (string) $this->value;
    }

    /**
     * The centre from which its positions are requested: the BARYCENTRE of its planet's system,
     * `500@5` for Jupiter's, and not the centre of the planet, `500@599`.
     *
     * It is the trap already noted in `Body::horizonsId`, seen from the other side. The engine
     * puts each outer planet at the barycentre of its system, because that is what VSOP87 and the
     * correction towards the JPL represent, so a satellite's position will be built by adding what
     * was downloaded to that barycentre. Downloading it from the centre of the planet would leave
     * out how far the planet sits from the barycentre, which for Pluto, with Charon, is two
     * thousand kilometres.
     *
     * @return string
     */
    public function horizonsCenter(): string
    {
        return '500@'.$this->system();
    }

    /**
     * The planet it revolves around.
     *
     * It is not written down: the first digit of the identifier is that of the system in the JPL
     * numbering (501 and 55501 are Jupiter's), and the planet is the `Body` whose Horizons
     * identifier is that digit. That way the barycentre here and `Body`'s are the same by
     * construction.
     *
     * @return Body
     */
    public function planet(): Body
    {
        foreach (Body::cases() as $body) {
            if ($body->horizonsId() === $this->system()) {
                return $body;
            }
        }

        throw new LogicException("Satellite {$this->value} does not belong to any system the engine knows.");
    }

    /**
     * The provisional ones arrive from the list as `S2003_J2`, because spaces do not fit there,
     * and they are written «S/2003 J 2», which is the IAU form.
     *
     * @return string
     */
    public function name(): string
    {
        if (preg_match('/^S(\d{4})_([A-Z])(\d+)$/', $this->name, $parts)) {
            return "S/{$parts[1]} {$parts[2]} {$parts[3]}";
        }

        return $this->name;
    }

    /**
     * @return DownloadableGroup
     */
    public function group(): DownloadableGroup
    {
        return DownloadableGroup::Satellites;
    }

    /**
     * @param float $jdTT
     * @return string
     */
    public function file(float $jdTT): string
    {
        return Downloadables::file($this->group(), (string) $this->value, $jdTT);
    }

    /**
     * The satellites of a planet, by identifier.
     *
     * @param Body $planet
     * @return list<self>
     */
    public static function of(Body $planet): array
    {
        return array_values(array_filter(self::cases(), fn (self $satellite) => $satellite->planet() === $planet));
    }

    /**
     * @return string
     */
    private function system(): string
    {
        return ((string) $this->value)[0];
    }
}
