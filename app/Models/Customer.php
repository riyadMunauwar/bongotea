<?php

namespace App\Models;

use App\Support\PhoneNumber;
use Database\Factories\CustomerFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * A person identified by their phone number, and nothing else.
 *
 * A Customer holds no name and no address: those belong to each Order they
 * placed, because a phone number is an identity, not a person. This also
 * handles the case the domain actually produces — a shop owner ordering for
 * three buyers is one identity bucket with three correctly-distinct Orders.
 */
#[Fillable(['phone'])]
class Customer extends Model implements AuthenticatableContract
{
    use Authenticatable;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /**
     * What Signing in proves, and so what a signed-in Customer is known by:
     * the number, which exists before any row does (`CustomerGuard`).
     */
    public function getAuthIdentifierName(): string
    {
        return 'phone';
    }

    /**
     * The Customer for a number as typed, created if this is the first time
     * the number has been seen.
     *
     * Normalising here rather than at the call site is the point: two spellings
     * of one number must never become two Customers, and the only way to
     * guarantee that is to give callers no way to skip it.
     */
    public static function forPhone(string $phone): self
    {
        $canonical = PhoneNumber::normalise($phone);

        if ($canonical === null) {
            throw new InvalidArgumentException("[{$phone}] is not a usable phone number.");
        }

        return static::firstOrCreate(['phone' => $canonical]);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
