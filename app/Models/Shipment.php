<?php

namespace App\Models;

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use Database\Factories\ShipmentFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * One attempt to get one Order to a door through one Courier.
 *
 * Its own axis on the Order (ADR-0018), exactly as a Payment is: an Order
 * holds as many Shipments as it took, at most one of them Active, and neither
 * this nor `OrderStatus` ever writes the other. A parcel that came back and
 * was sent again is two rows, because the first attempt cost money whatever
 * the second one does.
 *
 * It records what it was told rather than what the Order says now: the
 * recipient as sent, what the Courier was told to collect, the Consignment
 * the Courier answered with, and what the Courier charged. What the Courier
 * said at the time is kept whole on the row, because a dispute months later
 * needs what was actually said rather than what was made of it.
 */
#[Fillable([
    'order_id',
    'courier',
    'status',
    'raw_status',
    'invoice_reference',
    'consignment_reference',
    'tracking_code',
    'collect_amount',
    'courier_charge',
    'recipient_name',
    'recipient_phone',
    'recipient_address',
    'recipient_district',
    'recipient_note',
    'parcel_details',
    'booked_at',
    'refused_at',
    'refusal_reason',
    'polled_at',
    'stuck_at',
    'abandoned_at',
    'abandoned_by',
    'booking_response',
])]
class Shipment extends Model
{
    /** @use HasFactory<ShipmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'courier' => Courier::class,
            'status' => ShipmentStatus::class,
            'collect_amount' => 'integer',
            'courier_charge' => 'integer',
            'booked_at' => 'datetime',
            'refused_at' => 'datetime',
            'polled_at' => 'datetime',
            'stuck_at' => 'datetime',
            'abandoned_at' => 'datetime',
            'parcel_details' => 'array',
            'booking_response' => 'array',
        ];
    }

    /**
     * Our reference for the next attempt on an Order: the order number plus
     * an attempt sequence, because a Courier refuses a duplicate invoice and
     * echoes this back on every answer it gives about the parcel.
     *
     * Shaped like a Payment's invoice reference and counted separately from
     * it: the two sequences are told to different companies and neither ever
     * sees the other's.
     *
     * Counted over the attempts that were actually made, so an attempt a
     * Courier refused — which records nothing (ADR-0020) — leaves the next
     * one free to take the number it would have had. A booking asks for it
     * under the Order's lock, or two bookings a second apart would count the
     * same.
     */
    public static function nextInvoiceReference(Order $order): string
    {
        return $order->order_number.'-'.($order->shipments()->count() + 1);
    }

    /**
     * What this Courier was told about the parcel itself, by the name its
     * driver declared the `ParcelField` under, or null where it was told
     * nothing of the sort.
     *
     * A driver reads what it sends from here rather than from Settings, so a
     * default changed between two attempts cannot rewrite what the first one
     * carried, and a per-parcel override in the send dialog is visible on the
     * row for as long as the row lives.
     */
    public function parcelDetail(string $name): ?string
    {
        $value = $this->parcel_details[$name] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Who wrote this attempt off, where somebody did. Abandoning is a time
     * and a name and never a status, so the Courier's last word survives it.
     *
     * @return BelongsTo<User, $this>
     */
    public function abandonedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'abandoned_by');
    }

    /**
     * Everything that happened to this attempt, as the system came to know
     * it, oldest first.
     *
     * @return HasMany<ShipmentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('id');
    }

    /**
     * Whether this attempt is still worth waiting on: the Courier has not
     * finished with it, it was not refused when it was offered, and nobody
     * has written it off.
     *
     * An Order has at most one, which is what stops a parcel being booked
     * twice, and an Order with none is an Order that may be sent. A row a
     * bulk booking wrote and has not dispatched yet counts — it is the one
     * parcel out for that Order from the moment the batch was accepted, which
     * is what stops the same Order being put in two batches.
     *
     * The refusal arm exists only because of those rows: a booking made while
     * a User waits records nothing when it is refused (ADR-0020), so the only
     * refusals that leave a row behind are the ones the sweep collects.
     */
    public function isActive(): bool
    {
        return ! $this->status->isTerminal()
            && $this->refused_at === null
            && $this->abandoned_at === null;
    }

    /**
     * The attempts still worth waiting on. The query behind `isActive()`,
     * and the one every Orders screen asks.
     *
     * @param  Builder<Shipment>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNotIn('status', ShipmentStatus::terminalValues())
            ->whereNull('refused_at')
            ->whereNull('abandoned_at');
    }

    /**
     * The attempts a bulk booking wrote down and nobody has handed to a
     * Courier yet — what `couriers:sweep` dispatches.
     *
     * Written and unconsigned, both: the status is what an operator reads and
     * the missing Consignment is what says the Courier has never seen this
     * parcel, and a row that has one is one to ask about rather than one to
     * send (ADR-0020).
     *
     * @param  Builder<Shipment>  $query
     */
    #[Scope]
    protected function awaitingDispatch(Builder $query): void
    {
        $query->active()
            ->where('status', ShipmentStatus::Written)
            ->whereNull('consignment_reference');
    }

    /**
     * The attempts that got the parcel to the door. The other half of over,
     * beside the one that came back — and what stops a delivered Order from
     * reading as one nobody ever sent.
     *
     * @param  Builder<Shipment>  $query
     */
    #[Scope]
    protected function arrived(Builder $query): void
    {
        $query->whereIn('status', ShipmentStatus::arrivalValues());
    }

    /**
     * Whether this attempt is written down and still waiting to be handed to
     * anybody: the `awaitingDispatch()` scope, asked of a row.
     *
     * Only a batch leaves one, and only until the next sweep (ADR-0020). The
     * Consignment is the half that matters to a caller — a row that has one
     * has been offered and taken, whatever else it says.
     */
    public function isAwaitingDispatch(): bool
    {
        return $this->isActive()
            && $this->status === ShipmentStatus::Written
            && $this->consignment_reference === null;
    }

    /**
     * Write this attempt off: a parcel a Courier never collected, or one held
     * so long there is nothing left to wait for.
     *
     * A time and a name and **never a status** (ADR-0018). The Courier's last
     * word is the fact worth keeping — a Shipment On Hold and then abandoned
     * says both, where a status overwritten would say only that somebody gave
     * up — so nothing here touches `status` or `raw_status`, and the timeline
     * is left exactly as the Courier wrote it. What the row loses is its
     * Active-ness, which is what frees the Order to be sent again.
     *
     * A parcel nobody has handed over yet cannot be written off, which is the
     * glossary read literally: Abandoned is said of a parcel a Courier never
     * *collected*, and a row awaiting dispatch has never been offered to one.
     * It is also what makes the missing lock sound. Abandoning otherwise only
     * ever takes a parcel out of Active, so it cannot make two parcels out for
     * one Order however it interleaves with a booking — but a row the sweep is
     * inside `bookAll()` for is the one case where that is untrue: written off
     * mid-call, it would come back holding a Consignment for a real parcel and
     * be out of Active all the same, freeing the Order to be sent a second
     * parcel nobody ordered. The state this refuses on is exactly the state
     * `CourierDispatcher::place()` writes, so there is no window between the
     * two: either the Consignment is not there yet and this refuses, or it is
     * and the parcel is a real one to write off.
     *
     * An attempt already written off is left exactly as it was: the decision
     * belongs to whoever made it, and a second User pressing the same button
     * must not be recorded as having made it.
     */
    public function abandon(User $by): void
    {
        if ($this->abandoned_at !== null || $this->isAwaitingDispatch()) {
            return;
        }

        $this->fill(['abandoned_at' => now(), 'abandoned_by' => $by->getKey()])->save();
    }

    /**
     * Write down something that happened to this parcel, once however many
     * times we are told it.
     *
     * The Courier's own word and its own time are what two tellings agree on,
     * so they are what the row is deduplicated by — and the database is what
     * enforces it (ADR-0019). The insert is made and the constraint is let to
     * refuse it, rather than asked about first: a poll and a webhook about
     * the same happening arrive at once, and a question asked before an
     * insert answers for a moment that has passed by the time it is written.
     * The first telling is answered with either way, so a caller can never
     * tell how many times it was told.
     */
    public function recordEvent(ShipmentStatus $status, ?string $rawStatus = null, DateTimeInterface|string|null $occurredAt = null): ShipmentEvent
    {
        $deduplicationId = ShipmentEvent::deduplicationIdFor($rawStatus ?? $status->value, $occurredAt);

        try {
            return $this->events()->create([
                'status' => $status,
                'raw_status' => $rawStatus,
                'occurred_at' => $occurredAt,
                'recorded_at' => now(),
                'deduplication_id' => $deduplicationId,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->events()->where('deduplication_id', $deduplicationId)->sole();
        }
    }

    /**
     * Write down a line a Courier said about this parcel that has no state
     * behind it — Pathao's "Package arrived at the sorting center" — without
     * moving anything.
     *
     * The bounded exception to "nothing inbound is believed" and the whole of
     * it (ADR-0019): narration arrives on a webhook and cannot be verified,
     * because the Courier that sends it keeps no history to ask. So it is
     * written as text and the row takes the state the parcel is **already**
     * in, which is exactly where it stood when the line was said. Nothing on
     * the Shipment is touched — not the canonical status, not `polled_at`,
     * not its Active-ness — so forged narration can add a noise line to one
     * timeline and move nothing else.
     *
     * Deduplicated on the words and the Courier's time like any other event,
     * so the same line told twice is one row — which is what the words are
     * cut to a row's length for.
     */
    public function recordNarration(string $narration, DateTimeInterface|string|null $occurredAt = null): ShipmentEvent
    {
        return $this->recordEvent($this->status, ShipmentEvent::asAWord($narration), $occurredAt);
    }
}
