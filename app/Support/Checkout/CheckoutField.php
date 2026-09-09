<?php

namespace App\Support\Checkout;

use App\Support\Orders\CapturedAnswer;
use Illuminate\View\ComponentAttributeBag;

/**
 * One thing a Checkout asks a Customer for.
 *
 * A field is whatever it is asked about paired with what a Merchant decides
 * about it: whether it is asked at all, how it is worded, what it hints at,
 * what it explains, and whether an answer is compulsory. The built-ins the
 * system knows by name and the Merchant's own are both this, sit in one ordered
 * list, and are presented and validated alike (ADR-0006).
 *
 * Asked and compulsory are two flags, not one. A field switched off keeps its
 * rules for when it comes back, and `required` on a field nobody is asked
 * decides nothing.
 *
 * One property differs between the two kinds, and it is the only one a caller
 * ever needs: where the answer goes. A built-in's goes to the column that has
 * always held it; a Merchant's own goes to the Order's snapshot, with its label
 * and type recorded beside the value. `snapshotsAnswer()` is that property, and
 * nothing else here branches on which kind of field it is holding.
 *
 * What the Merchant decided is a property; what the kind of field dictates is a
 * method. Reading `$field->label` and calling `$field->options()` in one breath
 * is that split, not an inconsistency.
 */
abstract class CheckoutField
{
    /**
     * How much of a row this question takes on a wide screen.
     *
     * Not a style token, because it is not a property anything declares: the
     * form is a grid and this says how many of its columns one question spans,
     * which the base stylesheet already has a flat class for. Nothing is
     * generated per field here either.
     *
     * A promise about wide screens only — below the breakpoint every question
     * is full width and there is no way to say otherwise. See `FieldWidth`.
     */
    public readonly FieldWidth $width;

    public function __construct(
        public readonly string $label,
        public readonly bool $enabled,
        public readonly bool $required,
        public readonly ?string $placeholder = null,
        public readonly ?string $help = null,
        ?FieldWidth $width = null,
    ) {
        $this->width = $width ?? FieldWidth::Full;
    }

    /**
     * What the answer is named in the submission, and — for a built-in — the
     * column it is written to.
     */
    abstract public function key(): string;

    abstract public function type(): CheckoutFieldType;

    /**
     * What a choice is made between, empty for a control that is not a choice.
     *
     * @return list<FieldOption>
     */
    abstract public function options(): array;

    /**
     * Whether this field's answer goes to the Order's snapshot rather than to a
     * column of its own. See ADR-0006: storage is a property of a field, not a
     * second system with its own list.
     */
    abstract public function snapshotsAnswer(): bool;

    /**
     * The stored shape carries a `built_in` flag saying which kind of field
     * wrote the row.
     *
     * Derived here on every read and never trusted on the way back in — the key
     * is what decides which kind a stored row is. It is written because the
     * Checkout tab has to tell the two kinds apart, and the only other thing it
     * could ask is the key box, which is a box a Merchant types in: a question
     * of their own that they happen to key `district` would otherwise turn into
     * a row they could neither correct nor delete, and so could never save past.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    /**
     * @return array<string, string>
     */
    abstract protected function answerMessages(): array;

    /**
     * What an answer must look like beyond whether it was given at all, which
     * `rules()` decides in front of this.
     *
     * @return list<mixed>
     */
    abstract protected function answerRules(): array;

    /**
     * HTML the control always carries, whatever a Merchant worded around it.
     *
     * @return array<string, string>
     */
    abstract protected function controlHtml(): array;

    /**
     * What a Merchant has said an answer must be, beyond what the control it is
     * asked with already dictates.
     *
     * Nothing, unless the field overrides it. A built-in constrains nothing:
     * its rules are the column's, and the Config decides its wording and its
     * two flags and no more (ADR-0006).
     */
    protected function constraints(): FieldConstraints
    {
        return FieldConstraints::none();
    }

    /**
     * The longest answer this field will take whatever a Merchant asks for, or
     * nothing where the field states its own length in its rules.
     */
    protected function lengthCeiling(): ?int
    {
        return null;
    }

    /**
     * @return list<mixed>
     */
    final public function rules(): array
    {
        return [
            ...$this->presenceRules(),
            ...$this->answerRules(),
            ...$this->constraints()->rulesFor($this->type(), $this->lengthCeiling()),
        ];
    }

    /**
     * What a Customer is told when their answer is refused: what the kind of
     * field says, and what the Merchant's own constraints say on top of it.
     *
     * @return array<string, string>
     */
    final public function messages(): array
    {
        return [
            ...$this->answerMessages(),
            ...$this->constraints()->messagesFor($this->key(), $this->type(), $this->lengthCeiling()),
        ];
    }

    /**
     * The HTML the control carries: what the kind of field always says, what
     * the merchant constrained an answer to, and their hint wherever the
     * control is one that carries the hint as an attribute. A Select renders
     * theirs as its first option instead, which is the view's to do.
     *
     * The constraints are spread over the top rather than merged under,
     * because they are the half of one definition the browser enforces and the
     * rules are the other: a stepping the Merchant asked for has to beat the
     * one the control was drawn with, or the two sides stop agreeing.
     *
     * The hint is merged in rather than constructed with, because merging is
     * what escapes: the bag's own `__toString` does not, and this is the one
     * attribute here a merchant types.
     */
    final public function controlAttributes(): ComponentAttributeBag
    {
        return (new ComponentAttributeBag([
            ...$this->controlHtml(),
            ...$this->constraints()->htmlFor($this->type(), $this->lengthCeiling()),
        ]))
            ->merge(array_filter([
                'placeholder' => $this->type()->carriesPlaceholderAttribute() ? $this->placeholder : null,
            ]));
    }

    /**
     * What the visitor said, in the shape it is stored in.
     *
     * A blank is a null throughout, because "we never asked" and "they had
     * nothing to say" are the two states worth telling apart and a blank is
     * neither. A checkbox is the exception: it is absent from a form post when
     * it is not ticked, so an untouched box is a false rather than a nothing.
     */
    final public function answerFrom(mixed $raw): string|bool|null
    {
        if ($this->type() === CheckoutFieldType::Checkbox) {
            return filter_var($raw, FILTER_VALIDATE_BOOL);
        }

        $answer = is_scalar($raw) ? trim((string) $raw) : '';

        return $answer === '' ? null : $answer;
    }

    /**
     * The answer as an Order records it: the question as it was worded and the
     * control it was asked with, beside what was said.
     *
     * Everything the Order needs to be read afterwards is copied in here, so
     * relabelling the field, deleting it or force-deleting the Page changes
     * nothing about what a past Order says.
     */
    final public function capture(string|bool|null $answer): CapturedAnswer
    {
        return new CapturedAnswer(
            key: $this->key(),
            label: $this->label,
            type: $this->type(),
            value: $answer,
            optionLabel: $this->optionLabelFor($answer),
        );
    }

    /**
     * Whether an answer was given at all.
     *
     * A compulsory checkbox is `accepted` rather than `required`: an unticked
     * box is absent from a form post, so `required` would pass for a box the
     * Customer refused. Both rules are implicit, so both fire on an answer that
     * never arrived.
     *
     * `nullable` rather than simply leaving the rules off: a blank answer
     * reaches validation as null, and without it an optional District left
     * alone would be checked for membership of a list it is not in.
     *
     * @return list<string>
     */
    private function presenceRules(): array
    {
        if ($this->type() === CheckoutFieldType::Checkbox) {
            return [$this->required ? 'accepted' : 'nullable'];
        }

        return [$this->required ? 'required' : 'nullable'];
    }

    /**
     * The wording the chosen option carried at Capture, where the answer was a
     * choice between listed ones.
     */
    private function optionLabelFor(string|bool|null $answer): ?string
    {
        if (! $this->type()->hasOptions() || ! is_string($answer)) {
            return null;
        }

        foreach ($this->options() as $option) {
            if ($option->value === $answer) {
                return $option->label;
            }
        }

        return null;
    }

    /**
     * Wording a Merchant typed, or null where they typed nothing.
     *
     * Whitespace collapses to null so an accidental space does not render an
     * empty help paragraph or a hint made of one blank character.
     */
    protected static function wording(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
