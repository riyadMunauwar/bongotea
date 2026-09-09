<?php

namespace App\Support\Payments;

use App\Enums\PaymentState;
use App\Models\Payment;
use App\Support\Money;
use App\Support\Settings\PaymentSettings;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * bKash Tokenized Checkout, URL mode: a grant token good for an hour opens
 * every call, a create answers the page to send the Customer to and a
 * payment id, the Customer comes back on a GET carrying that id, an execute
 * takes the money, and a status query answers for everything after.
 *
 * The one gateway whose payment id is ours from the start: the create
 * answers it, the Payment keeps it in its start record, and every question
 * is asked by that id and never by one read off a return. There is no query
 * by our invoice reference, so a Payment whose create never answered has
 * nothing to be asked by and stays Pending until the sweep expires it, which
 * is also how long bKash keeps a payment id alive.
 *
 * A return saying success is not Paid until the execute reports Completed
 * (ADR-0013): the execute is the Verification for a Customer who came back,
 * and the status query the Verification for one who did not, or for a
 * Payment whose execute has already been refused. bKash allows two grants
 * an hour, so the token is cached for just under one and refreshed at most
 * once per call, on an authentication failure.
 *
 * The Notification URL is registered once through bKash's support desk
 * rather than sent with each Payment; what arrives there is an SNS-style
 * envelope whose `Message` names our invoice, read for that and nothing more.
 */
class Bkash implements PaymentGateway
{
    private const GRANT_PATH = '/tokenized/checkout/token/grant';

    private const CREATE_PATH = '/tokenized/checkout/create';

    private const EXECUTE_PATH = '/tokenized/checkout/execute';

    private const STATUS_PATH = '/tokenized/checkout/payment/status';

    /** The create's mode for a checkout the Customer is sent to by URL. */
    private const MODE = '0011';

    private const INTENT = 'sale';

    private const SUCCESS = '0000';

    /** bKash's own word for money taken. */
    private const COMPLETED = 'Completed';

    /** bKash's codes for a payment id that is dead: cancelled by the Customer, or one bKash does not know. */
    private const CANCELLED_CODE = '2069';

    private const INVALID_PAYMENT_ID_CODE = '2002';

    /** The return's status under which the payment id is worth executing. */
    private const RETURN_SUCCESS = 'success';

    /**
     * How long a grant token is kept: bKash issues one for an hour, so just
     * under that leaves no window in which a cached token is stale.
     */
    public const TOKEN_TTL_SECONDS = 3300;

    /** Seconds to connect, and seconds for the whole call: a slow gateway must not hold a Capture for long. */
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 15;

    /** The execute moves the money and bKash asks for thirty seconds on it; nothing but the Processing Page waits on it. */
    private const EXECUTE_TIMEOUT = 30;

    public function __construct(
        private readonly PaymentSettings $settings,
        private readonly Http $http,
        private readonly Cache $cache,
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
        return ['bkashAppKey', 'bkashAppSecret', 'bkashUsername', 'bkashPassword'];
    }

    public function takesNotificationUrl(): bool
    {
        return false;
    }

    public function start(Payment $payment, string $returnUrl, ?string $notificationUrl): PaymentStarted
    {
        $body = [
            'mode' => self::MODE,
            'payerReference' => $payment->order->customer_phone,
            'callbackURL' => $returnUrl,
            'amount' => Money::toMajorString($payment->amount),
            'currency' => $payment->currency,
            'intent' => self::INTENT,
            'merchantInvoiceNumber' => $payment->invoice_reference,
        ];

        try {
            $response = $this->ask(self::CREATE_PATH, $body);
        } catch (ConnectionException $exception) {
            throw new PaymentNotStarted(
                "bKash could not be reached to start Payment [{$payment->invoice_reference}].",
                ['error' => $exception->getMessage()],
            );
        }

        $answer = $this->body($response);

        if ($this->code($answer) !== self::SUCCESS || blank($answer['bkashURL'] ?? null) || blank($answer['paymentID'] ?? null)) {
            throw new PaymentNotStarted(
                "bKash refused to start Payment [{$payment->invoice_reference}]: ".$this->reason($answer, $response),
                $answer,
            );
        }

        return new PaymentStarted($answer['bkashURL'], $answer);
    }

    /**
     * The payment id the Customer came back with, where the return says the
     * Customer got through: that is what makes an execute right. A failure
     * or cancel return carries the same id, and reading nothing off it is
     * what keeps the driver from executing a payment nobody authorised; the
     * status query then answers for the attempt, by the id the create gave.
     */
    public function paymentReferenceFromReturn(Request $request): ?string
    {
        if ($request->input('status') !== self::RETURN_SUCCESS) {
            return null;
        }

        return $this->paymentId($request->input('paymentID'));
    }

    /**
     * The webhook: an SNS envelope, posted as JSON, whose `Message` is the
     * transaction as a JSON string naming our invoice, the amount and the
     * money it is in. Read for those and never for its status. The first
     * message on a fresh registration is a subscription to confirm, which is
     * done here and names no Payment.
     */
    public function readNotification(Request $request): ?Notification
    {
        $envelope = $this->envelope($request);

        if (($envelope['Type'] ?? $request->header('x-amz-sns-message-type')) === 'SubscriptionConfirmation') {
            $this->confirmSubscription($envelope['SubscribeURL'] ?? null);

            return null;
        }

        $message = $envelope['Message'] ?? null;

        if (is_string($message)) {
            $message = json_decode($message, true);
        }

        if (! is_array($message)) {
            return null;
        }

        $invoiceReference = $message['merchantInvoiceNumber'] ?? null;

        if (! is_string($invoiceReference) || $invoiceReference === '') {
            return null;
        }

        $amount = $message['amount'] ?? null;
        $currency = $message['currency'] ?? null;

        return new Notification(
            $invoiceReference,
            null,
            is_scalar($amount) && is_numeric($amount) ? Money::toMinorUnits($amount) : null,
            is_string($currency) && $currency !== '' ? Str::upper($currency) : null,
        );
    }

    public function verify(Payment $payment): Verification
    {
        $paymentId = $this->paymentIdOf($payment);

        if ($paymentId === null) {
            return new Verification(PaymentState::Pending, ['error' => 'The Payment holds no bKash payment id to ask by.']);
        }

        try {
            if ($this->shouldExecute($payment)) {
                return $this->executeThenQuery($payment, $paymentId);
            }

            return $this->keepingRefusedExecute($payment, $this->queryStatus($payment, $paymentId));
        } catch (ConnectionException $exception) {
            return $this->keepingRefusedExecute($payment, new Verification(PaymentState::Pending, ['error' => $exception->getMessage()]));
        } catch (PaymentNotStarted $refusedToken) {
            return $this->keepingRefusedExecute($payment, new Verification(PaymentState::Pending, ['error' => $refusedToken->getMessage()] + $refusedToken->response));
        }
    }

    /**
     * A refused execute on the record stays on it: the verifier writes every
     * Pending answer over the last, so an answer that did not execute carries
     * the earlier refusal forward, or the next Verification would execute
     * again.
     */
    private function keepingRefusedExecute(Payment $payment, Verification $verification): Verification
    {
        $refused = $payment->verification_response['execute'] ?? null;

        if (! is_array($refused) || isset($verification->response['execute'])) {
            return $verification;
        }

        return new Verification($verification->state, $verification->response + ['execute' => $refused], $verification->gatewayReference);
    }

    /**
     * The execute: the one call that takes the money, for a Customer who
     * came back saying so. Settled on where it answers for this Payment; an
     * answer that does not — refused, already executed, or naming some other
     * invoice — settles nothing, and the status query decides, with the
     * execute's answer kept beside it for the record.
     */
    private function executeThenQuery(Payment $payment, string $paymentId): Verification
    {
        $execute = $this->body($this->ask(self::EXECUTE_PATH, ['paymentID' => $paymentId], self::EXECUTE_TIMEOUT));

        $state = $this->stateOf($payment, $execute);

        if ($state !== PaymentState::Pending) {
            return new Verification($state, $execute, $this->paymentId($execute['paymentID'] ?? null));
        }

        $query = $this->queryStatus($payment, $paymentId);

        return new Verification($query->state, $query->response + ['execute' => $execute], $query->gatewayReference);
    }

    /**
     * The status query, by the payment id: what bKash knows of the attempt
     * without touching it. The reference handed back is the id bKash itself
     * names in the answer, so a refusal names none.
     */
    private function queryStatus(Payment $payment, string $paymentId): Verification
    {
        $response = $this->ask(self::STATUS_PATH, ['paymentID' => $paymentId]);

        $answer = $this->body($response);

        if (! $response->successful() && ! isset($answer['statusCode'])) {
            return new Verification(PaymentState::Pending, $answer + ['http_status' => $response->status()]);
        }

        return new Verification($this->stateOf($payment, $answer), $answer, $this->paymentId($answer['paymentID'] ?? null));
    }

    /**
     * Whether a Verification should execute rather than only ask: the
     * Customer came back saying success with the id the create issued (the
     * Processing Page's address is guessable, the payment id is not, so a
     * return naming another id is nobody's authorisation), and no earlier
     * Verification has had the execute refused. A refused execute is on the
     * record under `execute`, and asking again would only be refused again,
     * so from then on the status query alone is asked.
     */
    private function shouldExecute(Payment $payment): bool
    {
        return $payment->gateway_reference !== null
            && $payment->gateway_reference === $this->paymentIdOf($payment)
            && ! isset($payment->verification_response['execute']);
    }

    /**
     * What one of bKash's answers means for this Payment. Paid only where
     * the status is Completed, the answer names this Payment's invoice, and
     * the amount taken in the money asked for is the Payment's own; Completed
     * naming this Payment with the wrong money is Failed, because money moved
     * and it was not this money. Completed naming some other invoice settles
     * nothing. bKash's own words for a dead id map to Cancelled and Failed;
     * anything else is still Pending.
     *
     * @param  array<string, mixed>  $answer
     */
    private function stateOf(Payment $payment, array $answer): PaymentState
    {
        $code = $this->code($answer);

        if ($code === self::SUCCESS) {
            if (Str::lower((string) ($answer['transactionStatus'] ?? '')) !== Str::lower(self::COMPLETED)) {
                return PaymentState::Pending;
            }

            if (! $this->namesThisPayment($payment, $answer)) {
                return PaymentState::Pending;
            }

            return $this->tookTheAmount($payment, $answer) ? PaymentState::Paid : PaymentState::Failed;
        }

        return match ($code) {
            self::CANCELLED_CODE => PaymentState::Cancelled,
            self::INVALID_PAYMENT_ID_CODE => PaymentState::Failed,
            default => PaymentState::Pending,
        };
    }

    /**
     * The execute names the invoice as `merchantInvoiceNumber`; the status
     * query, as `merchantInvoice`.
     *
     * @param  array<string, mixed>  $answer
     */
    private function namesThisPayment(Payment $payment, array $answer): bool
    {
        $named = $answer['merchantInvoiceNumber'] ?? $answer['merchantInvoice'] ?? null;

        return $named === $payment->invoice_reference;
    }

    /**
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
     * The payment id every question is asked by: the one the create answered
     * and the Payment's start record holds, never one read off a return.
     */
    private function paymentIdOf(Payment $payment): ?string
    {
        return $this->paymentId($payment->start_response['paymentID'] ?? null);
    }

    private function paymentId(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * One authenticated question to bKash. A token bKash no longer honours
     * is replaced once and the question asked again; a second refusal is
     * the answer, so ordinary traffic never spends the two grants an hour.
     *
     * @param  array<string, string>  $body
     */
    private function ask(string $path, array $body, int $timeout = self::TIMEOUT): Response
    {
        $response = $this->authorised($this->token(), $timeout)->post($this->host().$path, $body);

        if ($this->refusedTheToken($response)) {
            $response = $this->authorised($this->token(fresh: true), $timeout)->post($this->host().$path, $body);
        }

        return $response;
    }

    private function refusedTheToken(Response $response): bool
    {
        return $response->status() === 401;
    }

    /**
     * The grant token, from the cache store where one is held, else granted
     * and kept. Keyed by host and application key so a switch to live or a
     * new key never presents the other's token.
     */
    private function token(bool $fresh = false): string
    {
        $key = 'bkash:grant-token:'.sha1($this->host().'|'.$this->settings->bkashAppKey.'|'.$this->settings->bkashUsername);

        if ($fresh) {
            $this->cache->forget($key);
        }

        return $this->cache->remember($key, self::TOKEN_TTL_SECONDS, fn (): string => $this->grant());
    }

    /**
     * The token grant: the merchant account's username and password as
     * headers, the application key and secret in the body. Refused is
     * "could not start" at Capture and still Pending at a Verification.
     */
    private function grant(): string
    {
        $response = $this->client()
            ->withHeaders(['username' => $this->settings->bkashUsername, 'password' => $this->settings->bkashPassword])
            ->post($this->host().self::GRANT_PATH, ['app_key' => $this->settings->bkashAppKey, 'app_secret' => $this->settings->bkashAppSecret]);

        $answer = $this->body($response);

        $token = $answer['id_token'] ?? null;

        if (! is_string($token) || $token === '' || (isset($answer['statusCode']) && $this->code($answer) !== self::SUCCESS)) {
            throw new PaymentNotStarted('bKash refused to grant a token: '.$this->reason($answer, $response), $answer);
        }

        return $token;
    }

    private function authorised(string $token, int $timeout): PendingRequest
    {
        return $this->client($timeout)->withHeaders(['Authorization' => $token, 'X-App-Key' => $this->settings->bkashAppKey]);
    }

    /**
     * bKash's subscription to confirm, on a fresh registration of the
     * Notification URL: visited once, and only where it is Amazon's own SNS
     * address, so a forged envelope cannot have this site fetch anything
     * else. Nothing is made of the answer.
     */
    private function confirmSubscription(mixed $subscribeUrl): void
    {
        if (! is_string($subscribeUrl) || ! $this->isSnsAddress($subscribeUrl)) {
            Log::warning('A bKash subscription confirmation named an address that is not SNS, and was not visited.');

            return;
        }

        try {
            $this->client()->get($subscribeUrl);
        } catch (ConnectionException $exception) {
            Log::warning('bKash\'s subscription could not be confirmed: '.$exception->getMessage());
        }
    }

    private function isSnsAddress(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && is_string($host)
            && preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host) === 1;
    }

    /**
     * The envelope as posted: SNS sends JSON under a text content type, so a
     * body the framework did not parse is decoded here.
     *
     * @return array<string, mixed>
     */
    private function envelope(Request $request): array
    {
        $input = $request->input();

        if ($input !== []) {
            return $input;
        }

        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function code(array $answer): string
    {
        return (string) ($answer['statusCode'] ?? $answer['errorCode'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function reason(array $answer, Response $response): string
    {
        return (string) ($answer['statusMessage'] ?? $answer['errorMessage'] ?? "HTTP {$response->status()}");
    }

    private function host(): string
    {
        return rtrim($this->settings->bkashSandbox ? $this->settings->bkashSandboxHost : $this->settings->bkashLiveHost, '/');
    }

    private function client(int $timeout = self::TIMEOUT): PendingRequest
    {
        return $this->http->connectTimeout(self::CONNECT_TIMEOUT)->timeout($timeout)->acceptJson()->asJson();
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : ['body' => $response->body()];
    }
}
