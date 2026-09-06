<?php

namespace App\Support\Checkout;

use Illuminate\Contracts\Support\Arrayable;

/**
 * What a Merchant has said an answer to one of their own questions must be.
 *
 * A bounded vocabulary and nothing else: a shortest and a longest for writing,
 * a smallest and a largest for a number, whole numbers, and a shape picked from
 * `FieldPattern`. There is no box anywhere that takes a rule string or an
 * expression of the Merchant's, here or on the Checkout tab, and that is the
 * point of the class rather than an omission from it.
 *
 * One definition, two renderings. `htmlFor()` and `rulesFor()` read the same
 * resolved figures out of `effective()`, so the attribute a browser enforces
 * and the rule a Capture checks cannot say different things — a Customer is
 * never blocked from typing something the server would have taken, and never
 * has something the browser allowed refused for a reason the form never showed.
 *
 * Held sparse, like `StyleTokens`: only what the Merchant actually set. A gap
 * is "anything will do", which is what nearly every question holds.
 *
 * @implements Arrayable<string, mixed>
 */
class FieldConstraints implements Arrayable
{
    private function __construct(
        private readonly ?int $minLength,
        private readonly ?int $maxLength,
        private readonly ?FieldPattern $pattern,

        /**
         * Kept as the Merchant wrote them rather than as floats. A number
         * question is answered in whatever units the Merchant asked in, "2.5"
         * is a bound they may reasonably set, and a figure that makes the round
         * trip unchanged is one the attribute and the rule cannot disagree
         * about.
         */
        private readonly ?string $min,
        private readonly ?string $max,
        private readonly bool $wholeNumbers,
    ) {}

    /**
     * Nothing said, which is what every question starts as and what a field the
     * system knows by name always holds — a built-in's rules belong to the
     * column waiting for the answer rather than to the Merchant (ADR-0006).
     */
    public static function none(): self
    {
        return new self(null, null, null, null, null, false);
    }

    /**
     * Rebuild a constraint set from its stored shape.
     *
     * Total on purpose, like everything a Checkout Config is read from. A
     * length that is not a whole number of characters, a shape that is not in
     * the vocabulary — an expression written in by some other route very much
     * included — and a bound that is not a number are all dropped. The worst a
     * Config written outside the Checkout tab can do is constrain an answer
     * less than it meant to, which is a question that takes more than the
     * Merchant wanted rather than a landing page answering paid traffic with a
     * 500.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $pattern = $stored['pattern'] ?? null;

        return new self(
            self::length($stored['min_length'] ?? null),
            self::length($stored['max_length'] ?? null),
            is_string($pattern) ? FieldPattern::tryFrom($pattern) : null,
            self::number($stored['min'] ?? null),
            self::number($stored['max'] ?? null),
            (bool) ($stored['whole_numbers'] ?? false),
        );
    }

    /**
     * The browser's half of the vocabulary, for the control this question is
     * asked with.
     *
     * A paragraph gets its lengths and not its shape, because HTML has no
     * `pattern` on a textarea. That asymmetry only ever fails in the safe
     * direction: the browser stops nothing it should have allowed, and the
     * Capture still refuses what the Merchant said it would, with the refusal
     * shown against the question that earned it.
     *
     * @return array<string, string>
     */
    public function htmlFor(CheckoutFieldType $type, ?int $ceiling = null): array
    {
        $effective = $this->effective($type, $ceiling);

        return array_map(strval(...), array_filter([
            'minlength' => $effective['min_length'],
            'maxlength' => $effective['max_length'],
            'pattern' => $type->carriesPatternAttribute() ? $effective['pattern']?->attribute() : null,
            'min' => $effective['min'],
            'max' => $effective['max'],

            /**
             * Stepping is the whole-numbers switch as a browser states it, and
             * the keyboard follows it: a question that takes no decimal point
             * should not open one on a phone.
             */
            'step' => $effective['whole_numbers'] ? '1' : null,
            'inputmode' => $effective['whole_numbers'] ? 'numeric' : null,
        ], fn (mixed $value): bool => $value !== null));
    }

    /**
     * The server's half, off the same figures.
     *
     * @return list<string>
     */
    public function rulesFor(CheckoutFieldType $type, ?int $ceiling = null): array
    {
        $effective = $this->effective($type, $ceiling);

        $rules = [];

        if ($effective['whole_numbers']) {
            $rules[] = 'integer';
        }

        /**
         * `min` and `max` are one pair of rules counting characters or
         * comparing figures by what stands beside them — `string` on a written
         * answer, `numeric` on a number — which is why a question is only ever
         * given the pair belonging to the control it is asked with.
         */
        foreach (['min' => 'min_length', 'max' => 'max_length'] as $rule => $length) {
            if ($effective[$length] !== null) {
                $rules[] = $rule.':'.$effective[$length];
            }
        }

        foreach (['min', 'max'] as $bound) {
            if ($effective[$bound] !== null) {
                $rules[] = $bound.':'.$effective[$bound];
            }
        }

        if ($effective['pattern'] !== null) {
            $rules[] = $effective['pattern']->rule();
        }

        return $rules;
    }

    /**
     * What a Customer is told when one of these refuses their answer.
     *
     * Keyed off the question's own key so a message cannot outlive it, worded
     * by what the bound means on the control it was set on — a longest on
     * writing counts characters and a largest on a number compares figures, and
     * telling a Customer the wrong one of those is worse than telling them
     * nothing — and said only where the matching rule is one this set actually
     * produced, so a field constraining nothing keeps the wording that belongs
     * to whatever else is checking it.
     *
     * @return array<string, string>
     */
    public function messagesFor(string $key, CheckoutFieldType $type, ?int $ceiling = null): array
    {
        $effective = $this->effective($type, $ceiling);

        if ($type->holdsNumber()) {
            return array_filter([
                $key.'.integer' => $effective['whole_numbers'] ? 'Please answer with a whole number.' : null,
                $key.'.min' => $effective['min'] === null ? null : 'Please answer with :min or more.',
                $key.'.max' => $effective['max'] === null ? null : 'Please answer with :max or less.',
            ], fn (?string $message): bool => $message !== null);
        }

        return array_filter([
            $key.'.min' => $effective['min_length'] === null ? null : 'Please write at least :min characters.',
            $key.'.max' => $effective['max_length'] === null ? null : 'That answer is longer than we can take.',
            $key.'.regex' => $effective['pattern']?->refusalMessage(),
        ], fn (?string $message): bool => $message !== null);
    }

    /**
     * Why a Merchant may not hold a written answer to this pair of lengths, in
     * the words the Checkout tab says it in — or nothing, where they may.
     *
     * Worded apart from the pair below for the reason `messagesFor()` words a
     * refusal apart: a length counts characters and a bound compares figures,
     * and a box labelled "Shortest answer" told it is larger than the largest
     * is being told about a control it is not.
     */
    public static function lengthRefusal(mixed $shortest, mixed $longest): ?string
    {
        return self::exceeds($shortest, $longest)
            ? 'A shortest answer longer than the longest is a question nobody can answer.'
            : null;
    }

    /**
     * Why a Merchant may not bound a number by this pair, in the words the
     * Checkout tab says it in — or nothing, where they may.
     *
     * Both are asked of the constraint set rather than worded in the admin, so
     * the tab that refuses a pair and the read that drops one cannot disagree
     * about which pairs are impossible to answer.
     */
    public static function boundsRefusal(mixed $smallest, mixed $largest): ?string
    {
        return self::exceeds($smallest, $largest)
            ? 'A smallest larger than the largest is a question nobody can answer.'
            : null;
    }

    /**
     * Whether this is a pair nothing could satisfy: two figures, the first
     * above the second. A pair with a gap in it bounds nothing yet and is not
     * something to refuse a Merchant over mid-edit.
     */
    private static function exceeds(mixed $smaller, mixed $larger): bool
    {
        $smaller = self::number($smaller);
        $larger = self::number($larger);

        return $smaller !== null && $larger !== null && (float) $smaller > (float) $larger;
    }

    /**
     * The sparse stored shape: what the Merchant set, and nothing at all for
     * what they left alone.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'min_length' => $this->minLength,
            'max_length' => $this->maxLength,
            'pattern' => $this->pattern?->value,
            'min' => $this->min,
            'max' => $this->max,
            'whole_numbers' => $this->wholeNumbers ?: null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * The figures both renderings are built from, for the control this question
     * is asked with.
     *
     * Three things happen here and nowhere else, which is what keeps the
     * browser and the server saying one thing.
     *
     * A constraint belonging to another kind of control is dropped: a shortest
     * length set while the question was a paragraph, and left behind when the
     * Merchant made it a dropdown, decides nothing.
     *
     * The longest is capped at what the control will hold whatever the Merchant
     * asked for, and that cap stands in wherever they asked for nothing — so
     * the ceiling is stated once rather than once in an attribute and once in a
     * rule that have to be kept in step.
     *
     * And a smallest above the largest is dropped rather than enforced. The tab
     * refuses that pair outright, so it can only arrive from some other route,
     * and the choice there is between a question nobody can answer and one that
     * takes a little more than was intended.
     *
     * @return array{min_length: int|null, max_length: int|null, pattern: FieldPattern|null, min: string|null, max: string|null, whole_numbers: bool}
     */
    private function effective(CheckoutFieldType $type, ?int $ceiling): array
    {
        $writing = $type->holdsWriting();

        $minLength = $writing ? $this->minLength : null;
        $maxLength = $writing ? ($this->maxLength ?? $ceiling) : null;

        if ($maxLength !== null && $ceiling !== null) {
            $maxLength = min($maxLength, $ceiling);
        }

        if ($minLength !== null && $maxLength !== null && $minLength > $maxLength) {
            $minLength = null;
        }

        $number = $type->holdsNumber();

        $min = $number ? $this->min : null;
        $max = $number ? $this->max : null;

        if ($min !== null && $max !== null && (float) $min > (float) $max) {
            $min = null;
        }

        return [
            'min_length' => $minLength,
            'max_length' => $maxLength,
            'pattern' => $writing ? $this->pattern : null,
            'min' => $min,
            'max' => $max,
            'whole_numbers' => $number && $this->wholeNumbers,
        ];
    }

    /**
     * A length as a whole number of characters, or nothing where what was
     * stored is not one.
     *
     * Zero goes with the negatives: a shortest of nothing is what every
     * question already allows, and a longest of nothing is a box no answer can
     * be typed into.
     */
    private static function length(mixed $value): ?int
    {
        if (! is_numeric($value) || (float) $value !== (float) (int) $value || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    /**
     * A bound on a number, in the Merchant's own writing of it, or nothing
     * where what was stored is not a number.
     */
    private static function number(mixed $value): ?string
    {
        if (is_bool($value) || ! is_scalar($value) || ! is_numeric(trim((string) $value))) {
            return null;
        }

        return trim((string) $value);
    }
}
