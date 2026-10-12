<?php

namespace Astronomy\Tests;

use Astronomy\Body;
use Astronomy\Equatorial;
use Astronomy\Ephemeris;
use Astronomy\Horizon;
use Astronomy\Position;
use Astronomy\Precession;
use Astronomy\Time;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * The three frames that were missing beside the geocentric one: heliocentric, barycentric and
 * topocentric, against Swiss Ephemeris and against the JPL.
 *
 * The figures are copied by hand so the suite runs with no network and no Swiss:
 *
 * - The heliocentric and the topocentric ones come from `swe_calc` (Swiss Ephemeris 2.10.03
 *   with the Moshier ephemerides, `SEFLG_HELCTR` and `SEFLG_TOPOCTR`, always with
 *   `SEFLG_SPEED`).
 * - The barycentric ones come from JPL Horizons: vectors from the barycentre (`500@0`) with
 *   light time, in the ecliptic of J2000. Swiss with Moshier does NOT give barycentric
 *   positions («barycentric Moshier positions are not supported»), so there is no Swiss to
 *   compare against there. They are brought to the date with `Precession::toDate` plus the
 *   nutation, the same rotation Pluto and the asteroids carry in the engine.
 * - And the heliocentric ones from the JPL as well (`500@10`), as a second opinion: they are
 *   what tells where the residual against Swiss comes from.
 *
 * A trap in the reference itself, stepped on while writing this: so that the observer rotated
 * by the same amount in both engines, Swiss was handed OUR delta T through
 * `swe_set_delta_t_userdef`, which wants it in DAYS and not in seconds (the unit of
 * `swe_deltat`). In seconds the Earth rotated fifty two days too far and the topocentric Moon
 * came out a degree and a half off, looking exactly as if the fault were ours.
 */
final class ReferenceFramesTest extends TestCase
{
    /**
     * What each heliocentric body is allowed against Swiss: arcseconds of longitude and of
     * latitude, a fraction of the distance, and arcseconds per day.
     *
     * A second and a bit for the inner ones, from 1700 to 2300. What is left is a LINEAR drift
     * of 0.3 arcseconds per century (from -0.7" in 1700 to +1.0" in 2300, the same in all of
     * them): the difference between the precession model VSOP87D uses to bring its series to
     * the date (IAU 1976) and the one Swiss uses (Vondrák 2011). That this is what it is, and
     * not the ephemeris, is what the comparison against the JPL below says, where the same
     * rotation goes on both sides and the residual stays at a tenth. Pluto is Moshier's Pluto
     * against the JPL's, which is ours: five seconds.
     *
     * ## These numbers CHANGED when the correction towards the JPL was tabulated
     *
     * `resources/astro/correction/*.bin` brings the series to DE440, and the reference here is
     * not DE440 but Moshier, which is another analytical theory. Getting closer to one is
     * moving away from the other, so it all had to be measured again instead of assumed.
     * Measured, from 1700 to 2300:
     *
     * | | against Swiss before | against Swiss now |
     * |---|---|---|
     * | Moon, Mercury, Venus, Earth, Mars | 0.95 to 1.05″ | 0.91 to 1.02″ |
     * | Jupiter | 0.87″ | 0.49″ |
     * | Saturn | 1.47″ | 0.88″ |
     * | Uranus | 2.98″ | 0.90″ |
     * | Neptune | 8.05″ | 3.92″ |
     *
     * So **against Swiss almost everything IMPROVES**, and most of all the two that were worst.
     * That is neither coincidence nor luck: Moshier is a fit to DE, so in Uranus and Neptune,
     * where our truncated VSOP87 was the one drifting, moving closer to DE440 moves us closer
     * to Moshier too. The only ones that get worse are the LATITUDE (from 0.04 to 0.09″ in the
     * Earth and the Moon, from 0.12 to 0.16 in Mercury) and Venus in longitude, by three
     * hundredths, and both are the same thing: the latitude term of the FK5 correction, still
     * applied to a planet that is already a JPL position and no longer a VSOP87 one. It is
     * written down in `PlanetCorrectionTest`.
     *
     * Neptune stays at four seconds and goes no lower because from there on it is Moshier that
     * rules: against the JPL, which is the good reference, it stays at 0.12″ like all the rest.
     *
     * @var array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    private const HELIOCENTRIC_TOLERANCE = [
        'moon' => [1.4, 0.15, 1.5e-7, 1.2],
        'mercury' => [1.4, 0.25, 4e-7, 1.2],
        'venus' => [1.4, 0.15, 3e-7, 1.2],
        'earth' => [1.4, 0.15, 1.5e-7, 1.2],
        'mars' => [1.4, 0.20, 3e-7, 1.2],
        'jupiter' => [1.0, 0.25, 1.5e-6, 1.2],
        'saturn' => [1.4, 0.20, 1e-6, 1.2],
        'uranus' => [1.4, 0.15, 1.2e-6, 1.2],
        'neptune' => [5.0, 0.20, 4e-6, 1.2],
        'pluto' => [6.0, 2.0, 1e-5, 1.2],
    ];

    /**
     * How far our barycentre is allowed to sit from DE440's, in kilometres.
     *
     * The barycentric Sun is the mass-weighted sum from DE440 over our own series
     * (`Ephemeris::barycentricSun`), and measured against the JPL vectors it stays between 60
     * and 135 km on these seven dates. With VSOP87's E series, which is what was there, it was
     * between 1000 and 1300 km with a shifting direction, and not from truncating it: the whole
     * series gave the same. That was the entire frame displaced: Mercury at 5.7 arcseconds and
     * the Sun, which sits 0.008 AU from the barycentre, at two hundred. It is measured in
     * kilometres and not in angle because for the Sun the angle says nothing: a hundred
     * kilometres at 0.008 AU are fifteen arcseconds, looking every bit like a fault.
     */
    private const BARYCENTRE_TOLERANCE_KM = 200.0;

    private const PLACES = [
        'Madrid' => [40.4168, -3.7038],
        'Singapore' => [1.3521, 103.8198],
        'Ushuaia' => [-54.8019, -68.3030],
    ];

    /** [body, jd TT, longitude, latitude, distance AU, speed] heliocentric according to Swiss (Moshier, HELCTR | SPEED). */
    private const SWISS_HELIOCENTRIC = [
        ['moon', 2341972.5, 100.58755394, -0.01022651, 0.984380991, 1.03746947],
        ['moon', 2378496.5, 100.28750083, -0.00974840, 0.982240500, 1.00621344],
        ['moon', 2415020.5, 100.17296441, 0.00270712, 0.980827009, 0.98654541],
        ['moon', 2451545.0, 100.49947008, 0.01391790, 0.981875901, 1.00410407],
        ['moon', 2488069.5, 100.72416251, 0.00269587, 0.984723174, 1.03656009],
        ['moon', 2524593.5, 100.31469933, -0.01152951, 0.986118143, 1.04845774],
        ['moon', 2561118.5, 100.93306746, -0.00892618, 0.984690505, 1.03524441],
        ['mercury', 2341972.5, 24.48710401, -2.43738608, 0.326252216, 5.60624479],
        ['mercury', 2378496.5, 127.30363729, 6.92233038, 0.329935978, 5.55242646],
        ['mercury', 2415020.5, 201.37630257, 3.05748764, 0.420725884, 3.37466262],
        ['mercury', 2451545.0, 253.77165762, -3.02195344, 0.466470498, 2.74501600],
        ['mercury', 2488069.5, 305.19623035, -6.79117162, 0.432138085, 3.23480890],
        ['mercury', 2524593.5, 14.20968887, -4.18196046, 0.342699491, 5.09877245],
        ['mercury', 2561118.5, 121.53159296, 6.57649085, 0.319973578, 5.89491927],
        ['venus', 2341972.5, 308.27529462, -2.75513501, 0.728345252, 1.58104014],
        ['venus', 2378496.5, 146.14817100, 3.21351202, 0.718604982, 1.62551857],
        ['venus', 2415020.5, 343.13176955, -3.39013472, 0.727434989, 1.58692041],
        ['venus', 2451545.0, 182.59231865, 3.26471301, 0.720212387, 1.61844863],
        ['venus', 2488069.5, 19.72590775, -2.87605707, 0.725212458, 1.59504034],
        ['venus', 2524593.5, 218.81350865, 2.17001725, 0.722800482, 1.60398983],
        ['venus', 2561118.5, 56.50188182, -1.32241439, 0.722424046, 1.60418919],
        ['earth', 2341972.5, 100.71497534, 0.00020784, 0.983154709, 1.01931757],
        ['earth', 2378496.5, 100.42914166, -0.00001538, 0.983220320, 1.01961749],
        ['earth', 2415020.5, 100.15338583, -0.00005740, 0.983266292, 1.01984754],
        ['earth', 2451545.0, 100.36816655, -0.00023233, 0.983327692, 1.01943156],
        ['earth', 2488069.5, 100.60332994, -0.00007839, 0.983357675, 1.01889026],
        ['earth', 2524593.5, 100.32535671, 0.00021791, 0.983429124, 1.01859124],
        ['earth', 2561118.5, 101.05840308, 0.00023663, 0.983529602, 1.01857123],
        ['mars', 2341972.5, 168.20960827, 1.58787789, 1.657516691, 0.44104654],
        ['mars', 2378496.5, 222.54161036, 0.17654054, 1.559686064, 0.49770990],
        ['mars', 2415020.5, 286.44634797, -1.56335527, 1.421401692, 0.59975848],
        ['mars', 2451545.0, 359.43840584, -1.41977867, 1.391203259, 0.62594799],
        ['mars', 2488069.5, 67.57009871, 0.54834694, 1.509411329, 0.53145169],
        ['mars', 2524593.5, 124.58614801, 1.77236618, 1.635464643, 0.45312446],
        ['mars', 2561118.5, 176.74327529, 1.51618447, 1.660510071, 0.43941978],
        ['jupiter', 2341972.5, 280.31824006, -0.06834101, 5.187896630, 0.08348179],
        ['jupiter', 2378496.5, 87.53194327, -0.24699269, 5.132362915, 0.08529266],
        ['jupiter', 2415020.5, 234.53866584, 0.92442952, 5.384912518, 0.07756056],
        ['jupiter', 2451545.0, 36.28805084, -1.17459981, 4.965382462, 0.09118635],
        ['jupiter', 2488069.5, 190.99167866, 1.29882829, 5.451422475, 0.07566277],
        ['jupiter', 2524593.5, 343.53901197, -1.13015525, 4.986990807, 0.09041161],
        ['jupiter', 2561118.5, 147.27207073, 0.88852644, 5.351440624, 0.07849314],
        ['saturn', 2341972.5, 333.04596184, -1.67256410, 9.762320719, 0.03200488],
        ['saturn', 2378496.5, 125.85343615, 0.59901345, 9.104579048, 0.03674405],
        ['saturn', 2415020.5, 266.51711216, 1.10356467, 10.066860514, 0.03013591],
        ['saturn', 2451545.0, 45.71642593, -2.30319652, 9.183858290, 0.03618647],
        ['saturn', 2488069.5, 199.98912155, 2.47456754, 9.666114092, 0.03266391],
        ['saturn', 2524593.5, 332.30693539, -1.49103057, 9.821576169, 0.03164938],
        ['saturn', 2561118.5, 124.12005099, 0.33989779, 9.070700505, 0.03704132],
        ['uranus', 2341972.5, 98.81794447, 0.34318461, 18.796209025, 0.01222896],
        ['uranus', 2378496.5, 174.12775020, 0.75849279, 18.299101388, 0.01292081],
        ['uranus', 2415020.5, 248.66032363, 0.06621775, 18.992782593, 0.01207904],
        ['uranus', 2451545.0, 316.41355678, -0.68484480, 19.924011837, 0.01091806],
        ['uranus', 2488069.5, 20.54125556, -0.62479400, 19.970673248, 0.01082455],
        ['uranus', 2524593.5, 87.66873030, 0.17054982, 19.080782217, 0.01190853],
        ['uranus', 2561118.5, 161.87505928, 0.77541234, 18.296988161, 0.01296415],
        ['neptune', 2341972.5, 4.68587990, -1.49428283, 29.868697675, 0.00611061],
        ['neptune', 2378496.5, 225.12485684, 1.78009773, 30.320945027, 0.00589911],
        ['neptune', 2415020.5, 85.69966322, -1.25822478, 29.871142873, 0.00613130],
        ['neptune', 2451545.0, 303.92392254, 0.24202529, 30.120613540, 0.00599791],
        ['neptune', 2488069.5, 165.57440315, 0.95109683, 30.207700566, 0.00597318],
        ['neptune', 2524593.5, 24.14522675, -1.64768464, 29.834573597, 0.00615829],
        ['neptune', 2561118.5, 244.06540954, 1.64780055, 30.329702605, 0.00592121],
        ['pluto', 2341972.5, 129.96750983, 7.07663185, 36.855989084, 0.00431520],
        ['pluto', 2378496.5, 332.50883864, -12.29026130, 40.921232677, 0.00360747],
        ['pluto', 2415020.5, 75.75855614, -9.59138019, 46.959821344, 0.00275128],
        ['pluto', 2451545.0, 250.54114936, 11.16167601, 30.223223431, 0.00654790],
        ['pluto', 2488069.5, 33.51797294, -16.79220973, 48.932438385, 0.00263882],
        ['pluto', 2524593.5, 143.63355683, 8.92388586, 35.799751195, 0.00463674],
        ['pluto', 2561118.5, 344.76577439, -13.35716142, 41.906927847, 0.00348003],
    ];

    /** [body, jd TDB, X, Y, Z] in AU, ecliptic and equinox of J2000, with light time, from JPL Horizons at the barycentre (500@0). */
    private const JPL_BARYCENTRIC = [
        ['sun', 2341972.5, -0.005140945457, 0.004829507365, 0.000125272398],
        ['sun', 2378496.5, 0.003495079809, -0.005812056983, -0.000067269985],
        ['sun', 2415020.5, 0.003183176539, 0.006366317957, -0.000103214441],
        ['sun', 2451545.0, -0.007137179400, -0.002795997168, 0.000206298510],
        ['sun', 2488069.5, 0.008407195481, 0.001031595681, -0.000253593013],
        ['sun', 2524593.5, -0.008404728040, 0.001600064473, 0.000207661534],
        ['sun', 2561118.5, 0.006892066387, -0.004266571732, -0.000147587001],
        ['mercury', 2341972.5, 0.280837347499, 0.161251319605, -0.013627574851],
        ['mercury', 2378496.5, -0.207471190392, 0.244710113527, 0.039804041921],
        ['mercury', 2415020.5, -0.384207596939, -0.156229078145, 0.022292904461],
        ['mercury', 2451545.0, -0.137288941247, -0.450066044002, -0.024385237811],
        ['mercury', 2488069.5, 0.247104070953, -0.355568620697, -0.051278383275],
        ['mercury', 2524593.5, 0.326623976913, 0.069214765699, -0.024828816067],
        ['mercury', 2561118.5, -0.139107896972, 0.278111709927, 0.036317381943],
        ['mars', 2341972.5, -1.647462650506, 0.223976808301, 0.046118106629],
        ['mars', 2378496.5, -1.092869004119, -1.115131055605, 0.004193272804],
        ['mars', 2415020.5, 0.438437140011, -1.346190189356, -0.039181068922],
        ['mars', 2451545.0, 1.383573326834, -0.016333661120, -0.034263774756],
        ['mars', 2488069.5, 0.618170329755, 1.381723897030, 0.013866115153],
        ['mars', 2524593.5, -0.869518839668, 1.391106464362, 0.050198981516],
        ['mars', 2561118.5, -1.639038508213, 0.210859566274, 0.043756353820],
        ['jupiter', 2341972.5, 1.294439722252, -5.017648839998, -0.009422935421],
        ['jupiter', 2378496.5, -0.025782642307, 5.126429783631, -0.019862427662],
        ['jupiter', 2415020.5, -3.013048589191, -4.453707133149, 0.085705013265],
        ['jupiter', 2451545.0, 3.994170636877, 2.935595818537, -0.101581139532],
        ['jupiter', 2488069.5, -5.365390065470, -0.907226688785, 0.123634009562],
        ['jupiter', 2524593.5, 4.698573014287, -1.643041359066, -0.097617060349],
        ['jupiter', 2561118.5, -4.271108797846, 3.209770072607, 0.080953082192],
        ['saturn', 2341972.5, 8.992861721677, -3.770951623995, -0.286915241158],
        ['saturn', 2378496.5, -5.682368768209, 7.104372569797, 0.098141532135],
        ['saturn', 2415020.5, -0.364089150480, -10.051973839767, 0.191493386721],
        ['saturn', 2451545.0, 6.399498891631, 6.566987817073, -0.368875578898],
        ['saturn', 2488069.5, -9.144760390327, -3.077666038659, 0.417984562169],
        ['saturn', 2524593.5, 8.452058060389, -4.980336078210, -0.253470622289],
        ['saturn', 2561118.5, -4.518964604540, 7.856517717870, 0.048643689314],
    ];

    /** [body, jd TDB, X, Y, Z] the same, from the centre of the Sun (500@10). */
    private const JPL_HELIOCENTRIC = [
        ['mercury', 2341972.5, 0.285978526444, 0.156421493430, -0.013752894769],
        ['mercury', 2378496.5, -0.210965225632, 0.250522822698, 0.039871268560],
        ['mercury', 2415020.5, -0.387390930410, -0.162594630078, 0.022396195881],
        ['mercury', 2451545.0, -0.130151181886, -0.447270221861, -0.024591603869],
        ['mercury', 2488069.5, 0.238697274425, -0.356599834534, -0.051024795513],
        ['mercury', 2524593.5, 0.335029207858, 0.067613387600, -0.025036631208],
        ['mercury', 2561118.5, -0.145998742123, 0.282378755074, 0.036464896672],
        ['venus', 2341972.5, 0.491159617612, -0.536655713702, -0.035350547282],
        ['venus', 2378496.5, -0.614629919029, 0.370120051144, 0.040428280993],
        ['venus', 2415020.5, 0.699832035360, -0.193762047401, -0.043046867355],
        ['venus', 2451545.0, -0.718305633645, -0.032569857164, 0.041015527567],
        ['venus', 2488069.5, 0.687556727881, 0.227749864270, -0.036454091863],
        ['venus', 2524593.5, -0.584222553920, -0.424692182400, 0.027586893137],
        ['venus', 2561118.5, 0.441531238722, 0.571537126234, -0.017090967328],
        ['earth', 2341972.5, -0.252864072591, 0.950080251998, 0.000639014581],
        ['earth', 2378496.5, -0.224919966880, 0.957148379319, 0.000426588757],
        ['earth', 2415020.5, -0.196790127017, 0.963372253553, 0.000213951595],
        ['earth', 2451545.0, -0.177037402928, 0.967259663404, -0.000004085340],
        ['earth', 2488069.5, -0.157309104988, 0.970693669668, -0.000218227678],
        ['earth', 2524593.5, -0.128828298882, 0.974954344746, -0.000432145985],
        ['earth', 2561118.5, -0.117641957672, 0.976468326980, -0.000650444859],
        ['moon', 2341972.5, -0.251063300541, 0.951826051314, 0.000461040671],
        ['moon', 2378496.5, -0.222331330261, 0.956747151001, 0.000259645865],
        ['moon', 2415020.5, -0.196630299381, 0.960915175545, 0.000260717652],
        ['moon', 2451545.0, -0.178988935042, 0.965423906291, 0.000238410139],
        ['moon', 2488069.5, -0.159577145820, 0.971707226551, -0.000170725496],
        ['moon', 2524593.5, -0.128998711055, 0.977644085953, -0.000635536479],
        ['moon', 2561118.5, -0.115641985924, 0.977876056482, -0.000809007563],
        ['mars', 2341972.5, -1.642321748161, 0.219146880134, 0.045992826549],
        ['mars', 2378496.5, -1.096363981669, -1.109319083114, 0.004260538469],
        ['mars', 2415020.5, 0.435253558060, -1.352556668143, -0.039077847786],
        ['mars', 2451545.0, 1.390710478152, -0.013538286930, -0.034470085644],
        ['mars', 2488069.5, 0.609762825477, 1.380692473457, 0.014119719306],
        ['mars', 2524593.5, -0.861114493058, 1.389506193151, 0.049991324843],
        ['mars', 2561118.5, -1.645930518981, 0.215126677637, 0.043903950863],
        ['jupiter', 2341972.5, 1.299580419579, -5.022478424601, -0.009548201932],
        ['jupiter', 2378496.5, -0.029277465480, 5.132241830144, -0.019795163400],
        ['jupiter', 2415020.5, -3.016232016544, -4.460073293450, 0.085808232690],
        ['jupiter', 2451545.0, 4.001308011302, 2.938391540599, -0.101787441268],
        ['jupiter', 2488069.5, -5.373797317854, -0.908257938207, 0.123887602390],
        ['jupiter', 2524593.5, 4.706977625269, -1.644641788833, -0.097824717719],
        ['jupiter', 2561118.5, -4.278000648743, 3.214036908750, 0.081100663264],
        ['saturn', 2341972.5, 8.998002597188, -3.775781326846, -0.287040507279],
        ['saturn', 2378496.5, -5.685863667251, 7.110184762521, 0.098208792547],
        ['saturn', 2415020.5, -0.367272523777, -10.058340149496, 0.191596608821],
        ['saturn', 2451545.0, 6.406636243777, 6.569783657879, -0.369081881554],
        ['saturn', 2488069.5, -9.153167656426, -3.078697380758, 0.418238153619],
        ['saturn', 2524593.5, 8.460462671564, -4.981936365134, -0.253678275332],
        ['saturn', 2561118.5, -4.525856459563, 7.860784404836, 0.048791265855],
        ['uranus', 2341972.5, -4.230465007757, 18.313532500634, 0.124869257775],
        ['uranus', 2378496.5, -18.271139671726, 0.982101412345, 0.242014301892],
        ['uranus', 2415020.5, -6.479658930710, -17.853276904555, 0.017769933842],
        ['uranus', 2451545.0, 14.431547818261, -13.734628164173, -0.238138826325],
        ['uranus', 2488069.5, 18.865223227865, 6.548503585341, -0.219661347391],
        ['uranus', 2524593.5, 1.705978980760, 19.004311259662, 0.048112370056],
        ['uranus', 2561118.5, -16.925282201219, 6.946609595518, 0.244080371910],
        ['neptune', 2341972.5, 29.501100226237, 4.606520180731, -0.774194905643],
        ['neptune', 2378496.5, -20.310639782561, -22.493809355950, 0.930914278969],
        ['neptune', 2415020.5, 1.515420801216, 29.825539814934, -0.649126129160],
        ['neptune', 2451545.0, 16.811597333975, -24.992070709857, 0.127239580939],
        ['neptune', 2488069.5, -29.059077642686, 8.235696395258, 0.500179831697],
        ['neptune', 2524593.5, 27.775978369115, 10.855711086509, -0.864019402019],
        ['neptune', 2561118.5, -15.214585083940, -26.222379413284, 0.891008244074],
        ['pluto', 2341972.5, -25.479613962491, 26.236844017851, 4.557233020237],
        ['pluto', 2378496.5, 36.327144636951, -16.700871287632, -8.717066245134],
        ['pluto', 2415020.5, 10.297737552987, 45.145670621183, -7.814052976458],
        ['pluto', 2451545.0, -9.875881849565, -27.958526901045, 5.850570250254],
        ['pluto', 2488069.5, 39.675583127296, 24.904228918317, -14.143220957411],
        ['pluto', 2524593.5, -27.420251334258, 22.339043556784, 5.544196471626],
        ['pluto', 2561118.5, 38.451924056838, -13.566060845395, -9.674617385187],
    ];

    /** [place, jd TT, body, longitude, latitude, distance AU, speed, geocentric longitude, geocentric latitude] according to Swiss (Moshier, TOPOCTR | SPEED, with OUR delta T set through set_delta_t_userdef, in days; regenerated when delta T moved to observed tables). */
    private const SWISS_TOPOCENTRIC = [
        ['Madrid', 2444736.0, 'moon', 148.26687065, 1.58187351, 0.0026094990, 13.44393950, 147.40158299, 1.93559525],
        ['Madrid', 2444736.0, 'mars', 42.05027267, -0.25452952, 2.4340172132, 0.73230369, 42.05040853, -0.25412893],
        ['Madrid', 2444736.25, 'moon', 150.92423533, 1.86198158, 0.0025800873, 8.61692815, 150.56129650, 2.19033456],
        ['Madrid', 2444736.25, 'mars', 42.23368287, -0.25182144, 2.4341328610, 0.73633486, 42.23460551, -0.25145946],
        ['Madrid', 2444736.5, 'moon', 153.27566673, 1.69429039, 0.0026079488, 11.48326518, 153.70197726, 2.43682340],
        ['Madrid', 2444736.5, 'mars', 42.41850112, -0.24957942, 2.4342407750, 0.74104944, 42.41874490, -0.24878888],
        ['Madrid', 2444736.75, 'moon', 156.82935935, 1.91199218, 0.0026568445, 16.22777982, 156.82479437, 2.67452079],
        ['Madrid', 2444736.75, 'mars', 42.60336981, -0.24694253, 2.4342846784, 0.73654604, 42.60282674, -0.24611720],
        ['Madrid', 2460676.5, 'moon', 293.61730590, -4.81449805, 0.0025913954, 17.57457396, 293.90258564, -4.61011378],
        ['Madrid', 2460676.5, 'mars', 121.91976919, 3.91371198, 0.6566848081, -0.34397624, 121.91812365, 3.91457218],
        ['Madrid', 2460676.75, 'moon', 297.83770375, -5.04676961, 0.0025697047, 14.83003119, 297.27007280, -4.48742090],
        ['Madrid', 2460676.75, 'mars', 121.83377766, 3.92131492, 0.6560900882, -0.33756455, 121.83581726, 3.92348415],
        ['Madrid', 2460677.0, 'moon', 300.78456295, -5.24031405, 0.0025258983, 9.38589844, 300.65007821, -4.34842815],
        ['Madrid', 2460677.0, 'mars', 121.75228819, 3.92889442, 0.6555357686, -0.31679629, 121.75289717, 3.93232173],
        ['Madrid', 2460677.25, 'moon', 303.24625333, -4.73304861, 0.0025320256, 11.81199880, 304.04187742, -4.19347240],
        ['Madrid', 2460677.25, 'mars', 121.67243816, 3.93897629, 0.6549407589, -0.32836377, 121.66937242, 3.94108386],
        ['Singapore', 2444736.0, 'moon', 147.28948005, 2.11969295, 0.0025662119, 7.28439409, 147.40158299, 1.93559525],
        ['Singapore', 2444736.0, 'mars', 42.04946469, -0.25393527, 2.4340715159, 0.73828781, 42.05040853, -0.25412893],
        ['Singapore', 2444736.25, 'moon', 149.69553621, 1.83918269, 0.0026162689, 13.14120971, 150.56129650, 2.19033456],
        ['Singapore', 2444736.25, 'mars', 42.23481943, -0.25181672, 2.4341745667, 0.74256318, 42.23460551, -0.25145946],
        ['Singapore', 2444736.5, 'moon', 153.73330304, 2.21527596, 0.0026661924, 17.72323321, 153.70197726, 2.43682340],
        ['Singapore', 2444736.5, 'mars', 42.41967487, -0.24902280, 2.4342003789, 0.73504500, 42.41874490, -0.24878888],
        ['Singapore', 2444736.75, 'moon', 157.69555314, 2.98013095, 0.0026374221, 12.65251569, 156.82479437, 2.67452079],
        ['Singapore', 2444736.75, 'mars', 42.60259689, -0.24580126, 2.4342571113, 0.73032287, 42.60282674, -0.24611720],
        ['Singapore', 2460676.5, 'moon', 294.84522440, -4.78438951, 0.0025541183, 13.29304449, 293.90258564, -4.61011378],
        ['Singapore', 2460676.5, 'mars', 121.91452181, 3.91396228, 0.6567144049, -0.33066089, 121.91812365, 3.91457218],
        ['Singapore', 2460676.75, 'moon', 297.27663701, -4.92951368, 0.0025083068, 7.65458581, 297.27007280, -4.48742090],
        ['Singapore', 2460676.75, 'mars', 121.83543153, 3.92181414, 0.6561530452, -0.30759138, 121.83581726, 3.92348415],
        ['Singapore', 2460677.0, 'moon', 299.70156979, -4.21644669, 0.0025355235, 13.28082649, 300.65007821, -4.34842815],
        ['Singapore', 2460677.0, 'mars', 121.75654774, 3.93279114, 0.6555267663, -0.33047325, 121.75289717, 3.93232173],
        ['Singapore', 2460677.25, 'moon', 303.92992550, -3.80843726, 0.0025743334, 19.18158251, 304.04187742, -4.19347240],
        ['Singapore', 2460677.25, 'mars', 121.66975409, 3.94259744, 0.6548992646, -0.35823956, 121.66937242, 3.94108386],
        ['Ushuaia', 2444736.0, 'moon', 147.28404845, 2.53278973, 0.0026398691, 15.58965475, 147.40158299, 1.93559525],
        ['Ushuaia', 2444736.0, 'mars', 42.05116853, -0.25347564, 2.4340525197, 0.73564108, 42.05040853, -0.25412893],
        ['Ushuaia', 2444736.25, 'moon', 150.79200342, 3.08485004, 0.0026216394, 11.90362403, 150.56129650, 2.19033456],
        ['Ushuaia', 2444736.25, 'mars', 42.23464532, -0.25050042, 2.4341258031, 0.73340908, 42.23460551, -0.25145946],
        ['Ushuaia', 2444736.5, 'moon', 153.34660283, 3.22145756, 0.0026086013, 9.47263181, 153.70197726, 2.43682340],
        ['Ushuaia', 2444736.5, 'mars', 42.41846590, -0.24794407, 2.4342382913, 0.73770663, 42.41874490, -0.24878888],
        ['Ushuaia', 2444736.75, 'moon', 156.05408400, 3.15599090, 0.0026408996, 12.82819332, 156.82479437, 2.67452079],
        ['Ushuaia', 2444736.75, 'mars', 42.60326754, -0.24557721, 2.4343246474, 0.73947003, 42.60282674, -0.24611720],
        ['Ushuaia', 2460676.5, 'moon', 293.48790578, -3.79460527, 0.0025391966, 14.12914631, 293.90258564, -4.61011378],
        ['Ushuaia', 2460676.5, 'mars', 121.91959168, 3.91770288, 0.6567370138, -0.32825759, 121.91812365, 3.91457218],
        ['Ushuaia', 2460676.75, 'moon', 297.48071093, -3.57212254, 0.0025541392, 16.84282483, 297.27007280, -4.48742090],
        ['Ushuaia', 2460676.75, 'mars', 121.83512449, 3.92705520, 0.6561063331, -0.34374543, 121.83581726, 3.92348415],
        ['Ushuaia', 2460677.0, 'moon', 301.35635067, -3.76899880, 0.0025273201, 13.39423947, 300.65007821, -4.34842815],
        ['Ushuaia', 2460677.0, 'mars', 121.75015171, 3.93458685, 0.6555334060, -0.33246209, 121.75289717, 3.93232173],
        ['Ushuaia', 2460677.25, 'moon', 304.20725291, -3.71170472, 0.0024994431, 10.30570683, 304.04187742, -4.19347240],
        ['Ushuaia', 2460677.25, 'mars', 121.66881754, 3.94292343, 0.6549746117, -0.32197940, 121.66937242, 3.94108386],
    ];

    public function test_the_heliocentric_positions_agree_with_swiss(): void
    {
        foreach (self::SWISS_HELIOCENTRIC as [$name, $jd, $lon, $lat, $dist, $vlon]) {
            $body = Body::from($name);
            $position = Ephemeris::heliocentric($body, $jd);
            [$tLon, $tLat, $tDist, $tVel] = self::HELIOCENTRIC_TOLERANCE[$name];

            $this->assertLessThan($tLon, abs(self::difference($position->longitude, $lon)),
                sprintf('%s heliocentric on %.1f: longitude %.6f, Swiss says %.6f', $body->name(), $jd, $position->longitude, $lon));
            $this->assertLessThan($tLat, abs($position->latitude - $lat) * 3600,
                sprintf('%s heliocentric on %.1f: latitude %.6f, Swiss says %.6f', $body->name(), $jd, $position->latitude, $lat));
            $this->assertLessThan($tDist, abs($position->distance - $dist) / $dist,
                sprintf('%s heliocentric on %.1f: distance %.9f, Swiss says %.9f', $body->name(), $jd, $position->distance, $dist));
            $this->assertLessThan($tVel, abs($position->speed - $vlon) * 3600,
                sprintf('%s heliocentric on %.1f: speed %.7f, Swiss says %.7f', $body->name(), $jd, $position->speed, $vlon));
        }
    }

    /**
     * Against the JPL with the SAME rotation on both sides: the 0.3"-per-century drift that is
     * left against Swiss disappears, and what shows is the bare ephemeris.
     *
     * **This is where what the correction did can be seen**, because the reference is the very
     * thing it corrects towards. Measured on these seven dates from 1700 to 2300, in longitude:
     *
     * | | before | now |
     * |---|---|---|
     * | Mercury | 0.195″ | 0.109″ |
     * | Venus | 0.065″ | 0.101″ |
     * | Earth and Moon | 0.115″ | 0.101″ |
     * | Mars | 0.563″ | 0.102″ |
     * | Jupiter | 0.593″ | 0.108″ |
     * | Saturn | 0.480″ | 0.113″ |
     * | Uranus | 3.399″ | 0.118″ |
     * | Neptune | 4.021″ | 0.124″ |
     *
     * All ten now land in the same tenth, and that tenth **is not the ephemerides**: it is the
     * FK5 correction, a constant 0.0903″ in longitude, which the engine still applies to a
     * corrected planet even though it is no longer a VSOP87 position but a JPL one in ICRF. The
     * latitude says the same: it goes from 0.01-0.08″ to 0.05-0.06″ in all of them, which is the
     * latitude term of that same correction. Pluto, which IS from the JPL and carries no FK5,
     * stays at 0.029″, and that is the real floor of this comparison.
     *
     * **The Uranus values were changed here.** They had been asked of Horizons as `799`, the
     * planet's CENTRE, which comes from the solution of its satellites and is another ephemeris:
     * against the `7` barycentre, which is DE440's and what VSOP87 represents, it drifts 1.27″ in
     * 1650 and 0.65″ in 2190, crossing zero around 2010. With the engine at one arcsecond that
     * did not show; with the engine at a tenth, Uranus came out at 1.49″ and looked like its
     * table's fault. It is the same trap already written down for Pluto (`999` against `9`).
     */
    public function test_the_heliocentric_positions_agree_with_the_jpl_under_the_same_rotation(): void
    {
        foreach (self::JPL_HELIOCENTRIC as [$name, $jd, $x, $y, $z]) {
            $body = Body::from($name);
            [$lon, $lat, $dist] = self::toDate($x, $y, $z, $jd);
            $position = Ephemeris::heliocentric($body, $jd);

            [$tLon, $tLat, $tDist] = [0.2, 0.1, 1e-7];

            $this->assertLessThan($tLon, abs(self::difference($position->longitude, $lon)),
                sprintf('%s heliocentric on %.1f: longitude %.6f, the JPL says %.6f', $body->name(), $jd, $position->longitude, $lon));
            $this->assertLessThan($tLat, abs($position->latitude - $lat) * 3600, "{$body->name()} on {$jd}: latitude");
            $this->assertLessThan($tDist, abs($position->distance - $dist) / $dist, "{$body->name()} on {$jd}: distance");
        }
    }

    /**
     * Against the JPL vectors from the barycentre. In kilometres where the barycentre rules (the
     * Sun and Mercury) and in angle where the planet's own ephemeris rules, with the same
     * tolerances as the heliocentric against the JPL: if the barycentre is right, moving from
     * one frame to the other adds nothing that shows.
     *
     * With the correction in place, the three measured in angle drop from 0.50-0.62″ to
     * 0.11-0.14″ and land where the heliocentric ones do, which is what the sentence above says.
     * The two measured in kilometres do not move: there it is not the planet's ephemeris that
     * rules but where we put the barycentre, and that is decided by `barycentricSun` with the
     * DE440 masses.
     */
    public function test_the_barycentric_positions_agree_with_the_jpl(): void
    {
        foreach (self::JPL_BARYCENTRIC as [$name, $jd, $x, $y, $z]) {
            $body = Body::from($name);
            $position = Ephemeris::barycentric($body, $jd);

            // Both as vectors in the ecliptic of the date, so the difference can be taken in kilometres.
            [$lon, $lat, $dist] = self::toDate($x, $y, $z, $jd);
            $jpl = self::vector($lon, $lat, $dist);
            $ours = self::vector($position->longitude, $position->latitude, $position->distance);

            $km = sqrt(($ours[0] - $jpl[0]) ** 2 + ($ours[1] - $jpl[1]) ** 2 + ($ours[2] - $jpl[2]) ** 2) * Equatorial::AU_KM;

            /* In kilometres only the Sun and Mercury, which is where the barycentre's offset
               rules. For a distant planet the kilometre criterion measures something else: four
               tenths of a second of its own heliocentric position at ten AU (Saturn in 1700) are
               already three thousand kilometres. For those the angle is what works: a hundred
               kilometres at an AU and a bit do not reach two tenths. */
            if ($body === Body::Sun || $body === Body::Mercury) {
                $this->assertLessThan(self::BARYCENTRE_TOLERANCE_KM, $km,
                    sprintf('%s barycentric on %.1f: %.0f km from the JPL', $body->name(), $jd, $km));
            } else {
                $this->assertLessThan(0.25, abs(self::difference($position->longitude, $lon)),
                    sprintf('%s barycentric on %.1f: longitude %.6f, the JPL says %.6f', $body->name(), $jd, $position->longitude, $lon));
                $this->assertLessThan(0.12, abs($position->latitude - $lat) * 3600, "{$body->name()} on {$jd}: barycentric latitude");
                $this->assertLessThan(8e-7, abs($position->distance - $dist) / $dist,
                    "{$body->name()} on {$jd}: barycentric distance");
            }
        }
    }

    /**
     * Against the definition: the barycentric position of any body minus its heliocentric one
     * has to be the barycentric Sun, the same vector for all of them at the same instant.
     *
     * To a millionth of an AU and not to zero, because the light time of each frame is measured
     * from its own origin and the two origins do not coincide: Mercury, the fastest of them,
     * moves two hundred kilometres in the four seconds light takes to go from one origin to the
     * other.
     */
    public function test_barycentric_minus_heliocentric_is_the_sun_for_any_body(): void
    {
        $bodies = [Body::Mercury, Body::Earth, Body::Moon, Body::Mars, Body::Jupiter, Body::Saturn, Body::Pluto];

        foreach ([2341972.5, 2451545.0, 2561118.5] as $jd) {
            $sun = Ephemeris::barycentric(Body::Sun, $jd);
            $vSun = self::vector($sun->longitude, $sun->latitude, $sun->distance);

            // The Sun sits at most a hundredth of an AU from the barycentre, two solar radii.
            $this->assertGreaterThan(0.0, $sun->distance);
            $this->assertLessThan(0.02, $sun->distance);

            foreach ($bodies as $body) {
                $b = Ephemeris::barycentric($body, $jd);
                $h = Ephemeris::heliocentric($body, $jd);
                $vB = self::vector($b->longitude, $b->latitude, $b->distance);
                $vH = self::vector($h->longitude, $h->latitude, $h->distance);

                $rest = sqrt(
                    ($vB[0] - $vH[0] - $vSun[0]) ** 2
                    + ($vB[1] - $vH[1] - $vSun[1]) ** 2
                    + ($vB[2] - $vH[2] - $vSun[2]) ** 2
                );

                $this->assertLessThan(5e-6, $rest, sprintf('%s on %.1f: barycentric minus heliocentric is %.2e AU away from the Sun', $body->name(), $jd, $rest));
            }
        }
    }

    /**
     * In the heliocentric frame the Earth sits where the Sun sits in the geocentric one, and the
     * two positions are the same one turned around: the geocentric Sun carries aberration
     * (twenty arcseconds) and the heliocentric Earth carries the light time from the Sun, which
     * for the Earth itself is worth exactly the same.
     *
     * **And they agree to zero, not «almost to the second».** They sat one arcsecond apart, and
     * that second was a wiring fault: `geometricGeocentric` took the Earth from `Vsop87` directly
     * and skipped its correction, so the Sun was computed with one Earth and the heliocentric
     * position with another. Now both come out of the same vector and the difference is machine
     * precision. That is why the tolerance is so tight: this no longer measures an
     * approximation, it measures that both go through the same place.
     */
    /**
     * What Horizons publishes of a body seen from ANOTHER body: apparent ecliptic longitude and
     * latitude, and distance. Request `EPHEM_TYPE='OBSERVER'`, `QUANTITIES='31,20'`,
     * `TIME_TYPE='TT'` and `CENTER='500@n'` with the barycentre of the observing system, which is
     * what VSOP87 and the tables represent, as everywhere else in the engine.
     *
     * @return list<array{0: string, 1: string, 2: float, 3: float, 4: float, 5: float}>
     */
    public static function planetocentricasDelJpl(): array
    {
        return [
            ['jupiter', 'mars', 2451545.0, 48.5141322, -0.9785483, 3.94118972185970],
            ['jupiter', 'mars', 2341970.0, 298.9489114, -0.5288422, 6.03295241130805],
            ['jupiter', 'mars', 2561120.0, 131.2309394, 0.5404199, 4.00163114451216],
            ['earth', 'mars', 2451545.0, 147.9665963, 1.0677844, 1.84946609303376],
            ['earth', 'mars', 2341970.0, 26.4157364, -1.6489641, 1.59568326372717],
            ['earth', 'mars', 2561120.0, 27.3130845, -1.4953748, 1.69361878601575],
            ['saturn', 'jupiter', 2451545.0, 56.4824279, -3.5115518, 4.36389259161503],
            ['saturn', 'jupiter', 2341970.0, 9.1311818, -2.0347832, 7.81556239235448],
            ['saturn', 'jupiter', 2561120.0, 93.0612787, -0.3950322, 4.65791548892235],
            ['sun', 'mars', 2451545.0, 179.4422523, 1.4197726, 1.39120771785424],
            ['sun', 'mars', 2341970.0, 351.2976372, -1.6088221, 1.65846966019156],
            ['sun', 'mars', 2561120.0, 353.2128050, -1.5032738, 1.65998249748516],
            ['venus', 'jupiter', 2451545.0, 212.1847447, 1.4669850, 5.57839099216659],
            ['venus', 'jupiter', 2341970.0, 100.5311082, -0.3085858, 4.53492405937584],
            ['venus', 'jupiter', 2561120.0, 330.9089998, -1.0299692, 5.38220030060916],
        ];
    }

    /**
     * A body seen from another body, against the JPL. This is `swe_calc_pctr`, and Swiss is no
     * reference here: **its `calc_pctr` does not work without ephemeris files**, because it needs
     * barycentric positions and Moshier does not give them. It answers «barycentric Moshier
     * positions are not supported», the same reason the barycentric positions above are not
     * compared against it either.
     *
     * **And ours has to be rotated to J2000 before comparing, because Horizons changes frame
     * depending on the centre.** Its `ObsEcLon` from the Earth goes in the true ecliptic of the
     * date (which is why `astronomy check` agrees to 0.18 arcseconds over eight hundred years),
     * and from any other body it goes in J2000's. Comparing them as they come gives 13.93
     * arcseconds in the year 2000, which is the nutation exactly, and 4.19 degrees in 1700 and in
     * 2300, which is three hundred years of precession. It looks like a serious fault and it is a
     * change of frame.
     *
     * That rotation has a price worth understanding so the result is not misread: **it takes the
     * difference of precession model with it**, which is what forms the engine's 0.13 arcsecond
     * floor. That is why hundredths come out here and tenths in geocentric. What this figure
     * measures is the planetocentric geometry, not the frame.
     */
    public function test_the_planetocentric_position_agrees_with_the_jpl(): void
    {
        foreach (self::planetocentricasDelJpl() as [$body, $centre, $jd, $longitude, $latitude, $distance]) {
            $position = Ephemeris::planetocentric(Body::from($body), Body::from($centre), $jd);
            [$ourLongitude, $ourLatitude] = $this->toJ2000Ecliptic($position->longitude, $position->latitude, $jd);

            $where = sprintf('%s from %s on %.1f', $body, $centre, $jd);

            $this->assertLessThan(0.02, $this->differenceInArcseconds($ourLongitude, $longitude), $where.': longitud');
            $this->assertLessThan(0.02, abs($ourLatitude - $latitude) * 3600, $where.': latitud');
            $this->assertLessThan(1.0e-6, abs($position->distance - $distance), $where.': distancia');
        }
    }

    /**
     * The aberration belongs to the OBSERVER, and that is the whole difficulty of this part: it
     * was buried inside the chain with the Earth's velocity hard-wired into it, together with
     * the deflection.
     *
     * Leaving it in place gives no error at all and moves the result by up to 29.8 arcseconds.
     * The worst case is the easiest one to understand: the Earth seen from Mars, where the wrong
     * velocity is precisely that of the body being looked at.
     */
    public function test_the_aberration_belongs_to_the_observer_and_not_to_the_earth(): void
    {
        $withTheObserversOwn = Ephemeris::planetocentric(Body::Earth, Body::Mars, 2451545.0);

        $aberrate = new \ReflectionMethod(Ephemeris::class, 'aberrationFrom');
        $speed = new \ReflectionMethod(Ephemeris::class, 'heliocentricVelocity');

        $ofMars = $speed->invoke(null, Body::Mars, 2451545.0);
        $ofTheEarth = $speed->invoke(null, Body::Earth, 2451545.0);

        // The same direction with both velocities, over a vector of the real distance.
        $distance = $withTheObserversOwn->distance;
        $unit = [
            cos(deg2rad($withTheObserversOwn->latitude)) * cos(deg2rad($withTheObserversOwn->longitude)) * $distance,
            cos(deg2rad($withTheObserversOwn->latitude)) * sin(deg2rad($withTheObserversOwn->longitude)) * $distance,
            sin(deg2rad($withTheObserversOwn->latitude)) * $distance,
        ];

        $right = $aberrate->invoke(null, $unit, $distance, $ofMars);
        $wrong = $aberrate->invoke(null, $unit, $distance, $ofTheEarth);

        $this->assertEqualsWithDelta(29.8, $this->angleBetweenInArcseconds($right, $wrong), 0.5);

        // And the two velocities really are different: Mars moves slower than the Earth.
        $modulus = fn (array $v): float => sqrt($v[0] ** 2 + $v[1] ** 2 + $v[2] ** 2);

        $this->assertLessThan($modulus($ofTheEarth), $modulus($ofMars));
    }

    /**
     * From the Earth and from the Sun it delegates to the usual geocentric and heliocentric
     * paths, and this demands it: **two definitions of the same thing is a place where they can
     * diverge**, and one of the two raises every chart on the site.
     */
    public function test_from_the_earth_and_from_the_sun_they_are_the_usual_ones(): void
    {
        foreach ([Body::Mars, Body::Moon, Body::Pluto] as $body) {
            $fromTheEarth = Ephemeris::planetocentric($body, Body::Earth, 2451545.0);
            $geocentric = Ephemeris::position($body, 2451545.0);

            $this->assertSame($geocentric->longitude, $fromTheEarth->longitude, $body->value);
            $this->assertSame($geocentric->latitude, $fromTheEarth->latitude, $body->value);
            $this->assertSame($geocentric->distance, $fromTheEarth->distance, $body->value);
            $this->assertSame($geocentric->speed, $fromTheEarth->speed, $body->value);

            $fromTheSun = Ephemeris::planetocentric($body, Body::Sun, 2451545.0);
            $heliocentric = Ephemeris::heliocentric($body, 2451545.0);

            $this->assertSame($heliocentric->longitude, $fromTheSun->longitude, $body->value);
            $this->assertSame($heliocentric->distance, $fromTheSun->distance, $body->value);
        }
    }

    /**
     * From another planet the Moon stops being a special case, and it is worth recording because
     * in geocentric it has two exceptions written into it.
     *
     * There it carries no annual aberration because it orbits WITH us and we share a velocity.
     * From Mars it shares nothing, so it comes in through everybody's path, and it shows: the
     * Moon and the Earth seen from Mars do not fall in the same place, they separate by as much
     * as the Moon is from the Earth seen from there.
     */
    public function test_from_another_planet_the_moon_is_not_a_special_case(): void
    {
        $moon = Ephemeris::planetocentric(Body::Moon, Body::Mars, 2451545.0);
        $earth = Ephemeris::planetocentric(Body::Earth, Body::Mars, 2451545.0);

        $separation = $this->differenceInArcseconds($moon->longitude, $earth->longitude) / 3600.0;

        // The Moon is 0.0026 AU from the Earth and both are 1.85 AU from Mars: the angle they
        // subtend cannot be more than a few tenths of a degree, nor can it be zero.
        $this->assertGreaterThan(0.01, $separation);
        $this->assertLessThan(0.2, $separation);

        // And the distances separate too, in the order of the Earth-Moon distance.
        $this->assertGreaterThan(1.0e-5, abs($moon->distance - $earth->distance));
        $this->assertLessThan(3.0e-3, abs($moon->distance - $earth->distance));
    }

    public function test_a_body_is_not_seen_from_itself_and_a_node_is_no_observatory(): void
    {
        foreach ([[Body::Mars, Body::Mars], [Body::Sun, Body::Sun]] as [$body, $centre]) {
            try {
                Ephemeris::planetocentric($body, $centre, 2451545.0);
                $this->fail($body->name().' seen from itself should throw');
            } catch (LogicException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }

        // A node is not a body: it is neither seen nor looked at from.
        foreach ([[Body::Mars, Body::TrueNode], [Body::MeanNode, Body::Mars]] as [$body, $centre]) {
            $this->expectException(LogicException::class);

            Ephemeris::planetocentric($body, $centre, 2451545.0);
        }
    }

    /**
     * The difference between two longitudes in arcseconds, folded to the shorter arc.
     * Subtracting them outright fails between 359 and 1 degree, which are two apart and not
     * three hundred and fifty eight.
     */
    private function differenceInArcseconds(float $one, float $other): float
    {
        return abs(fmod($one - $other + 540.0, 360.0) - 180.0) * 3600.0;
    }

    /**
     * From the true ecliptic of the date to J2000's: the nutation is removed and it is precessed.
     * It is the same path `NodesAndApsidesTest` takes to compare elements against Horizons.
     */
    private function toJ2000Ecliptic(float $longitude, float $latitude, float $jd): array
    {
        $centuries = Time::centuries($jd);
        $l = deg2rad($longitude - rad2deg(Time::nutation($centuries)[0]));
        $b = deg2rad($latitude);

        $vector = Precession::toJ2000([cos($b) * cos($l), cos($b) * sin($l), sin($b)], $centuries);
        $modulus = sqrt($vector[0] ** 2 + $vector[1] ** 2 + $vector[2] ** 2);

        return [
            fmod(rad2deg(atan2($vector[1], $vector[0])) + 360.0, 360.0),
            rad2deg(asin($vector[2] / $modulus)),
        ];
    }

    /**
     * @param array{0: float, 1: float, 2: float} $a
     * @param array{0: float, 1: float, 2: float} $b
     * @return float
     */
    private function angleBetweenInArcseconds(array $a, array $b): float
    {
        $asVector = [
            $a[1] * $b[2] - $a[2] * $b[1],
            $a[2] * $b[0] - $a[0] * $b[2],
            $a[0] * $b[1] - $a[1] * $b[0],
        ];

        return rad2deg(atan2(
            sqrt($asVector[0] ** 2 + $asVector[1] ** 2 + $asVector[2] ** 2),
            $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2]
        )) * 3600.0;
    }

    public function test_the_earth_only_exists_outside_the_geocentric_frame(): void
    {
        foreach ([2415020.5, 2451545.0, 2460676.5] as $jd) {
            $earth = Ephemeris::heliocentric(Body::Earth, $jd);
            $sun = Ephemeris::position(Body::Sun, $jd);

            $this->assertLessThan(0.01, abs(self::difference($earth->longitude, $sun->longitude + 180.0)), "Earth heliocentric on {$jd}");
            $this->assertEqualsWithDelta(-$sun->latitude, $earth->latitude, 0.01 / 3600);
            $this->assertEqualsWithDelta($sun->distance, $earth->distance, 2e-7);
            $this->assertFalse($earth->isRetrograde());
        }

        // It is in no list of the chart: it is the observer, not a body that gets read.
        $this->assertNotContains(Body::Earth, Body::classical());
        $this->assertNotContains(Body::Earth, Body::traditional());
        $this->assertNotContains(Body::Earth, Body::all());

        $this->expectException(LogicException::class);
        Ephemeris::position(Body::Earth, 2451545.0);
    }

    public function test_the_earth_has_no_topocentric_position_either(): void
    {
        $this->expectException(LogicException::class);
        Ephemeris::topocentric(Body::Earth, 2451545.0, 40.4, -3.7);
    }

    public function test_the_sun_has_no_heliocentric_position(): void
    {
        // Barycentric yes, and with a distance: in that frame it moves.
        $this->assertGreaterThan(0.0, Ephemeris::barycentric(Body::Sun, 2451545.0)->distance);

        $this->expectException(LogicException::class);
        Ephemeris::heliocentric(Body::Sun, 2451545.0);
    }

    public function test_the_lunar_points_only_exist_from_the_earth(): void
    {
        foreach (Body::lunarPoints() as $point) {
            foreach (['heliocentric', 'barycentric'] as $frame) {
                try {
                    Ephemeris::$frame($point, 2451545.0);
                    $this->fail("{$point->name()} should have no {$frame} position.");
                } catch (LogicException) {
                    $this->addToAssertionCount(1);
                }
            }

            // And in topocentric positions they carry no parallax: they are directions with no distance.
            $geo = Ephemeris::position($point, 2460676.5);
            $topo = Ephemeris::topocentric($point, 2460676.5, 40.4168, -3.7038);

            $this->assertSame($geo->longitude, $topo->longitude, $point->name());
            $this->assertSame($geo->speed, $topo->speed, $point->name());
        }
    }

    /**
     * What is compared here is above all the PARALLAX: the topocentric minus the geocentric
     * position, ours against Swiss's. That sets aside what was already known (Moshier and ELP
     * are not the same Moon: 1.3 arcseconds in 2025) from what is new, which stays at a tenth.
     *
     * The Moon's speed carries ten arcseconds a day of margin and it is not ours: the topocentric
     * speed Swiss declares departs by up to 4.5"/day from the numerical derivative of ITS OWN
     * topocentric longitude (the geocentric one stays at 0.03), with a diurnal swing. Ours is the
     * real derivative, at five minutes, and with steps of sixty, twenty and one second it does
     * not move.
     */
    public function test_the_topocentric_positions_agree_with_swiss(): void
    {
        foreach (self::SWISS_TOPOCENTRIC as [$place, $jd, $name, $lon, $lat, $dist, $vlon, $geoLon, $geoLat]) {
            [$latitude, $longitude] = self::PLACES[$place];
            $body = Body::from($name);

            $topo = Ephemeris::topocentric($body, $jd, $latitude, $longitude);
            $geo = Ephemeris::position($body, $jd);

            [$tLon, $tLat, $tKm, $tVel] = $body === Body::Moon ? [1.5, 1.0, 60.0, 10.0] : [0.3, 0.1, 700.0, 0.1];
            $where = "{$body->name()} from {$place} on {$jd}";

            $this->assertLessThan($tLon, abs(self::difference($topo->longitude, $lon)), "{$where}: longitude {$topo->longitude}, Swiss says {$lon}");
            $this->assertLessThan($tLat, abs($topo->latitude - $lat) * 3600, "{$where}: latitud");
            $this->assertLessThan($tKm, abs($topo->distance - $dist) * Equatorial::AU_KM, "{$where}: distancia");
            $this->assertLessThan($tVel, abs($topo->speed - $vlon) * 3600, "{$where}: speed {$topo->speed}, Swiss says {$vlon}");

            $parallaxLon = self::difference($topo->longitude, $geo->longitude) - self::difference($lon, $geoLon);
            $parallaxLat = (($topo->latitude - $geo->latitude) - ($lat - $geoLat)) * 3600;

            $this->assertLessThan(0.3, abs($parallaxLon), "{$where}: the parallax in longitude is off by {$parallaxLon}\" from Swiss's");
            $this->assertLessThan(0.3, abs($parallaxLat), "{$where}: the parallax in latitude is off by {$parallaxLat}\" from Swiss's");
        }
    }

    /**
     * Against the definition: the parallax is zero with the Moon at the zenith, because the
     * observer is right on the line from the centre of the Earth to the Moon, and it is almost a
     * degree with the Moon on the horizon. The place is built so that this holds: the observer's
     * latitude is the Moon's declination and their meridian is its right ascension.
     */
    public function test_the_moons_parallax_is_zero_at_the_zenith_and_almost_a_degree_at_the_horizon(): void
    {
        $jdTT = 2460676.5;
        $jdUt = Time::ut($jdTT);

        $geo = Ephemeris::position(Body::Moon, $jdTT);
        $equatorial = Horizon::equatorial($geo, $jdTT);

        $latitude = $equatorial->declination;
        $longitude = fmod($equatorial->rightAscension - Time::apparentSiderealTime($jdUt) + 540.0, 360.0) - 180.0;

        $zenith = Ephemeris::topocentric(Body::Moon, $jdTT, $latitude, $longitude);

        // What is left at the zenith is the diurnal aberration, a third of a second, which is not
        // parallax: the Earth turns under an observer looking straight up as well.
        $this->assertLessThan(0.5, self::separation($geo, $zenith), 'At the zenith there is no parallax');
        $this->assertLessThan($geo->distance, $zenith->distance, 'From the ground the Moon is one Earth radius closer');

        // A quarter turn east: the Moon is rising and the parallax is almost the horizontal one.
        $horizon = Ephemeris::topocentric(Body::Moon, $jdTT, $latitude, $longitude + 90.0);
        $parallax = self::separation($geo, $horizon);

        $this->assertGreaterThan(0.9, $parallax);
        $this->assertLessThan(1.0, $parallax);

        // And it is worth what the geometry says: the angle between the geocentric vector and that
        // same vector minus the observer's.
        $observer = Horizon::observerVector($latitude, $longitude + 90.0, 0.0, $jdUt);
        $moon = $equatorial->vector();
        $difference = [$moon[0] - $observer[0], $moon[1] - $observer[1], $moon[2] - $observer[2]];
        $cosine = ($moon[0] * $difference[0] + $moon[1] * $difference[1] + $moon[2] * $difference[2])
            / (sqrt($moon[0] ** 2 + $moon[1] ** 2 + $moon[2] ** 2) * sqrt($difference[0] ** 2 + $difference[1] ** 2 + $difference[2] ** 2));

        $this->assertEqualsWithDelta(rad2deg(acos($cosine)), $parallax, 1.0 / 3600);
    }

    /**
     * From J2000 to the true ecliptic of the date, the same as the engine does with the JPL
     * tables: precession rotation and nutation in longitude.
     */
    private static function toDate(float $x, float $y, float $z, float $jd): array
    {
        $t = Time::centuries($jd);
        $v = Precession::toDate([$x, $y, $z], $t);

        return [
            fmod(rad2deg(atan2($v[1], $v[0])) + rad2deg(Time::nutation($t)[0]) + 720.0, 360.0),
            rad2deg(atan2($v[2], hypot($v[0], $v[1]))),
            sqrt($x * $x + $y * $y + $z * $z),
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private static function vector(float $longitude, float $latitude, float $distance): array
    {
        $l = deg2rad($longitude);
        $b = deg2rad($latitude);

        return [$distance * cos($b) * cos($l), $distance * cos($b) * sin($l), $distance * sin($b)];
    }

    /**
     * Difference of longitudes in arcseconds, knowing that 359 and 1 are two degrees apart.
     */
    private static function difference(float $a, float $b): float
    {
        return (fmod($a - $b + 540.0, 360.0) - 180.0) * 3600.0;
    }

    /**
     * Angle between two positions, in degrees, through the dot product of their directions.
     */
    private static function separation(Position $a, Position $b): float
    {
        $u = self::vector($a->longitude, $a->latitude, 1.0);
        $v = self::vector($b->longitude, $b->latitude, 1.0);

        $cross = sqrt(($u[1] * $v[2] - $u[2] * $v[1]) ** 2 + ($u[2] * $v[0] - $u[0] * $v[2]) ** 2 + ($u[0] * $v[1] - $u[1] * $v[0]) ** 2);

        return rad2deg(atan2($cross, $u[0] * $v[0] + $u[1] * $v[1] + $u[2] * $v[2]));
    }
}
