<?php

namespace App\Support\Checkout;

use App\Enums\PaymentMethod;

/**
 * Everything a Checkout says in the Merchant's own words: the Order Button,
 * the totals labels, each Payment Method's long and short label, and the
 * slots a Checkout Layout owns of its own.
 *
 * Every word has a default drawn from the designs, Bangla first and English
 * second, so a Page that says nothing reads the way the design does. The
 * defaults live here rather than in the view for the reason the button's do:
 * what the Words tab shows as a placeholder and what the island draws have to
 * be the same sentence.
 *
 * Copy and never a figure. Nothing here is in the Pricing Fingerprint, so a
 * Merchant editing a word on a live Page never turns away an Order somebody is
 * half-way through typing (ADR-0007).
 *
 * Every value is nullable and null means the default. A value the tab would
 * refuse is dropped rather than drawn: the refusals are worded here once and
 * asked from the Words tab, where there is a Merchant to tell, and from
 * `fromArray()`, where there is not, so the two cannot disagree about what a
 * Checkout may say. A slot a layout owns is stored flat here under the slot's
 * own key whatever layout the Page is arranged in, so a word written for a
 * layout the Page has moved off is kept unseen and comes back when the layout
 * does.
 */
final class CheckoutWords
{
    public const DEFAULT_SUBTOTAL_LABEL = 'সাবটোটাল / Subtotal';

    public const DEFAULT_DELIVERY_LABEL = 'ডেলিভারি চার্জ / Delivery';

    public const DEFAULT_TOTAL_LABEL = 'মোট / Total';

    /** A totals row is a word or two beside a figure, in two scripts. */
    public const LABEL_MAX_LENGTH = 40;

    /** A method's long label names it in two scripts with room for a hint in brackets. */
    public const PAYMENT_LABEL_MAX_LENGTH = 60;

    /** A short label has to fit a segmented button on a phone. */
    public const SHORT_PAYMENT_LABEL_MAX_LENGTH = 20;

    /**
     * @param  array<string, array{long: ?string, short: ?string}>  $paymentLabels  One entry per Payment Method, keyed by its stored name.
     * @param  array<string, ?string>  $slots  One entry per WordSlot, keyed by its stored name; null for the design's words.
     */
    private function __construct(
        public readonly OrderButton $orderButton,

        /** What the subtotal row says, or null for the design's words. */
        public readonly ?string $subtotal,

        /** What the delivery row says, or null for the design's words. */
        public readonly ?string $delivery,

        /** What the total row says, or null for the design's words. */
        public readonly ?string $total,

        private readonly array $paymentLabels,

        private readonly array $slots,
    ) {}

    /**
     * The Checkout as designed, which is what every Page written before a
     * Merchant could reword it holds.
     */
    public static function default(): self
    {
        return self::fromArray([]);
    }

    /**
     * Rebuild the words from their stored shape.
     *
     * Total on purpose, like everything a Checkout Config is read from: a
     * missing key, a blank, or a value the tab would refuse all read as the
     * default for that word, and a Payment Method the stored shape never
     * mentioned reads as its design label.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        $storedLabels = (array) ($stored['payment'] ?? []);

        $paymentLabels = [];

        foreach (PaymentMethod::cases() as $method) {
            $pair = (array) ($storedLabels[$method->value] ?? []);

            $paymentLabels[$method->value] = [
                'long' => self::kept($pair['long'] ?? null, self::paymentLabelRefusal(...)),
                'short' => self::kept($pair['short'] ?? null, self::shortPaymentLabelRefusal(...)),
            ];
        }

        $slots = [];

        foreach (WordSlot::cases() as $slot) {
            $slots[$slot->value] = self::kept($stored[$slot->value] ?? null, $slot->refusal(...));
        }

        return new self(
            OrderButton::fromArray((array) ($stored['order_button'] ?? [])),
            self::kept($stored['subtotal'] ?? null, self::labelRefusal(...)),
            self::kept($stored['delivery'] ?? null, self::labelRefusal(...)),
            self::kept($stored['total'] ?? null, self::labelRefusal(...)),
            $paymentLabels,
            $slots,
        );
    }

    /**
     * Why a totals row cannot be labelled this, or null where it can.
     */
    public static function labelRefusal(string $label): ?string
    {
        return self::lineRefusal('A totals label', $label, self::LABEL_MAX_LENGTH);
    }

    /**
     * Why a Payment Method's long label cannot be this, or null where it can.
     */
    public static function paymentLabelRefusal(string $label): ?string
    {
        return self::lineRefusal('A payment method\'s label', $label, self::PAYMENT_LABEL_MAX_LENGTH);
    }

    /**
     * Why a Payment Method's short label cannot be this, or null where it can.
     */
    public static function shortPaymentLabelRefusal(string $label): ?string
    {
        return self::lineRefusal('A payment method\'s short label', $label, self::SHORT_PAYMENT_LABEL_MAX_LENGTH);
    }

    public function subtotalLabel(): string
    {
        return $this->subtotal ?? self::DEFAULT_SUBTOTAL_LABEL;
    }

    public function deliveryLabel(): string
    {
        return $this->delivery ?? self::DEFAULT_DELIVERY_LABEL;
    }

    public function totalLabel(): string
    {
        return $this->total ?? self::DEFAULT_TOTAL_LABEL;
    }

    /**
     * What a method is called where the layout has room: two scripts, and a
     * hint in brackets where the design carries one.
     */
    public function paymentLabel(PaymentMethod $method): string
    {
        return $this->paymentLabels[$method->value]['long'] ?? self::defaultPaymentLabel($method);
    }

    /**
     * What a method is called where the layout is tight: a segmented button
     * or a chip, one word wide.
     */
    public function shortPaymentLabel(PaymentMethod $method): string
    {
        return $this->paymentLabels[$method->value]['short'] ?? self::defaultShortPaymentLabel($method);
    }

    /**
     * What a layout's own slot says: the Merchant's words, or the design's.
     *
     * Answered for any slot whatever layout the Page is arranged in; whether
     * the slot is drawn at all is the layout's to say (`CheckoutLayout::draws()`),
     * and the view asks that first.
     */
    public function slot(WordSlot $slot): string
    {
        return $this->slots[$slot->value] ?? $slot->defaultValue();
    }

    /**
     * The design's long label for a method. Exhaustive, so a method added to
     * the enum has to be given its words here before anything draws it.
     */
    public static function defaultPaymentLabel(PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::CashOnDelivery => 'ক্যাশ অন ডেলিভারি / Cash on Delivery',
            PaymentMethod::Bkash => 'বিকাশ / bKash',
            PaymentMethod::SslCommerz => 'কার্ড / Card (SSLCommerz)',
            PaymentMethod::AamarPay => 'কার্ড / Card (aamarPay)',
            PaymentMethod::PayStation => 'কার্ড / Card (PayStation)',
        };
    }

    /**
     * The design's short label for a method, which is the brand where the
     * brand is short and the design's abbreviation where it is not.
     */
    public static function defaultShortPaymentLabel(PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::CashOnDelivery => 'COD',
            PaymentMethod::Bkash => 'bKash',
            PaymentMethod::SslCommerz => 'SSLCommerz',
            PaymentMethod::AamarPay => 'aamarPay',
            PaymentMethod::PayStation => 'PayStation',
        };
    }

    /**
     * The stored shape: the shared words under their own keys, and every
     * layout-owned slot flat beside them under the slot's key.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order_button' => $this->orderButton->toArray(),
            'subtotal' => $this->subtotal,
            'delivery' => $this->delivery,
            'total' => $this->total,
            'payment' => $this->paymentLabels,
            ...$this->slots,
        ];
    }

    /**
     * The value where the Checkout may say it, else null: the silent half of a
     * refusal, for a Config written by a route with nobody to tell. Blank is
     * the Merchant saying nothing, so it is null and never refused.
     *
     * @param  callable(string): ?string  $refusal
     */
    public static function kept(mixed $value, callable $refusal): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' && $refusal($value) === null ? $value : null;
    }

    /**
     * Why a string that must fit on one line cannot be this, or null.
     */
    public static function lineRefusal(string $what, string $value, int $maxLength): ?string
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            return $what.' is one line.';
        }

        return self::textRefusal($what, $value, $maxLength);
    }

    /**
     * Why a string that may run to a few lines cannot be this, or null.
     */
    public static function textRefusal(string $what, string $value, int $maxLength): ?string
    {
        if (mb_strlen($value) > $maxLength) {
            return $what.' is at most '.$maxLength.' characters.';
        }

        return null;
    }
}
