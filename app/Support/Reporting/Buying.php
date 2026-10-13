<?php

namespace App\Support\Reporting;

/**
 * What the Buying report draws: everything bought inside a Reporting Window,
 * read once by Supplier and once by Product, with what it all came to.
 *
 * The two lists are the same deliveries counted along two axes and always add
 * up to the same totals — one says where the money went, the other says what
 * it bought. Neither is a subset of the other and neither is drawn from the
 * other; a widget picks the one its screen is asking about.
 *
 * A **Voided** delivery is in none of it. It is a delivery that should never
 * have been recorded, the ledger has already taken every unit of it back out,
 * and a buying figure that counted it would tell a Merchant they spend more
 * than they do.
 *
 * Money is integer minor units; formatting is the caller's, through `Money`.
 */
final readonly class Buying
{
    public function __construct(
        public ReportingWindow $window,

        /**
         * Biggest spend first, then most units, then by name.
         *
         * @var list<BuyingSupplierRow>
         */
        public array $bySupplier,

        /**
         * Biggest spend first, then most units, then by name.
         *
         * @var list<BuyingProductRow>
         */
        public array $byProduct,

        /** Deliveries recorded inside the window that stand. */
        public int $deliveries,

        /** Grams that arrived across all of them. */
        public int $units,

        /** What the window cost altogether. */
        public int $cost,
    ) {}
}
