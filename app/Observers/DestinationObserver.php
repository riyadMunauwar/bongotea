<?php

namespace App\Observers;

use App\Models\Destination;
use Illuminate\Database\Eloquent\Builder;

/**
 * The default flag, which a Destination does not get for free.
 *
 * Exactly one Destination per Provider carries it, so the store always has
 * somewhere sensible to report: the first Destination for a Provider takes
 * it, marking a second one unmarks the first, and deleting the one that holds
 * it passes it on rather than leaving the Provider with none.
 */
class DestinationObserver
{
    /**
     * The first Destination for a Provider is that Provider's default: a
     * Merchant who adds one and stops has not configured nothing.
     */
    public function creating(Destination $destination): void
    {
        if (! Destination::query()->where('provider', $destination->provider)->exists()) {
            $destination->is_default = true;
        }
    }

    public function saved(Destination $destination): void
    {
        $this->keepOneDefaultPerProvider($destination);
    }

    /**
     * A deleted Destination takes its outbox rows with it — they have nowhere
     * left to go — and hands its default flag to the oldest that remains, so
     * a tidy-up never silently stops reporting.
     */
    public function deleted(Destination $destination): void
    {
        if ($destination->is_default) {
            Destination::query()
                ->where('provider', $destination->provider)
                ->orderBy('id')
                ->first()
                ?->forceFill(['is_default' => true])
                ->saveQuietly();
        }
    }

    /**
     * Exactly one, both ways round: marking one default unmarks the rest of
     * its Provider's, and a save that left the Provider with none gives it
     * back to the oldest.
     *
     * The second half is the one that matters. Unticking the box is not a
     * way to say "report nowhere" — that is what switching a Destination off
     * is — and a Provider with no default silently stops the store reporting
     * to it while the screen still says it is reporting.
     *
     * Written quietly, and after the save rather than before it, because the
     * row that wins is the one that was just saved: doing it in `saving`
     * would let a failed write leave the Provider with no default at all.
     */
    private function keepOneDefaultPerProvider(Destination $destination): void
    {
        $siblings = fn (): Builder => Destination::query()->where('provider', $destination->provider);

        if ($destination->is_default) {
            $siblings()
                ->whereKeyNot($destination->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);

            return;
        }

        if ($siblings()->where('is_default', true)->exists()) {
            return;
        }

        $siblings()->oldest('id')->first()?->forceFill(['is_default' => true])->saveQuietly();
    }
}
