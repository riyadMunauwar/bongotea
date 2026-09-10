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
 * ticked. And a slot may not be drawn in the document at all — a `Control`'s
 * word sits on a control a behaving layout's script builds, so it has no
 * `WordPlace` and rides to the script on the form element instead.
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

    case StepperProductsHeadline = 'stepper_products_headline';
    case StepperDetailsHeadline = 'stepper_details_headline';
    case StepperPaymentHeadline = 'stepper_payment_headline';
    case StepperProductsStep = 'stepper_products_step';
    case StepperDetailsStep = 'stepper_details_step';
    case StepperPaymentStep = 'stepper_payment_step';
    case StepperNext = 'stepper_next';
    case StepperBack = 'stepper_back';

    case AccordionProductsHeadline = 'accordion_products_headline';
    case AccordionDetailsHeadline = 'accordion_details_headline';
    case AccordionPaymentHeadline = 'accordion_payment_headline';

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

    case InvoiceTitle = 'invoice_title';
    case InvoiceItemHeading = 'invoice_item_heading';
    case InvoiceQuantityHeading = 'invoice_quantity_heading';
    case InvoiceAmountHeading = 'invoice_amount_heading';

    /** A headline or an eyebrow is one short line over a section, in two scripts. */
    public const HEADLINE_MAX_LENGTH = 60;

    /** A line beneath the button is a sentence, not a paragraph of terms. */
    public const LINE_MAX_LENGTH = 120;

    /**
     * A word on a control is a step in a rail or a button beside another
     * button: one or two words, in whichever scripts fit them.
     */
    public const CONTROL_MAX_LENGTH = 24;

    /** The placeholder the number of picked products stands in for. */
    public const COUNT_PLACEHOLDER = '{count}';

    /** The placeholder the delivery charge stands in for. */
    public const DELIVERY_PLACEHOLDER = '{delivery}';

    /**
     * The design's words for this slot.
     *
     * Bilingual, Bangla first and English second, wherever the design writes
     * two scripts — and one script alone where it writes one: the Invoice's
     * three column headings in English, because a heading a hair over a
     * stepper has room for one word and the bill it heads is read across
     * rather than down; the Stepper's three step labels in Bangla and its
     * Back in English, because a step in a rail is one word wide. A Merchant
     * who wants two scripts there writes them, like every other word here.
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
            self::StepperProductsHeadline => 'কোন প্রোডাক্টগুলো নিবেন? / Choose your items',
            self::StepperDetailsHeadline => 'কোথায় পাঠাবো? / Where should we deliver?',
            self::StepperPaymentHeadline => 'পেমেন্ট ও কনফার্ম / Payment & confirm',
            self::StepperProductsStep => 'প্রোডাক্ট',
            self::StepperDetailsStep => 'ডেলিভারি',
            self::StepperPaymentStep => 'পেমেন্ট',
            self::StepperNext => 'পরবর্তী / Next →',
            self::StepperBack => '← Back',
            self::AccordionProductsHeadline => 'প্রোডাক্ট / Products',
            self::AccordionDetailsHeadline => 'ডেলিভারি / Delivery',
            self::AccordionPaymentHeadline => 'পেমেন্ট / Payment',
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
            self::InvoiceTitle => 'অর্ডার ফর্ম / Order form',
            self::InvoiceItemHeading => 'Item',
            self::InvoiceQuantityHeading => 'Qty',
            self::InvoiceAmountHeading => 'Amount',
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
            self::StepperProductsHeadline => 'Products panel headline',
            self::StepperDetailsHeadline => 'Delivery panel headline',
            self::StepperPaymentHeadline => 'Payment panel headline',
            self::StepperProductsStep => 'Products step label',
            self::StepperDetailsStep => 'Delivery step label',
            self::StepperPaymentStep => 'Payment step label',
            self::StepperNext => 'Next button',
            self::StepperBack => 'Back button',
            self::AccordionProductsHeadline => 'Products header',
            self::AccordionDetailsHeadline => 'Delivery header',
            self::AccordionPaymentHeadline => 'Payment header',
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
            self::InvoiceTitle => 'Form title',
            self::InvoiceItemHeading => 'Item column heading',
            self::InvoiceQuantityHeading => 'Quantity column heading',
            self::InvoiceAmountHeading => 'Amount column heading',
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
            self::StepperProductsHeadline,
            self::StepperDetailsHeadline,
            self::StepperPaymentHeadline,
            self::StepperProductsStep,
            self::StepperDetailsStep,
            self::StepperPaymentStep,
            self::StepperNext,
            self::StepperBack,
            self::AccordionProductsHeadline,
            self::AccordionDetailsHeadline,
            self::AccordionPaymentHeadline,
            self::DarkPanelSummaryEyebrow,
            self::DarkPanelFormHeadline,
            self::BentoGridProductsEyebrow,
            self::BentoGridDetailsEyebrow,
            self::BentoGridPaymentEyebrow,
            self::CompactEmbedTitle,
            self::ChipsSelectEyebrow,
            self::ChipsEmptyLine,
            self::ChipsDeliveryEyebrow,
            self::ChipsPaymentEyebrow,
            self::InvoiceTitle,
            self::InvoiceItemHeading,
            self::InvoiceQuantityHeading,
            self::InvoiceAmountHeading => null,
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
            self::StepperProductsHeadline,
            self::StepperDetailsHeadline,
            self::StepperPaymentHeadline,
            self::AccordionProductsHeadline,
            self::AccordionDetailsHeadline,
            self::AccordionPaymentHeadline,
            self::DarkPanelFormHeadline,
            self::CompactEmbedTitle,
            self::InvoiceTitle => WordKind::Headline,
            self::StepperProductsStep,
            self::StepperDetailsStep,
            self::StepperPaymentStep,
            self::StepperNext,
            self::StepperBack => WordKind::Control,
            self::SplitReversedBundleEyebrow,
            self::DarkPanelSummaryEyebrow,
            self::BentoGridProductsEyebrow,
            self::BentoGridDetailsEyebrow,
            self::BentoGridPaymentEyebrow,
            self::ChipsSelectEyebrow,
            self::ChipsDeliveryEyebrow,
            self::ChipsPaymentEyebrow => WordKind::Eyebrow,
            self::CompactEmbedItemCount => WordKind::Count,
            self::InvoiceItemHeading,
            self::InvoiceQuantityHeading,
            self::InvoiceAmountHeading => WordKind::ColumnHeading,
            self::ChipsEmptyLine => WordKind::EmptyState,
            self::SplitHint,
            self::SplitReversedFootnote => WordKind::Line,
        };
    }

    /**
     * Where on the form the island draws this slot, or null for a word the
     * document does not draw at all.
     *
     * A `Control`'s word sits on a control the script builds and the markup
     * does not carry, so there is nowhere on the form to put it: it rides to
     * the script on the form element instead. Null rather than a place of its
     * own, because a place is what `CheckoutLayout::slotAt()` looks a word up
     * by, and a layout puts at most one word at each — a Stepper wording
     * three steps and two buttons would have five words at one place.
     */
    public function place(): ?WordPlace
    {
        return match ($this) {
            self::StepperProductsStep,
            self::StepperDetailsStep,
            self::StepperPaymentStep,
            self::StepperNext,
            self::StepperBack => null,
            self::StackedProductsHeadline,
            self::SplitOrderHeadline,
            self::SplitReversedBundleEyebrow,
            self::DarkPanelSummaryEyebrow,
            self::BentoGridProductsEyebrow,
            self::CompactEmbedTitle,
            self::InvoiceTitle,
            self::StepperProductsHeadline,
            self::AccordionProductsHeadline,
            self::ChipsSelectEyebrow => WordPlace::Products,
            self::CompactEmbedItemCount => WordPlace::ItemCount,
            self::InvoiceItemHeading => WordPlace::ItemHeading,
            self::InvoiceQuantityHeading => WordPlace::QuantityHeading,
            self::InvoiceAmountHeading => WordPlace::AmountHeading,
            self::ChipsEmptyLine => WordPlace::EmptyList,
            self::StackedDetailsHeadline,
            self::SplitDetailsHeadline,
            self::SplitReversedFormHeadline,
            self::StepperDetailsHeadline,
            self::AccordionDetailsHeadline,
            self::DarkPanelFormHeadline,
            self::BentoGridDetailsEyebrow => WordPlace::Details,
            self::SplitDeliveryHeadline,
            self::ChipsDeliveryEyebrow => WordPlace::Delivery,
            self::StackedPaymentHeadline,
            self::SplitPaymentHeadline,
            self::StepperPaymentHeadline,
            self::AccordionPaymentHeadline,
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
     * a word on a control shorter still, and a line beneath the button or
     * beneath an empty list has room for a sentence.
     */
    public function refusal(string $value): ?string
    {
        return match ($this->kind()) {
            WordKind::Control => CheckoutWords::lineRefusal('A word on a button', $value, self::CONTROL_MAX_LENGTH),
            WordKind::NumberedHeadline,
            WordKind::Headline => CheckoutWords::lineRefusal('A headline', $value, self::HEADLINE_MAX_LENGTH),
            WordKind::Eyebrow => CheckoutWords::lineRefusal('An eyebrow', $value, self::HEADLINE_MAX_LENGTH),
            WordKind::Count => CheckoutWords::lineRefusal('A count', $value, self::HEADLINE_MAX_LENGTH),
            WordKind::ColumnHeading => CheckoutWords::lineRefusal('A column heading', $value, self::HEADLINE_MAX_LENGTH),
            WordKind::EmptyState => CheckoutWords::lineRefusal('An empty-state line', $value, self::LINE_MAX_LENGTH),
            WordKind::Line => CheckoutWords::lineRefusal('A line beneath the button', $value, self::LINE_MAX_LENGTH),
        };
    }
}
