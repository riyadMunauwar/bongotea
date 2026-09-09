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
 * aamarPay's hosted checkout, as its documentation describes it: a JSON post
 * starts a transaction and answers a payment page, the Customer is posted
 * back with aamarPay's transaction id, and the transaction check is asked by
 * our own reference — the one question aamarPay answers.
 *
 * The sandbox host throughout, because the tests switch the gateway on with
 * the sandbox flag at its default.
 */
final class AamarPayScript extends GatewayScript
{
    private const HOST = 'https://sandbox.aamarpay.com';

    private const STORE_ID = 'aamarpaytest';

    private const SIGNATURE_KEY = 'dbb74894e82415a2f7ff0ec3a97e4183';

    private const TRACK = 'AAM1690275828103929';

    /** What every transaction id of the gateway's opens with; the rest names the attempt, so two Payments never share one. */
    private const TRANSACTION_ID_PREFIX = 'AAM2609081200';

    public function method(): PaymentMethod
    {
        return PaymentMethod::AamarPay;
    }

    public function makeUsable(): void
    {
        app(PaymentSettings::class)->with([
            'aamarpayEnabled' => true,
            'aamarpayStoreId' => self::STORE_ID,
            'aamarpaySignatureKey' => self::SIGNATURE_KEY,
        ])->save();
    }

    public function gatewayPageUrl(): string
    {
        return self::HOST.'/paynow.php?track='.self::TRACK;
    }

    public function gatewayReference(Payment $payment): string
    {
        return self::TRANSACTION_ID_PREFIX.strtoupper(str_replace('-', '', $payment->invoice_reference));
    }

    public function fakeStart(): void
    {
        Http::fake([self::HOST.'/jsonpost.php' => Http::response([
            'result' => 'true',
            'payment_url' => $this->gatewayPageUrl(),
        ])]);
    }

    public function startResponseFor(Payment $payment): array
    {
        return ['result' => 'true', 'payment_url' => $this->gatewayPageUrl()];
    }

    /**
     * aamarPay's documentation names no shape for a refusal; this is the
     * one its sandbox gives for a wrong store id.
     */
    public function fakeStartRefused(): void
    {
        Http::fake([self::HOST.'/jsonpost.php' => Http::response([
            'result' => 'false',
            'message' => 'Invalid Store ID or Signature Key',
        ])]);
    }

    public function fakeOutage(): void
    {
        Http::fake(fn (Request $request) => throw new ConnectionException(
            "cURL error 28: Connection timed out after 5000 milliseconds for {$request->url()}"
        ));
    }

    /**
     * aamarPay has the Notification URL registered once through its support
     * desk, so the start carries none.
     */
    public function assertStartedFor(Payment $payment, string $returnUrl, string $notificationUrl): void
    {
        $order = $payment->order;

        Http::assertSent(fn (Request $request): bool => $request->url() === self::HOST.'/jsonpost.php'
            && $request->isJson()
            && $request['store_id'] === self::STORE_ID
            && $request['signature_key'] === self::SIGNATURE_KEY
            && $request['tran_id'] === $payment->invoice_reference
            && $request['amount'] === Money::toMajorString($payment->amount)
            && $request['currency'] === 'BDT'
            && $request['desc'] === $order->lines->first()->product_name
            && $request['cus_name'] === $order->customer_name
            && $request['cus_email'] !== ''
            && $request['cus_phone'] === $order->customer_phone
            && $request['cus_add1'] === $order->customer_address
            && $request['cus_city'] === $order->customer_district
            && $request['cus_country'] === 'Bangladesh'
            && $request['success_url'] === $returnUrl
            && $request['fail_url'] === $returnUrl
            && $request['cancel_url'] === $returnUrl
            && $request['type'] === 'json'
            && ! isset($request['ipn_url']));
    }

    public function assertStartsSent(int $count): void
    {
        Http::assertSentCount($count);
    }

    public function assertStartedWithEveryMandatoryFieldFilled(Payment $payment): void
    {
        Http::assertSent(fn (Request $request): bool => $request['tran_id'] === $payment->invoice_reference
            && filled($request['cus_name'])
            && filled($request['cus_email'])
            && filled($request['desc'])
            && $request['cus_phone'] === $payment->order->customer_phone);
    }

    public function paidReturn(Payment $payment): array
    {
        return $this->returnFields($payment, '2', 'Successful') + ['pg_txnid' => $this->gatewayReference($payment), 'epw_txnid' => $this->gatewayReference($payment)];
    }

    public function failedReturn(Payment $payment): array
    {
        return $this->returnFields($payment, '7', 'Failed') + ['pg_txnid' => $this->gatewayReference($payment), 'epw_txnid' => $this->gatewayReference($payment)];
    }

    /**
     * aamarPay's documentation gives the cancel URL no payload of its own;
     * the Customer is sent back naming our reference and nothing of the
     * gateway's, since nothing was attempted.
     */
    public function cancelledReturn(Payment $payment): array
    {
        return ['mer_txnid' => $payment->invoice_reference, 'pay_status' => 'Cancelled'];
    }

    /**
     * The IPN: the return's fields posted to the registered URL, for a taken
     * Payment only, whether or not the Customer's browser ever came back.
     */
    public function paidNotification(Payment $payment): array
    {
        return $this->paidReturn($payment);
    }

    public function notificationNamingNoPayment(): array
    {
        return ['mer_txnid' => 'NOSUCH01-1', 'pg_txnid' => self::TRANSACTION_ID_PREFIX.'NOSUCH011', 'status_code' => '2', 'pay_status' => 'Successful', 'amount' => '10.00', 'currency' => 'BDT'];
    }

    public function notificationWithMismatchedAmount(Payment $payment): array
    {
        return ['amount' => Money::toMajorString($payment->amount - 100), 'amount_original' => Money::toMajorString($payment->amount - 100)] + $this->paidReturn($payment);
    }

    /**
     * Taken. Asked one way only — by our reference — whether or not the
     * Customer came back.
     */
    public function fakeVerifiedPaid(Payment $payment): void
    {
        $this->fakeTransactionCheck($payment, $this->checkFields($payment, '2', 'Successful', Money::toMajorString($payment->amount)));
    }

    /**
     * Initiated and nothing more: the Customer never got as far as paying,
     * and nothing has been decided.
     */
    public function fakeVerifiedStillPending(Payment $payment): void
    {
        $this->fakeTransactionCheck($payment, $this->checkFields($payment, '0', 'Initiated', Money::toMajorString($payment->amount)));
    }

    public function fakeVerifiedShort(Payment $payment): void
    {
        $this->fakeTransactionCheck($payment, $this->checkFields($payment, '2', 'Successful', Money::toMajorString($payment->amount - 100)));
    }

    /**
     * aamarPay has no question by its own id to say an id is invalid; the
     * one question, by our reference, says the attempt failed.
     */
    public function fakeVerifiedInvalid(Payment $payment): void
    {
        $this->fakeTransactionCheck($payment, $this->checkFields($payment, '7', 'Failed', Money::toMajorString($payment->amount)));
    }

    /**
     * The return's id is never asked by, so a return carrying another
     * Payment's id changes nothing about the question: asked by our
     * reference, aamarPay says ours was taken, under its own id. A driver
     * that did ask by the return's id would be answered the other Payment's
     * transaction, and settle wrong.
     */
    public function fakeVerifiedForAnotherPayment(Payment $payment): void
    {
        Http::fake(fn (Request $request) => str_starts_with($request->url(), self::HOST.'/api/v1/trxcheck/request.php')
            && $request['request_id'] === $this->gatewayReference($payment)
            ? Http::response(['mer_txnid' => 'OTHER123-1'] + $this->checkFields($payment, '2', 'Successful', Money::toMajorString($payment->amount)))
            : null);

        $this->fakeVerifiedPaid($payment);
    }

    /**
     * aamarPay's status codes name no cancelled Customer, so the word is
     * what says so, beside the failed code.
     */
    public function fakeVerifiedCancelled(Payment $payment): void
    {
        $this->fakeTransactionCheck($payment, $this->checkFields($payment, '7', 'Cancelled', Money::toMajorString($payment->amount)));
    }

    public function assertVerificationsSent(int $count): void
    {
        Http::assertSentCount($count);
    }

    /** One question, by our reference, whatever the return carried. */
    public function questionsToSettlePastAnotherReference(): int
    {
        return 1;
    }

    public function invoiceReferenceNamedBy(array $verificationResponse): ?string
    {
        return $verificationResponse['mer_txnid'] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function returnFields(Payment $payment, string $statusCode, string $payStatus): array
    {
        return [
            'pg_service_charge_bdt' => '2',
            'amount_original' => Money::toMajorString($payment->amount),
            'gateway_fee' => '',
            'pg_service_charge_usd' => 'Not-Available',
            'pg_card_bank_name' => 'Not Available',
            'pg_card_bank_country' => 'Not Available',
            'card_number' => '1234XXXXXXXXX123',
            'card_holder' => '',
            'status_code' => $statusCode,
            'pay_status' => $payStatus,
            'success_url' => 'https://example.test/pay/aamarpay/'.$payment->invoice_reference,
            'fail_url' => 'https://example.test/pay/aamarpay/'.$payment->invoice_reference,
            'cus_name' => 'Customer Name',
            'cus_email' => 'customer@test.com',
            'cus_phone' => '0178273****',
            'currency_merchant' => 'BDT',
            'convertion_rate' => '1',
            'ip_address' => '203.0.113.10',
            'other_currency' => '',
            'mer_txnid' => $payment->invoice_reference,
            'store_id' => self::STORE_ID,
            'merchant_id' => self::STORE_ID,
            'currency' => 'BDT',
            'store_amount' => Money::toMajorString($payment->amount - 2625),
            'pay_time' => '2026-09-08 12:00:00',
            'amount' => Money::toMajorString($payment->amount),
            'bank_txn' => '1094621001640',
            'card_type' => 'DBBL-VISA',
            'reason' => 'Not Available',
            'pg_card_risklevel' => '0',
            'pg_error_code_details' => 'Not Available',
            'opt_a' => '',
            'opt_b' => '',
            'opt_c' => '',
            'opt_d' => '',
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function checkFields(Payment $payment, string $statusCode, string $payStatus, string $amount): array
    {
        return [
            'pg_txnid' => $this->gatewayReference($payment),
            'mer_txnid' => $payment->invoice_reference,
            'risk_title' => 'Not-Available',
            'risk_level' => null,
            'cus_name' => 'Rahim Uddin',
            'cus_email' => 'customer@example.com',
            'cus_phone' => '01712345678',
            'cus_add1' => 'Dhaka',
            'cus_add2' => 'Dhaka',
            'cus_city' => 'Dhaka',
            'cus_state' => 'Dhaka',
            'cus_postcode' => '1206',
            'cus_country' => 'Bangladesh',
            'cus_fax' => '',
            'ship_name' => null,
            'ship_add1' => null,
            'ship_add2' => null,
            'ship_city' => null,
            'ship_state' => null,
            'ship_postcode' => null,
            'ship_country' => null,
            'desc' => null,
            'merchant_id' => self::STORE_ID,
            'store_id' => self::STORE_ID,
            'amount' => $amount,
            'amount_bdt' => $amount,
            'pay_status' => $payStatus,
            'status_code' => $statusCode,
            'status_title' => $statusCode === '2' ? 'Successful Transaction' : $payStatus,
            'cardnumber' => 'XXXXXXXXXXXXXXX',
            'approval_code' => $statusCode === '2' ? '123456' : '',
            'payment_processor' => 'DBBL-VISA',
            'bank_trxid' => $statusCode === '2' ? '1094621001640' : '',
            'payment_type' => 'VISA',
            'error_code' => $statusCode === '2' ? '0000' : '1000',
            'error_title' => 'Not-Available',
            'bin_country' => 'Not-Available',
            'bin_issuer' => 'Not-Available',
            'bin_cardtype' => 'Not-Available',
            'bin_cardcategory' => 'Not-Available',
            'date' => '2026-09-08 12:00:00',
            'date_processed' => '2026-09-08 12:00:05',
            'amount_currency' => $amount,
            'rec_amount' => Money::toMajorString(Money::toMinorUnits($amount) - 2625),
            'processing_ratio' => '2.50',
            'processing_charge' => '26.25',
            'ip' => '203.0.113.10',
            'currency' => 'BDT',
            'currency_merchant' => 'BDT',
            'convertion_rate' => 'Not-Available',
            'opt_a' => 'Not-Available',
            'opt_b' => 'Not-Available',
            'opt_c' => 'Not-Available',
            'opt_d' => 'Not-Available',
            'verify_status' => 'PENDING',
            'call_type' => 'Post-Method',
            'email_send' => '1',
            'doc_recived' => 'NO',
            'checkout_status' => $statusCode === '2' ? 'Paid' : 'Not-Paid-Yet',
        ];
    }

    /**
     * The transaction check, asked by our reference. Matched on the
     * reference in the request rather than on the address alone, so the
     * fakes for two Payments on one Order stand side by side and each
     * answers for its own; a question about any other reference falls
     * through.
     *
     * @param  array<string, mixed>  $response
     */
    private function fakeTransactionCheck(Payment $payment, array $response): void
    {
        Http::fake(fn (Request $request) => str_starts_with($request->url(), self::HOST.'/api/v1/trxcheck/request.php')
            && $request['request_id'] === $payment->invoice_reference
            ? Http::response($response)
            : null);
    }
}
