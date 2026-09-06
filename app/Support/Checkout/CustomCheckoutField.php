<?php

namespace App\Support\Checkout;

use Illuminate\Validation\Rule;

/**
 * A question a Merchant added to a Checkout of their own.
 *
 * It sits in the same ordered list as the built-ins and behaves the same way in
 * every respect but one: its answer goes to the Order's snapshot rather than to
 * a column (ADR-0006). That is what lets it be placed between two built-ins,
 * reordered with them, and rendered and validated by the same code.
 *
 * Unlike a built-in, its key, its type and its options are the Merchant's, so
 * all three come off the stored Config rather than out of an enum. The key is
 * its identity in a submission and in the snapshot, which is why the Checkout
 * tab refuses one that duplicates another or collides with something the
 * Capture already names.
 */
class CustomCheckoutField extends CheckoutField
{
    /**
     * What a key may look like: a name a form input and a JSON key can both
     * carry, and one a reader can tell from a built-in at a glance.
     */
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /** Public because the Checkout tab caps the box at the same figure. */
    public const KEY_MAX_LENGTH = 40;

    /**
     * The names a Capture already uses for something else.
     *
     * A question keyed `page` would arrive where the Page's own slug is read
     * from and be checked against rules meant for it, so the Checkout tab
     * refuses these the way it refuses a built-in's key. They are listed here
     * beside the pattern rather than in the admin, because a Config is editable
     * by other routes than the Checkout tab.
     *
     * This is every name the island's form posts, and it has to stay every one
     * of them: two inputs of a name arrive as the last of the pair, so a
     * question sharing one does not sit beside what it collides with — it
     * replaces it. `fingerprint` is the worst of them and the reason to check
     * this list against `checkout.blade.php` when a name is added there. A
     * Customer's typed answer arriving in place of the pricing hash fails
     * `PricingFingerprint::matches()` every time, so every Order on a Page
     * that is spending money is turned away as stale, with nothing on the
     * Checkout tab saying why (ADR-0007). Note the Merchant is steered
     * straight at it: the key is derived from the label, so a question
     * labelled "Fingerprint" asks for exactly this.
     */
    private const RESERVED_KEYS = ['page', 'fingerprint', 'selected', 'quantities', 'delivery_option'];

    /**
     * What the Merchant said an answer has to be, from the bounded vocabulary
     * and never from a rule of their own writing.
     */
    private readonly FieldConstraints $fieldConstraints;

    /**
     * @param  list<FieldOption>  $fieldOptions
     */
    public function __construct(
        private readonly string $fieldKey,
        private readonly CheckoutFieldType $fieldType,
        private readonly array $fieldOptions,
        string $label,
        bool $enabled,
        bool $required,
        ?string $placeholder = null,
        ?string $help = null,
        ?StyleTokens $styleTokens = null,
        ?FieldConstraints $constraints = null,
        ?FieldWidth $width = null,
    ) {
        $this->fieldConstraints = $constraints ?? FieldConstraints::none();

        parent::__construct($label, $enabled, $required, $placeholder, $help, $styleTokens, $width);
    }

    /**
     * Rebuild a Merchant's own field from its stored shape, or nothing where
     * there is no field to rebuild.
     *
     * Total on purpose, like everything a Checkout Config is read from: an
     * unusable key, a type nothing renders, or a choice between no options is
     * dropped rather than thrown over. Every one of them is refused on the
     * Checkout tab, so a dropped row is a Config written by some other route —
     * and a landing page answering paid traffic with a 500 costs more than one
     * asking a Merchant one question fewer than they meant.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(string $key, array $stored): ?self
    {
        if (! self::keyIsAvailable($key)) {
            return null;
        }

        $type = $stored['type'] ?? null;
        $type = is_string($type) ? CheckoutFieldType::tryFrom($type) : null;

        if ($type === null) {
            return null;
        }

        $options = self::optionsFromArray($stored);

        /** A choice between nothing is a control a Customer cannot answer. */
        if ($type->hasOptions() && $options === []) {
            return null;
        }

        return new self(
            $key,
            $type,
            $options,
            /** A blank label would render a question nobody can read. */
            label: self::wording($stored['label'] ?? null) ?? $key,
            /** Absent means on: a question a Merchant added is one they want asked. */
            enabled: (bool) ($stored['enabled'] ?? true),
            /**
             * Absent means optional. A question the system did not ask for is
             * not one to refuse a cash-on-delivery Order over unless the
             * Merchant says so.
             */
            required: (bool) ($stored['required'] ?? false),
            placeholder: self::wording($stored['placeholder'] ?? null),
            help: self::wording($stored['help'] ?? null),
            styleTokens: StyleTokens::fromArray((array) ($stored['style_tokens'] ?? [])),
            constraints: FieldConstraints::fromArray((array) ($stored['constraints'] ?? [])),
            /** Absent is the full row every question had before one could sit beside another. */
            width: FieldWidth::fromStored($stored['width'] ?? null),
        );
    }

    /**
     * Whether a Merchant may key one of their own questions this.
     */
    public static function keyIsAvailable(string $key): bool
    {
        return self::keyRefusal($key) === null;
    }

    /**
     * Why a Merchant may not key one of their own questions this, in the words
     * the Checkout tab says it in — or nothing, where they may.
     *
     * Shape, the built-ins and the names a Capture already uses are answered in
     * one place, so the tab that refuses a key and the Config that drops one
     * cannot disagree about which keys are allowed. The wording lives here
     * beside the reason rather than in the admin for the same reason.
     */
    public static function keyRefusal(string $key): ?string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            return 'A key is lowercase letters, numbers and underscores, starting with a letter.';
        }

        if (strlen($key) > self::KEY_MAX_LENGTH) {
            return 'A key is at most '.self::KEY_MAX_LENGTH.' characters.';
        }

        if (BuiltInField::tryFrom($key) !== null) {
            return 'This checkout already asks a question under that key. Give yours one of its own.';
        }

        if (in_array($key, self::RESERVED_KEYS, true)) {
            return 'The order form already uses that name for something else. Give yours another.';
        }

        return null;
    }

    public function key(): string
    {
        return $this->fieldKey;
    }

    public function type(): CheckoutFieldType
    {
        return $this->fieldType;
    }

    /**
     * @return list<FieldOption>
     */
    public function options(): array
    {
        return $this->fieldOptions;
    }

    /**
     * Nothing is waiting for this answer but the Order itself, which records it
     * with its label and type so that relabelling or deleting the question
     * afterwards leaves past Orders reading exactly as they did.
     */
    public function snapshotsAnswer(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function answerMessages(): array
    {
        /** Keyed off the field's own key, so a message cannot outlive it. */
        return match ($this->fieldType) {
            CheckoutFieldType::Select, CheckoutFieldType::Radio => [
                $this->fieldKey.'.required' => 'Please choose one of the options listed.',
                $this->fieldKey.'.in' => 'Please choose one of the options listed.',
            ],
            CheckoutFieldType::Checkbox => [
                $this->fieldKey.'.accepted' => 'Please tick this to continue.',
            ],
            CheckoutFieldType::Number => [
                $this->fieldKey.'.required' => 'Please answer this question.',
                $this->fieldKey.'.numeric' => 'Please answer with a number.',
            ],
            /**
             * The two written controls. What a length or a shape refuses is
             * worded by `FieldConstraints`, which is what sets them.
             */
            default => [
                $this->fieldKey.'.required' => 'Please answer this question.',
            ],
        };
    }

    /**
     * A Merchant's own question is the one place the bounded vocabulary
     * applies: a built-in answers to the column waiting for it (ADR-0006).
     */
    protected function constraints(): FieldConstraints
    {
        return $this->fieldConstraints;
    }

    protected function lengthCeiling(): ?int
    {
        return $this->fieldType->lengthCeiling();
    }

    /**
     * @return array{key: string, label: string, type: string, built_in: bool, enabled: bool, required: bool, placeholder: string|null, help: string|null, width: string, style_tokens: array<string, string>, constraints: array<string, mixed>, options: list<array{value: string, label: string}>}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->fieldKey,
            'label' => $this->label,
            'type' => $this->fieldType->value,
            'built_in' => false,
            'enabled' => $this->enabled,
            'required' => $this->required,
            'placeholder' => $this->placeholder,
            'help' => $this->help,
            'width' => $this->width->value,
            'style_tokens' => $this->styleTokens->toArray(),
            'constraints' => $this->fieldConstraints->toArray(),
            'options' => array_map(fn (FieldOption $option): array => $option->toArray(), $this->fieldOptions),
        ];
    }

    /**
     * What an answer must look like, by the control it was asked with.
     *
     * A choice is checked against the list as it stands, so a value outside it
     * is refused rather than snapshotted as an answer nobody was offered — the
     * same treatment a delivery arrangement the Page does not offer gets.
     *
     * How long a written answer may be is not here. That is one figure serving
     * an attribute and a rule together, and `FieldConstraints` is where it is
     * stated — capped at the control's ceiling whether or not the Merchant
     * asked for anything.
     *
     * @return list<mixed>
     */
    protected function answerRules(): array
    {
        return match ($this->fieldType) {
            CheckoutFieldType::Text, CheckoutFieldType::Textarea => ['string'],
            CheckoutFieldType::Number => ['numeric'],
            CheckoutFieldType::Select, CheckoutFieldType::Radio => [
                'string',
                Rule::in(FieldOption::values($this->fieldOptions)),
            ],
            CheckoutFieldType::Checkbox => ['boolean'],
        };
    }

    /**
     * Never a `placeholder`. That word is the Merchant's, and CheckoutField
     * merges theirs in wherever the control can show one.
     *
     * Never a length or a shape either: those are the browser's half of what
     * `FieldConstraints` resolves, and CheckoutField writes them over the top
     * of this so that one figure reaches the attribute and the rule alike.
     *
     * The checkbox carries a value of its own because an unticked box sends
     * nothing and a ticked one would otherwise send `on`, which is not
     * something the `boolean` rule accepts.
     *
     * @return array<string, string>
     */
    protected function controlHtml(): array
    {
        return match ($this->fieldType) {
            CheckoutFieldType::Text => ['type' => 'text'],
            CheckoutFieldType::Textarea => ['rows' => '3'],
            CheckoutFieldType::Number => ['type' => 'number', 'inputmode' => 'decimal', 'step' => 'any'],
            CheckoutFieldType::Select => [],
            CheckoutFieldType::Radio => ['type' => 'radio'],
            CheckoutFieldType::Checkbox => ['type' => 'checkbox', 'value' => '1'],
        };
    }

    /**
     * The choices as stored, without the ones that are not choices.
     *
     * Deduplicated by value for the reason two Delivery Options under one name
     * are: a list offering the same answer twice is a choice that decides
     * nothing, and the value is what an Order records.
     *
     * @param  array<string, mixed>  $stored
     * @return list<FieldOption>
     */
    private static function optionsFromArray(array $stored): array
    {
        $options = [];

        foreach ((array) ($stored['options'] ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $option = FieldOption::fromArray($option);

            if ($option === null || isset($options[$option->value])) {
                continue;
            }

            $options[$option->value] = $option;
        }

        return array_values($options);
    }
}
