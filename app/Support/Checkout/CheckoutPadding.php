<?php

namespace App\Support\Checkout;

use Illuminate\Contracts\Support\Arrayable;

/**
 * The padding on one of the Checkout's two boxes — the ground or the card — as
 * a vertical length and a horizontal one.
 *
 * A small typed value on the Container concern, refused and read as totally as
 * every other Config value: a length this installation cannot use is dropped
 * for the box's design figure and never throws, because a landing page
 * answering paid traffic with a 500 costs more than one drawn at the padding it
 * started with.
 *
 * It is deliberately not a Style Token. A token is a colour, a corner or a
 * typeface read by a flat rule that was already there; a padding is written
 * into the `padding` shorthand the box carries, and the ground's is emitted or
 * neutralised structurally with the Container's toggle (ADR-0027). And its
 * units are not a token length's: `%` and `vw` are refused for a radius because
 * they resolve against something the Checkout does not own, but a padding
 * against the wrapper a Merchant dropped the shortcode into, or the viewport,
 * is exactly the reach a flush layout wants — so the allowlist is wider here on
 * purpose.
 *
 * @implements Arrayable<string, string>
 */
class CheckoutPadding implements Arrayable
{
    /**
     * The units a padding length may carry.
     *
     * Wider than a Style Token length's `{ px, rem, em }`: `%` resolves against
     * the containing block a Merchant wrapped `[checkout]` in and `vw` against
     * the viewport, which for a padding is a reach a merchant flushing the
     * Checkout into their own layout actually wants, rather than the surprise
     * it would be in a radius copied between Pages.
     */
    private const UNITS = ['px', 'rem', 'em', '%', 'vw'];

    /**
     * The most a padding length may run to, in characters.
     *
     * Read by the Container tab as the box's `maxLength` and here as a refusal,
     * so the two cannot disagree. The pattern below already bounds the figure
     * to three whole digits and three decimals — a sane ceiling, so a typo
     * cannot pad a Checkout off the page — and this is the longest that leaves.
     */
    public const MAX_LENGTH = 10;

    public function __construct(
        public readonly string $vertical,
        public readonly string $horizontal,
    ) {}

    /**
     * Rebuild a padding from its stored shape, falling back to the box's design
     * figure for either length the stored shape does not carry or cannot use.
     *
     * Each side is refused on its own by the same check the tab words, so a
     * damaged vertical never takes a good horizontal down with it. Absent, blank
     * or refused is the design's figure, which is what every Config written
     * before this concern existed holds and what those Pages have always drawn —
     * so a published Page reads back byte-identically with no data migration.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored, self $default): self
    {
        return new self(
            self::kept($stored['vertical'] ?? null, $default->vertical),
            self::kept($stored['horizontal'] ?? null, $default->horizontal),
        );
    }

    /**
     * One stored length, kept where it is one this installation can use and
     * dropped for the given default where it is not.
     *
     * The silent half of "refused twice", shared so anything reading a single
     * bounded length off a Config — a padding side here, the card's width on the
     * Container — drops what it cannot use through the one `refusal()` and cannot
     * disagree with the tab about what a length is.
     */
    public static function kept(mixed $value, string $default): string
    {
        if (! is_scalar($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value !== '' && self::refusal($value) === null ? $value : $default;
    }

    /**
     * Why a padding length may not be stored, or nothing where it may.
     *
     * Refusing rather than sanitising is the whole of "no value entered in the
     * admin can break out of the emitted style block": what survives this is a
     * number and one of the units listed, or a bare zero. None of them can
     * carry a semicolon, a brace, a quote or a `<`, so none can end the
     * declaration it is written into — and no `calc`, no `var`, no keyword and
     * no raw CSS gets through the pattern to try.
     *
     * A bare `0` is the one number that needs no unit, and the one a Merchant
     * flushing the card types. Negatives are refused rather than floored: there
     * is no negative padding, so a minus sign is a typo, and a typo silently
     * turned into something else is how a Merchant ends up unable to explain
     * what their Page looks like.
     */
    public static function refusal(string $value): ?string
    {
        if (strlen($value) > self::MAX_LENGTH) {
            return 'That is too long. Use at most '.self::MAX_LENGTH.' characters.';
        }

        if ($value === '0') {
            return null;
        }

        $units = implode('|', array_map(preg_quote(...), self::UNITS));

        return preg_match('/^(?:\d{1,3}|\d{0,3}\.\d{1,3})(?:'.$units.')$/', $value) === 1
            ? null
            : 'Use a padding like 1.5rem, 24px or 0. Units: '.implode(', ', self::UNITS).'.';
    }

    /**
     * The value written into the box's `padding` shorthand: one length where
     * the two sides are the same, two where they differ.
     *
     * Collapsing an even padding to the single value CSS reads it as is what
     * lets a box padded the same on every side — the card's default — emit
     * exactly the bytes it always did, before there was a horizontal to state
     * beside the vertical.
     */
    public function css(): string
    {
        return $this->vertical === $this->horizontal
            ? $this->vertical
            : $this->vertical.' '.$this->horizontal;
    }

    /**
     * Whether this padding is the same pair as another — read to tell a box
     * still at the design's figure from one a Merchant has set, so the phone's
     * tighter figure narrows only the design's padding and a Merchant's own
     * holds at every width.
     */
    public function equals(self $other): bool
    {
        return $this->vertical === $other->vertical && $this->horizontal === $other->horizontal;
    }

    /**
     * @return array{vertical: string, horizontal: string}
     */
    public function toArray(): array
    {
        return [
            'vertical' => $this->vertical,
            'horizontal' => $this->horizontal,
        ];
    }
}
