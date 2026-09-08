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
 * SSLCommerz's hosted checkout, as its documentation describes it: a form
 * post starts a session and answers a gateway page, the Customer is posted
 * back with a validation id, and the validation API is asked by that id.
 *
 * The sandbox host throughout, because the tests switch the gateway on with
 * the sandbox flag at its default.
 */
final class SslCommerzScript extends GatewayScript
{
    private const HOST = 'https://sandbox.sslcommerz.com';

    private const STORE_ID = 'store_test_1';

    private const STORE_PASSWORD = 'pw_test_1';

    private const SESSION_KEY = '3CB2A6A2C4C0E8A7F5D1B9E2A1C3D4E5';

    private const VALIDATION_ID = '2609081200001AbCdEfGhIjK';

    public function method(): PaymentMethod
    {
        return PaymentMethod::SslCommerz;
    }

    public function makeUsable(): void
    {
        app(PaymentSettings::class)->with([
            'sslcommerzEnabled' => true,
            'sslcommerzStoreId' => self::STORE_ID,
            'sslcommerzStorePassword' => self::STORE_PASSWORD,
        ])->save();
    }

    public function gatewayPageUrl(): string
    {
        return self::HOST.'/EasyCheckOut/testcde'.self::SESSION_KEY;
    }

    public function gatewayReference(): string
    {
        return self::VALIDATION_ID;
    }

    public function fakeStart(): void
    {
        Http::fake([self::HOST.'/gwprocess/v4/api.php' => Http::response([
            'status' => 'SUCCESS',
            'failedreason' => '',
            'sessionkey' => self::SESSION_KEY,
            'gw' => ['visa' => 'visacard', 'master' => 'mastercard', 'mobilebanking' => 'bkash,nagad'],
            'redirectGatewayURL' => '',
            'directPaymentURLBank' => '',
            'directPaymentURLCard' => '',
            'directPaymentURL' => '',
            'redirectGatewayURLFailed' => '',
            'GatewayPageURL' => $this->gatewayPageUrl(),
            'storeBanner' => '',
            'storeLogo' => '',
            'desc' => [],
            'is_direct_pay_enable' => '0',
        ])]);
    }

    public function fakeStartRefused(): void
    {
        Http::fake([self::HOST.'/gwprocess/v4/api.php' => Http::response([
            'status' => 'FAILED',
            'failedreason' => 'Store Credential Error Or Store is De-active',
        ])]);
    }

    public function fakeOutage(): void
    {
        Http::fake(fn (Request $request) => throw new ConnectionException(
            "cURL error 28: Connection timed out after 5000 milliseconds for {$request->url()}"
        ));
    }

    public function assertStartedFor(Payment $payment, string $returnUrl): void
    {
        $order = $payment->order;

        Http::assertSent(fn (Request $request): bool => $request->url() === self::HOST.'/gwprocess/v4/api.php'
            && $request->isForm()
            && $request['store_id'] === self::STORE_ID
            && $request['store_passwd'] === self::STORE_PASSWORD
            && $request['total_amount'] === Money::toMajorString($payment->amount)
            && $request['currency'] === 'BDT'
            && $request['tran_id'] === $payment->invoice_reference
            && $request['success_url'] === $returnUrl
            && $request['fail_url'] === $returnUrl
            && $request['cancel_url'] === $returnUrl
            && $request['cus_name'] === $order->customer_name
            && $request['cus_phone'] === $order->customer_phone
            && $request['cus_email'] !== ''
            && $request['cus_add1'] === $order->customer_address
            && $request['cus_city'] === $order->customer_district
            && $request['cus_country'] === 'Bangladesh'
            && $request['product_name'] === $order->lines->first()->product_name
            && $request['product_category'] !== ''
            && $request['product_profile'] === 'general'
            && $request['shipping_method'] === 'NO');
    }

    public function paidReturn(Payment $payment): array
    {
        return $this->returnFields($payment, 'VALID') + ['val_id' => self::VALIDATION_ID, 'bank_tran_id' => '2609081200001BANK'];
    }

    public function failedReturn(Payment $payment): array
    {
        return $this->returnFields($payment, 'FAILED') + ['val_id' => self::VALIDATION_ID, 'error' => 'Transaction failed'];
    }

    public function cancelledReturn(Payment $payment): array
    {
        return $this->returnFields($payment, 'CANCELLED') + ['error' => 'Cancelled by user'];
    }

    public function fakeVerifiedPaid(Payment $payment): void
    {
        $this->fakeValidation($this->validationFields($payment, 'VALID', Money::toMajorString($payment->amount)));
    }

    public function fakeVerifiedShort(Payment $payment): void
    {
        $this->fakeValidation($this->validationFields($payment, 'VALID', Money::toMajorString($payment->amount - 100)));
    }

    /**
     * An id the validation API does not know names no transaction, so the
     * driver asks by our reference, and that answer says the attempt failed.
     */
    public function fakeVerifiedInvalid(Payment $payment): void
    {
        $this->fakeValidation(['status' => 'INVALID_TRANSACTION']);
        $this->fakeTransactionQuery($payment, 'FAILED', 'Transaction failed');
    }

    public function fakeVerifiedForAnotherPayment(Payment $payment): void
    {
        $this->fakeValidation(['tran_id' => 'OTHER123-1'] + $this->validationFields($payment, 'VALID', Money::toMajorString($payment->amount)));
        $this->fakeTransactionQuery($payment, 'VALID', '');
    }

    public function fakeVerifiedCancelled(Payment $payment): void
    {
        $this->fakeTransactionQuery($payment, 'CANCELLED', 'Cancelled by user');
    }

    public function assertVerificationsSent(int $count): void
    {
        Http::assertSentCount($count);
    }

    /**
     * @return array<string, string>
     */
    private function returnFields(Payment $payment, string $status): array
    {
        return [
            'tran_id' => $payment->invoice_reference,
            'amount' => Money::toMajorString($payment->amount),
            'card_type' => 'VISA-Dutch Bangla',
            'store_amount' => Money::toMajorString($payment->amount - 2625),
            'status' => $status,
            'tran_date' => '2026-09-08 12:00:00',
            'currency' => 'BDT',
            'card_issuer' => 'STANDARD CHARTERED BANK',
            'card_brand' => 'VISA',
            'card_issuer_country' => 'Bangladesh',
            'card_issuer_country_code' => 'BD',
            'store_id' => self::STORE_ID,
            'verify_sign' => 'a1b2c3d4e5f6',
            'verify_key' => 'amount,bank_tran_id,card_brand',
            'currency_type' => 'BDT',
            'currency_amount' => Money::toMajorString($payment->amount),
            'currency_rate' => '1.0000',
            'base_fair' => '0.00',
            'value_a' => '',
            'value_b' => '',
            'value_c' => '',
            'value_d' => '',
            'risk_level' => '0',
            'risk_title' => 'Safe',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function validationFields(Payment $payment, string $status, string $amount): array
    {
        return [
            'status' => $status,
            'tran_date' => '2026-09-08 12:00:00',
            'tran_id' => $payment->invoice_reference,
            'val_id' => self::VALIDATION_ID,
            'amount' => $amount,
            'store_amount' => Money::toMajorString($payment->amount - 2625),
            'currency' => 'BDT',
            'bank_tran_id' => '2609081200001BANK',
            'card_type' => 'VISA-Dutch Bangla',
            'card_no' => '432149XXXXXX0667',
            'card_issuer' => 'STANDARD CHARTERED BANK',
            'card_brand' => 'VISA',
            'card_issuer_country' => 'Bangladesh',
            'card_issuer_country_code' => 'BD',
            'currency_type' => 'BDT',
            'currency_amount' => $amount,
            'currency_rate' => '1.0000',
            'base_fair' => '0.00',
            'value_a' => '',
            'value_b' => '',
            'value_c' => '',
            'value_d' => '',
            'emi_instalment' => '0',
            'emi_amount' => '0.00',
            'emi_description' => '',
            'emi_issuer' => '',
            'account_details' => '',
            'risk_title' => 'Safe',
            'risk_level' => '0',
            'APIConnect' => 'DONE',
            'validated_on' => '2026-09-08 12:00:05',
            'gw_version' => 'v4',
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function fakeValidation(array $response): void
    {
        Http::fake([self::HOST.'/validator/api/validationserverAPI.php*' => Http::response($response)]);
    }

    /**
     * The transaction query by our reference, answering one attempt in the
     * given status.
     */
    private function fakeTransactionQuery(Payment $payment, string $status, string $error): void
    {
        Http::fake([self::HOST.'/validator/api/merchantTransIDvalidationAPI.php*' => Http::response([
            'APIConnect' => 'DONE',
            'no_of_trans_found' => 1,
            'element' => [[
                'tran_id' => $payment->invoice_reference,
                'val_id' => $status === 'VALID' ? self::VALIDATION_ID : '',
                'status' => $status,
                'amount' => Money::toMajorString($payment->amount),
                'store_amount' => $status === 'VALID' ? Money::toMajorString($payment->amount - 2625) : '0.00',
                'currency' => 'BDT',
                'bank_tran_id' => $status === 'VALID' ? '2609081200001BANK' : '',
                'tran_date' => '2026-09-08 12:00:00',
                'currency_type' => 'BDT',
                'currency_amount' => Money::toMajorString($payment->amount),
                'error' => $error,
            ]],
        ])]);
    }
}
