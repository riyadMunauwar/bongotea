<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Support\PhoneNumber;
use Database\Factories\CustomerFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
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

    /**
     * Every line of every Order they placed, deleted Orders' left out.
     *
     * @return HasManyThrough<OrderLine, Order, $this>
     */
    public function orderLines(): HasManyThrough
    {
        return $this->hasManyThrough(OrderLine::class, Order::class);
    }

    /**
     * Each Customer with what their Orders come to, for the admin's Customers
     * screens: the name, shop and District on their latest Order, how many
     * Orders they placed whatever became of them and how many were Cancelled,
     * the Kilograms and Sales of the ones Sales counts, and the first and last
     * time they ordered. A deleted Order is in none of it.
     *
     * Latest is by when an Order was captured, so an Order typed in today for
     * last week does not name the Customer.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withOrderFigures(Builder $query): void
    {
        $notCancelled = fn (Builder $query): Builder => $query->where('status', '!=', OrderStatus::Cancelled->value);
        $linesNotCancelled = fn (Builder $query): Builder => $query->where('orders.status', '!=', OrderStatus::Cancelled->value);

        $latest = fn (string $column): Builder => Order::query()
            ->select($column)
            ->whereColumn('orders.customer_id', 'customers.id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1);

        $query
            ->select('customers.*')
            ->addSelect([
                'latest_name' => $latest('customer_name'),
                'latest_shop' => $latest('shop_name'),
                'latest_district' => $latest('customer_district'),
            ])
            ->withCount([
                'orders',
                'orders as cancelled_count' => fn (Builder $query): Builder => $query->where('status', OrderStatus::Cancelled->value),
            ])
            ->withSum(['orders as sales' => $notCancelled], 'total')
            ->withSum(['orderLines as kilograms' => $linesNotCancelled], 'order_lines.quantity')
            ->withMin('orders as first_ordered_at', 'created_at')
            ->withMax('orders as last_ordered_at', 'created_at');
    }
}
