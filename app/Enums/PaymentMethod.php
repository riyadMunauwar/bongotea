<?php

namespace App\Enums;

use App\Support\Payments\AamarPay;
use App\Support\Payments\Bkash;
use App\Support\Payments\CashOnDelivery;
use App\Support\Payments\PaymentGateway;
use App\Support\Payments\SslCommerz;
use App\Support\Settings\PaymentSettings;
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
 * one way. Whether one can be offered is the Payment Methods settings' to say
 * (`isUsable()`); whether a Payment can then be started is its driver's, and a
 * method with none captures the Order Unpaid and sends the Customer to the
 * Retry Page.
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
     * The methods that send the Customer to a gateway: every case but Cash
     * on Delivery, in the enum's order. What the Processing Page routes are
     * registered for.
     *
     * @return list<self>
     */
    public static function online(): array
    {
        return array_values(array_filter(self::cases(), fn (self $method): bool => $method->isOnline()));
    }

    /**
     * The driver this method pays through, or null while it has none.
     *
     * A method with no driver cannot start a Payment and cannot verify one,
     * so an Order placed under it stays Unpaid until the driver arrives. It
     * can still be offered once its settings are filled in: whether it is
     * offered is `isUsable()`'s answer, not this one's.
     */
    public function gateway(): ?PaymentGateway
    {
        return match ($this) {
            self::CashOnDelivery => new CashOnDelivery,
            self::SslCommerz => app(SslCommerz::class),
            self::Bkash => app(Bkash::class),
            self::AamarPay => app(AamarPay::class),
            self::PayStation => null,
        };
    }

    /**
     * Whether paying this way sends the Customer to a gateway before the
     * Thank-you Page, and so whether an Order placed under it is Unpaid until
     * a Payment is Paid. False is the whole of what Cash on Delivery is.
     *
     * Answered here rather than by the driver because one of the four
     * online methods has none yet and is already Unpaid when chosen; a
     * driver's `isOnline()` must agree with this.
     */
    public function isOnline(): bool
    {
        return $this !== self::CashOnDelivery;
    }

    /**
     * Whether an Order can be paid this way on this installation.
     *
     * The Merchant's switch, their credentials and the installation currency,
     * read from the Payment Methods settings, which is the one place that rule
     * lives. Nothing that draws or validates a method asks any other question,
     * and nothing here asks whether a driver exists: a method whose settings
     * are filled in is offered, and captures Unpaid until it can start a
     * Payment.
     */
    public function isUsable(): bool
    {
        return app(PaymentSettings::class)->isUsable($this);
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
     * Where a Checkout fetches the logo from: the Merchant's replacement where
     * the Payment Methods settings hold one, else the default.
     */
    public function logoUrl(): string
    {
        return app(PaymentSettings::class)->logoUrlFor($this);
    }
}
