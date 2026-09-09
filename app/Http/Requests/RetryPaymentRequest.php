<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a Customer choosing again on the Retry Page.
 *
 * A name and never anything more, like the chooser on the Checkout: what a
 * method does is read off the Order's Page, and a name the Page does not
 * offer now is refused beside the chooser rather than written onto the
 * Order. Required, because the Retry Page exists to make this one choice.
 */
class RetryPaymentRequest extends FormRequest
{
    /**
     * Whoever holds the order number holds the Order, as on the Order
     * Complete Page. There is no user to authorize.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'payment_method' => [
                'required',
                'string',
                Rule::in(PaymentMethod::names($this->order()->retryPaymentMethods())),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_method.required' => 'Please choose how you would like to pay.',
            'payment_method.in' => 'Please choose one of the payment methods listed.',
        ];
    }

    /**
     * The method the Customer chose, already checked against what the Page
     * offers.
     */
    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from($this->string('payment_method')->toString());
    }

    /**
     * The Order being retried, as the route resolved it by order number.
     */
    private function order(): Order
    {
        return $this->route('order');
    }
}
