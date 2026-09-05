<?php

namespace App\Support\Checkout;

/**
 * One delivery arrangement a Page offers, named and priced by the Merchant.
 *
 * A label and an amount, and nothing else: "Inside Dhaka" at 60, "Outside
 * Dhaka" at 120, "Free" at nothing. The label is the Merchant's own wording
 * rather than a district name a reader has to map back to a price band, which
 * is what a User working the Order needs in order to explain the figure at the
 * door (ADR-0005).
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
 * Repricing one does not, and that is a live defect rather than a property of
 * this design. A visitor holding HTML written to disk before the change is
 * captured at the new amount having agreed to the old one — the same gap
 * repricing a Product already has, recorded in ADR-0007, and closed by the
 * fingerprint that is its own piece of work.
 */
class DeliveryOption
{
    public function __construct(
        public readonly string $label,

        /** Integer minor units, like every other money value in the system. */
        public readonly int $amount,
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
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): ?self
    {
        $label = $stored['label'] ?? null;
        $label = is_string($label) ? trim($label) : '';

        if ($label === '') {
            return null;
        }

        return new self($label, max(0, (int) ($stored['amount'] ?? 0)));
    }

    /**
     * @return array{label: string, amount: int}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'amount' => $this->amount,
        ];
    }
}
