<?php

namespace App\Support\Checkout;

/**
 * What the order button says, and the line beneath it, in the Merchant's own
 * words.
 *
 * Two sentences and nothing else. The wording may carry `{total}`, which the
 * island replaces with the figure the courier will collect — at render for a
 * visitor whose scripts never run, and again by the script every time that
 * figure moves — so the price can sit on the button itself at the moment a
 * Customer taps it.
 *
 * Copy, not a figure handed over at the door: neither value is in the Pricing
 * Fingerprint, so a Merchant editing a word on a live Page never turns away an
 * Order somebody is half-way through typing (ADR-0007).
 *
 * Blank is the Merchant saying nothing, and a Page that says nothing reads the
 * way the design does, Bangla first and English second. So `null` here means
 * "the default", and the defaults live here rather than in the view for that
 * reason: what the tab shows as the placeholder and what the island draws have
 * to be the same sentence. One of the Checkout Words, held by `CheckoutWords`
 * beside the totals and payment labels.
 */
class OrderButton
{
    public const DEFAULT_WORDING = 'অর্ডার করুন / Order Now';

    public const DEFAULT_NOTE = 'পার্সেল হাতে পেয়ে নগদে পরিশোধ করুন / Pay in cash when the parcel reaches you.';

    /** The placeholder the live total stands in for, spelled the one way the tab documents. */
    public const TOTAL_PLACEHOLDER = '{total}';

    /**
     * A button carries one short line: any longer and it wraps on a phone into
     * something that no longer reads as a button.
     */
    public const WORDING_MAX_LENGTH = 80;

    /** The line beneath is a line, not a paragraph of terms. */
    public const NOTE_MAX_LENGTH = 200;

    private function __construct(
        /** What the button says, or null for the words it has always said. */
        public readonly ?string $wording,

        /** The line beneath the button, or null for the line it has always carried. */
        public readonly ?string $note,
    ) {}

    /**
     * The button as it has always read, which is what every Page written before
     * the copy could be changed holds.
     */
    public static function default(): self
    {
        return new self(null, null);
    }

    /**
     * Rebuild the copy from its stored shape.
     *
     * Total on purpose, like everything a Checkout Config is read from. Blank
     * is the Merchant saying nothing, and a value the tab would refuse is
     * dropped rather than drawn: the tab words the refusal where there is a
     * Merchant to tell, and this makes it again silently for a Config written
     * by some other route. Either way the button falls back to the words it
     * always had rather than a Page answering paid traffic with a 500.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        return new self(
            CheckoutWords::kept($stored['wording'] ?? null, self::wordingRefusal(...)),
            CheckoutWords::kept($stored['note'] ?? null, self::noteRefusal(...)),
        );
    }

    /**
     * Why the button cannot say this, or null where it can.
     *
     * The one place the refusal is worded, asked by the Checkout tab and by
     * `fromArray()` so the two cannot disagree about what a button may say.
     */
    public static function wordingRefusal(string $wording): ?string
    {
        return CheckoutWords::lineRefusal('The button\'s wording', $wording, self::WORDING_MAX_LENGTH);
    }

    /**
     * Why the line beneath the button cannot say this, or null where it can.
     */
    public static function noteRefusal(string $note): ?string
    {
        return CheckoutWords::textRefusal('The line beneath the button', $note, self::NOTE_MAX_LENGTH);
    }

    /**
     * What the button says, with the total placeholder still in it — the
     * template the script substitutes into as the figure moves.
     */
    public function wording(): string
    {
        return $this->wording ?? self::DEFAULT_WORDING;
    }

    /**
     * What the button says for a given total, which is what the island draws:
     * a visitor whose scripts never run reads a figure and never a placeholder.
     */
    public function wordingFor(string $total): string
    {
        return str_replace(self::TOTAL_PLACEHOLDER, $total, $this->wording());
    }

    /**
     * Whether the wording carries the total at all, which is whether the script
     * has anything to keep truthful on the button as the customer types.
     */
    public function carriesTotal(): bool
    {
        return str_contains($this->wording(), self::TOTAL_PLACEHOLDER);
    }

    public function note(): string
    {
        return $this->note ?? self::DEFAULT_NOTE;
    }

    /**
     * @return array{wording: ?string, note: ?string}
     */
    public function toArray(): array
    {
        return [
            'wording' => $this->wording,
            'note' => $this->note,
        ];
    }
}
