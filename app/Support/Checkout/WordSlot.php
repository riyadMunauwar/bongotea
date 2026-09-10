<?php

namespace App\Support\Checkout;

/**
 * A word a Checkout Layout owns of its own: a numbered headline, an eyebrow,
 * a line beneath the button.
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
 * What a slot is (`kind()`) and where it sits (`place()`) are the slot's own,
 * so the view draws every layout's words by the same few rules.
 *
 * A slot may name a figure — `{count}` for how many products are picked,
 * `{delivery}` for the delivery charge — which the island fills at render and
 * keeps truthful by script, the way the Order Button carries `{total}`. A slot
 * may also be drawn only sometimes: the empty-state line is in the markup
 * whenever its layout is chosen, and the sheet shows it while nothing is
 * ticked.
 */
enum WordSlot: string
{
    case StackedProductsHeadline = 'stacked_products_headline';
    case StackedDetailsHeadline = 'stacked_details_headline';
    case StackedPaymentHeadline = 'stacked_payment_headline';

    case SplitOrderHeadline = 'split_order_headline';
    case SplitDetailsHeadline = 'split_details_headline';
    case SplitDeliveryHeadline = 'split_delivery_headline';
    case SplitPaymentHeadline = 'split_payment_headline';
    case SplitHint = 'split_hint';

    case SplitReversedBundleEyebrow = 'split_reversed_bundle_eyebrow';
    case SplitReversedFormHeadline = 'split_reversed_form_headline';
    case SplitReversedFootnote = 'split_reversed_footnote';

    case DarkPanelSummaryEyebrow = 'dark_panel_summary_eyebrow';
    case DarkPanelFormHeadline = 'dark_panel_form_headline';

    case BentoGridProductsEyebrow = 'bento_grid_products_eyebrow';
    case BentoGridDetailsEyebrow = 'bento_grid_details_eyebrow';
    case BentoGridPaymentEyebrow = 'bento_grid_payment_eyebrow';

    case CompactEmbedTitle = 'compact_embed_title';
    case CompactEmbedItemCount = 'compact_embed_item_count';

    case ChipsSelectEyebrow = 'chips_select_eyebrow';
    case ChipsEmptyLine = 'chips_empty_line';
    case ChipsDeliveryEyebrow = 'chips_delivery_eyebrow';
    case ChipsPaymentEyebrow = 'chips_payment_eyebrow';

    /** A headline or an eyebrow is one short line over a section, in two scripts. */
    public const HEADLINE_MAX_LENGTH = 60;

    /** A line beneath the button is a sentence, not a paragraph of terms. */
    public const LINE_MAX_LENGTH = 120;

    /** The placeholder the number of picked products stands in for. */
    public const COUNT_PLACEHOLDER = '{count}';

    /** The placeholder the delivery charge stands in for. */
    public const DELIVERY_PLACEHOLDER = '{delivery}';

    /**
     * The design's words for this slot.
     */
    public function defaultValue(): string
    {
        return match ($this) {
            self::StackedProductsHeadline => 'প্রোডাক্ট নির্বাচন করুন / Select products',
            self::StackedDetailsHeadline => 'ডেলিভারি তথ্য / Delivery details',
            self::StackedPaymentHeadline => 'পেমেন্ট / Payment method',
            self::SplitOrderHeadline => 'আপনার অর্ডার / Your order',
            self::SplitDetailsHeadline => 'ডেলিভারি তথ্য / Delivery details',
            self::SplitDeliveryHeadline => 'ডেলিভারি এলাকা / Delivery area',
            self::SplitPaymentHeadline => 'পেমেন্ট / Payment',
            self::SplitHint => 'অর্ডার কনফার্ম করতে আমরা কল করব / We will call to confirm your order',
            self::SplitReversedBundleEyebrow => 'বান্ডেল · {count}টি নির্বাচিত / Bundle · {count} selected',
            self::SplitReversedFormHeadline => 'অর্ডার করতে ফর্মটি পূরণ করুন / Fill in the form to order',
            self::SplitReversedFootnote => 'ডেলিভারি {delivery} সহ / Delivery {delivery} included',
            self::DarkPanelSummaryEyebrow => 'আপনার অর্ডার / Order summary',
            self::DarkPanelFormHeadline => 'ডেলিভারি তথ্য / Delivery details',
            self::BentoGridProductsEyebrow => '০১ · প্রোডাক্ট / 01 · Products',
            self::BentoGridDetailsEyebrow => '০২ · আপনার তথ্য / 02 · Your details',
            self::BentoGridPaymentEyebrow => '০৩ · পেমেন্ট / 03 · Payment',
            self::CompactEmbedTitle => 'দ্রুত অর্ডার / Quick order',
            self::CompactEmbedItemCount => '{count}টি আইটেম / {count} items',
            self::ChipsSelectEyebrow => 'আইটেম বাছুন / Select items',
            self::ChipsEmptyLine => 'কোনো প্রোডাক্ট সিলেক্ট করা হয়নি / Nothing selected',
            self::ChipsDeliveryEyebrow => 'ডেলিভারি / Delivery',
            self::ChipsPaymentEyebrow => 'পেমেন্ট / Payment',
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
            self::SplitOrderHeadline => 'Order panel headline',
            self::SplitDetailsHeadline => 'Delivery details headline',
            self::SplitDeliveryHeadline => 'Delivery area headline',
            self::SplitPaymentHeadline => 'Payment headline',
            self::SplitHint => 'Hint under the button',
            self::SplitReversedBundleEyebrow => 'Bundle eyebrow',
            self::SplitReversedFormHeadline => 'Form headline',
            self::SplitReversedFootnote => 'Footnote under the button',
            self::DarkPanelSummaryEyebrow => 'Summary eyebrow',
            self::DarkPanelFormHeadline => 'Form headline',
            self::BentoGridProductsEyebrow => 'Products eyebrow',
            self::BentoGridDetailsEyebrow => 'Details eyebrow',
            self::BentoGridPaymentEyebrow => 'Payment eyebrow',
            self::CompactEmbedTitle => 'Card title',
            self::CompactEmbedItemCount => 'Item count',
            self::ChipsSelectEyebrow => 'Select items eyebrow',
            self::ChipsEmptyLine => 'Nothing selected line',
            self::ChipsDeliveryEyebrow => 'Delivery eyebrow',
            self::ChipsPaymentEyebrow => 'Payment eyebrow',
        };
    }

    /**
     * A line under the box where the slot may name a figure, saying how; null
     * for a slot that is words alone.
     */
    public function helperText(): ?string
    {
        return match ($this) {
            self::SplitReversedBundleEyebrow => 'Write '.self::COUNT_PLACEHOLDER.' anywhere in it for the number of products picked, kept up to date as the customer ticks.',
            self::SplitReversedFootnote => 'Write '.self::DELIVERY_PLACEHOLDER.' anywhere in it for the delivery charge of the option chosen.',
            self::CompactEmbedItemCount => 'Write '.self::COUNT_PLACEHOLDER.' anywhere in it for the number of products picked, kept up to date as the customer ticks.',
            self::StackedProductsHeadline,
            self::StackedDetailsHeadline,
            self::StackedPaymentHeadline,
            self::SplitOrderHeadline,
            self::SplitDetailsHeadline,
            self::SplitDeliveryHeadline,
            self::SplitPaymentHeadline,
            self::SplitHint,
            self::SplitReversedFormHeadline,
            self::DarkPanelSummaryEyebrow,
            self::DarkPanelFormHeadline,
            self::BentoGridProductsEyebrow,
            self::BentoGridDetailsEyebrow,
            self::BentoGridPaymentEyebrow,
            self::CompactEmbedTitle,
            self::ChipsSelectEyebrow,
            self::ChipsEmptyLine,
            self::ChipsDeliveryEyebrow,
            self::ChipsPaymentEyebrow => null,
        };
    }

    /**
     * How the island draws this slot.
     */
    public function kind(): WordKind
    {
        return match ($this) {
            self::StackedProductsHeadline,
            self::StackedDetailsHeadline,
            self::StackedPaymentHeadline => WordKind::NumberedHeadline,
            self::SplitOrderHeadline,
            self::SplitDetailsHeadline,
            self::SplitDeliveryHeadline,
            self::SplitPaymentHeadline,
            self::SplitReversedFormHeadline,
            self::DarkPanelFormHeadline,
            self::CompactEmbedTitle => WordKind::Headline,
            self::SplitReversedBundleEyebrow,
            self::DarkPanelSummaryEyebrow,
            self::BentoGridProductsEyebrow,
            self::BentoGridDetailsEyebrow,
            self::BentoGridPaymentEyebrow,
            self::ChipsSelectEyebrow,
            self::ChipsDeliveryEyebrow,
            self::ChipsPaymentEyebrow => WordKind::Eyebrow,
            self::CompactEmbedItemCount => WordKind::Count,
            self::ChipsEmptyLine => WordKind::EmptyState,
            self::SplitHint,
            self::SplitReversedFootnote => WordKind::Line,
        };
    }

    /**
     * Where on the form the island draws this slot.
     */
    public function place(): WordPlace
    {
        return match ($this) {
            self::StackedProductsHeadline,
            self::SplitOrderHeadline,
            self::SplitReversedBundleEyebrow,
            self::DarkPanelSummaryEyebrow,
            self::BentoGridProductsEyebrow,
            self::CompactEmbedTitle,
            self::ChipsSelectEyebrow => WordPlace::Products,
            self::CompactEmbedItemCount => WordPlace::ItemCount,
            self::ChipsEmptyLine => WordPlace::EmptyList,
            self::StackedDetailsHeadline,
            self::SplitDetailsHeadline,
            self::SplitReversedFormHeadline,
            self::DarkPanelFormHeadline,
            self::BentoGridDetailsEyebrow => WordPlace::Details,
            self::SplitDeliveryHeadline,
            self::ChipsDeliveryEyebrow => WordPlace::Delivery,
            self::StackedPaymentHeadline,
            self::SplitPaymentHeadline,
            self::BentoGridPaymentEyebrow,
            self::ChipsPaymentEyebrow => WordPlace::Payment,
            self::SplitHint,
            self::SplitReversedFootnote => WordPlace::Button,
        };
    }

    /**
     * Why the slot cannot say this, or null where it can.
     *
     * Every slot is one line: a heading, an eyebrow and a count a short one,
     * and a line beneath the button or beneath an empty list has room for a
     * sentence.
     */
    public function refusal(string $value): ?string
    {
        return match ($this->kind()) {
            WordKind::NumberedHeadline,
            WordKind::Headline => CheckoutWords::lineRefusal('A headline', $value, self::HEADLINE_MAX_LENGTH),
            WordKind::Eyebrow => CheckoutWords::lineRefusal('An eyebrow', $value, self::HEADLINE_MAX_LENGTH),
            WordKind::Count => CheckoutWords::lineRefusal('A count', $value, self::HEADLINE_MAX_LENGTH),
            WordKind::EmptyState => CheckoutWords::lineRefusal('An empty-state line', $value, self::LINE_MAX_LENGTH),
            WordKind::Line => CheckoutWords::lineRefusal('A line beneath the button', $value, self::LINE_MAX_LENGTH),
        };
    }
}
