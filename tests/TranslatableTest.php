<?php

namespace Astronomy\Tests;

use Astronomy\Ayanamsa;
use Astronomy\Body;
use Astronomy\DownloadableGroup;
use Astronomy\EclipseType;
use Astronomy\HeliacalEvent;
use Astronomy\HouseSystem;
use Astronomy\Limb;
use Astronomy\MoonPhase;
use Astronomy\Nakshatra;
use Astronomy\Pass;
use Astronomy\PositionType;
use Astronomy\ReferenceEcliptic;
use Astronomy\Sign;
use Astronomy\Translatable;
use Astronomy\Twilight;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionEnum;

/**
 * The contract a translator depends on: a key that never moves and a name in English.
 *
 * The package carries no translations, so the only thing it owes whoever wants them is this pair.
 * These tests are what makes the promise worth anything: a key that is not a frozen slug, or two
 * cases sharing one, would break the translation of whoever indexed a file by it, silently and in
 * their code rather than in ours.
 */
class TranslatableTest extends TestCase
{
    /**
     * Everything meant to be read by a person, and how many cases each one has.
     *
     * The counts are written out on purpose: adding a case to any of these means someone has to
     * add its row to every translation file out there, so it should not slip in unnoticed.
     *
     * @return array<string, array{class-string, int}>
     */
    public static function types(): array
    {
        return [
            'bodies' => [Body::class, 42],
            'signs' => [Sign::class, 12],
            'house systems' => [HouseSystem::class, 23],
            'ayanamsas' => [Ayanamsa::class, 43],
            'eclipse types' => [EclipseType::class, 5],
            'twilights' => [Twilight::class, 3],
            'heliacal events' => [HeliacalEvent::class, 4],
            'nakshatras' => [Nakshatra::class, 27],
            'passes' => [Pass::class, 4],
            'limbs' => [Limb::class, 3],
            'position types' => [PositionType::class, 5],
            'reference ecliptics' => [ReferenceEcliptic::class, 3],
            'downloadable groups' => [DownloadableGroup::class, 3],
        ];
    }

    /**
     * @param class-string $enum
     * @param int $expected
     */
    #[DataProvider('types')]
    public function test_every_case_carries_a_key_and_an_english_name(string $enum, int $expected): void
    {
        $cases = $enum::cases();

        $this->assertCount($expected, $cases, $enum.' has gained or lost cases');

        $keys = [];

        foreach ($cases as $case) {
            $this->assertInstanceOf(Translatable::class, $case, $enum.' is meant to be translatable');

            $key = $case->key();
            $name = $case->name();

            $this->assertMatchesRegularExpression(
                '/^[a-z0-9]+(-[a-z0-9]+)*$/',
                $key,
                sprintf('%s::%s has key «%s», which is not a lower case slug', $enum, $case->name, $key)
            );

            $this->assertNotSame('', trim($name), sprintf('%s::%s has no English name', $enum, $case->name));

            $this->assertDoesNotMatchRegularExpression(
                '/[áéíóúñü]/iu',
                $name,
                sprintf('%s::%s is named «%s», which is not English', $enum, $case->name, $name)
            );

            $keys[] = $key;
        }

        $this->assertSame(
            count($keys),
            count(array_unique($keys)),
            $enum.' has two cases sharing one key, so a translation of one would answer for the other'
        );
    }

    /**
     * And the key of whatever has a slug for a value IS that value, with nothing in between.
     *
     * That is what lets an application index by the key and still read a shared link or a saved
     * row, which carry the value. If the two ever parted ways, the same thing would have two
     * identifiers and nobody would know which one to write down.
     */
    public function test_a_slug_value_is_its_own_key(): void
    {
        $checked = 0;

        foreach (self::types() as [$enum, $count]) {
            $backing = (new ReflectionEnum($enum))->getBackingType();

            if ($backing === null || (string) $backing !== 'string') {
                continue;
            }

            foreach ($enum::cases() as $case) {
                $this->assertSame($case->value, $case->key(), $enum.'::'.$case->name);
                $checked++;
            }
        }

        // Body, HouseSystem, Ayanamsa, EclipseType, HeliacalEvent and DownloadableGroup.
        $this->assertSame(120, $checked);
    }

    /**
     * What is not an enum keeps the same promise, and it is written here so it is not forgotten.
     *
     * `MoonPhase` hands out one of eight frozen keys and `Sign` returns three more for the
     * element, the modality and the polarity. None of them is an enum, so no interface reaches
     * them; the contract is the same and this is what says so.
     */
    public function test_the_loose_strings_are_keys_too(): void
    {
        $phases = ['new-moon', 'first-quarter', 'full-moon', 'last-quarter',
                   'waxing-crescent', 'waxing-gibbous', 'waning-gibbous', 'waning-crescent'];

        /* A whole lunation sampled every six hours, which visits all eight. The list above is
           then an EXPECTATION and not a note: what `MoonPhase` hands out has to be exactly
           those eight, no more and no fewer, and each of them a frozen slug.

           It used to loop over the list checking it against the regular expression, which is a
           literal written two lines up matched against a pattern: an assertion that is true by
           construction and cannot go red however the engine changes. */
        $seen = [];

        for ($i = 0; $i < 120; $i++) {
            $name = MoonPhase::at(2451545.0 + $i * 0.25)->name;

            $this->assertMatchesRegularExpression(
                '/^[a-z]+(-[a-z]+)*$/',
                $name,
                'MoonPhase returned «'.$name.'», which is not a lower case slug'
            );

            $seen[$name] = true;
        }

        sort($phases);
        $found = array_keys($seen);
        sort($found);

        $this->assertSame($phases, $found, 'MoonPhase has gained, lost or renamed a phase');

        foreach (Sign::cases() as $sign) {
            foreach ([$sign->element(), $sign->modality(), $sign->polarity()] as $key) {
                $this->assertMatchesRegularExpression('/^[a-z]+$/', $key, $sign->name);
            }
        }

        // The three of them together are twelve keys and not thirty-six: four elements, three
        // modalities and two polarities, each answering for its share of the zodiac.
        $this->assertSame(4, count(array_unique(array_map(fn (Sign $s) => $s->element(), Sign::cases()))));
        $this->assertSame(3, count(array_unique(array_map(fn (Sign $s) => $s->modality(), Sign::cases()))));
        $this->assertSame(2, count(array_unique(array_map(fn (Sign $s) => $s->polarity(), Sign::cases()))));
    }
}
