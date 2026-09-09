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
 * aamarPay's hosted checkout: a JSON post starts a transaction and answers
 * the page to send the Customer to, the Customer is posted back with
 * aamarPay's transaction id, and the transaction check is asked by our own
 * reference.
 *
 * The one question aamarPay answers is by the merchant's transaction id, so
 * every Verification is asked by our invoice reference whether or not the
 * Customer came back: there is no second question to fall through to, and
 * an id read off a return is never what the driver asks by. The Notification
 * URL is registered once through aamarPay's support desk, never sent with a
 * Payment. Sandbox and live are two hosts under the same paths, picked by
 * the Merchant's switch.
 *
 * Every mandatory field the start call wants is filled from the Order, and
 * where the Checkout never asked the question a fixed placeholder stands in,
 * for the reason `SslCommerz` gives.
 */
class AamarPay implements PaymentGateway
{
    private const SANDBOX_HOST = 'https://sandbox.aamarpay.com';

    private const LIVE_HOST = 'https://secure.aamarpay.com';

    private const START_PATH = '/jsonpost.php';

    private const TRANSACTION_CHECK_PATH = '/api/v1/trxcheck/request.php';

    /**
     * aamarPay's status codes: 0 initiated, 2 successful, 3 expired, 7
     * failed. There is no code for a Customer who cancelled; the status word
     * is read for that.
     */
    private const STATUS_PAID = '2';

    private const STATUS_EXPIRED = '3';

    private const STATUS_FAILED = '7';

    /**
     * What stands in for a detail the Checkout did not ask. The email is in
     * a reserved domain, so nothing aamarPay sends to it reaches anyone.
     */
    private const PLACEHOLDER_NAME = 'Customer';

    private const PLACEHOLDER_EMAIL = 'customer@example.com';

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
        return ['aamarpayStoreId', 'aamarpaySignatureKey'];
    }

    public function takesNotificationUrl(): bool
    {
        return false;
    }

    public function start(Payment $payment, string $returnUrl, ?string $notificationUrl): PaymentStarted
    {
        $order = $payment->order;

        $body = [
            ...$this->credentials(),
            'tran_id' => $payment->invoice_reference,
            'amount' => Money::toMajorString($payment->amount),
            'currency' => $payment->currency,
            'desc' => Str::limit($order->lines->map(fn (OrderLine $line): string => $line->product_name)->implode(', '), 255, ''),
            'cus_name' => $order->customer_name ?: self::PLACEHOLDER_NAME,
            'cus_email' => $order->customer_email ?: self::PLACEHOLDER_EMAIL,
            'cus_phone' => $order->customer_phone,
            'cus_country' => self::COUNTRY,
            'success_url' => $returnUrl,
            'fail_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'type' => 'json',
        ];

        if (filled($order->customer_address)) {
            $body['cus_add1'] = $order->customer_address;
        }

        if (filled($order->customer_district)) {
            $body['cus_city'] = $order->customer_district;
        }

        try {
            $response = $this->client()->post($this->host().self::START_PATH, $body);
        } catch (ConnectionException $exception) {
            throw new PaymentNotStarted(
                "aamarPay could not be reached to start Payment [{$payment->invoice_reference}].",
                ['error' => $exception->getMessage()],
            );
        }

        $answer = $this->body($response);

        if (! $this->accepted($answer) || blank($answer['payment_url'] ?? null)) {
            throw new PaymentNotStarted(
                "aamarPay refused to start Payment [{$payment->invoice_reference}]: ".$this->reason($answer, $response),
                $answer,
            );
        }

        return new PaymentStarted($answer['payment_url'], $answer);
    }

    /**
     * aamarPay's transaction id, carried on the return once something was
     * attempted. Written down as the Customer's having come back and never
     * asked by: the check is by our reference whatever the return says.
     */
    public function paymentReferenceFromReturn(Request $request): ?string
    {
        return $this->transactionId($request);
    }

    /**
     * The IPN: the return's fields posted to the registered URL by aamarPay
     * itself for a taken Payment, so it names the Payment by our tran_id and
     * carries aamarPay's id and the amount beside it. Read for those and not
     * for its status.
     */
    public function readNotification(Request $request): ?Notification
    {
        $invoiceReference = $request->input('mer_txnid');

        if (! is_string($invoiceReference) || $invoiceReference === '') {
            return null;
        }

        $amount = $request->input('amount');
        $currency = $request->input('currency');

        return new Notification(
            $invoiceReference,
            $this->transactionId($request),
            is_scalar($amount) && is_numeric($amount) ? Money::toMinorUnits($amount) : null,
            is_string($currency) && $currency !== '' ? Str::upper($currency) : null,
        );
    }

    /**
     * The transaction check, by our invoice reference: the one question
     * aamarPay answers, and so the one asked whether the Customer came back
     * with an id, came back without one, or never came back at all.
     *
     * The answer hands aamarPay's own id back only for a Payment that was
     * taken, so the record holds the id of money that moved and nothing an
     * unauthenticated return lent it.
     */
    public function verify(Payment $payment): Verification
    {
        try {
            $response = $this->client()->get($this->host().self::TRANSACTION_CHECK_PATH, [
                ...$this->credentials(),
                'request_id' => $payment->invoice_reference,
                'type' => 'json',
            ]);
        } catch (ConnectionException $exception) {
            return new Verification(PaymentState::Pending, ['error' => $exception->getMessage()]);
        }

        $answer = $this->body($response);

        if (! $response->successful() || ! isset($answer['status_code'])) {
            return new Verification(PaymentState::Pending, $answer + ['http_status' => $response->status()]);
        }

        $state = $this->stateOf($payment, $answer);

        return new Verification($state, $answer, $state === PaymentState::Paid ? $this->transactionIdOf($answer) : null);
    }

    /**
     * What aamarPay's answer means for this Payment. Paid only where the
     * status code says taken, the answer names this Payment, and the amount
     * taken is the amount asked for; a taken status that fails either is
     * Failed, because money moved and it was not this money. Expired and
     * Failed are aamarPay's own codes; a cancelled Customer is read off the
     * status word, since no code names one; anything else, the initiated
     * code included, is still Pending.
     *
     * @param  array<string, mixed>  $answer
     */
    private function stateOf(Payment $payment, array $answer): PaymentState
    {
        $code = (string) ($answer['status_code'] ?? '');

        if ($code === self::STATUS_PAID) {
            return $this->namesThisPayment($payment, $answer) && $this->tookTheAmount($payment, $answer)
                ? PaymentState::Paid
                : PaymentState::Failed;
        }

        if (Str::startsWith(Str::upper((string) ($answer['pay_status'] ?? '')), 'CANCEL')) {
            return PaymentState::Cancelled;
        }

        return match ($code) {
            self::STATUS_FAILED => PaymentState::Failed,
            self::STATUS_EXPIRED => PaymentState::Expired,
            default => PaymentState::Pending,
        };
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function namesThisPayment(Payment $payment, array $answer): bool
    {
        return ($answer['mer_txnid'] ?? null) === $payment->invoice_reference;
    }

    /**
     * Whether the amount taken, in the money it was taken in, is the
     * Payment's own.
     *
     * @param  array<string, mixed>  $answer
     */
    private function tookTheAmount(Payment $payment, array $answer): bool
    {
        $amount = $answer['amount'] ?? null;

        return is_scalar($amount)
            && is_numeric($amount)
            && Money::toMinorUnits($amount) === $payment->amount
            && Str::upper((string) ($answer['currency'] ?? '')) === $payment->currency;
    }

    /**
     * Whether the start was accepted. aamarPay answers `result` as the
     * string "true"; a boolean is taken too, so a change of encoding on
     * their side is not a refused Customer.
     *
     * @param  array<string, mixed>  $answer
     */
    private function accepted(array $answer): bool
    {
        $result = $answer['result'] ?? null;

        return $result === true || (is_string($result) && Str::lower($result) === 'true');
    }

    /**
     * What aamarPay said when it refused, for the record and the log. Its
     * documentation names no shape for a refusal, so the first sentence
     * found under the usual keys is taken, else the HTTP status.
     *
     * @param  array<string, mixed>  $answer
     */
    private function reason(array $answer, Response $response): string
    {
        foreach (['message', 'error', 'errors', 'reason', 'body'] as $key) {
            $value = $answer[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return Str::limit($value, 255, '');
            }

            if (is_array($value) && $value !== []) {
                return Str::limit(json_encode($value, JSON_UNESCAPED_SLASHES) ?: '', 255, '');
            }
        }

        return "HTTP {$response->status()}";
    }

    private function transactionId(Request $request): ?string
    {
        return $this->transactionIdOf($request->all());
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function transactionIdOf(array $fields): ?string
    {
        $transactionId = $fields['pg_txnid'] ?? null;

        return is_string($transactionId) && $transactionId !== '' ? $transactionId : null;
    }

    /**
     * @return array<string, string>
     */
    private function credentials(): array
    {
        return [
            'store_id' => $this->settings->aamarpayStoreId,
            'signature_key' => $this->settings->aamarpaySignatureKey,
        ];
    }

    private function host(): string
    {
        return $this->settings->aamarpaySandbox ? self::SANDBOX_HOST : self::LIVE_HOST;
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
