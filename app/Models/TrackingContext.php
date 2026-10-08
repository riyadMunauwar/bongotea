<?php

namespace App\Models;

use App\Enums\TrackingProvider;
use Database\Factories\TrackingContextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * What the browser told us at the moment something happened: where it was,
 * who it looked like, and which ad click brought it.
 *
 * None of it is personal in the sense the Order's own columns are, and all of
 * it is what a Provider matches on itself — which is why it is carried
 * un-hashed and kept apart from the Order.
 *
 * A Moment recorded while the browser is there is handed one of these in
 * memory and never saves it: the outbox row it writes carries the same
 * things, and a second copy would be a second thing to prune. What is kept is
 * the one case where the browser will be gone when the Moment arrives — an
 * Order paid online, whose `Purchase` belongs to the Verification that says
 * Paid — and `MomentRecorder::captured()` is the one place that decides so.
 *
 * The click identifiers are a bag keyed by the name each Provider knows them
 * under (`fbc`, `fbp`, `ttclid`), so one shape serves every Provider and each
 * takes only what it recognises. A visitor whose browser ran no script sends
 * none, and everything still works with an empty bag.
 */
#[Fillable([
    'order_id',
    'source_url',
    'ip_address',
    'user_agent',
    'click_ids',
])]
class TrackingContext extends Model
{
    /** @use HasFactory<TrackingContextFactory> */
    use HasFactory;

    /**
     * What a request names the bag of click identifiers the browser fills in
     * just before it submits.
     */
    public const CLICK_IDS_FIELD = 'click_ids';

    /**
     * Longer than any identifier a platform issues, and short enough that a
     * hand-made post cannot fill a column with an essay. A value past it is
     * cut rather than refused: tracking is never a precondition for buying,
     * so nothing about a click identifier may turn a Customer away.
     */
    private const MAX_CLICK_ID_LENGTH = 255;

    protected function casts(): array
    {
        return [
            'click_ids' => 'array',
        ];
    }

    /**
     * The Order this was the visit for. Trashed included, like the outbox's
     * own reference: an Order soft-deleted between Capture and the Moment
     * still happened.
     *
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    /**
     * Nothing known: what a Moment recorded outside a request has, and what a
     * Provider is handed when the browser is long gone and nothing was kept.
     */
    public static function none(): self
    {
        return new self;
    }

    /**
     * What one request says about the browser that made it.
     *
     * The source URL is passed in rather than read off the request, because
     * the address that matters to a Provider is the Page the visitor was on,
     * and a Capture arrives as a POST to the Checkout endpoint.
     */
    public static function fromRequest(Request $request, ?string $sourceUrl = null): self
    {
        return new self([
            'source_url' => $sourceUrl,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'click_ids' => self::clickIdsIn($request),
        ]);
    }

    /**
     * What was kept for this Order at Capture, or nothing where the browser's
     * word was never worth keeping — an Order that reported at Capture, or
     * one captured while the store reported nowhere at all.
     */
    public static function forOrder(Order $order): self
    {
        return $order->trackingContext ?? self::none();
    }

    /**
     * Keep this for the Order, once. The first visit's word is what stands:
     * whatever asks again is a later request, and a Provider is owed the
     * click that produced the Order rather than the one that happened to
     * re-submit it.
     */
    public function keepFor(Order $order): void
    {
        static::query()->firstOrCreate(
            ['order_id' => $order->getKey()],
            $this->only(['source_url', 'ip_address', 'user_agent', 'click_ids']),
        );
    }

    /**
     * The click identifiers a submission carried, reduced to the ones some
     * Provider recognises.
     *
     * Filtered rather than validated, and never a rule on the Capture: these
     * arrive from a script the visitor's browser may not have run, in a field
     * no Customer can see or correct, and an Order refused over one would be
     * a sale lost to tracking. A name no Provider knows is dropped, a value
     * that is not a string is dropped, and a long one is cut.
     *
     * @return array<string, string>
     */
    private static function clickIdsIn(Request $request): array
    {
        $sent = $request->input(self::CLICK_IDS_FIELD);

        if (! is_array($sent)) {
            return [];
        }

        $clickIds = [];

        foreach (self::clickIdNames() as $name) {
            $value = is_string($sent[$name] ?? null) ? trim($sent[$name]) : '';

            if ($value !== '') {
                $clickIds[$name] = Str::limit($value, self::MAX_CLICK_ID_LENGTH, '');
            }
        }

        return $clickIds;
    }

    /**
     * Every click identifier any Provider matches on, asked of the Providers
     * themselves so that adding TikTok adds `ttclid` here without this class
     * learning a platform's vocabulary.
     *
     * @return list<string>
     */
    private static function clickIdNames(): array
    {
        $names = [];

        foreach (TrackingProvider::cases() as $provider) {
            $names = [...$names, ...$provider->driver()->clickIdNames()];
        }

        return array_values(array_unique($names));
    }
}
