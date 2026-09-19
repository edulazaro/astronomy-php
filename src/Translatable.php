<?php

namespace Astronomy;

/**
 * Anything this package returns that a person is meant to read.
 *
 * The package has no translations and never will. A table of names is not astronomy, and the
 * moment a library carries one language the next question is which ten and who keeps them. What
 * it owes whoever uses it is something else, and this is it: a key that never moves and a name
 * in English**, so that translating is indexing a file by the key.
 *
 * $names = ['sun' => 'Sol', 'moon' => 'Luna', …];
 *
 * echo $names[$body->key()] ?? $body->name();
 *
 * Two methods, and the difference between them is the whole point:
 *
 * - `key()` is an identifier and is frozen. Lower case, words joined by hyphens, and it does
 * not change when a case is renamed or when a value that means something else changes. It is
 * what a translation file, a cache or a saved row should be indexed by.
 * - `name()` is English and may be reworded. It is there so that whoever does not translate
 * still gets something readable, and so that the two languages never live in the same place.
 *
 * Why an interface and not a convention. Before this, the identifier a translator had to use
 * came in four different shapes depending on the type: a slug in `value` for six of the enums, a
 * number that meant something else for four more (the index of a sign, the degrees of a
 * twilight), nothing at all for the four that carry no value, and a plain string for what is not
 * an enum. Nothing said which one to pick, so the one application that did it picked both: of its
 * 231 rows, 132 were indexed by the name of the case, which moves the day somebody renames it. It
 * did move, and it cost 66 keys rewritten by hand.
 *
 * What is NOT here, and does not need to be: `Star`, which already carries `key` and `name` as
 * properties, `MoonPhase`, whose `name` is already one of eight frozen keys (`full-moon`,
 * `waxing-gibbous`…), and `Sign::element()`, `modality()` and `polarity()`, which already return
 * keys (`fire`, `cardinal`, `active`).
 */
interface Translatable
{
    /**
     * The stable identifier, in lower case and with hyphens. It never changes.
     *
     * @return string
     */
    public function key(): string;

    /**
     * The name in English, for whoever does not translate.
     *
     * @return string
     */
    public function name(): string;
}
