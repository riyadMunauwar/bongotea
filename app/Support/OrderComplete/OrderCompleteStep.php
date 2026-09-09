<?php

namespace App\Support\OrderComplete;

/**
 * One of the four steps a Timeline layout draws: what happens, and when.
 *
 * Null on either means the built-in words for that position, which live on
 * the Config beside the other defaults.
 */
final class OrderCompleteStep
{
    public const LABEL_MAX_LENGTH = 40;

    public const TIMING_MAX_LENGTH = 40;

    private function __construct(
        public readonly ?string $label,
        public readonly ?string $timing,
    ) {}

    /** Nothing said about either. */
    public static function none(): self
    {
        return new self(null, null);
    }

    /**
     * Rebuild a step from its stored shape, reading totally.
     */
    public static function fromArray(mixed $stored): self
    {
        if (! is_array($stored)) {
            return self::none();
        }

        return new self(
            OrderCompleteCopy::kept($stored['label'] ?? null, self::labelRefusal(...)),
            OrderCompleteCopy::kept($stored['timing'] ?? null, self::timingRefusal(...)),
        );
    }

    public static function labelRefusal(string $label): ?string
    {
        return OrderCompleteCopy::lineRefusal('A step\'s label', $label, self::LABEL_MAX_LENGTH);
    }

    public static function timingRefusal(string $timing): ?string
    {
        return OrderCompleteCopy::lineRefusal('A step\'s timing', $timing, self::TIMING_MAX_LENGTH);
    }

    /**
     * @return array{label: ?string, timing: ?string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'timing' => $this->timing,
        ];
    }
}
