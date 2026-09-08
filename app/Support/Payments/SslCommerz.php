<?php

namespace App\Support\Payments;

use App\Enums\PaymentState;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Support\Money;
use App\Support\Settings\PaymentSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * SSLCommerz's hosted checkout: a form post starts a session and answers the
 * page to send the Customer to, the Customer is posted back with a validation
 * id, and the validation API is asked by that id.
 *
 * The one gateway that takes the Notification URL with every Payment rather
 * than having it registered through support. Sandbox and live are two hosts
 * under the same paths, picked by the Merchant's switch.
 *
 * Every mandatory field the start call wants is filled from the Order, and
 * where the Checkout never asked the question a fixed placeholder stands in:
 * the Checkout does not grow a question for the gateway's sake, and a blank
 * refused by the gateway would be a Customer turned away for a question the
 * Merchant chose not to ask.
 */
class SslCommerz implements PaymentGateway
{
    private const SANDBOX_HOST = 'https://sandbox.sslcommerz.com';

    private const LIVE_HOST = 'https://securepay.sslcommerz.com';

    private const START_PATH = '/gwprocess/v4/api.php';

    private const VALIDATION_PATH = '/validator/api/validationserverAPI.php';

    private const TRANSACTION_QUERY_PATH = '/validator/api/merchantTransIDvalidationAPI.php';

    /** The two answers the validation API gives a Payment that was taken. */
    private const PAID_STATUSES = ['VALID', 'VALIDATED'];

    /**
     * What stands in for a detail the Checkout did not ask. The email is in
     * a reserved domain, so nothing SSLCommerz sends to it reaches anyone.
     */
    private const PLACEHOLDER_NAME = 'Customer';

    private const PLACEHOLDER_EMAIL = 'customer@example.com';

    private const PLACEHOLDER_ADDRESS = 'Not provided';

    private const PLACEHOLDER_CITY = 'Dhaka';

    private const COUNTRY = 'Bangladesh';

    /** Seconds to connect, and seconds for the whole call: a slow gateway must not hold a Capture for long. */
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 15;

    public function __construct(
        private readonly PaymentSettings $settings,
        private readonly Http $http,
    ) {}

    public function isOnline(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function credentialProperties(): array
    {
        return ['sslcommerzStoreId', 'sslcommerzStorePassword'];
    }

    public function takesNotificationUrl(): bool
    {
        return true;
    }

    public function start(Payment $payment, string $returnUrl, ?string $notificationUrl): PaymentStarted
    {
        $order = $payment->order;

        $fields = [
            ...$this->credentials(),
            'total_amount' => Money::toMajorString($payment->amount),
            'currency' => $payment->currency,
            'tran_id' => $payment->invoice_reference,
            'success_url' => $returnUrl,
            'fail_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'cus_name' => $order->customer_name ?: self::PLACEHOLDER_NAME,
            'cus_email' => $order->customer_email ?: self::PLACEHOLDER_EMAIL,
            'cus_add1' => $order->customer_address ?: self::PLACEHOLDER_ADDRESS,
            'cus_city' => $order->customer_district ?: self::PLACEHOLDER_CITY,
            'cus_country' => self::COUNTRY,
            'cus_phone' => $order->customer_phone,
            'product_name' => Str::limit($order->lines->map(fn (OrderLine $line): string => $line->product_name)->implode(', '), 255, ''),
            'product_category' => 'General',
            'product_profile' => 'general',
            'shipping_method' => 'NO',
            'num_of_item' => $order->lines->count(),
        ];

        if ($notificationUrl !== null) {
            $fields['ipn_url'] = $notificationUrl;
        }

        try {
            $response = $this->client()->asForm()->post($this->host().self::START_PATH, $fields);
        } catch (ConnectionException $exception) {
            throw new PaymentNotStarted(
                "SSLCommerz could not be reached to start Payment [{$payment->invoice_reference}].",
                ['error' => $exception->getMessage()],
            );
        }

        $answer = $this->body($response);

        if (($answer['status'] ?? null) !== 'SUCCESS' || blank($answer['GatewayPageURL'] ?? null)) {
            throw new PaymentNotStarted(
                "SSLCommerz refused to start Payment [{$payment->invoice_reference}]: ".($answer['failedreason'] ?? "HTTP {$response->status()}"),
                $answer,
            );
        }

        return new PaymentStarted($answer['GatewayPageURL'], $answer);
    }

    /**
     * The validation id the return carries once something was attempted; a
     * Customer who cancelled on the gateway's page is sent back without one.
     */
    public function paymentReferenceFromReturn(Request $request): ?string
    {
        return $this->validationId($request);
    }

    /**
     * The IPN: the return's fields posted to the ipn_url by SSLCommerz itself,
     * so it names the Payment by our tran_id and carries the val_id and the
     * amount beside it. Read for those and not for its status.
     */
    public function readNotification(Request $request): ?Notification
    {
        $invoiceReference = $request->input('tran_id');

        if (! is_string($invoiceReference) || $invoiceReference === '') {
            return null;
        }

        $amount = $request->input('amount');
        $currency = $request->input('currency');

        return new Notification(
            $invoiceReference,
            $this->validationId($request),
            is_scalar($amount) && is_numeric($amount) ? Money::toMinorUnits($amount) : null,
            is_string($currency) && $currency !== '' ? Str::upper($currency) : null,
        );
    }

    public function verify(Payment $payment): Verification
    {
        try {
            return $payment->gateway_reference !== null
                ? $this->verifyByValidationId($payment)
                : $this->verifyByInvoiceReference($payment);
        } catch (ConnectionException $exception) {
            return new Verification(PaymentState::Pending, ['error' => $exception->getMessage()]);
        }
    }

    /**
     * The validation API, by the id the Customer came back with: the one
     * answer SSLCommerz gives about a single attempt.
     *
     * Believed only where the answer names this Payment. The id arrived on an
     * unauthenticated return, so an id that is not ours — invalid, or some
     * other transaction's — says nothing about this Payment, and the question
     * is asked again by our own reference rather than settled on it.
     */
    private function verifyByValidationId(Payment $payment): Verification
    {
        $response = $this->ask(self::VALIDATION_PATH, ['val_id' => $payment->gateway_reference, 'v' => '1']);

        $answer = $this->body($response);

        if (! $response->successful() || ! isset($answer['status'])) {
            return new Verification(PaymentState::Pending, $answer + ['http_status' => $response->status()]);
        }

        if (! $this->namesThisPayment($payment, $answer)) {
            return $this->verifyByInvoiceReference($payment);
        }

        return new Verification($this->stateOf($payment, $answer), $answer, $answer['val_id'] ?? null);
    }

    /**
     * The transaction query API, by our invoice reference: every attempt
     * SSLCommerz knows under it, so a Payment the Customer cancelled or never
     * returned from is still answered for.
     */
    private function verifyByInvoiceReference(Payment $payment): Verification
    {
        $response = $this->ask(self::TRANSACTION_QUERY_PATH, ['tran_id' => $payment->invoice_reference]);

        $answer = $this->body($response);

        if (! $response->successful() || ! is_array($answer['element'] ?? null)) {
            return new Verification(PaymentState::Pending, $answer + ['http_status' => $response->status()]);
        }

        /** @var list<array<string, mixed>> $attempts */
        $attempts = array_values(array_filter($answer['element'], 'is_array'));

        foreach ($attempts as $attempt) {
            if ($this->stateOf($payment, $attempt) === PaymentState::Paid) {
                return new Verification(PaymentState::Paid, $answer, $attempt['val_id'] ?? null);
            }
        }

        $latest = end($attempts);

        return new Verification($latest === false ? PaymentState::Pending : $this->stateOf($payment, $latest), $answer);
    }

    /**
     * What one of SSLCommerz's answers about an attempt means for this
     * Payment. Paid only where the status says taken, the attempt names this
     * Payment, and the amount taken is the amount asked for; a taken status
     * that fails either is Failed, because money moved and it was not this
     * money. Cancelled and Expired are the gateway's own words for an attempt
     * nobody completed; anything not yet decided there is still Pending.
     *
     * @param  array<string, mixed>  $answer
     */
    private function stateOf(Payment $payment, array $answer): PaymentState
    {
        $status = Str::upper((string) ($answer['status'] ?? ''));

        if (in_array($status, self::PAID_STATUSES, true)) {
            return $this->namesThisPayment($payment, $answer) && $this->tookTheAmount($payment, $answer)
                ? PaymentState::Paid
                : PaymentState::Failed;
        }

        return match ($status) {
            'FAILED' => PaymentState::Failed,
            'CANCELLED' => PaymentState::Cancelled,
            'EXPIRED' => PaymentState::Expired,
            default => PaymentState::Pending,
        };
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function namesThisPayment(Payment $payment, array $answer): bool
    {
        return ($answer['tran_id'] ?? null) === $payment->invoice_reference;
    }

    /**
     * Whether the amount taken, in the money it was taken in, is the
     * Payment's own. `amount` is SSLCommerz's figure in BDT whatever the
     * Customer paid in, which is the money every Payment here is for.
     *
     * @param  array<string, mixed>  $answer
     */
    private function tookTheAmount(Payment $payment, array $answer): bool
    {
        $amount = $answer['amount'] ?? null;

        return is_scalar($amount)
            && Money::toMinorUnits($amount) === $payment->amount
            && Str::upper((string) ($answer['currency'] ?? '')) === $payment->currency;
    }

    /**
     * One of SSLCommerz's two question endpoints, with the credentials and the
     * JSON format every question carries.
     *
     * @param  array<string, string|null>  $query
     */
    private function ask(string $path, array $query): Response
    {
        return $this->client()->get($this->host().$path, [...$this->credentials(), ...$query, 'format' => 'json']);
    }

    private function validationId(Request $request): ?string
    {
        $validationId = $request->input('val_id');

        return is_string($validationId) && $validationId !== '' ? $validationId : null;
    }

    /**
     * @return array<string, string>
     */
    private function credentials(): array
    {
        return [
            'store_id' => $this->settings->sslcommerzStoreId,
            'store_passwd' => $this->settings->sslcommerzStorePassword,
        ];
    }

    private function host(): string
    {
        return $this->settings->sslcommerzSandbox ? self::SANDBOX_HOST : self::LIVE_HOST;
    }

    private function client(): PendingRequest
    {
        return $this->http->connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT)->acceptJson();
    }

    /**
     * The gateway's answer as an array whatever it sent, so a body that is
     * not JSON is still kept for the record rather than thrown away.
     *
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : ['body' => $response->body()];
    }
}
