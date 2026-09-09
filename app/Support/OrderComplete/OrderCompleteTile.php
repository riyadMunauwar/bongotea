<?php

namespace App\Support\OrderComplete;

/**
 * One of the three tiles a Cards grid layout draws under the headline: a
 * title and a line beneath it.
 *
 * Null on either means the built-in words for that position, which live on
 * the Config beside the other defaults.
 */
final class OrderCompleteTile
{
    public const TITLE_MAX_LENGTH = 60;

    public const LINE_MAX_LENGTH = 160;

    private function __construct(
        public readonly ?string $title,
        public readonly ?string $line,
    ) {}

    /** Nothing said about either. */
    public static function none(): self
    {
        return new self(null, null);
    }

    /**
     * Rebuild a tile from its stored shape, reading totally.
     */
    public static function fromArray(mixed $stored): self
    {
        if (! is_array($stored)) {
            return self::none();
        }

        return new self(
            OrderCompleteCopy::kept($stored['title'] ?? null, self::titleRefusal(...)),
            OrderCompleteCopy::kept($stored['line'] ?? null, self::lineRefusal(...)),
        );
    }

    public static function titleRefusal(string $title): ?string
    {
        return OrderCompleteCopy::lineRefusal('A tile\'s title', $title, self::TITLE_MAX_LENGTH);
    }

    public static function lineRefusal(string $line): ?string
    {
        return OrderCompleteCopy::textRefusal('A tile\'s line', $line, self::LINE_MAX_LENGTH);
    }

    /**
     * @return array{title: ?string, line: ?string}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'line' => $this->line,
        ];
    }
}
