<?php

namespace Tests\Fixtures\Payments;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Support\Money;
use App\Support\Settings\PaymentSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * PayStation's hosted checkout, as its documentation describes it: a form
 * post with the credentials in the body starts a transaction and answers a
 * payment page, the Customer is sent back by GET with the outcome word, our
 * invoice number and PayStation's transaction id, and the transaction status
 * is asked two ways — v2 by PayStation's id, v1 by our invoice number — with
 * the merchant id in a header and nothing else.
 *
 * The sandbox host throughout, because the tests switch the gateway on with
 * the sandbox flag at its default.
 */
final class PayStationScript extends GatewayScript
{
    private const HOST = 'https://sandbox.paystation.com.bd';

    private const MERCHANT_ID = '104-1653730183';

    private const PASSWORD = 'gamecoderstorepass';

    private const CHECKOUT_PATH = '/checkout/12117397758013220/ZzHkhjalJ1ulsjuJOdx1mCSN1nj5AGPAvr1BlBjpccTFlC02hX';

    /** What every transaction id of the gateway's opens with; the rest names the attempt, so two Payments never share one. */
    private const TRANSACTION_ID_PREFIX = 'CG26';

    public function method(): PaymentMethod
    {
        return PaymentMethod::PayStation;
    }

    public function makeUsable(): void
    {
        app(PaymentSettings::class)->with([
            'paystationEnabled' => true,
            'paystationMerchantId' => self::MERCHANT_ID,
            'paystationPassword' => self::PASSWORD,
        ])->save();
    }

    public function gatewayPageUrl(): string
    {
        return self::HOST.self::CHECKOUT_PATH;
    }

    public function gatewayReference(Payment $payment): string
    {
        return self::TRANSACTION_ID_PREFIX.strtoupper(str_replace('-', '', $payment->invoice_reference));
    }

    public function fakeStart(): void
    {
        Http::fake([self::HOST.'/initiate-payment' => Http::response([
            'status_code' => '200',
            'status' => 'success',
            'message' => 'Payment Link Created Successfully.',
            'payment_amount' => '1050.00',
            'invoice_number' => 'GP000001-1',
            'payment_url' => $this->gatewayPageUrl(),
        ])]);
    }

    public function startResponseFor(Payment $payment): array
    {
        return [
            'status_code' => '200',
            'status' => 'success',
            'message' => 'Payment Link Created Successfully.',
            'payment_amount' => Money::toMajorString($payment->amount),
            'invoice_number' => $payment->invoice_reference,
            'payment_url' => $this->gatewayPageUrl(),
        ];
    }

    public function fakeStartRefused(): void
    {
        Http::fake([self::HOST.'/initiate-payment' => Http::response([
            'status_code' => '1008',
            'status' => 'failed',
            'message' => 'Duplicate invoice number.',
        ])]);
    }

    public function fakeOutage(): void
    {
        Http::fake(fn (Request $request) => throw new ConnectionException(
            "cURL error 28: Connection timed out after 5000 milliseconds for {$request->url()}"
        ));
    }

    /**
     * The credentials ride in the body of the start and in no header, and
     * PayStation has the Notification URL registered once per merchant, so
     * the start carries none.
     */
    public function assertStartedFor(Payment $payment, string $returnUrl, string $notificationUrl): void
    {
        $order = $payment->order;

        Http::assertSent(fn (Request $request): bool => $request->url() === self::HOST.'/initiate-payment'
            && $request->isForm()
            && ! $request->hasHeader('merchantId')
            && $request['merchantId'] === self::MERCHANT_ID
            && $request['password'] === self::PASSWORD
            && $request['invoice_number'] === $payment->invoice_reference
            && $request['currency'] === 'BDT'
            && $request['payment_amount'] === Money::toMajorString($payment->amount)
            && $request['reference'] === $order->order_number
            && $request['cust_name'] === $order->customer_name
            && $request['cust_phone'] === $order->customer_phone
            && $request['cust_email'] !== ''
            && $request['cust_address'] === $order->customer_address.', '.$order->customer_district
            && $request['callback_url'] === $returnUrl
            && $request['checkout_items'] === $order->lines->first()->product_name
            && ! isset($request['ipn_url']));
    }

    public function assertStartsSent(int $count): void
    {
        Http::assertSentCount($count);
    }

    public function assertStartedWithEveryMandatoryFieldFilled(Payment $payment): void
    {
        Http::assertSent(fn (Request $request): bool => $request['invoice_number'] === $payment->invoice_reference
            && filled($request['cust_name'])
            && filled($request['cust_email'])
            && filled($request['callback_url'])
            && $request['cust_phone'] === $payment->order->customer_phone);
    }

    /**
     * The GET PayStation sends the browser back with: the outcome word, our
     * invoice number, and its transaction id where one was issued.
     */
    public function paidReturn(Payment $payment): array
    {
        return ['status' => 'Successful', 'invoice_number' => $payment->invoice_reference, 'trx_id' => $this->gatewayReference($payment)];
    }

    /** A failed attempt is sent back with no transaction id, as the status answer for one has none. */
    public function failedReturn(Payment $payment): array
    {
        return ['status' => 'Failed', 'invoice_number' => $payment->invoice_reference, 'trx_id' => ''];
    }

    public function cancelledReturn(Payment $payment): array
    {
        return ['status' => 'Canceled', 'invoice_number' => $payment->invoice_reference];
    }

    /**
     * The IPN: a flat JSON body posted to the registered URL after a taken
     * Payment only, naming our invoice number, PayStation's id and the amount
     * as a number. Posted here as fields, which the driver reads the same way.
     */
    public function paidNotification(Payment $payment): array
    {
        return [
            'invoice_number' => $payment->invoice_reference,
            'trx_status' => 'Success',
            'trx_id' => $this->gatewayReference($payment),
            'trx_amount' => Money::toMajorString($payment->amount),
            'order_date_time' => '2026-09-08 12:00:00',
            'payment_method' => 'bKash',
            'reference' => 'bKash',
        ];
    }

    public function notificationNamingNoPayment(): array
    {
        return [
            'invoice_number' => 'NOSUCH01-1',
            'trx_status' => 'Success',
            'trx_id' => self::TRANSACTION_ID_PREFIX.'NOSUCH011',
            'trx_amount' => '10.00',
            'order_date_time' => '2026-09-08 12:00:00',
            'payment_method' => 'Nagad',
            'reference' => 'Nagad',
        ];
    }

    public function notificationWithMismatchedAmount(Payment $payment): array
    {
        return ['trx_amount' => Money::toMajorString($payment->amount - 100)] + $this->paidNotification($payment);
    }

    /** Taken, whichever way it is asked. */
    public function fakeVerifiedPaid(Payment $payment): void
    {
        $this->fakeStatus($payment, 'success', $this->gatewayReference($payment), Money::toMajorString($payment->amount));
    }

    /** Initiated and nothing more: PayStation's `processing`, with no transaction id yet. */
    public function fakeVerifiedStillPending(Payment $payment): void
    {
        $this->fakeStatus($payment, 'processing', '', Money::toMajorString($payment->amount));
    }

    public function fakeVerifiedShort(Payment $payment): void
    {
        $this->fakeStatus($payment, 'success', $this->gatewayReference($payment), Money::toMajorString($payment->amount - 100));
    }

    /**
     * By PayStation's id, not found; by our invoice number, the attempt
     * failed, with no transaction id since none was ever issued.
     */
    public function fakeVerifiedInvalid(Payment $payment): void
    {
        $this->fakeStatusByTransactionId($payment, [
            'status_code' => '2001',
            'status' => 'failed',
            'message' => 'Transaction not found in system',
        ]);

        $this->fakeStatusByInvoice($payment, $this->found($this->statusData($payment, 'failed', '', Money::toMajorString($payment->amount))));
    }

    /**
     * By the id the Customer came back with, some other Payment's transaction,
     * taken for the same figure; by our invoice number, ours was taken.
     */
    public function fakeVerifiedForAnotherPayment(Payment $payment): void
    {
        $this->fakeStatusByTransactionId($payment, $this->found([
            'invoice_number' => 'OTHER123-1',
        ] + $this->statusData($payment, 'success', $this->gatewayReference($payment), Money::toMajorString($payment->amount), v2: true)));

        $this->fakeVerifiedPaid($payment);
    }

    /**
     * PayStation documents no status for a Customer who cancelled; its
     * checkout sends the browser back with the word, and the status answer
     * for such an attempt carries it too.
     */
    public function fakeVerifiedCancelled(Payment $payment): void
    {
        $this->fakeStatus($payment, 'Canceled', '', Money::toMajorString($payment->amount));
    }

    public function assertVerificationsSent(int $count): void
    {
        Http::assertSentCount($count);
    }

    public function invoiceReferenceNamedBy(array $verificationResponse): ?string
    {
        return $verificationResponse['data']['invoice_number'] ?? null;
    }

    /**
     * The same outcome told both ways: v2 by PayStation's id and v1 by our
     * invoice number, each matched on the reference in its request so the
     * fakes for two Payments on one Order stand side by side.
     */
    private function fakeStatus(Payment $payment, string $status, string $transactionId, string $amount): void
    {
        $this->fakeStatusByTransactionId($payment, $this->found($this->statusData($payment, $status, $transactionId, $amount, v2: true)));
        $this->fakeStatusByInvoice($payment, $this->found($this->statusData($payment, $status, $transactionId, $amount)));
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function fakeStatusByTransactionId(Payment $payment, array $response): void
    {
        Http::fake(fn (Request $request) => $request->url() === self::HOST.'/v2/transaction-status'
            && $request->hasHeader('merchantId', self::MERCHANT_ID)
            && $request->isJson()
            && $request['trxId'] === $this->gatewayReference($payment)
            ? Http::response($response)
            : null);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function fakeStatusByInvoice(Payment $payment, array $response): void
    {
        Http::fake(fn (Request $request) => $request->url() === self::HOST.'/transaction-status'
            && $request->hasHeader('merchantId', self::MERCHANT_ID)
            && $request->isForm()
            && $request['invoice_number'] === $payment->invoice_reference
            ? Http::response($response)
            : null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function found(array $data): array
    {
        return [
            'status_code' => '200',
            'status' => 'success',
            'message' => 'Transaction found',
            'data' => $data,
        ];
    }

    /**
     * The `data` of a status answer: v1's shape by default, v2's with its
     * three amounts and date when asked for.
     *
     * @return array<string, mixed>
     */
    private function statusData(Payment $payment, string $status, string $transactionId, string $amount, bool $v2 = false): array
    {
        $taken = str_starts_with(strtolower($status), 'success');

        $data = [
            'invoice_number' => $payment->invoice_reference,
            'trx_status' => $status,
            'trx_id' => $transactionId,
        ];

        if ($v2) {
            $data += [
                'trx_amount' => $taken ? (float) $amount : 0,
                'trx_date' => '2026-09-08',
                'request_amount' => Money::toMajorString($payment->amount),
            ];
        }

        return $data + [
            'payment_amount' => $amount,
            'order_date_time' => '2026-09-08 12:00:00',
            'payer_mobile_no' => $taken ? '018*******' : '',
            'payment_method' => $taken ? 'bKash' : '',
            'reference' => $payment->order->order_number,
            'checkout_items' => 'Sample Product',
        ];
    }
}
