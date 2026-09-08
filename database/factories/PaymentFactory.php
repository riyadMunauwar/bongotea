<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Settings\PaymentSettings;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * A Pending SSLCommerz attempt for its Order's total, the shape every
     * Payment starts in.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->state(['payment_method' => PaymentMethod::SslCommerz]),
            'method' => PaymentMethod::SslCommerz,
            'state' => PaymentState::Pending,
            'amount' => fn (array $attributes): int => Order::find($attributes['order_id'])->total,
            'currency' => PaymentSettings::CURRENCY,
            'invoice_reference' => fn (array $attributes): string => Payment::nextInvoiceReference(Order::find($attributes['order_id'])),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'state' => PaymentState::Paid,
            'gateway_reference' => fake()->regexify('[0-9]{12}[A-Z0-9]{6}'),
            'verified_at' => now(),
        ]);
    }
}
