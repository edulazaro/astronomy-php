<?php

namespace Astronomy;

/**
 * Two bodies at the same height above the celestial equator.
 */
readonly class FoundParallel
{
    /**
     * A parallel or contraparallel of declination between two points.
     */
    public function __construct(
        public Position $a,
        public Position $b,
        public float $declinationA,
        public float $declinationB,
        public float $orb,
        /** True if they are on the same side of the equator; false if on opposite sides. */
        public bool $parallel,
    ) {}

    /**
     * @return string
     */
    public function name(): string
    {
return $this->parallel ? 'parallel' : 'contraparallel';
    }

    /**
     * The bodies are asked for their name IN THE SAME LOCALE as the sentence around them.
     *
     * Asking `name()` with no argument gave English by default, so this line came out as «Sun
     * paralelo Moon»: half English inside a Spanish sentence, on the chart sheet and in the text
     * handed to the interpreter. Nothing failed and no test looks at it.
     *
     * @return string
     */
    public function summary(): string
    {
        return sprintf(
            '%s %s %s · %s and %s · orb %s',
            $this->a->body->name(),
            $this->name(),
            $this->b->body->name(),
            self::inDegrees($this->declinationA),
            self::inDegrees($this->declinationB),
            self::inDegrees($this->orb)
        );
    }

    /**
     * @param float $degrees
     * @return string
     */
    private static function inDegrees(float $degrees): string
    {
        $sign = $degrees < 0 ? '-' : '';
        $absolute = abs($degrees);
        $whole = (int) floor($absolute);

        return sprintf("%s%d° %02d'", $sign, $whole, round(($absolute - $whole) * 60));
    }
}
