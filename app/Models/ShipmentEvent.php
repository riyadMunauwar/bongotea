<?php

namespace App\Models;

use App\Enums\ShipmentStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ShipmentEventFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing that happened to a Shipment, as the system came to know it.
 *
 * It carries two times (ADR-0018): `occurred_at`, when the Courier says it
 * happened, which most couriers do not say for most of what they report; and
 * `recorded_at`, when we found out, which is always there. A timeline drawing
 * only ours would misdate every parcel from the couriers that say least, and
 * one drawing only theirs would show nothing at all for Steadfast.
 *
 * Rows are written once however many times the same happening is told to us —
 * a poll and a webhook minutes apart, or RedEx's whole history re-imported on
 * every poll. What two tellings agree on is the Courier's own word and its
 * own time, and that is the `deduplication_id` a unique key is held over.
 */
#[Fillable([
    'shipment_id',
    'status',
    'raw_status',
    'occurred_at',
    'recorded_at',
    'deduplication_id',
])]
class ShipmentEvent extends Model
{
    /** @use HasFactory<ShipmentEventFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ShipmentStatus::class,
            'occurred_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * What two tellings of one happening agree on: the Courier's own word for
     * it, and the Courier's own time where there is one.
     *
     * A string and not the two columns, because SQL counts two nulls as
     * distinct and a Courier that gives no times would slip the same
     * happening past a unique key on every poll.
     */
    public static function deduplicationIdFor(string $word, DateTimeInterface|string|null $occurredAt = null): string
    {
        /** Read to an instant and spelled one way, so a time told twice in two spellings is one happening. */
        $at = blank($occurredAt) ? '' : CarbonImmutable::parse($occurredAt)->utc()->toAtomString();

        return $word.'@'.$at;
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    /**
     * When this is drawn on a timeline: the Courier's own time where it gave
     * one, ours otherwise. Never null, so a timeline never has a hole in it.
     */
    public function happenedAt(): DateTimeInterface
    {
        return $this->occurred_at ?? $this->recorded_at;
    }
}
