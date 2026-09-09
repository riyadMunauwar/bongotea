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
 * bKash Tokenized Checkout, as its documentation describes it: a grant token
 * good for an hour, a JSON create that answers a bKash page and a payment id,
 * the Customer sent back on a GET carrying that id and a status, an execute
 * that takes the money, and a status query for everything after. The
 * Notification is an SNS-style message whose `Message` names our invoice.
 *
 * The sandbox host throughout, because the tests switch the gateway on with
 * the sandbox flag at its default.
 */
final class BkashScript extends GatewayScript
{
    private const HOST = 'https://tokenized.sandbox.bka.sh/v1.2.0-beta';

    private const APP_KEY = 'app_key_test_1';

    private const APP_SECRET = 'app_secret_test_1';

    private const USERNAME = 'sandboxTestUser';

    private const PASSWORD = 'sandbox_pw_1';

    private const ID_TOKEN = 'eyJraWQiOiJ0ZXN0In0.eyJzdWIiOiJ0ZXN0In0.c2lnbmF0dXJl';

    /** What every payment id of the gateway's opens with; the rest names the attempt, so two Payments never share one. */
    private const PAYMENT_ID_PREFIX = 'TR0011';

    private const TRX_ID = '6H6201QDIY';

    public function method(): PaymentMethod
    {
        return PaymentMethod::Bkash;
    }

    public function makeUsable(): void
    {
        app(PaymentSettings::class)->with([
            'bkashEnabled' => true,
            'bkashAppKey' => self::APP_KEY,
            'bkashAppSecret' => self::APP_SECRET,
            'bkashUsername' => self::USERNAME,
            'bkashPassword' => self::PASSWORD,
        ])->save();
    }

    public function gatewayPageUrl(): string
    {
        return 'https://sandbox.payment.bkash.com/?mode=0011&apiVersion=v1.2.0-beta&hash=3CB2A6A2C4C0E8A7';
    }

    public function gatewayReference(Payment $payment): string
    {
        return self::paymentIdFor($payment->invoice_reference);
    }

    public function fakeStart(): void
    {
        $this->fakeGrant();

        Http::fake(fn (Request $request) => $this->isTo($request, '/tokenized/checkout/create')
            ? Http::response([
                'statusCode' => '0000',
                'statusMessage' => 'Successful',
                'paymentID' => self::paymentIdFor((string) $request['merchantInvoiceNumber']),
                'bkashURL' => $this->gatewayPageUrl(),
                'callbackURL' => $request['callbackURL'],
                'successCallbackURL' => $request['callbackURL'].'?paymentID='.self::paymentIdFor((string) $request['merchantInvoiceNumber']).'&status=success',
                'failureCallbackURL' => $request['callbackURL'].'?paymentID='.self::paymentIdFor((string) $request['merchantInvoiceNumber']).'&status=failure',
                'cancelledCallbackURL' => $request['callbackURL'].'?paymentID='.self::paymentIdFor((string) $request['merchantInvoiceNumber']).'&status=cancel',
                'amount' => $request['amount'],
                'intent' => 'sale',
                'currency' => 'BDT',
                'paymentCreateTime' => '2026-09-08T12:00:00:000 GMT+0600',
                'transactionStatus' => 'Initiated',
                'merchantInvoiceNumber' => $request['merchantInvoiceNumber'],
            ])
            : null);
    }

    public function startResponseFor(Payment $payment): array
    {
        return [
            'statusCode' => '0000',
            'statusMessage' => 'Successful',
            'paymentID' => $this->gatewayReference($payment),
            'bkashURL' => $this->gatewayPageUrl(),
            'transactionStatus' => 'Initiated',
            'merchantInvoiceNumber' => $payment->invoice_reference,
        ];
    }

    public function fakeStartRefused(): void
    {
        $this->fakeGrant();

        Http::fake(fn (Request $request) => $this->isTo($request, '/tokenized/checkout/create')
            ? Http::response(['errorCode' => '2001', 'errorMessage' => 'Invalid App Key'])
            : null);
    }

    public function fakeOutage(): void
    {
        Http::fake(fn (Request $request) => throw new ConnectionException(
            "cURL error 28: Connection timed out after 5000 milliseconds for {$request->url()}"
        ));
    }

    /**
     * bKash has the Notification URL registered once through its support
     * desk, so the create carries none. The create is asserted with the
     * token and the application key in its headers; the grant that issued
     * the token is not, because a second Payment in the hour reuses it
     * (`BkashDriverTest` is where the grant itself is asserted).
     */
    public function assertStartedFor(Payment $payment, string $returnUrl, string $notificationUrl): void
    {
        Http::assertSent(fn (Request $request): bool => $this->isTo($request, '/tokenized/checkout/create')
            && $request->isJson()
            && $request->hasHeader('Authorization', self::ID_TOKEN)
            && $request->hasHeader('X-App-Key', self::APP_KEY)
            && $request['mode'] === '0011'
            && $request['payerReference'] === $payment->order->customer_phone
            && $request['callbackURL'] === $returnUrl
            && $request['amount'] === Money::toMajorString($payment->amount)
            && $request['currency'] === 'BDT'
            && $request['intent'] === 'sale'
            && $request['merchantInvoiceNumber'] === $payment->invoice_reference);
    }

    public function assertStartsSent(int $count): void
    {
        expect(Http::recorded(fn (Request $request): bool => $this->isTo($request, '/tokenized/checkout/create')))->toHaveCount($count);
    }

    public function assertStartedWithEveryMandatoryFieldFilled(Payment $payment): void
    {
        Http::assertSent(fn (Request $request): bool => $this->isTo($request, '/tokenized/checkout/create')
            && $request['merchantInvoiceNumber'] === $payment->invoice_reference
            && filled($request['payerReference'])
            && filled($request['callbackURL'])
            && filled($request['amount']));
    }

    public function paidReturn(Payment $payment): array
    {
        return $this->returnFields($payment, 'success');
    }

    public function failedReturn(Payment $payment): array
    {
        return $this->returnFields($payment, 'failure');
    }

    /**
     * bKash's cancel return carries the payment id like every other; the
     * driver reads no reference off it, because there is nothing to execute.
     */
    public function cancelledReturn(Payment $payment): array
    {
        return $this->returnFields($payment, 'cancel');
    }

    public function paidNotification(Payment $payment): array
    {
        return $this->notification([
            'merchantInvoiceNumber' => $payment->invoice_reference,
            'amount' => Money::toMajorString($payment->amount),
        ]);
    }

    public function notificationNamingNoPayment(): array
    {
        return $this->notification(['merchantInvoiceNumber' => 'NOSUCH01-1', 'amount' => '10.00']);
    }

    public function notificationWithMismatchedAmount(Payment $payment): array
    {
        return $this->notification([
            'merchantInvoiceNumber' => $payment->invoice_reference,
            'amount' => Money::toMajorString($payment->amount - 100),
        ]);
    }

    /**
     * Taken, whichever way it is asked: executed on the Customer's return,
     * or queried where they never came back.
     */
    public function fakeVerifiedPaid(Payment $payment): void
    {
        $this->fakeGrant();
        $this->fakeExecute($payment, $this->executed($payment, Money::toMajorString($payment->amount)));
        $this->fakeStatus($payment, $this->status($payment, 'Completed', Money::toMajorString($payment->amount)));
    }

    /**
     * Asked by the payment id, bKash has the Payment Initiated and nothing
     * more: the Customer never got as far as paying.
     */
    public function fakeVerifiedStillPending(Payment $payment): void
    {
        $this->fakeGrant();
        $this->fakeStatus($payment, $this->status($payment, 'Initiated', Money::toMajorString($payment->amount)));
    }

    public function fakeVerifiedShort(Payment $payment): void
    {
        $this->fakeGrant();
        $this->fakeExecute($payment, $this->executed($payment, Money::toMajorString($payment->amount - 100)));
        $this->fakeStatus($payment, $this->status($payment, 'Completed', Money::toMajorString($payment->amount - 100)));
    }

    /**
     * An id bKash does not know can never be paid: the execute is refused,
     * and the status query, asked next, says the same.
     */
    public function fakeVerifiedInvalid(Payment $payment): void
    {
        $this->fakeGrant();
        $this->fakeExecute($payment, ['statusCode' => '2002', 'statusMessage' => 'Invalid Payment ID']);
        $this->fakeStatus($payment, ['statusCode' => '2002', 'statusMessage' => 'Invalid Payment ID']);
    }

    public function fakeVerifiedForAnotherPayment(Payment $payment): void
    {
        $this->fakeGrant();
        $this->fakeExecute($payment, ['merchantInvoiceNumber' => 'OTHER123-1'] + $this->executed($payment, Money::toMajorString($payment->amount)));
        $this->fakeStatus($payment, $this->status($payment, 'Completed', Money::toMajorString($payment->amount)));
    }

    public function fakeVerifiedCancelled(Payment $payment): void
    {
        $this->fakeGrant();
        $this->fakeExecute($payment, ['statusCode' => '2069', 'statusMessage' => 'Transaction already cancelled']);
        $this->fakeStatus($payment, ['statusCode' => '2069', 'statusMessage' => 'Transaction already cancelled']);
    }

    /**
     * The execute and the status query are the questions about a Payment;
     * a token grant is not one, and is left out of the count.
     */
    public function assertVerificationsSent(int $count): void
    {
        $asked = Http::recorded(fn (Request $request): bool => $this->isTo($request, '/tokenized/checkout/execute')
            || $this->isTo($request, '/tokenized/checkout/payment/status'));

        expect($asked)->toHaveCount($count);
    }

    public function invoiceReferenceNamedBy(array $verificationResponse): ?string
    {
        return $verificationResponse['merchantInvoice'] ?? $verificationResponse['merchantInvoiceNumber'] ?? null;
    }

    private static function paymentIdFor(string $invoiceReference): string
    {
        return self::PAYMENT_ID_PREFIX.strtoupper(str_replace('-', '', $invoiceReference));
    }

    /**
     * @return array<string, string>
     */
    private function returnFields(Payment $payment, string $status): array
    {
        return [
            'version' => 'v1.2.0-beta',
            'product' => 'tokenized-checkout',
            'paymentID' => $this->gatewayReference($payment),
            'status' => $status,
            'signature' => 'cm8HBfl65A',
        ];
    }

    /**
     * The SNS-style envelope bKash's webhook posts, with the transaction as
     * a JSON string in `Message`.
     *
     * @param  array<string, string>  $transaction
     * @return array<string, string>
     */
    private function notification(array $transaction): array
    {
        return [
            'Type' => 'Notification',
            'MessageId' => '3e4c7a6a-1c4e-4b8e-9f4a-2c6d9e8f1a2b',
            'TopicArn' => 'arn:aws:sns:ap-southeast-1:000000000000:bkash-webhook',
            'Message' => json_encode([
                'dateTime' => '20260908120000',
                'debitMSISDN' => '8801770618575',
                'creditOrganizationName' => 'Gio Pages',
                'creditShortCode' => '01929918000',
                'trxID' => self::TRX_ID,
                'transactionStatus' => 'Completed',
                'transactionType' => '1003',
                'currency' => 'BDT',
                'transactionReference' => '',
                ...$transaction,
            ]),
            'Timestamp' => '2026-09-08T06:00:00.000Z',
            'SignatureVersion' => '1',
            'Signature' => 'a1b2c3d4e5f6',
            'SigningCertURL' => 'https://sns.ap-southeast-1.amazonaws.com/SimpleNotificationService-0000.pem',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function executed(Payment $payment, string $amount): array
    {
        return [
            'statusCode' => '0000',
            'statusMessage' => 'Successful',
            'paymentID' => $this->gatewayReference($payment),
            'customerMsisdn' => '01770618575',
            'payerReference' => $payment->order->customer_phone,
            'trxID' => self::TRX_ID,
            'transactionStatus' => 'Completed',
            'paymentExecuteTime' => '2026-09-08T12:00:41:428 GMT+0600',
            'amount' => $amount,
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => $payment->invoice_reference,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function status(Payment $payment, string $transactionStatus, string $amount): array
    {
        $completed = $transactionStatus === 'Completed';

        return [
            'statusCode' => '0000',
            'statusMessage' => 'Successful',
            'paymentID' => $this->gatewayReference($payment),
            'mode' => '0011',
            'payerReference' => $payment->order->customer_phone,
            'paymentCreateTime' => '2026-09-08T12:00:00:000 GMT+0600',
            'paymentExecuteTime' => $completed ? '2026-09-08T12:00:41:428 GMT+0600' : '',
            'amount' => $amount,
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoice' => $payment->invoice_reference,
            'trxID' => $completed ? self::TRX_ID : '',
            'transactionStatus' => $transactionStatus,
            'verificationStatus' => $completed ? 'Complete' : 'Incomplete',
        ];
    }

    private function fakeGrant(): void
    {
        Http::fake(fn (Request $request) => $this->isTo($request, '/tokenized/checkout/token/grant')
            ? Http::response([
                'statusCode' => '0000',
                'statusMessage' => 'Successful',
                'id_token' => self::ID_TOKEN,
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'refresh_token' => 'refresh_token_test_1',
            ])
            : null);
    }

    /**
     * The execute, asked by this Payment's own payment id. Matched on the id
     * in the request rather than on the address alone, so the fakes for two
     * Payments on one Order stand side by side and each answers for its own.
     *
     * @param  array<string, mixed>  $response
     */
    private function fakeExecute(Payment $payment, array $response): void
    {
        $this->fakeAnswerWhenAsked('/tokenized/checkout/execute', $this->gatewayReference($payment), $response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function fakeStatus(Payment $payment, array $response): void
    {
        $this->fakeAnswerWhenAsked('/tokenized/checkout/payment/status', $this->gatewayReference($payment), $response);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function fakeAnswerWhenAsked(string $path, string $paymentId, array $response): void
    {
        Http::fake(fn (Request $request) => $this->isTo($request, $path) && $request['paymentID'] === $paymentId
            ? Http::response($response)
            : null);
    }

    private function isTo(Request $request, string $path): bool
    {
        return str_starts_with($request->url(), self::HOST.$path);
    }
}
