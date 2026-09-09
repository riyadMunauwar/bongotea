<?php

namespace App\Support\OrderComplete;

/**
 * One button on the Order Complete Page: what it says and where it goes, in
 * the Merchant's own words, or nothing on either count.
 *
 * What nothing means is the Config's to say, because it differs by button: a
 * primary button with no label says "Continue shopping" and one with no link
 * goes to the Page; a secondary button with no label is not drawn at all.
 */
final class OrderCompleteButton
{
    /** A button carries one short line: any longer and it wraps on a phone into something that no longer reads as a button. */
    public const LABEL_MAX_LENGTH = 60;

    private function __construct(
        public readonly ?string $label,
        public readonly ?string $link,
    ) {}

    /** Nothing said about either. */
    public static function none(): self
    {
        return new self(null, null);
    }

    /**
     * Rebuild a button from its stored shape, reading totally: a value the tab
     * would refuse is dropped rather than drawn, and something that is not a
     * shape at all is a button nobody wrote.
     */
    public static function fromArray(mixed $stored): self
    {
        if (! is_array($stored)) {
            return self::none();
        }

        return new self(
            OrderCompleteCopy::kept($stored['label'] ?? null, self::labelRefusal(...)),
            OrderCompleteCopy::kept($stored['link'] ?? null, self::linkRefusal(...)),
        );
    }

    /**
     * Why a button cannot say this, or null where it can.
     */
    public static function labelRefusal(string $label): ?string
    {
        return OrderCompleteCopy::lineRefusal('The button\'s label', $label, self::LABEL_MAX_LENGTH);
    }

    /**
     * Why a button cannot point here, or null where it can.
     */
    public static function linkRefusal(string $link): ?string
    {
        return OrderCompleteCopy::linkRefusal('The button\'s link', $link);
    }

    /**
     * @return array{label: ?string, link: ?string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'link' => $this->link,
        ];
    }
}
