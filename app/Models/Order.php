<?php

namespace App\Models;

use App\Enums\OrderKind;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Observers\OrderObserver;
use App\Observers\OrderTextObserver;
use App\Support\Settings\PaymentSettings;
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
 * A Customer's committed request for products, captured at an agreed price.
 *
 * An Order records everything it needs to be fulfilled without consulting
 * anything else: the customer's details and — on its lines — what was sold
 * and at what price. Every relation off this model is a reference for
 * context, never a source of truth for a figure.
 */
#[ObservedBy([OrderObserver::class, OrderTextObserver::class])]
#[Fillable([
    'kind',
    'customer_id',
    'customer_name',
    'customer_phone',
    'customer_email',
    'customer_address',
    'customer_district',
    'shop_name',
    'note',
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
     * What every order number starts with, so a number read out on the phone
     * or found in a text message is recognisably the store's.
     */
    private const NUMBER_PREFIX = 'BT-';

    /**
     * Six characters of that alphabet is ~10^9 orders — sparse enough that
     * the Order Complete Page cannot be found by walking the range, which is
     * what lets the number address that page at all, and short enough to
     * read back over a phone line.
     */
    private const NUMBER_LENGTH = 6;

    /**
     * The attribute `withPaidFlag()` loads the answer to `isPaid()` under.
     */
    private const PAID_FLAG = 'is_paid';

    /**
     * The attribute `withArrivedFlag()` loads the answer to `hasArrived()`
     * under.
     */
    private const ARRIVED_FLAG = 'has_arrived';

    /**
     * Mirrors the database defaults, because an Order exists before its lines
     * do: Capture inserts the Order and then attaches the line that gives it a
     * subtotal, and the total invariant has to hold over an integer in between.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'subtotal' => 0,
        'total_kilograms' => 0,
        'total' => 0,
        'status' => OrderStatus::New->value,
        'kind' => OrderKind::Bulk->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,

            /** Bulk or retail, settled at Capture and refused any change after it (ADR-0034). */
            'kind' => OrderKind::class,

            /**
             * How the Customer said they would pay, by name. Its own axis:
             * whether money moved is answered by the Order's Payments, and
             * neither this nor `status` ever overwrites the other (ADR-0013).
             */
            'payment_method' => PaymentMethod::class,

            'subtotal' => 'integer',
            'total_kilograms' => 'integer',
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
            $number = self::NUMBER_PREFIX;

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
     * The User who let the Order past Free to Sell with a reason (ADR-0037),
     * named even after they are filed away. Written only by Repricing, never
     * from the form, which is why neither column is fillable.
     *
     * @return BelongsTo<User, $this>
     */
    public function soldBeyondStockBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_beyond_stock_by')->withTrashed();
    }

    /**
     * Whether the Order's weight is held out of Free to Sell: neither
     * Cancelled nor deleted (ADR-0037). The status half of the set
     * `CommittedStock::withFreeToSell()` counts; the other half, not yet sent,
     * is the caller's to know.
     */
    public function holdsStock(): bool
    {
        return in_array($this->status, [OrderStatus::New, OrderStatus::Confirmed], true) && ! $this->trashed();
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
     * Every attempt to get this Order to a door, oldest first: at most one of
     * them Active, and the rest are what became of the parcels that went
     * before it (ADR-0018).
     *
     * @return HasMany<Shipment, $this>
     */
    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->orderBy('id');
    }

    /**
     * The attempt still worth waiting on, if there is one: what stops a
     * parcel being booked twice, and what an Orders screen draws. Null for an
     * Order that has never been sent, and for one whose every attempt is over.
     *
     * @return HasOne<Shipment, $this>
     */
    public function activeShipment(): HasOne
    {
        /** Newest first and read as one: the invariant says there is at most one, and an ordering says which even if that is ever broken. */
        return $this->hasOne(Shipment::class)->active()->latest('id');
    }

    /**
     * Whether a parcel is out for this Order. The one reading of it, asked by
     * the booking action, by the booking itself under the Order's lock, and
     * by the Orders list alike.
     *
     * The Orders list draws the Active Shipment on every row and then asks
     * this of every row again, so the loaded relation answers where there is
     * one: a page of fifty rows is one query rather than fifty-one. The
     * booking reads the Order fresh under its lock (`CourierBooker::book()`),
     * which loads no relations, so the invariant is never decided off a
     * relation somebody loaded a moment ago.
     */
    public function isShipping(): bool
    {
        if ($this->relationLoaded('activeShipment')) {
            return $this->activeShipment !== null;
        }

        return $this->shipments()->active()->exists();
    }

    /**
     * Whether any parcel was ever made for this Order, whatever became of it.
     * Its lines are locked from then on: what the courier will collect has
     * already been handed to them, so the Order is never repriced under it.
     */
    public function hasAnyShipment(): bool
    {
        return $this->shipments()->exists();
    }

    /**
     * Whether a parcel of this Order's ever got to the door — all of it, or
     * some of it.
     *
     * The half of "over" that ends an Order rather than reopening it: a
     * returned parcel is a question a User answers by sending again, where an
     * arrival is finished, and sending again after one would be a duplicate
     * parcel the Merchant pays for (ADR-0018). Asked by the booking under the
     * Order's lock and by the action that offers it, the way `isShipping()` is
     * — and by nothing else, so "already delivered" has one reading.
     *
     * The Orders list asks this of every row it draws, so it answers off the
     * flag where a query loaded one (`withArrivedFlag()`), exactly as
     * `isPaid()` does: a page of fifty rows is one query rather than fifty.
     * The booking reads the Order fresh under its lock, which loads no flag,
     * so the refusal is never decided off a column somebody selected a moment
     * ago.
     */
    public function hasArrived(): bool
    {
        if ($this->hasAttribute(self::ARRIVED_FLAG)) {
            return (bool) $this->getAttribute(self::ARRIVED_FLAG);
        }

        return $this->shipments()->arrived()->exists();
    }

    /**
     * The Orders still to be handed to a Courier: nothing out for them, and
     * nothing of theirs that ever reached a door.
     *
     * Deliberately not "no Active Shipment", which every delivered Order
     * satisfies too — a week-old installation would answer this with its
     * whole archive and bury the handful of Orders nobody has sent. Two
     * readings of over, and only one of them puts an Order back on the list:
     * a parcel that came back is a question a User has to answer — ring the
     * Customer, correct the address, send it again — where a parcel that
     * arrived is finished (ADR-0018). An attempt a User wrote off says the
     * parcel is dead, so its Order is to send again as well.
     *
     * @param  Builder<Order>  $query
     */
    #[Scope]
    protected function notYetSent(Builder $query): void
    {
        $query->whereDoesntHave('shipments', fn (Builder $shipments): Builder => $shipments
            ->where(fn (Builder $stillOut): Builder => $stillOut->active())
            ->orWhere(fn (Builder $atTheDoor): Builder => $atTheDoor->arrived()));
    }

    /**
     * What a Courier is told to collect at the door, in minor units: nothing
     * where money has already moved, the Order's total — Delivery Charge
     * included — otherwise.
     *
     * Read off the Payments and never off the Payment Method (ADR-0018): an
     * Unpaid Order that chose an online method ships with the full total,
     * because choosing to pay online is not paying, and a Cash on Delivery
     * Order whose Pending Payment turned out to have been taken must not be
     * collected from twice.
     */
    public function amountDueOnDelivery(): int
    {
        return $this->isPaid() ? 0 : $this->total;
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
     * Load the answer to `hasArrived()` onto every Order a query returns, for
     * the reason `withPaidFlag()` exists: the Orders list asks it of every row
     * to decide whether that row may still be sent, and a relation cannot
     * answer it the way the loaded Active Shipment answers `isShipping()` —
     * an Order's arrivals are the attempts it is *not* holding.
     *
     * @param  Builder<Order>  $query
     */
    #[Scope]
    protected function withArrivedFlag(Builder $query): void
    {
        $query->withExists(['shipments as '.self::ARRIVED_FLAG => fn (Builder $shipments): Builder => $shipments->arrived()]);
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
     * What the Customer wrote for the courier, or null where they wrote
     * nothing, so a screen draws a line only when there is one to draw.
     */
    public function deliveryNote(): ?string
    {
        return filled($this->note) ? trim($this->note) : null;
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
     * The Payment Methods the Retry Page offers this Order: the ones the
     * Checkout offers now, so a method switched off since Capture is not.
     *
     * @return list<PaymentMethod>
     */
    public function retryPaymentMethods(): array
    {
        return app(PaymentSettings::class)->offeredMethods();
    }

    /**
     * The Saving the Customer was shown: each line's MRP less what it charges,
     * times its Kilograms, as both were stamped when it was priced — never
     * today's MRP. A line stamped with no MRP saves nothing.
     */
    public function savingShown(): int
    {
        return (int) $this->lines->sum(fn (OrderLine $line): int => $line->mrp === null ? 0 : max(0, $line->mrp - $line->unit_price) * $line->quantity);
    }

    public function isRetail(): bool
    {
        return $this->kind === OrderKind::Retail;
    }

    /**
     * What the Order weighs, in grams: every line through `OrderLine::grams()`,
     * the one place a line's weight is said (ADR-0035).
     */
    public function grams(): int
    {
        return (int) $this->lines()->sum(OrderLine::grams());
    }

    /**
     * The Kilograms on the Order's bulk lines. A retail line holds a count of
     * Packs, not Kilograms, so it adds nothing here, and a retail Order's
     * `total_kilograms` is nought; its weight is read off its lines' grams
     * (ADR-0035). A bulk line names its Pack too, so its kind says which it is.
     */
    public function bulkKilograms(): int
    {
        return (int) $this->lines()->where('kind', OrderKind::Bulk)->sum('quantity');
    }

    /**
     * Re-derive the subtotal and the total Kilograms from the lines.
     *
     * Always from the lines' *snapshotted* figures — never from a Product's
     * current price, which would reintroduce the exact bug the snapshot closes.
     * `total` follows from the observer, so this is the only entry point
     * anything needs to call after a line changes.
     */
    public function recalculateSubtotal(): void
    {
        $this->subtotal = (int) $this->lines()->sum('line_total');
        $this->total_kilograms = $this->bulkKilograms();

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
