<?php

namespace App\Support\Checkout;

/**
 * A word a Checkout Layout owns of its own: a numbered headline, a step label,
 * a card title, an empty-state line.
 *
 * Only a Page arranged in the layout that draws a slot is asked what it says,
 * and a word written for a layout the Page is not arranged in is kept unseen
 * and comes back when the layout does. Every slot has a default drawn from the
 * designs, Bangla first and English second, so a Page that says nothing reads
 * the way the design does.
 *
 * One case per slot across all ten layouts, each stored flat under `words`
 * by its case value, so a layout arriving later adds its cases here and its
 * Section on the Words tab and nothing about the stored shape changes. Which
 * layout owns a slot is the layout's to say (`CheckoutLayout::wordSlots()`),
 * not the slot's: the layout is the exhaustive match the view and the tab read.
 */
enum WordSlot: string
{
    case StackedProductsHeadline = 'stacked_products_headline';
    case StackedDetailsHeadline = 'stacked_details_headline';
    case StackedPaymentHeadline = 'stacked_payment_headline';

    /** A headline is a line beside a badge, in two scripts. */
    public const HEADLINE_MAX_LENGTH = 60;

    /**
     * The design's words for this slot.
     */
    public function defaultValue(): string
    {
        return match ($this) {
            self::StackedProductsHeadline => 'প্রোডাক্ট নির্বাচন করুন / Select products',
            self::StackedDetailsHeadline => 'ডেলিভারি তথ্য / Delivery details',
            self::StackedPaymentHeadline => 'পেমেন্ট / Payment method',
        };
    }

    /**
     * What the Words tab calls the box.
     */
    public function label(): string
    {
        return match ($this) {
            self::StackedProductsHeadline => 'Products headline',
            self::StackedDetailsHeadline => 'Delivery details headline',
            self::StackedPaymentHeadline => 'Payment headline',
        };
    }

    /**
     * Why the slot cannot say this, or null where it can.
     *
     * Every slot so far is a one-line headline; a slot that may run to more
     * than a line, when one arrives, matches its own case here.
     */
    public function refusal(string $value): ?string
    {
        return match ($this) {
            self::StackedProductsHeadline,
            self::StackedDetailsHeadline,
            self::StackedPaymentHeadline => CheckoutWords::lineRefusal('A headline', $value, self::HEADLINE_MAX_LENGTH),
        };
    }
}
