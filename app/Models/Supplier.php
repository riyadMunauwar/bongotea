<?php

namespace App\Models;

use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Somebody the Merchant buys from, known by a name, a phone number and a
 * note.
 *
 * Recorded once and pointed at, rather than typed onto each delivery, so that
 * everything ever bought from one of them reads as one buying history instead
 * of as however somebody spelled the name that day.
 *
 * A Supplier is never a Customer. One is where goods come from and the other
 * is where they go, and nothing is shared between them — not even the phone,
 * which is canonicalised on a Customer because a Customer *is* their number
 * and is left as written here because a Supplier is not.
 *
 * Editable, unlike everything else in this area: a name spelled wrong or a
 * number that has changed is a correction to a reference rather than a
 * rewrite of something that happened. What happened is the Supply, and that
 * is immutable.
 */
#[Fillable(['name', 'phone', 'note'])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Everything ever delivered by them.
     *
     * @return HasMany<Supply, $this>
     */
    public function supplies(): HasMany
    {
        return $this->hasMany(Supply::class);
    }
}
