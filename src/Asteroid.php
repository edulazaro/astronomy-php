<?php

namespace Astronomy;

use InvalidArgumentException;

/**
 * Any of the asteroids the JPL has, by its number or by its designation.
 *
 * Ceres, Pallas, Juno, Vesta, Chiron and Pholus are already in `Body`, with their table in the
 * repository. This is for the rest, which are 1,563,747 counted on 14 September 2026, 895,910 of
 * them numbered.
 *
 * Not by name, and that is on purpose. «Eris» becomes 136199 by asking the JPL, and here there
 * is no network. And a name misleads in a way a number does not: there is an asteroid 1181 Lilith
 * that is not the Lilith of the chart, and a 763 Cupido and a 5731 Zeus that are not the Uranian
 * bodies.
 */
final readonly class Asteroid implements DownloadableBody
{
    /**
     * @param int|null $number
     * @param string|null $designation
     */
    private function __construct(
        /** The catalogue number, or null if it does not have one yet. */
        public ?int $number,
        /** The provisional designation, only when it has no number. */
        public ?string $designation,
    ) {}

    /**
     * A numbered asteroid: 136199 is Eris.
     *
     * @param int $number
     * @return self
     *
     * @throws InvalidArgumentException
     */
    public static function number(int $number): self
    {
        if ($number < 1) {
            throw new InvalidArgumentException("Asteroids are numbered from 1, and {$number} arrived.");
        }

        return new self($number, null);
    }

    /**
     * An asteroid without a number, by its provisional designation: «2024 YR4», «2040 P-L».
     *
     * The shape is validated because the designation ends up in the name of a file and in the query
     * to the JPL: without that, «../..» would be a path and any text a query.
     *
     * @param string $designation
     * @return self
     *
     * @throws InvalidArgumentException
     */
    public static function designation(string $designation): self
    {
        $designation = strtoupper((string) preg_replace('/\s+/', ' ', trim($designation)));

        if (ctype_digit($designation)) {
            throw new InvalidArgumentException("«{$designation}» is a catalogue number, and goes through Asteroid::number().");
        }

        if (! preg_match('/^(\d{4} [A-Z]{2}\d{0,4}|\d{4} (P-L|T-[123]))$/', $designation)) {
            throw new InvalidArgumentException("«{$designation}» does not have the shape of an asteroid designation, like «2024 YR4» or «2040 P-L». Names are not accepted: the number has to be given.");
        }

        return new self(null, $designation);
    }

    /**
     * The semicolon tells Horizons to search among the small bodies: without it, «1» is the
     * barycentre of Mercury and not Ceres. It is what `Body` already does with the tabulated ones. A
     * designation goes with `DES=`, which searches only in that column.
     *
     * @return string
     */
    public function horizonsId(): string
    {
        return $this->number !== null ? "{$this->number};" : "DES={$this->designation};";
    }

    /**
     * In parentheses if it is a number, which is how the Minor Planet Center writes it: «(136199)».
     *
     * @return string
     */
    public function name(): string
    {
        return $this->number !== null ? "({$this->number})" : (string) $this->designation;
    }

    /**
     * @return DownloadableGroup
     */
    public function group(): DownloadableGroup
    {
        return DownloadableGroup::Asteroids;
    }

    /**
     * @param float $jdTT
     * @return string
     */
    public function file(float $jdTT): string
    {
        $key = $this->number !== null ? (string) $this->number : str_replace(' ', '_', (string) $this->designation);

        return Downloadables::file($this->group(), $key, $jdTT);
    }
}
