<?php

namespace App\Support\Checkout;

/**
 * How a Checkout is arranged.
 *
 * A layout decides what the island draws and where, and nothing about what it
 * asks for or what it charges: the questions, the delivery arrangements, the
 * payment methods and the token set are the same objects whichever of these is
 * chosen, and every one of them is rendered by all ten, in the same document
 * order, capturing the same complete Order (ADR-0015).
 *
 * Ten of them, in the design's order (1a–1j). There is one stylesheet, and a
 * layout adds a class to the root and a small block of its own rules to the
 * end of it, rather than a sheet of its own. These styles ride inside every
 * cached page, so a layout that shipped its own copy would charge every
 * visitor for an arrangement their Page is not using, whereas a block emitted
 * only where it applies charges nobody (ADR-0009).
 *
 * A layout may own a slot of its own — a numbered headline, a step label — and
 * only a Page arranged in it is asked what the slot says. What a layout draws
 * and does is said here as predicates the view and the Checkout tab both read,
 * every one an exhaustive match over all ten, so a layout added or changed
 * has to answer each question before anything draws it.
 *
 * Stacked is drawn as designed and is what the base sheet draws. The other
 * nine are chosen from the same list and, until each one's own rules land,
 * draw as the base does under a class of their own.
 */
enum CheckoutLayout: string
{
    case Stacked = 'stacked';
    case Split = 'split';
    case SplitReversed = 'split_reversed';
    case Stepper = 'stepper';
    case Accordion = 'accordion';
    case BentoGrid = 'bento_grid';
    case CompactEmbed = 'compact_embed';
    case Chips = 'chips';
    case Invoice = 'invoice';
    case DarkPanel = 'dark_panel';

    /**
     * The names the Checkout stored its first layouts under, and the one of
     * these ten each is read as.
     *
     * Read here and nowhere else. The retired `form_only` is not among them
     * because it was never an arrangement — it was the Product List switch
     * wearing a layout's name — and `CheckoutConfig::fromArray()` is the one
     * reader that remembers what it meant; here it falls to Stacked like any
     * unknown name.
     *
     * @var array<string, self>
     */
    private const RETIRED_NAMES = [
        'compact' => self::CompactEmbed,
        'summary_first' => self::SplitReversed,
    ];

    /**
     * What a stored Config's layout is, whatever it happens to hold.
     *
     * Total, like everything a Config is read from: a name nothing renders and
     * a Config written before a Page could choose one both mean the arrangement
     * the Checkout has always had. A landing page answering paid traffic with a
     * 500 costs more than one drawn in the layout it started in.
     *
     * A Page saved under one of the first layouts keeps working: the old name
     * is read as the one of these ten nearest to what it drew, so nothing a
     * Merchant published comes up empty on upgrade.
     */
    public static function fromStored(mixed $stored): self
    {
        if (! is_string($stored)) {
            return self::Stacked;
        }

        return self::tryFrom($stored) ?? self::RETIRED_NAMES[$stored] ?? self::Stacked;
    }

    /**
     * The words this layout owns of its own, in the order the Customer meets
     * them on the form. Empty for a layout that draws none.
     *
     * @return list<WordSlot>
     */
    public function wordSlots(): array
    {
        return match ($this) {
            self::Stacked => [
                WordSlot::StackedProductsHeadline,
                WordSlot::StackedDetailsHeadline,
                WordSlot::StackedPaymentHeadline,
            ],
            self::Split,
            self::SplitReversed,
            self::Stepper,
            self::Accordion,
            self::BentoGrid,
            self::CompactEmbed,
            self::Chips,
            self::Invoice,
            self::DarkPanel => [],
        };
    }

    /**
     * Whether this layout draws one of its own slots — which is also whether
     * a Page arranged in it is asked what the slot says.
     */
    public function draws(WordSlot $slot): bool
    {
        return in_array($slot, $this->wordSlots(), true);
    }

    /**
     * Whether a Delivery Option's detail line ("1–2 days") is drawn under its
     * name here.
     *
     * The roomy arrangements draw a delivery choice as a boxed row with room
     * for a second line; the segmented ones draw it as a button a word wide,
     * where a second line has nowhere to go. The line is kept in the Config
     * either way, so a Page moved to a roomy layout draws it again.
     */
    public function drawsDeliveryDetail(): bool
    {
        return match ($this) {
            self::Stacked,
            self::Split,
            self::Stepper,
            self::Accordion,
            self::Invoice,
            self::DarkPanel => true,
            self::SplitReversed,
            self::BentoGrid,
            self::CompactEmbed,
            self::Chips => false,
        };
    }

    /**
     * Whether a Payment Method is named by its short label here rather than
     * its long one.
     *
     * The long label names a method in two scripts with room for a hint; the
     * short one is a word wide, for a segmented control, a two-by-two grid or
     * a centred box on a phone. Which a layout draws is the layout's to say —
     * a Merchant sets both once on the Words tab and never has to know which
     * fits.
     */
    public function drawsShortPaymentLabels(): bool
    {
        return match ($this) {
            self::BentoGrid,
            self::CompactEmbed,
            self::DarkPanel => true,
            self::Stacked,
            self::Split,
            self::SplitReversed,
            self::Stepper,
            self::Accordion,
            self::Chips,
            self::Invoice => false,
        };
    }

    /**
     * Whether two half-width questions sit side by side on a wide screen here.
     *
     * The narrow-column arrangements pair nothing: a Merchant choosing one has
     * already said what they want the form to do, and paired questions work
     * against it. The width itself is untouched — it is read again the moment
     * the Page is arranged any other way — so what this answers is what a
     * Merchant is looking at, not what their Config holds. The Checkout tab
     * says so where the widths are set.
     */
    public function pairsQuestions(): bool
    {
        return match ($this) {
            self::Stacked,
            self::Split,
            self::Stepper,
            self::Accordion,
            self::BentoGrid,
            self::Chips,
            self::Invoice,
            self::DarkPanel => true,
            self::SplitReversed,
            self::CompactEmbed => false,
        };
    }

    /**
     * Whether this layout does something as well as arranging: one panel at
     * a time behind Next and Back, or one section open at a time.
     *
     * Behaviour lives in the island's one inline script, keyed off the
     * layout's modifier class, and never reorders the document or changes what
     * is captured: without script every panel shows in document order and the
     * form submits the same complete Order (ADR-0015).
     */
    public function behaves(): bool
    {
        return match ($this) {
            self::Stepper,
            self::Accordion => true,
            self::Stacked,
            self::Split,
            self::SplitReversed,
            self::BentoGrid,
            self::CompactEmbed,
            self::Chips,
            self::Invoice,
            self::DarkPanel => false,
        };
    }

    /**
     * What the Order Button says here where the Merchant has said nothing.
     *
     * Several of the designs print the live total inside the button, and one
     * (Invoice) words the button differently altogether; the design's words
     * for the layout are the default, and a Merchant's own wording, once
     * written, stands on every layout alike.
     */
    public function defaultButtonWording(): string
    {
        return match ($this) {
            self::Stacked => OrderButton::DEFAULT_WORDING.' · '.OrderButton::TOTAL_PLACEHOLDER,
            self::Split,
            self::SplitReversed,
            self::Stepper,
            self::Accordion,
            self::BentoGrid,
            self::CompactEmbed,
            self::Chips,
            self::Invoice,
            self::DarkPanel => OrderButton::DEFAULT_WORDING,
        };
    }

    /**
     * The class this layout puts on the Checkout root, beside `gio-checkout`.
     *
     * Always emitted, in every layout, so a Merchant writing their own CSS has
     * a stable thing to hang it on whether or not the arrangement they chose
     * carries any rules of its own. It is a modifier on one base sheet and
     * never a second sheet: adding a layout is a rule, not a copy.
     */
    public function modifierClass(): string
    {
        return 'gio-checkout-'.str_replace('_', '-', $this->value);
    }

    /**
     * The rules this arrangement adds to the base sheet.
     *
     * Emitted only on a Page arranged in this layout, and never as a sheet of
     * its own: the base is common to every arrangement and this is a small
     * block appended to it (ADR-0009). Every rule in a block is a descendant
     * of the layout's own modifier class — two classes deep, never three —
     * so all of it is inert on a Page arranged any other way.
     *
     * One block per case, written by hand from the handoff README's figures,
     * so nothing here is generated from a Merchant's figures — the sheet is
     * still the same bytes for every Page arranged the same way, however many
     * questions it asks and however much of it is restyled.
     *
     * Stacked adds nothing: it is what the base sheet draws. The other nine
     * add nothing yet either, and draw as the base does until each one's own
     * rules land.
     */
    public function styleRules(): string
    {
        return match ($this) {
            self::Stacked,
            self::Split,
            self::SplitReversed,
            self::Stepper,
            self::Accordion,
            self::BentoGrid,
            self::CompactEmbed,
            self::Chips,
            self::Invoice,
            self::DarkPanel => '',
        };
    }

    /**
     * What the layout looks like, in one line, for the card a Merchant picks
     * it from.
     */
    public function description(): string
    {
        return match ($this) {
            self::Stacked => 'One column in a white card: numbered sections for the products, the details and the payment, then the totals and a button carrying the total.',
            self::Split => 'The form on the left and a grey summary panel on the right that stays on screen as the customer works down the questions.',
            self::SplitReversed => 'Large product cards with a running subtotal on the left, a compact form with the total inside the button on the right.',
            self::Stepper => 'Three steps — products, details, payment — one panel at a time behind Next and Back.',
            self::Accordion => 'Three sections that open one at a time, each closed header showing what was chosen in it, with a total bar at the foot.',
            self::BentoGrid => 'Four bordered cells with numbered eyebrows and a full-width dark bar carrying the total and the button.',
            self::CompactEmbed => 'A narrow card with a title and an item count, for a page that has already done the selling.',
            self::Chips => 'Products as pills to tap on and off, a list of what is picked, and a two-column delivery and payment block.',
            self::Invoice => 'A bill to agree to: a titled table of items, underlined inputs, monospace totals and a black Place order button.',
            self::DarkPanel => 'A navy summary panel beside a white form, for a page that wants the checkout to read as its own thing.',
        };
    }

    /**
     * Where the card's thumbnail is, under `public/`, or null while the layout
     * has none yet.
     *
     * A static picture shipped with the app, one per layout as each lands, so
     * a Merchant sees what they are choosing before they choose it.
     */
    public function thumbnail(): ?string
    {
        return match ($this) {
            self::Stacked => 'images/checkout-layouts/stacked.svg',
            self::Split,
            self::SplitReversed,
            self::Stepper,
            self::Accordion,
            self::BentoGrid,
            self::CompactEmbed,
            self::Chips,
            self::Invoice,
            self::DarkPanel => null,
        };
    }

    /**
     * What the Checkout tab calls this arrangement.
     */
    public function label(): string
    {
        return match ($this) {
            self::Stacked => 'Stacked',
            self::Split => 'Split',
            self::SplitReversed => 'Split reversed',
            self::Stepper => 'Stepper',
            self::Accordion => 'Accordion',
            self::BentoGrid => 'Bento grid',
            self::CompactEmbed => 'Compact embed',
            self::Chips => 'Chips',
            self::Invoice => 'Invoice',
            self::DarkPanel => 'Dark panel',
        };
    }

    /**
     * The ten, in the design's order, as the card picker lists them.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return self::byValue(fn (self $layout): string => $layout->label());
    }

    /**
     * The layouts that own words of their own, in the design's order — the
     * ones the Words tab has a section for.
     *
     * @return list<self>
     */
    public static function owningWords(): array
    {
        return array_values(array_filter(self::cases(), fn (self $layout): bool => $layout->wordSlots() !== []));
    }

    /**
     * @return array<string, string>
     */
    public static function descriptions(): array
    {
        return self::byValue(fn (self $layout): string => $layout->description());
    }

    /**
     * @return array<string, ?string>
     */
    public static function thumbnails(): array
    {
        return self::byValue(fn (self $layout): ?string => $layout->thumbnail());
    }

    /**
     * @template T
     *
     * @param  callable(self): T  $of
     * @return array<string, T>
     */
    private static function byValue(callable $of): array
    {
        $byValue = [];

        foreach (self::cases() as $layout) {
            $byValue[$layout->value] = $of($layout);
        }

        return $byValue;
    }
}
