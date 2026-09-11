<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Observers\OrderObserver;
use App\Support\Checkout\StyleTokens;
use App\Support\Money;
use App\Support\OrderComplete\OrderCompleteConfig;
use App\Support\OrderComplete\OrderCompleteWords;
use App\Support\Orders\AsCapturedAnswers;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A Customer's committed request for products, captured on a Page at an agreed
 * price.
 *
 * An Order records everything it needs to be fulfilled without consulting
 * anything else: the customer's details, the page it came from, and — on its
 * lines — what was sold and at what price. Every relation off this model is a
 * reference for context, never a source of truth for a figure.
 */
#[ObservedBy(OrderObserver::class)]
#[Fillable([
    'customer_id',
    'page_id',
    'page_slug',
    'customer_name',
    'customer_phone',
    'customer_email',
    'customer_address',
    'customer_district',
    'answers',
    'delivery_label',
    'delivery_charge',
    'payment_method',
    'status',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * Unambiguous over a phone line: no `0`/`O` and no `1`/`I`, so an operator
     * reading a number back to a customer cannot mis-hear it into a different
     * order.
     */
    private const NUMBER_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    /**
     * Eight characters of that alphabet is ~10^12 orders — sparse enough that
     * the Order Complete Page cannot be found by walking the range, which is what
     * lets the number address that page at all.
     */
    private const NUMBER_LENGTH = 8;

    /**
     * The attribute `withPaidFlag()` loads the answer to `isPaid()` under.
     */
    private const PAID_FLAG = 'is_paid';

    /**
     * The key of the Merchant's question whose answer is the Customer's note
     * for the courier; see `deliveryNote()`.
     */
    private const NOTE_ANSWER_KEY = 'note';

    /**
     * Mirrors the database defaults, because an Order exists before its lines
     * do: Capture inserts the Order and then attaches the line that gives it a
     * subtotal, and the total invariant has to hold over an integer in between.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'subtotal' => 0,
        'total' => 0,
        'status' => OrderStatus::New->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,

            /**
             * How the Customer said they would pay, by name. Its own axis:
             * whether money moved is answered by the Order's Payments, and
             * neither this nor `status` ever overwrites the other (ADR-0013).
             */
            'payment_method' => PaymentMethod::class,

            /**
             * The answers to the questions this Page's Merchant added of their
             * own, each carrying the wording and the control it was asked with.
             * Read as an empty list where a Checkout asked none, so a screen
             * never branches on whether an Order has a snapshot.
             */
            'answers' => AsCapturedAnswers::class,

            'subtotal' => 'integer',
            'delivery_charge' => 'integer',
            'total' => 'integer',
        ];
    }

    /**
     * An unused order number.
     *
     * Collision is checked rather than assumed: the alphabet makes one
     * astronomically unlikely, but the column is unique and a duplicate would
     * surface as a failed Capture — a lost order — rather than as a warning.
     */
    public static function generateNumber(): string
    {
        do {
            $number = '';

            for ($i = 0; $i < self::NUMBER_LENGTH; $i++) {
                $number .= self::NUMBER_ALPHABET[random_int(0, strlen(self::NUMBER_ALPHABET) - 1)];
            }
        } while (static::withTrashed()->where('order_number', $number)->exists());

        return $number;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The Page this Order was captured on, when it still exists. `page_slug`
     * is the attribution that survives regardless.
     *
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class)->withTrashed();
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /**
     * Every attempt to collect this Order's total online, oldest first: at
     * most one ends Paid, and the rest are what the gateway said about the
     * others (ADR-0013).
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /**
     * What the browser told us when this Order was captured, kept only where
     * the Moment that will report it is still to come — an Order paid online,
     * whose `Purchase` belongs to the Verification that says Paid and happens
     * with no browser present.
     *
     * Deliberately not part of the Order: an Order records what it needs to
     * be fulfilled, and it is kept indefinitely, which an IP address must not
     * be.
     *
     * @return HasOne<TrackingContext, $this>
     */
    public function trackingContext(): HasOne
    {
        return $this->hasOne(TrackingContext::class);
    }

    /**
     * Whether money has moved for this Order: any Payment a Verification
     * has marked Paid. Derived from the Payments and never a column here,
     * because the answer is theirs; a Paid Order still starts New and needs
     * the confirmation call.
     */
    public function isPaid(): bool
    {
        if ($this->hasAttribute(self::PAID_FLAG)) {
            return (bool) $this->getAttribute(self::PAID_FLAG);
        }

        return $this->paidPayments()->exists();
    }

    /**
     * The payment axis in one badge, as the queue and the Order page both draw
     * it: the method by name, and whether money has moved. Paid is said
     * whatever the method, because an Order switched to Cash on Delivery
     * while a Payment was Pending can still end Paid, and that is the one
     * case where the courier must not also collect. Cash on Delivery with no
     * Paid Payment says only its name: it is simply due.
     */
    public function paymentBadgeLabel(): string
    {
        $method = $this->payment_method->getLabel();

        return match (true) {
            $this->isPaid() => $method.' · Paid',
            $this->payment_method->isOnline() => $method.' · Unpaid',
            default => $method,
        };
    }

    /**
     * Unpaid is the one word drawn in red, because the call an operator makes
     * about it is a different call — "pay now or switch to cash" rather than
     * "confirm the address".
     */
    public function paymentBadgeColor(): string
    {
        return match (true) {
            $this->isPaid() => 'success',
            $this->payment_method->isOnline() => 'danger',
            default => 'gray',
        };
    }

    /**
     * Load the answer to `isPaid()` onto every Order a query returns, so a
     * screen listing Orders asks the database once rather than once per row.
     *
     * @param  Builder<Order>  $query
     */
    #[Scope]
    protected function withPaidFlag(Builder $query): void
    {
        $query->withExists('paidPayments as '.self::PAID_FLAG);
    }

    /**
     * The Unpaid Orders: an online method and no Paid Payment. What an
     * operator chases with "pay now or switch to cash".
     *
     * @param  Builder<Order>  $query
     */
    #[Scope]
    protected function unpaid(Builder $query): void
    {
        $query->where('payment_method', '!=', PaymentMethod::CashOnDelivery)
            ->whereDoesntHave('paidPayments');
    }

    /**
     * The Orders that are both Paid and Cancelled at once: the operator's
     * decision and the money's story, kept side by side until somebody refunds
     * (ADR-0013). What an operator opens this list to refund.
     *
     * @param  Builder<Order>  $query
     */
    #[Scope]
    protected function paidButCancelled(Builder $query): void
    {
        $query->where('status', OrderStatus::Cancelled)
            ->whereHas('paidPayments');
    }

    /**
     * Whether money moved more than once: two Payments a Verification has
     * marked Paid. Each is recorded as it is, because it happened; the Order
     * is flagged, because somebody must refund one (ADR-0013).
     */
    public function isPaidTwice(): bool
    {
        return $this->paidPayments()->count() > 1;
    }

    /**
     * The Payments a Verification has marked Paid: the one reading of "money
     * moved for this Order", oldest first.
     *
     * @return HasMany<Payment, $this>
     */
    public function paidPayments(): HasMany
    {
        return $this->payments()->where('state', PaymentState::Paid);
    }

    /**
     * The Payments nothing has been verified about yet: the ones a Verification
     * is still worth making for, and the ones "check with gateway" asks about.
     *
     * @return HasMany<Payment, $this>
     */
    public function pendingPayments(): HasMany
    {
        return $this->payments()->where('state', PaymentState::Pending);
    }

    /**
     * The Payment the Customer was sent to the gateway for only seconds ago
     * and nothing has been verified about yet, if there is one: what holds
     * off starting another, so a Customer tapping again cannot pay twice.
     * Null once the wait is over, or once it is settled either way.
     */
    public function recentPendingPayment(): ?Payment
    {
        return $this->payments()
            ->where('state', PaymentState::Pending)
            ->where('created_at', '>', now()->subSeconds(Payment::RETRY_WAIT_SECONDS))
            ->latest('id')
            ->first();
    }

    /**
     * Whether this Order chose an online Payment Method and has no Paid
     * Payment: none started, or every one Pending, Failed, Cancelled or
     * Expired. An Unpaid Order sits in the queue like any other; the call an
     * operator makes about it is a different call (ADR-0013).
     */
    public function isUnpaid(): bool
    {
        return $this->payment_method->isOnline() && ! $this->isPaid();
    }

    /**
     * What the Customer wrote for the courier, when the Page asked and they
     * answered.
     *
     * The Checkout has no built-in note; a Merchant who wants one adds a
     * question keyed `note`, and this is what the Customer typed into it.
     * Null where the Page never asked, the Customer left it blank, or the
     * question keyed so is not one a person types into, so the Order Complete
     * Page draws a line only when there is one to draw.
     */
    public function deliveryNote(): ?string
    {
        foreach ($this->answers as $answer) {
            if ($answer->key === self::NOTE_ANSWER_KEY && $answer->optionLabel === null && is_string($answer->value) && trim($answer->value) !== '') {
                return $answer->value;
            }
        }

        return null;
    }

    /**
     * The page that follows this Order as it stands: the Retry Page while it
     * is Unpaid, the Order Complete Page once it is settled as far as the Checkout
     * is concerned. The one wording of that choice, read after a Capture, a
     * choice on the Retry Page and a Verification alike.
     */
    public function followingPageUrl(): string
    {
        return $this->isUnpaid()
            ? route('checkout.retry', $this->order_number)
            : route('checkout.order-complete', $this->order_number);
    }

    /**
     * What this Order's Order Complete Page says and how it is arranged: the
     * Config of the Page it was captured on, read as it stands now rather than
     * snapshotted. The default Config where the Page is gone, because a
     * Customer with an old link is owed the page and never an error.
     */
    public function orderCompleteConfig(): OrderCompleteConfig
    {
        return $this->page?->order_complete_config ?? OrderCompleteConfig::default();
    }

    /**
     * What this Order's Order Complete Page says: the Config's words with this
     * Order's own number and total in place of the placeholders, and the Page
     * it was captured on as the address a button with no link of its own goes
     * to. That address survives the Page: `page_slug` is the attribution kept
     * for exactly that.
     */
    public function orderCompleteWords(): OrderCompleteWords
    {
        return $this->orderCompleteConfig()->wordsFor(
            $this->order_number,
            Money::format($this->total),
            route('landing.show', $this->page_slug),
        );
    }

    /**
     * What this Order's Page styles its Checkout with, for the page after the
     * order to match the form before it. Nothing said where the Page is gone,
     * which completes to the look the page has always had.
     */
    public function pageStyleTokens(): StyleTokens
    {
        return $this->page?->checkout_config->styleTokens ?? StyleTokens::none();
    }

    /**
     * The Payment Methods the Retry Page offers this Order: what its Page
     * offers now, read through the same filtered list the Checkout draws
     * from. Cash on Delivery alone where the Page is gone, because an Order
     * with nothing to be paid through is still owed a way to be settled.
     *
     * @return list<PaymentMethod>
     */
    public function retryPaymentMethods(): array
    {
        return $this->page?->checkout_config->offeredMethods() ?? [PaymentMethod::CashOnDelivery];
    }

    /**
     * Re-derive the subtotal from the lines.
     *
     * Always from the lines' *snapshotted* figures — never from a Product's
     * current price, which would reintroduce the exact bug the snapshot closes.
     * `total` follows from the observer, so this is the only entry point
     * anything needs to call after a line changes.
     */
    public function recalculateSubtotal(): void
    {
        $this->subtotal = (int) $this->lines()->sum('line_total');

        $this->save();
    }

    /**
     * An address that changed between Capture and dispatch is exactly what you
     * want a trail for when a parcel goes missing.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
