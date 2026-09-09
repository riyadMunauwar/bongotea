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
 * PayStation's hosted checkout: a form post starts a transaction and answers
 * the page to send the Customer to, the Customer is sent back by GET with
 * PayStation's transaction id, and the transaction status is asked by that
 * id where the Payment holds one and by our invoice number otherwise.
 *
 * PayStation answers two status questions — v2 by its own `trxId`, v1 by our
 * `invoice_number` — so the Verification asks the v2 question first for a
 * Payment the Customer came back with an id for, and settles on it only
 * where the answer names this Payment; anything else falls through to the
 * v1 question by our own reference, the way `SslCommerz` falls through to
 * its query. The credentials travel differently per call, as the
 * documentation says: both in the body of the start, the merchant id alone
 * in a header of the status questions. The Notification URL is registered
 * once per merchant through PayStation's support desk, never sent with a
 * Payment. Sandbox and live are two hosts under the same paths, picked by
 * the Merchant's switch.
 *
 * Every mandatory field the start call wants is filled from the Order, and
 * where the Checkout never asked the question a fixed placeholder stands in,
 * for the reason `SslCommerz` gives.
 */
class PayStation implements PaymentGateway
{
    private const SANDBOX_HOST = 'https://sandbox.paystation.com.bd';

    private const LIVE_HOST = 'https://api.paystation.com.bd';

    private const START_PATH = '/initiate-payment';

    private const STATUS_BY_INVOICE_PATH = '/transaction-status';

    private const STATUS_BY_TRANSACTION_ID_PATH = '/v2/transaction-status';

    /** The one status code that says a request was answered; `1008` is a duplicate invoice, `2001` a transaction not found. */
    private const STATUS_CODE_OK = '200';

    /**
     * What stands in for a detail the Checkout did not ask. The email is in
     * a reserved domain, so nothing PayStation sends to it reaches anyone.
     */
    private const PLACEHOLDER_NAME = 'Customer';

    private const PLACEHOLDER_EMAIL = 'customer@example.com';

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
        return ['paystationMerchantId', 'paystationPassword'];
    }

    public function takesNotificationUrl(): bool
    {
        return false;
    }

    public function start(Payment $payment, string $returnUrl, ?string $notificationUrl): PaymentStarted
    {
        $order = $payment->order;

        $body = [
            'merchantId' => $this->settings->paystationMerchantId,
            'password' => $this->settings->paystationPassword,
            'invoice_number' => $payment->invoice_reference,
            'currency' => $payment->currency,
            'payment_amount' => Money::toMajorString($payment->amount),
            'reference' => $order->order_number,
            'cust_name' => $order->customer_name ?: self::PLACEHOLDER_NAME,
            'cust_phone' => $order->customer_phone,
            'cust_email' => $order->customer_email ?: self::PLACEHOLDER_EMAIL,
            'callback_url' => $returnUrl,
            'checkout_items' => Str::limit($order->lines->map(fn (OrderLine $line): string => $line->product_name)->implode(', '), 255, ''),
        ];

        $address = implode(', ', array_filter([$order->customer_address, $order->customer_district], filled(...)));

        if ($address !== '') {
            $body['cust_address'] = $address;
        }

        try {
            $response = $this->client()->asForm()->post($this->host().self::START_PATH, $body);
        } catch (ConnectionException $exception) {
            throw new PaymentNotStarted(
                "PayStation could not be reached to start Payment [{$payment->invoice_reference}].",
                ['error' => $exception->getMessage()],
            );
        }

        $answer = $this->body($response);

        if (! $this->answered($response, $answer) || blank($answer['payment_url'] ?? null)) {
            throw new PaymentNotStarted(
                "PayStation refused to start Payment [{$payment->invoice_reference}]: ".$this->reason($answer, $response),
                $answer,
            );
        }

        return new PaymentStarted($answer['payment_url'], $answer);
    }

    /**
     * PayStation's transaction id, on the return once one was issued.
     * Written down as the Customer's having come back, and asked by only in
     * a question whose answer must still name this Payment.
     */
    public function paymentReferenceFromReturn(Request $request): ?string
    {
        return $this->transactionId($request->all());
    }

    /**
     * The IPN: a flat JSON body posted to the registered URL by PayStation
     * itself for a taken Payment, naming the Payment by our invoice number
     * with PayStation's id and the amount beside it. Read for those and not
     * for its status. It names no currency; PayStation takes BDT alone, and
     * so does every Payment under it.
     */
    public function readNotification(Request $request): ?Notification
    {
        $invoiceReference = $request->input('invoice_number');

        if (! is_string($invoiceReference) || $invoiceReference === '') {
            return null;
        }

        $amount = $request->input('trx_amount');

        return new Notification(
            $invoiceReference,
            $this->transactionId($request->all()),
            is_scalar($amount) && is_numeric($amount) ? Money::toMinorUnits($amount) : null,
        );
    }

    /**
     * The transaction status: v2 by PayStation's id where the Payment holds
     * one, settled on only when the answer names this Payment, else v1 by
     * our invoice number, which is asked whether the Customer came back
     * without an id or never came back at all.
     *
     * The id on the Payment arrived on an unauthenticated GET to a guessable
     * address, so an answer that is not found or names another Payment
     * settles nothing: it is kept beside the v1 answer for the record and
     * the Payment is decided by our own reference. The answer hands
     * PayStation's own id back only for a Payment that was taken, so the
     * record holds the id of money that moved and nothing a return lent it.
     */
    public function verify(Payment $payment): Verification
    {
        $byTransactionId = null;

        if ($payment->gateway_reference !== null) {
            try {
                $response = $this->client()->withHeaders($this->merchantHeader())->asJson()
                    ->post($this->host().self::STATUS_BY_TRANSACTION_ID_PATH, ['trxId' => $payment->gateway_reference]);
            } catch (ConnectionException $exception) {
                return new Verification(PaymentState::Pending, ['error' => $exception->getMessage()]);
            }

            $byTransactionId = $this->body($response);

            if ($this->found($response, $byTransactionId) && $this->namesThisPayment($payment, $byTransactionId)) {
                return $this->verification($payment, $byTransactionId);
            }
        }

        $beside = $byTransactionId === null ? [] : ['by_transaction_id' => $byTransactionId];

        try {
            $response = $this->client()->withHeaders($this->merchantHeader())->asForm()
                ->post($this->host().self::STATUS_BY_INVOICE_PATH, ['invoice_number' => $payment->invoice_reference]);
        } catch (ConnectionException $exception) {
            return new Verification(PaymentState::Pending, ['error' => $exception->getMessage()] + $beside);
        }

        $answer = $this->body($response) + $beside;

        /** Not found by our own reference, or answered about another Payment: nothing to settle on, and the sweep asks again. */
        if (! $this->found($response, $answer) || ! $this->namesThisPayment($payment, $answer)) {
            return new Verification(PaymentState::Pending, $answer + ['http_status' => $response->status()]);
        }

        return $this->verification($payment, $answer);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function verification(Payment $payment, array $answer): Verification
    {
        $state = $this->stateOf($payment, $answer['data']);

        return new Verification($state, $answer, $state === PaymentState::Paid ? $this->transactionId($answer['data']) : null);
    }

    /**
     * What a found transaction's `data` means for this Payment. Paid only
     * where the status says taken and the amount taken is the amount asked
     * for; a taken status for another figure is Failed, because money moved
     * and it was not this money. Failed and refunded are PayStation's own
     * words, and a refund is money that did not stay. PayStation documents
     * no word for a cancelled Customer, but its checkout sends the browser
     * back with one, so a status opening with it is read as Cancelled.
     * Anything else, `processing` included, is still Pending.
     *
     * There is no currency in the answer to check: PayStation takes BDT
     * alone, and so does every Payment started under it.
     *
     * @param  array<string, mixed>  $data
     */
    private function stateOf(Payment $payment, array $data): PaymentState
    {
        $status = Str::lower(trim((string) ($data['trx_status'] ?? '')));

        if (Str::startsWith($status, 'success')) {
            return $this->tookTheAmount($payment, $data) ? PaymentState::Paid : PaymentState::Failed;
        }

        if (Str::startsWith($status, 'cancel')) {
            return PaymentState::Cancelled;
        }

        return match (true) {
            Str::startsWith($status, 'fail'), Str::startsWith($status, 'refund') => PaymentState::Failed,
            default => PaymentState::Pending,
        };
    }

    /**
     * Whether a status answer says a transaction was found: answered, with
     * PayStation's own code for success, and carrying its `data`.
     *
     * @param  array<string, mixed>  $answer
     */
    private function found(Response $response, array $answer): bool
    {
        return $this->answered($response, $answer) && is_array($answer['data'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function namesThisPayment(Payment $payment, array $answer): bool
    {
        return ($answer['data']['invoice_number'] ?? null) === $payment->invoice_reference;
    }

    /**
     * Whether the amount taken is the Payment's own. `payment_amount` is
     * the one figure both status questions answer with.
     *
     * @param  array<string, mixed>  $data
     */
    private function tookTheAmount(Payment $payment, array $data): bool
    {
        $amount = $data['payment_amount'] ?? null;

        return is_scalar($amount)
            && is_numeric($amount)
            && Money::toMinorUnits($amount) === $payment->amount;
    }

    /**
     * Whether PayStation answered the request rather than refusing it: its
     * `status_code` of 200 with `status` of success. The code arrives as a
     * string; a number is taken too.
     *
     * @param  array<string, mixed>  $answer
     */
    private function answered(Response $response, array $answer): bool
    {
        return $response->successful()
            && (string) ($answer['status_code'] ?? '') === self::STATUS_CODE_OK
            && Str::lower((string) ($answer['status'] ?? '')) === 'success';
    }

    /**
     * What PayStation said when it refused, for the record and the log: its
     * message, with the code where it gave one, else the HTTP status.
     *
     * @param  array<string, mixed>  $answer
     */
    private function reason(array $answer, Response $response): string
    {
        $message = $answer['message'] ?? null;
        $code = $answer['status_code'] ?? null;

        if (is_string($message) && $message !== '') {
            return Str::limit(is_scalar($code) && (string) $code !== '' ? "{$code} {$message}" : $message, 255, '');
        }

        return "HTTP {$response->status()}";
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function transactionId(array $fields): ?string
    {
        $transactionId = $fields['trx_id'] ?? null;

        return is_string($transactionId) && $transactionId !== '' ? $transactionId : null;
    }

    /**
     * @return array<string, string>
     */
    private function merchantHeader(): array
    {
        return ['merchantId' => $this->settings->paystationMerchantId];
    }

    private function host(): string
    {
        return $this->settings->paystationSandbox ? self::SANDBOX_HOST : self::LIVE_HOST;
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
