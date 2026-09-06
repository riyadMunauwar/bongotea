<?php

namespace App\Support\Checkout;

use Illuminate\Contracts\Support\Arrayable;

/**
 * What a Merchant has said about how something looks.
 *
 * Held sparse on purpose: only the tokens they actually set. That is what lets
 * one object serve both places styling is decided — the Checkout, where the
 * gaps are filled with defaults before anything is emitted, and a single field,
 * where a gap means "whatever the Checkout said" and nothing is emitted at all.
 *
 * A field's set is emitted on its own wrapper and the cascade gives it to that
 * field alone. No selector is generated for it, which is why a Checkout's
 * emitted styles are the same length whether it asks two questions or twenty,
 * and why a Merchant's own stylesheet still overrides us at the specificity it
 * always did.
 *
 * @implements Arrayable<string, string>
 */
class StyleTokens implements Arrayable
{
    /**
     * @param  array<string, string>  $values  keyed by StyleToken value
     */
    private function __construct(private readonly array $values) {}

    /**
     * Nothing said, which is what a Checkout nobody restyled and a field
     * following the Checkout both hold.
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Rebuild a token set from its stored shape.
     *
     * Total on purpose, like everything a Checkout Config is read from. An
     * unknown name and a value its token refuses are both dropped, so the worst
     * a Config written by some other route can do is style less than it meant
     * to. This is also the second half of "nothing entered in the admin can
     * break out of the emitted style block": the Checkout tab refuses such a
     * value with a message, and this refuses it again without one, so the block
     * is safe whichever route wrote the Config.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $values = [];

        foreach ($stored as $name => $value) {
            $token = is_string($name) ? StyleToken::tryFrom($name) : null;

            if ($token === null || ! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            /** Blank is the Merchant saying nothing, not a value to be refused. */
            if ($value === '' || $token->refusal($value) !== null) {
                continue;
            }

            $values[$token->value] = $value;
        }

        return new self($values);
    }

    /**
     * The same set with every token the Merchant left alone at its default.
     *
     * What the Checkout root is emitted from, so the complete named set is
     * declared in one place and every rule below it can read a token rather
     * than carrying a fallback of its own.
     */
    public function completed(): self
    {
        $values = [];

        foreach (StyleToken::cases() as $token) {
            $values[$token->value] = $this->values[$token->value] ?? $token->defaultValue();
        }

        return new self($values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    public function value(StyleToken $token): ?string
    {
        return $this->values[$token->value] ?? null;
    }

    /**
     * The declarations, in the token set's own order rather than the order they
     * were stored in, so the same styling always renders the same bytes.
     */
    public function declarations(): string
    {
        $declarations = [];

        foreach (StyleToken::cases() as $token) {
            if (isset($this->values[$token->value])) {
                $declarations[] = $token->property().': '.$this->values[$token->value].';';
            }
        }

        return implode(' ', $declarations);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
