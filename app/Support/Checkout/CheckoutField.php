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
     * What this one field looks like, where the Merchant wanted it to differ
     * from the rest.
     *
     * Sparse: only the tokens they redeclared for it. The view writes them onto
     * the field's own wrapper and the cascade confines them to it, so drawing
     * attention to one question costs one redeclaration and no selector — see
     * `StyleTokens`.
     */
    public readonly StyleTokens $styleTokens;

    public function __construct(
        public readonly string $label,
        public readonly bool $enabled,
        public readonly bool $required,
        public readonly ?string $placeholder = null,
        public readonly ?string $help = null,
        ?StyleTokens $styleTokens = null,
    ) {
        $this->styleTokens = $styleTokens ?? StyleTokens::none();
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
    abstract public function messages(): array;

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
     * @return list<mixed>
     */
    final public function rules(): array
    {
        return [...$this->presenceRules(), ...$this->answerRules()];
    }

    /**
     * The HTML the control carries: what the kind of field always says, plus
     * the merchant's hint wherever the control is one that carries the hint as
     * an attribute. A Select renders theirs as its first option instead, which
     * is the view's to do.
     *
     * Merged in rather than constructed with, because merging is what escapes:
     * the bag's own `__toString` does not, and this is the one attribute here a
     * merchant types.
     */
    final public function controlAttributes(): ComponentAttributeBag
    {
        return (new ComponentAttributeBag($this->controlHtml()))
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
