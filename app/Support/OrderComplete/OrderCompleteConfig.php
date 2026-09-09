<?php

namespace App\Support\OrderComplete;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Everything one Page decides about its own Order Complete Page: how it is
 * arranged, and what it says in the Merchant's own words.
 *
 * Self-contained and per Page like a Checkout Config, and not part of one: the
 * Checkout captures the Order, and this is where the Customer lands afterwards.
 * Nothing is inherited at render time, so editing one Page changes one Page.
 *
 * Every string is nullable and null means the built-in words, as the Order
 * Button does. The defaults, the maximum lengths and the refusals live here so
 * that what the tab shows as a placeholder and what the page draws are the same
 * sentence, and so that the tab's rules and the silent read cannot disagree.
 *
 * Copy may carry `{order_number}` and `{total}`, and no Customer fact: the
 * Customer sees themselves in the Deliver-to block, shown only to the browser
 * that placed the Order (ADR-0014).
 *
 * @implements Arrayable<string, mixed>
 */
class OrderCompleteConfig implements Arrayable
{
    public const DEFAULT_STATUS_LINE = 'Order confirmed';

    public const DEFAULT_HEADLINE = 'Your order is confirmed';

    public const DEFAULT_MESSAGE = 'Thank you. Your order has been placed.';

    public const DEFAULT_NOTE = 'We will call you shortly to confirm your order.';

    public const DEFAULT_PRIMARY_BUTTON_LABEL = 'Continue shopping';

    /**
     * The built-in words for the four steps, in English, for a Page that has
     * said nothing about them.
     *
     * @var list<array{label: string, timing: string}>
     */
    public const DEFAULT_STEPS = [
        ['label' => 'Order placed', 'timing' => 'Just now'],
        ['label' => 'Confirmation call', 'timing' => 'Within 2 hours'],
        ['label' => 'Shipped', 'timing' => 'Tomorrow'],
        ['label' => 'Delivered', 'timing' => '1–2 days'],
    ];

    /**
     * The built-in words for the three tiles, in English, for a Page that has
     * said nothing about them.
     *
     * @var list<array{title: string, line: string}>
     */
    public const DEFAULT_TILES = [
        ['title' => 'Order received', 'line' => 'We will call you within 2 hours to confirm.'],
        ['title' => 'Delivery in 1–2 days', 'line' => 'Your parcel is on its way once we have confirmed.'],
        ['title' => 'Pay on delivery', 'line' => 'Pay when your parcel arrives.'],
    ];

    public const STATUS_LINE_MAX_LENGTH = 60;

    public const HEADLINE_MAX_LENGTH = 120;

    public const MESSAGE_MAX_LENGTH = 300;

    public const NOTE_MAX_LENGTH = 300;

    public const STEP_COUNT = 4;

    public const TILE_COUNT = 3;

    /**
     * @param  list<OrderCompleteStep>  $steps  exactly four, by position
     * @param  list<OrderCompleteTile>  $tiles  exactly three, by position
     */
    private function __construct(
        public readonly OrderCompleteLayout $layout,
        public readonly ?string $statusLine,
        public readonly ?string $headline,
        public readonly ?string $message,
        public readonly ?string $note,
        public readonly OrderCompleteButton $primaryButton,
        public readonly OrderCompleteButton $secondaryButton,
        public readonly array $steps,
        public readonly array $tiles,
    ) {}

    /**
     * The page a Page starts with, and the one every existing Page was
     * backfilled to: Centered classic, every word the built-in one, a primary
     * button back to the Page and no second button.
     */
    public static function default(): self
    {
        return self::fromArray([]);
    }

    /**
     * Rebuild a Config from its stored shape.
     *
     * Total on purpose: a missing key, a layout nothing draws, a string too
     * long for its box or a value that is not text at all each read as the
     * default for that key, and nothing throws. A Customer with an old link is
     * owed the page, never a 500, whichever route wrote the Config.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        return new self(
            layout: OrderCompleteLayout::fromStored($stored['layout'] ?? null),
            statusLine: OrderCompleteCopy::kept($stored['status_line'] ?? null, self::statusLineRefusal(...)),
            headline: OrderCompleteCopy::kept($stored['headline'] ?? null, self::headlineRefusal(...)),
            message: OrderCompleteCopy::kept($stored['message'] ?? null, self::messageRefusal(...)),
            note: OrderCompleteCopy::kept($stored['note'] ?? null, self::noteRefusal(...)),
            primaryButton: OrderCompleteButton::fromArray($stored['primary_button'] ?? null),
            secondaryButton: OrderCompleteButton::fromArray($stored['secondary_button'] ?? null),
            steps: self::byPosition($stored['steps'] ?? null, self::STEP_COUNT, OrderCompleteStep::fromArray(...)),
            tiles: self::byPosition($stored['tiles'] ?? null, self::TILE_COUNT, OrderCompleteTile::fromArray(...)),
        );
    }

    /**
     * A fixed number of slots read by position: a stored list short of the
     * count is padded with slots nobody wrote, and one beyond it is cut.
     *
     * @template T
     *
     * @param  callable(mixed): T  $read
     * @return list<T>
     */
    private static function byPosition(mixed $stored, int $count, callable $read): array
    {
        $stored = is_array($stored) ? array_values($stored) : [];

        $slots = [];

        for ($position = 0; $position < $count; $position++) {
            $slots[] = $read($stored[$position] ?? null);
        }

        return $slots;
    }

    public static function statusLineRefusal(string $statusLine): ?string
    {
        return OrderCompleteCopy::lineRefusal('The status line', $statusLine, self::STATUS_LINE_MAX_LENGTH);
    }

    public static function headlineRefusal(string $headline): ?string
    {
        return OrderCompleteCopy::lineRefusal('The headline', $headline, self::HEADLINE_MAX_LENGTH);
    }

    public static function messageRefusal(string $message): ?string
    {
        return OrderCompleteCopy::textRefusal('The message', $message, self::MESSAGE_MAX_LENGTH);
    }

    public static function noteRefusal(string $note): ?string
    {
        return OrderCompleteCopy::textRefusal('The note', $note, self::NOTE_MAX_LENGTH);
    }

    /** The status line as it will be drawn, placeholders still in it. */
    public function statusLine(): string
    {
        return $this->statusLine ?? self::DEFAULT_STATUS_LINE;
    }

    /** The headline as it will be drawn, placeholders still in it. */
    public function headline(): string
    {
        return $this->headline ?? self::DEFAULT_HEADLINE;
    }

    /** The message as it will be drawn, placeholders still in it. */
    public function message(): string
    {
        return $this->message ?? self::DEFAULT_MESSAGE;
    }

    /** The note as it will be drawn, placeholders still in it. */
    public function note(): string
    {
        return $this->note ?? self::DEFAULT_NOTE;
    }

    /**
     * This Config's words for one Order: every string with its default filled
     * in and the Order's own facts in place of the placeholders, which is what
     * the page draws.
     *
     * @param  string  $total  already formatted, the figure the summary prints
     * @param  string  $pageUrl  the Page's own address, where a button with no link of its own goes
     */
    public function wordsFor(string $orderNumber, string $total, string $pageUrl): OrderCompleteWords
    {
        return new OrderCompleteWords($this, $orderNumber, $total, $pageUrl);
    }

    /**
     * @return array{layout: string, status_line: ?string, headline: ?string, message: ?string, note: ?string, primary_button: array{label: ?string, link: ?string}, secondary_button: array{label: ?string, link: ?string}, steps: list<array{label: ?string, timing: ?string}>, tiles: list<array{title: ?string, line: ?string}>}
     */
    public function toArray(): array
    {
        return [
            'layout' => $this->layout->value,
            'status_line' => $this->statusLine,
            'headline' => $this->headline,
            'message' => $this->message,
            'note' => $this->note,
            'primary_button' => $this->primaryButton->toArray(),
            'secondary_button' => $this->secondaryButton->toArray(),
            'steps' => array_map(fn (OrderCompleteStep $step): array => $step->toArray(), $this->steps),
            'tiles' => array_map(fn (OrderCompleteTile $tile): array => $tile->toArray(), $this->tiles),
        ];
    }
}
