<?php

namespace App\Models;

use App\Enums\TrackingProvider;
use App\Observers\DestinationObserver;
use App\Support\Tracking\AsEncryptedSecret;
use App\Support\Tracking\Provider;
use Database\Factories\DestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One configured place a Provider's events are sent: a Facebook dataset and
 * the token that writes to it, under a name the Merchant chose.
 *
 * A Merchant may hold several — separate brands, separate clients — and marks
 * one default per Provider, which is where a Page that has picked nothing
 * reports. The observer keeps that "exactly one" true and flushes every
 * cached Page whenever one is saved or deleted, because a Destination's
 * dataset is baked into the Pixel snippet a cached Page carries.
 *
 * Whether this Destination can actually be sent to is not asked here: it is
 * the Provider's answer, in one wording, through `refusal()`.
 */
#[ObservedBy(DestinationObserver::class)]
#[Fillable([
    'label',
    'provider',
    'dataset',
    'access_token',
    'enabled',
    'is_default',
])]
class Destination extends Model
{
    /** @use HasFactory<DestinationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'provider' => TrackingProvider::class,
            'access_token' => AsEncryptedSecret::class,
            'enabled' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return HasMany<TrackingEvent, $this>
     */
    public function trackingEvents(): HasMany
    {
        return $this->hasMany(TrackingEvent::class);
    }

    /**
     * The driver that turns Moments into this Provider's wire format and
     * makes the call.
     */
    public function driver(): Provider
    {
        return $this->provider->driver();
    }

    /**
     * Why this Destination cannot be delivered to, in the Provider's own one
     * wording, or null where it can. Every call site asks this rather than
     * restating the rule.
     */
    public function refusal(): ?string
    {
        return $this->driver()->refusal($this);
    }

    public function isUsable(): bool
    {
        return $this->refusal() === null;
    }

    /**
     * Whether a credential is stored, without saying what it is: what the
     * form's "a value is stored" hint asks.
     */
    public function hasAccessToken(): bool
    {
        return filled($this->getRawOriginal('access_token'));
    }

    /**
     * Where a Page that has picked no Destination of its own reports: the one
     * marked default for each Provider, switched on.
     *
     * @param  Builder<Destination>  $query
     */
    #[Scope]
    protected function defaulted(Builder $query): void
    {
        $query->where('enabled', true)->where('is_default', true);
    }
}
