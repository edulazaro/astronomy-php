<?php

namespace Astronomy;

use InvalidArgumentException;

/**
 * One of the comets the JPL has, by its designation: «1P», «73P-B», «C/1995 O1».
 *
 * There are 4,076 of them, 610 numbered periodic ones, counted on 14 September 2026.
 *
 * It has nothing to do with the aspect figure of the same name, which is a grand trine
 * with a fourth point opposite one of its vertices.
 */
final readonly class Comet implements DownloadableBody
{
    /**
     * @param string $designation
     */
    private function __construct(
        /** The designation without the name: «1P» and not «1P/Halley». */
        public string $designation,
    ) {}

    /**
     * A comet by its designation.
     *
     * The ones for numbered periodic comets are accepted, with their fragment if they have one
     * («1P», «73P-B»), and the provisional ones («C/1995 O1», «P/2010 A2», «C/2019 Y4-B»). A name
     * after the slash, as in «1P/Halley», is stripped; a bare name, «Halley», is not accepted,
     * because turning it into a designation means asking the JPL, just like with the asteroids. The
     * shape is validated because it ends up in the name of a file and in the question to the JPL.
     *
     * @param string $designation
     * @return self
     *
     * @throws InvalidArgumentException
     */
    public static function designation(string $designation): self
    {
        $designation = strtoupper((string) preg_replace('/\s+/', ' ', trim($designation)));

        if (preg_match('/^(\d+[PDI](-[A-Z]{1,2})?)\/.+$/', $designation, $parts)) {
            $designation = $parts[1];
        }

        if (! preg_match('/^(\d+[PDI](-[A-Z]{1,2})?|[PCDXAI]\/-?\d{1,4} [A-Z]{1,2}\d{0,3}(-[A-Z]{1,2})?)$/', $designation)) {
            throw new InvalidArgumentException("«{$designation}» does not have the shape of a comet designation, like «1P», «73P-B» or «C/1995 O1». Names are not accepted.");
        }

        return new self($designation);
    }

    /**
     * `CAP` and `NOFRAG` are not decoration, and both are measured against Horizons.
     *
     * A periodic comet has one orbital solution per apparition, and without `CAP` Horizons answers
     * with the list of apparitions instead of with positions; `CAP` picks the last apparition before
     * today. And a split comet has fragments that begin alike: «73P-B» with `CAP` and without
     * `NOFRAG` returns 26 matches, fragment B and its subfragments BA to BY, and no position at all.
     * With both of them set, 1P, 73P-B, C/1995 O1, C/2019 Y4-B and P/2010 A2 (which turns out to be
     * 354P, numbered later) answer with an ephemeris, and the ones that do not need them are not
     * changed at all.
     *
     * For an old apparition the last one is not the right solution, and picking the one from that
     * epoch is work for when the comets get downloaded, not for this identifier.
     *
     * @return string
     */
    public function horizonsId(): string
    {
        return "DES={$this->designation};CAP;NOFRAG;";
    }

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->designation;
    }

    /**
     * @return DownloadableGroup
     */
    public function group(): DownloadableGroup
    {
        return DownloadableGroup::Comets;
    }

    /**
     * @param float $jdTT
     * @return string
     */
    public function file(float $jdTT): string
    {
        return Downloadables::file($this->group(), str_replace(['/', ' '], '_', $this->designation), $jdTT);
    }
}
