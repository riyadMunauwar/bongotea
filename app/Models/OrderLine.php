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
 *
 * `unit_cost` is the other half of the pair, stamped at Capture from the
 * weighted average the ledger held at that instant and immutable for the
 * same reason (ADR-0022): a Supply arriving next month must not rewrite what
 * this sale earned. Null is unknown rather than free — a Product nothing has
 * been recorded buying stamps none.
 *
 * `mrp` is the Product's MRP as it stood when the line was priced, so the
 * Saving the buyer was shown is still on the record after the MRP moves.
 *
 * `category_id` is the Category the Product was sold under, fixed at Capture
 * for the same reason: moving a Product to another Category must not rewrite
 * what was sold as what. Null once that Category is deleted.
 *
 * `tier_min`/`tier_max` name which of its Product's Tiers the price came
 * from, as the range stood when it was priced (ADR-0031). A line under a Price
 * Override records no Tier: `price_override_reason` and `price_overridden_by`
 * say why and who instead, and its `unit_price` is kept through any Repricing.
 */
#[ObservedBy(OrderLineObserver::class)]
#[Fillable(['product_id', 'category_id', 'product_name', 'unit_price', 'mrp', 'unit_cost', 'quantity', 'tier_min', 'tier_max', 'price_override_reason', 'price_overridden_by'])]
class OrderLine extends Model
{
    /** @use HasFactory<OrderLineFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'mrp' => 'integer',
            'unit_cost' => 'integer',
            'quantity' => 'integer',
            'line_total' => 'integer',
            'tier_min' => 'integer',
            'tier_max' => 'integer',
        ];
    }

    /**
     * The Order this line belongs to, trashed or not.
     *
     * `withTrashed` because OrderResource deliberately routes to soft-deleted
     * Orders, so their lines are still editable — and a line whose parent
     * resolves to null cannot recalculate the totals it just invalidated.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
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

    /**
     * The User who set this line's Price Override, filed away or not, so the
     * name stays on the record after they leave.
     *
     * @return BelongsTo<User, $this>
     */
    public function priceOverriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'price_overridden_by')->withTrashed();
    }

    /** Whether a User set this line's price by hand, which only an override's reason says. */
    public function isPriceOverridden(): bool
    {
        return $this->price_override_reason !== null;
    }
}
