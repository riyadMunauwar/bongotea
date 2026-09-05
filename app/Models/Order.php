<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Observers\OrderObserver;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    'delivery_label',
    'delivery_charge',
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
     * the confirmation page cannot be found by walking the range, which is what
     * lets the number address that page at all.
     */
    private const NUMBER_LENGTH = 8;

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
