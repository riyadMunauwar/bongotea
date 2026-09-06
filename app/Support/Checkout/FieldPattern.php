<?php

namespace App\Support\Checkout;

/**
 * The shapes an answer may be held to, by name.
 *
 * A closed list rather than a box a Merchant types an expression into. A
 * malformed rule does not surface in the admin as an error — it surfaces as a
 * conversion rate going to zero on a Page that is spending money — and an
 * expression nobody vetted runs on a public endpoint the Merchant cannot test
 * against. So the vocabulary is fixed here, in front of a reviewer, and the
 * Checkout tab offers it as a list to pick from.
 *
 * One expression serves both sides. The browser is handed it as a `pattern`,
 * which it anchors and compiles for itself; the server is handed the same
 * source anchored explicitly. Neither is written twice, so a Customer cannot be
 * blocked from typing something a Capture would have accepted.
 *
 * Every expression here is a plain character class, which is what keeps that
 * promise true across two regular-expression engines. It uses no backtracking
 * construct, so it cannot run long on an answer a stranger posts; it escapes
 * the characters a browser's newer `v` mode reserves inside a class; and it
 * matches Unicode letters and marks rather than ASCII, because a Customer
 * writing their name in Bangla is the ordinary case here and not the exception.
 */
enum FieldPattern: string
{
    case Letters = 'letters';
    case Digits = 'digits';
    case LettersAndDigits = 'letters_and_digits';

    /**
     * How the Checkout tab words each shape in the list a Merchant picks from.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Letters->value => 'Letters only',
            self::Digits->value => 'Digits only',
            self::LettersAndDigits->value => 'Letters and digits only',
        ];
    }

    /**
     * The one definition, unanchored: what a whole answer has to be made of.
     *
     * Spaces, apostrophes and hyphens ride along with the letters because a
     * name and a street are written with them, and a shape that refused
     * "Nur-e-Alam" would be refusing real Customers rather than bad answers.
     */
    public function expression(): string
    {
        return match ($this) {
            self::Letters => "[\p{L}\p{M}\s'\-]+",
            self::Digits => '[0-9]+',
            self::LettersAndDigits => "[\p{L}\p{M}0-9\s'\-]+",
        };
    }

    /**
     * The browser's half. A `pattern` is anchored by the browser itself and
     * compiled against the whole value, so it is handed the source as it is.
     */
    public function attribute(): string
    {
        return $this->expression();
    }

    /**
     * The server's half: the same source, anchored here because PCRE does not
     * anchor for us, and grouped so that a future expression with an
     * alternation in it cannot anchor only its first branch.
     */
    public function rule(): string
    {
        return 'regex:/^(?:'.$this->expression().')$/u';
    }

    /**
     * What a Customer is told when their answer is not this shape.
     *
     * Said as what to do rather than as what was wrong: a Customer mid-purchase
     * on a phone needs the next move, not a diagnosis.
     */
    public function refusalMessage(): string
    {
        return match ($this) {
            self::Letters => 'Please answer with letters only.',
            self::Digits => 'Please answer with digits only.',
            self::LettersAndDigits => 'Please answer with letters and digits only.',
        };
    }
}
