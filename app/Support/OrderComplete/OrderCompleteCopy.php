<?php

namespace App\Support\OrderComplete;

/**
 * What every customizable string on the Order Complete Page has in common:
 * how a stored value is cleaned, how it is refused, and how the Order's own
 * facts are written into it.
 *
 * The refusals are worded here once and asked from two places — the Order
 * complete tab, where there is a Merchant to tell, and `fromArray()`, where
 * there is not — so the two cannot disagree about what a page may say.
 *
 * Two placeholders and no more, and neither names the Customer: their details
 * are shown in the Deliver-to block to the browser that placed the Order, and
 * copy that could carry them would carry them to every reader (ADR-0014).
 */
final class OrderCompleteCopy
{
    public const ORDER_NUMBER_PLACEHOLDER = '{order_number}';

    public const TOTAL_PLACEHOLDER = '{total}';

    /** Long enough for any address a Merchant would paste, short enough to refuse a paragraph. */
    public const LINK_MAX_LENGTH = 2000;

    /**
     * A stored value trimmed, or null where there was nothing but whitespace
     * or something that is not text at all. Null is the Merchant saying
     * nothing, and a page that says nothing keeps its own words.
     */
    public static function cleaned(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * The value where the page may say it, else null: the silent half of a
     * refusal, for a Config written by a route with nobody to tell.
     *
     * @param  callable(string): ?string  $refusal
     */
    public static function kept(mixed $value, callable $refusal): ?string
    {
        $value = self::cleaned($value);

        return $value !== null && $refusal($value) === null ? $value : null;
    }

    /**
     * Why a string that must fit on one line cannot be this, or null.
     */
    public static function lineRefusal(string $what, string $value, int $maxLength): ?string
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            return $what.' is one line.';
        }

        return self::textRefusal($what, $value, $maxLength);
    }

    /**
     * Why a string that may run to a few lines cannot be this, or null.
     */
    public static function textRefusal(string $what, string $value, int $maxLength): ?string
    {
        if (mb_strlen($value) > $maxLength) {
            return $what.' is at most '.$maxLength.' characters.';
        }

        return null;
    }

    /**
     * Why a button cannot point here, or null.
     *
     * A full web address or a path on this site, and nothing else: a scheme
     * the browser would run rather than fetch is refused, and so is anything
     * with a space in it, which is not an address at all.
     */
    public static function linkRefusal(string $what, string $value): ?string
    {
        if (mb_strlen($value) > self::LINK_MAX_LENGTH) {
            return $what.' is at most '.self::LINK_MAX_LENGTH.' characters.';
        }

        if (preg_match('~^(?:https?://\S+|/\S*)$~', $value) !== 1) {
            return $what.' is a full web address starting with http:// or https://, or a path on this site starting with /.';
        }

        return null;
    }

    /**
     * The text with the Order's own facts in place of the placeholders.
     */
    public static function fill(string $text, string $orderNumber, string $total): string
    {
        return str_replace(
            [self::ORDER_NUMBER_PLACEHOLDER, self::TOTAL_PLACEHOLDER],
            [$orderNumber, $total],
            $text,
        );
    }

    /**
     * A link with the Order's own facts in place of the placeholders, each
     * encoded so that a total with a currency sign in it stays one query value.
     */
    public static function fillLink(string $link, string $orderNumber, string $total): string
    {
        return self::fill($link, rawurlencode($orderNumber), rawurlencode($total));
    }
}
