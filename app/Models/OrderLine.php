<?php

namespace App\Models;

use App\Enums\OrderKind;
use App\Observers\OrderLineObserver;
use App\Support\Bangla;
use App\Support\Inventory\Grams;
use Database\Factories\OrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

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
 *
 * On a retail Order a line is one Pack's worth (ADR-0034): `pack_id` names the
 * Pack, `pack_grams` keeps its weight as it stood at Capture, `quantity` is
 * how many, `unit_price` the Pack Price, `mrp` the Pack's MRP and `unit_cost`
 * what one Pack's weight cost; it records no Tier. A Pack deleted later leaves
 * the line whole: the reference goes null and the weight stays.
 *
 * A bulk line names the Pack its whole Kilograms are supplied in the same way
 * (ADR-0036): `pack_id` and `pack_grams` as they stood at Capture, while
 * `quantity` stays its Kilograms and the Tiers alone price it. Its packet
 * count is never stored — it is read off the Kilograms and the recorded weight,
 * which are both fixed at Capture and so cannot disagree. A bulk line written
 * before Packs reached bulk names none.
 *
 * `kind` is its Order's, copied when the line is first written, so the line
 * says on its own row whether its quantity is Kilograms or Packs.
 */
#[ObservedBy(OrderLineObserver::class)]
#[Fillable(['product_id', 'category_id', 'pack_id', 'pack_grams', 'product_name', 'unit_price', 'mrp', 'unit_cost', 'quantity', 'tier_min', 'tier_max', 'price_override_reason', 'price_overridden_by'])]
class OrderLine extends Model
{
    /** @use HasFactory<OrderLineFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => OrderKind::class,
            'unit_price' => 'integer',
            'mrp' => 'integer',
            'unit_cost' => 'integer',
            'quantity' => 'integer',
            'line_total' => 'integer',
            'tier_min' => 'integer',
            'tier_max' => 'integer',
            'pack_grams' => 'integer',
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

    /**
     * The Pack this line sold, while it still exists; its weight is on the
     * line regardless.
     *
     * @return BelongsTo<Pack, $this>
     */
    public function pack(): BelongsTo
    {
        return $this->belongsTo(Pack::class);
    }

    /** Whether this line is one Pack's worth rather than Kilograms: a retail line. */
    public function isPack(): bool
    {
        return $this->kind === OrderKind::Retail;
    }

    /**
     * How many packets the line is sent in: a retail line's count, or the
     * packets a bulk line's Kilograms make of its recorded Pack weight. Null
     * for a bulk line that names no Pack.
     */
    public function packets(): ?int
    {
        return match (true) {
            $this->isPack() => $this->quantity,
            $this->pack_grams === null => null,
            default => Grams::packetsIn(Grams::fromKilograms($this->quantity), $this->pack_grams),
        };
    }

    /**
     * The line as the admin names it: "Assam, 250 g × 2" for a Pack, the
     * Product's name alone for Kilograms.
     */
    public function description(): string
    {
        return $this->isPack() ? "{$this->product_name}, ".Pack::nameFor($this->pack_grams)." × {$this->quantity}" : $this->product_name;
    }

    /**
     * The line as the Customer reads it on the Order Complete Page: "Assam,
     * ২৫০ গ্রাম × ২" for a Pack, "Assam × ৮ কেজি" for Kilograms.
     */
    public function banglaDescription(): string
    {
        if (! $this->isPack()) {
            return $this->product_name.' × '.Bangla::number($this->quantity).' কেজি';
        }

        return "{$this->product_name}, ".Pack::banglaNameFor($this->pack_grams).' × '.Bangla::number($this->quantity);
    }

    /** Whether a User set this line's price by hand, which only an override's reason says. */
    public function isPriceOverridden(): bool
    {
        return $this->price_override_reason !== null;
    }

    /**
     * How many grams a line takes off its Product's shelf, as a column
     * expression over `order_lines`.
     *
     * The one place the ledger reads a line's weight — booking, a Return, a
     * Partial Return, a write-off and Committed all sum this (ADR-0035). A bulk
     * line is its Kilograms × 1000 whatever Pack they come in, and a retail
     * line its Pack's weight as stamped at Capture × its count, so both leave
     * the same shelf.
     */
    public static function grams(): Expression
    {
        return DB::raw("case when order_lines.kind = '".OrderKind::Retail->value."' then order_lines.pack_grams else ".Grams::PER_KILOGRAM.' end * order_lines.quantity');
    }
}
