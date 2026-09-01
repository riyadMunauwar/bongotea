<?php

namespace App\Models;

use App\Observers\OrderLineObserver;
use Database\Factories\OrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Product's worth of an Order: what was sold, at what unit price, and in
 * what quantity, as agreed at Capture.
 *
 * `product_name` and `unit_price` are immutable after Capture. A discount, if
 * one is ever wanted, arrives as its own explicit field rather than as a
 * hand-edited figure here — an adjustment should be visible as an adjustment.
 */
#[ObservedBy(OrderLineObserver::class)]
#[Fillable(['product_id', 'product_name', 'unit_price', 'quantity'])]
class OrderLine extends Model
{
    /** @use HasFactory<OrderLineFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'line_total' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The Product this line refers to, when it still exists. Everything needed
     * to fulfil the line is already on the line.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
