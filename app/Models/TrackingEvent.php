<?php

namespace App\Models;

use App\Enums\TrackingEventState;
use App\Enums\TrackingMoment;
use Database\Factories\TrackingEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outbox row: one provider event, for one Destination, waiting to be
 * delivered.
 *
 * Written during a request and never sent from one. Everything a Provider is
 * told about the Customer is built in the sweep out of the Order this points
 * at, so the row itself holds no personal data and can be pruned without
 * touching a fulfilment record.
 */
#[Fillable([
    'destination_id',
    'moment',
    'event',
    'deduplication_id',
    'occurred_at',
    'page_id',
    'page_slug',
    'order_id',
    'source_url',
    'ip_address',
    'user_agent',
    'click_ids',
    'state',
])]
class TrackingEvent extends Model
{
    /** @use HasFactory<TrackingEventFactory> */
    use HasFactory;

    /**
     * A row starts Pending and having been attempted nothing, mirroring the
     * database defaults so a model made in memory reads the same as one read
     * back.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'state' => TrackingEventState::Pending->value,
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'moment' => TrackingMoment::class,
            'state' => TrackingEventState::class,
            'occurred_at' => 'datetime',
            'sent_at' => 'datetime',
            'click_ids' => 'array',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Destination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /**
     * The Order this event is about, where it has one. Trashed included: an
     * Order soft-deleted between Capture and the sweep still happened, and
     * the Provider is owed the event.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    /**
     * The rows a sweep picks up: Pending, oldest first, so a Destination's
     * batch is in the order the Moments happened.
     *
     * @param  Builder<TrackingEvent>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('state', TrackingEventState::Pending)->orderBy('id');
    }
}
