<?php

namespace App\Support\Checkout;

/**
 * One delivery arrangement a Page offers, named and priced by the Merchant.
 *
 * A label and an amount, and beneath the label an optional detail line:
 * "Inside Dhaka" at 60, "1–2 days"; "Outside Dhaka" at 120, "3–5 days";
 * "Free" at nothing. The label is the Merchant's own wording rather than a
 * district name a reader has to map back to a price band, which is what a User
 * working the Order needs in order to explain the figure at the door
 * (ADR-0005).
 *
 * The label is also the option's identity. A Page cannot offer two options
 * under one name — that is refused on the Checkout tab and deduplicated here —
 * so the label is what a Customer's choice names in the submission, and what an
 * Order snapshots.
 *
 * Renaming one therefore refuses the submissions of visitors mid-form on that
 * Page: their form names an arrangement it no longer offers, and being turned
 * away with a reload is the safe direction to fail in.
 *
 * Repricing one refuses them too, by a different route: the amount is part of
 * what the courier collects, so it is in the fingerprint the form carries and
 * the Capture re-checks (`PricingFingerprint`, ADR-0007). Without that a
 * visitor holding HTML written to disk before the change would be captured at
 * the new amount having agreed to the old one.
 *
 * The detail line refuses nobody. It is copy and not a figure — nothing an
 * Order snapshots and nothing the courier collects — so it is outside the
 * fingerprint the way the Checkout Words are, and a Merchant adding "1–2 days"
 * to a live Page turns away no Order somebody is half-way through.
 */
class DeliveryOption
{
    /**
     * A detail line is a line beneath a name in a row a thumb has to hit: any
     * longer and it is a paragraph pushing the price out of the row.
     */
    public const DETAIL_MAX_LENGTH = 60;

    public function __construct(
        public readonly string $label,

        /** Integer minor units, like every other money value in the system. */
        public readonly int $amount,

        /** The line beneath the name, or null where the Merchant wrote none. */
        public readonly ?string $detail = null,
    ) {}

    /**
     * Rebuild an option from its stored shape, or nothing where there is no
     * option to rebuild.
     *
     * Total on purpose, like everything else a Checkout Config is read from: a
     * landing page answering paid traffic with a 500 costs more than one
     * offering a Merchant one arrangement fewer than they meant. A nameless
     * arrangement is dropped rather than rendered as a blank radio button
     * nobody can choose between, and a negative amount — which no control on
     * the Checkout tab can produce, and which the column could not hold — is
     * floored at nothing rather than paying the Customer to receive a parcel.
     *
     * A detail line the tab would refuse is dropped and the option kept: the
     * tab words the refusal where there is a Merchant to tell, and this makes
     * it again silently for a Config written by some other route. Copy that
     * cannot be drawn is not a reason to stop offering the arrangement.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): ?self
    {
        $label = $stored['label'] ?? null;
        $label = is_string($label) ? trim($label) : '';

        if ($label === '') {
            return null;
        }

        return new self(
            $label,
            max(0, (int) ($stored['amount'] ?? 0)),
            CheckoutWords::kept($stored['detail'] ?? null, self::detailRefusal(...)),
        );
    }

    /**
     * Why the line beneath a name cannot say this, or null where it can.
     *
     * The one place the refusal is worded, asked by the Delivery tab and by
     * `fromArray()` so the two cannot disagree about what a detail line may
     * say.
     */
    public static function detailRefusal(string $detail): ?string
    {
        return CheckoutWords::lineRefusal('The detail line', $detail, self::DETAIL_MAX_LENGTH);
    }

    /**
     * The stored shape, with the detail key present only where there is a
     * detail: a row the Merchant said nothing under stores nothing extra, and
     * reads back as the shape every Page stored before the line existed.
     *
     * @return array{label: string, amount: int, detail?: string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'amount' => $this->amount,
            ...($this->detail === null ? [] : ['detail' => $this->detail]),
        ];
    }
}
