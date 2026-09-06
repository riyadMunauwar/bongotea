<?php

namespace App\Support\Checkout;

/**
 * A Checkout Field the system knows by name.
 *
 * Everything immutable about it comes from its `BuiltInField`: the key, the
 * control, the markup and the rules. A Config decides only the wording and the
 * two flags, because a column is waiting for the answer and nothing else may be
 * written there (ADR-0006).
 *
 * It answers for its built-in rather than handing it out, so a form and a rule
 * set are written against "a field" and never against what kind of field it
 * happens to be.
 */
class BuiltInCheckoutField extends CheckoutField
{
    public function __construct(
        public readonly BuiltInField $builtIn,
        string $label,
        bool $enabled,
        bool $required,
        ?string $placeholder = null,
        ?string $help = null,
        ?StyleTokens $styleTokens = null,
    ) {
        parent::__construct($label, $enabled, $required, $placeholder, $help, $styleTokens);
    }

    public static function default(BuiltInField $builtIn): self
    {
        return new self(
            $builtIn,
            label: $builtIn->defaultLabel(),
            enabled: $builtIn->defaultsToEnabled(),
            required: $builtIn->defaultsToRequired(),
            placeholder: $builtIn->defaultPlaceholder(),
        );
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(BuiltInField $builtIn, array $stored): self
    {
        $label = $stored['label'] ?? null;

        return new self(
            $builtIn,
            /** A blank label would render a form nobody can read. */
            label: is_string($label) && trim($label) !== '' ? $label : $builtIn->defaultLabel(),
            /**
             * A mandatory field is on however it was stored. Phone has no
             * off-switch on the Checkout tab, and this is what makes that true
             * of a Config edited by any other route as well — a Checkout that
             * stopped asking for a phone number would take Orders nobody could
             * call. Absent means "written before the switch existed", which is
             * the field's own default and not "off".
             */
            enabled: $builtIn->isMandatory() || (bool) ($stored['enabled'] ?? $builtIn->defaultsToEnabled()),
            /**
             * Independent of `enabled`, and kept across a switch off and back
             * on: a Merchant trying a shorter form does not lose what they
             * configured behind it. `required` on a field nobody is asked is
             * simply inert.
             */
            required: $builtIn->isMandatory() || (bool) ($stored['required'] ?? $builtIn->defaultsToRequired()),
            /**
             * Absent and blank mean different things. A Config written before
             * placeholders were configurable has no such key, and must keep
             * rendering the wording the field has always carried; a Merchant who
             * empties the box means they want no hint at all.
             */
            placeholder: array_key_exists('placeholder', $stored)
                ? self::wording($stored['placeholder'])
                : $builtIn->defaultPlaceholder(),
            help: self::wording($stored['help'] ?? null),
            /**
             * A built-in is restyled like any other field. Where an answer goes
             * is what a built-in has no say over; how the question looks is not
             * that, and the one a Merchant most often wants picked out is the
             * phone number they are not allowed to switch off.
             */
            styleTokens: StyleTokens::fromArray((array) ($stored['style_tokens'] ?? [])),
        );
    }

    public function key(): string
    {
        return $this->builtIn->value;
    }

    /**
     * Never what a stored Config claims. A type is what a column can hold, so
     * it is read off the built-in even though the stored shape carries one for
     * the Checkout tab's benefit.
     */
    public function type(): CheckoutFieldType
    {
        return $this->builtIn->type();
    }

    /**
     * @return list<FieldOption>
     */
    public function options(): array
    {
        return $this->builtIn->options();
    }

    /**
     * A column has always held this answer, and moving it into the snapshot to
     * buy uniformity would be moving settled data into a serialised blob.
     */
    public function snapshotsAnswer(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->builtIn->messages();
    }

    /**
     * The stored shape, which is also the shape the Checkout tab edits a row at
     * a time. One list on screen means one row shape underneath it, so `type`
     * and `options` are both here — the first stating what the built-in
     * dictates and the Config ignores, the second always empty, because a
     * District's choices are installation configuration and no built-in has
     * choices a Merchant may author.
     *
     * @return array{key: string, label: string, type: string, built_in: bool, enabled: bool, required: bool, placeholder: string|null, help: string|null, style_tokens: array<string, string>, options: list<array{value: string, label: string}>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'label' => $this->label,
            'type' => $this->type()->value,
            'built_in' => true,
            'enabled' => $this->enabled,
            'required' => $this->required,
            'placeholder' => $this->placeholder,
            'help' => $this->help,
            'style_tokens' => $this->styleTokens->toArray(),
            'options' => [],
        ];
    }

    /**
     * @return list<mixed>
     */
    protected function answerRules(): array
    {
        return $this->builtIn->rules();
    }

    /**
     * @return array<string, string>
     */
    protected function controlHtml(): array
    {
        return $this->builtIn->controlAttributes();
    }
}
