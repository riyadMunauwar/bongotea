<?php

namespace App\Enums;

use App\Support\Payments\CashOnDelivery;
use App\Support\Payments\PaymentGateway;
use Filament\Support\Contracts\HasLabel;

/**
 * One of the named ways an Order can be paid.
 *
 * A fixed set known by name rather than rows a Merchant defines: a Merchant has
 * one bKash account, and a Page's list of names survives a Settings row being
 * wiped. Each case resolves to one driver behind one contract, so adding a
 * gateway is one class and one case here (ADR-0013). The enum is the registry.
 *
 * The four online cases are named now so that a Page can already hold a list
 * that mentions them, and so that Orders, Payments and Settings all spell them
 * one way. Until each has its driver it resolves to nothing usable, and so is
 * offered nowhere whatever a Page says.
 *
 * Stored as a string column and cast, never as a native ENUM, for the reason
 * `OrderStatus` is one.
 */
enum PaymentMethod: string implements HasLabel
{
    case CashOnDelivery = 'cash_on_delivery';
    case Bkash = 'bkash';
    case SslCommerz = 'sslcommerz';
    case AamarPay = 'aamarpay';
    case PayStation = 'paystation';

    /**
     * Where the default logos live, under the document root.
     *
     * Shipped in the codebase rather than uploaded, so the Checkout looks right
     * with no upload from the Merchant; a replacement, when a Merchant makes
     * one, is a Settings matter and not this enum's.
     */
    private const LOGO_DIRECTORY = 'images/payment-methods';

    /**
     * What a stored name is, whatever it happens to hold.
     *
     * Total, like everything a Config is read from: a name nothing knows, or
     * something that is not a name at all, is null rather than an exception,
     * and the caller drops it. A landing page answering paid traffic with a
     * 500 costs more than a list one method shorter than a Merchant meant.
     */
    public static function fromStored(mixed $stored): ?self
    {
        return is_string($stored) ? self::tryFrom($stored) : null;
    }

    /**
     * A list of methods as the names they are stored and posted under.
     *
     * The one place the mapping is written, so a Config's stored shape and the
     * request's rule are one shape.
     *
     * @param  list<self>  $methods
     * @return list<string>
     */
    public static function names(array $methods): array
    {
        return array_map(fn (self $method): string => $method->value, $methods);
    }

    /**
     * What the Checkout tab and the Checkout call each method, keyed by name.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $method) {
            $labels[$method->value] = $method->getLabel();
        }

        return $labels;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::CashOnDelivery => 'Cash on Delivery',
            self::Bkash => 'bKash',
            self::SslCommerz => 'SSLCommerz',
            self::AamarPay => 'aamarPay',
            self::PayStation => 'PayStation',
        };
    }

    /**
     * The driver this method pays through, or null while it has none.
     *
     * Null is the whole of "resolves to nothing usable": a method with no
     * driver cannot start a Payment, cannot verify one and cannot be offered,
     * and every reading that draws or validates a method goes through
     * `isUsable()` for that reason.
     */
    public function gateway(): ?PaymentGateway
    {
        return match ($this) {
            self::CashOnDelivery => new CashOnDelivery,
            self::Bkash, self::SslCommerz, self::AamarPay, self::PayStation => null,
        };
    }

    /**
     * Whether an Order can be paid this way on this installation.
     *
     * Today that is whether the method has a driver at all. The Payment
     * Methods settings will add the Merchant's switch, their credentials and
     * the installation currency to this answer, and nothing that draws or
     * validates a method should ask any other question.
     */
    public function isUsable(): bool
    {
        return $this->gateway() !== null;
    }

    /**
     * The default logo, as a path under the document root.
     *
     * Named after the case with the underscores turned to hyphens, the way a
     * public file is usually named, so the asset can be found from the name
     * without a table.
     */
    public function logoPath(): string
    {
        return self::LOGO_DIRECTORY.'/'.str_replace('_', '-', $this->value).'.svg';
    }

    /**
     * Where a Checkout fetches the default logo from.
     */
    public function logoUrl(): string
    {
        return asset($this->logoPath());
    }
}
