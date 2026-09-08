<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Support\Payments\PaymentGateway;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One attempt to collect an Order's total through one online Payment Method,
 * from the moment the Customer is sent to the gateway until the gateway's
 * answer is verified.
 *
 * Its own axis on the Order (ADR-0013): it starts Pending, and only a
 * Verification the system made itself ever puts it in Paid. What the gateway
 * said at the start and when asked is kept whole on the row, because a
 * dispute months later needs what was actually said rather than what was
 * made of it at the time.
 */
#[Fillable([
    'order_id',
    'method',
    'state',
    'amount',
    'currency',
    'gateway_reference',
    'invoice_reference',
    'verified_at',
    'start_response',
    'verification_response',
])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * How long a Pending Payment holds off another on the same Order.
     *
     * A Customer who taps again seconds after being sent to the gateway is
     * about to pay twice; one who comes back a minute later with the
     * first attempt still unanswered has given up on it. About a minute is
     * the line between the two, and the Retry Page says so while it holds.
     */
    public const RETRY_WAIT_SECONDS = 60;

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'state' => PaymentState::class,
            'amount' => 'integer',
            'verified_at' => 'datetime',
            'start_response' => 'array',
            'verification_response' => 'array',
        ];
    }

    /**
     * Our reference for the next attempt on an Order: the order number plus an
     * attempt sequence, since gateways refuse a duplicate invoice id.
     *
     * Counted over every attempt the Order has made, whatever became of them,
     * so a number is never reused; the caller holds the Order's lock while
     * asking, or two Captures a second apart would count the same.
     */
    public static function nextInvoiceReference(Order $order): string
    {
        return $order->order_number.'-'.($order->payments()->count() + 1);
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The driver this Payment is collected through. Never null: a Payment is
     * only ever started by a method that has one.
     */
    public function gateway(): PaymentGateway
    {
        return $this->method->gateway() ?? throw new LogicException(
            "Payment [{$this->invoice_reference}] was started under {$this->method->getLabel()}, which has no driver."
        );
    }

    /**
     * The Processing Page the gateway sends the Customer back to: under this
     * Payment's method, by our invoice reference and nothing of the gateway's.
     */
    public function processingUrl(): string
    {
        return route('payment.processing', $this->routeParameters());
    }

    /**
     * The Verification the Processing Page calls once drawn.
     */
    public function verifyUrl(): string
    {
        return route('payment.verify', $this->routeParameters());
    }

    /**
     * Where the gateway tells the system on its own: one address per method,
     * naming no Payment, because the gateway says which in its own words and
     * the driver reads it.
     */
    public function notificationUrl(): string
    {
        return route('payment.notify', ['method' => $this->method->value]);
    }

    public function isPending(): bool
    {
        return $this->state === PaymentState::Pending;
    }

    /**
     * @return array{method: string, payment: string}
     */
    private function routeParameters(): array
    {
        return ['method' => $this->method->value, 'payment' => $this->invoice_reference];
    }
}
