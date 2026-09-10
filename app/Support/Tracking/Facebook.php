<?php

namespace App\Support\Tracking;

use App\Enums\TrackingMoment;
use App\Enums\TrackingProvider;
use App\Models\Destination;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\TrackingEvent;
use App\Support\Money;
use App\Support\Settings\GeneralSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use stdClass;

/**
 * Facebook's Conversions API: one POST per batch to a dataset's events
 * address, authorised by the access token the Merchant stored.
 *
 * The first Provider, and the one the contract was drawn around. Everything
 * platform-specific lives here — the event names, the `user_data` keys, the
 * hashing rules Meta publishes, the batch ceiling and the seven-day
 * acceptance window — so that the outbox and the sweep speak only Moments.
 *
 * Match keys are SHA-256 hashed after Meta's own normalisation: trimmed,
 * lower-cased, and for a phone number reduced to digits with the country code
 * and no leading zero. What is deliberately *not* hashed is the IP address,
 * the User-Agent and the click identifiers: Meta matches on those itself, and
 * a hashed one matches nobody.
 */
class Facebook implements Provider
{
    private const HOST = 'https://graph.facebook.com';

    /** Pinned rather than floating: a version bump changes the payload, and that is a change to read before taking. */
    private const API_VERSION = 'v21.0';

    /** Events one request may carry, as Meta documents it. */
    private const BATCH_LIMIT = 1000;

    /** Meta refuses an event whose `event_time` is older than seven days. */
    private const ACCEPTANCE_WINDOW_SECONDS = 7 * 86400;

    /** Seconds to connect, and seconds for the whole call: the sweep is not a place to wait out an outage. */
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 20;

    /** Every Moment is reported as having happened on the website, Paid included: there is no other surface. */
    private const ACTION_SOURCE = 'website';

    /** What Meta calls a parcel a courier brings to the door, as against one the buyer collects. */
    private const DELIVERY_CATEGORY = 'home_delivery';

    private const CONTENT_TYPE = 'product';

    /**
     * The error codes Meta answers a caller it is rate limiting: the
     * application's own limit, the user's, the page's, and the general one.
     * They arrive as a 400, which is why they cannot be told from a real
     * refusal by the status alone.
     */
    private const THROTTLED_ERROR_CODES = [4, 17, 32, 613];

    private const HTTP_TOO_MANY_REQUESTS = 429;

    public function __construct(private readonly Http $http) {}

    public function provider(): TrackingProvider
    {
        return TrackingProvider::Facebook;
    }

    /**
     * A Visit is two events, because Meta wants a page view and a product
     * view and a landing Page is both at once. Capture and Paid each produce
     * a `Purchase`; which of the two an Order goes through is decided by how
     * it is being paid and not here — a Cash on Delivery Order has no later
     * moment, and an online one has no earlier certainty.
     *
     * @return list<string>
     */
    public function eventsFor(TrackingMoment $moment): array
    {
        return match ($moment) {
            TrackingMoment::Visit => ['PageView', 'ViewContent'],
            TrackingMoment::CheckoutStarted => ['InitiateCheckout'],
            TrackingMoment::Capture, TrackingMoment::Paid => ['Purchase'],
        };
    }

    public function batchLimit(): int
    {
        return self::BATCH_LIMIT;
    }

    public function acceptanceWindowSeconds(): int
    {
        return self::ACCEPTANCE_WINDOW_SECONDS;
    }

    public function refusal(Destination $destination): ?string
    {
        if (! $destination->enabled) {
            return "{$destination->label} is switched off.";
        }

        if (blank($destination->dataset)) {
            return "{$destination->label} has no ".$this->provider()->datasetLabel().'.';
        }

        if (blank($destination->access_token)) {
            return "{$destination->label} has no access token.";
        }

        return null;
    }

    /**
     * @param  list<TrackingEvent>  $events
     */
    public function send(Destination $destination, array $events): Delivery
    {
        $address = self::HOST.'/'.self::API_VERSION.'/'.$destination->dataset.'/events';

        try {
            $response = $this->client()->post($address, [
                'data' => array_map($this->payloadFor(...), $events),
                'access_token' => $destination->access_token,
            ]);
        } catch (ConnectionException $exception) {
            return Delivery::unreachable($exception->getMessage());
        }

        $answer = $this->body($response);

        /**
         * A 5xx is Meta having a bad minute and says nothing about the batch,
         * so it is an outage like a refused connection. Only Meta's own
         * refusal — a wrong token, a payload it will not take — is final,
         * because retrying that is an infinite loop.
         */
        if ($response->serverError()) {
            return Delivery::unreachable("Facebook answered HTTP {$response->status()}.");
        }

        /**
         * Being throttled is not a refusal of the batch, whatever the status
         * says: Meta answers its rate limits with a 400 carrying one of a few
         * codes as often as with a 429. Marked final, a spike that tripped
         * the limit would throw away a thousand real events, so this is an
         * outage like any other and the next sweep asks again.
         */
        if ($this->isThrottled($response, $answer)) {
            return Delivery::unreachable($this->reason($response, $answer));
        }

        if ($response->failed()) {
            return Delivery::refused($this->reason($response, $answer), $answer);
        }

        return Delivery::accepted($answer);
    }

    /**
     * One row as Meta's wire format.
     *
     * Everything about the Customer is read from the Order the row points at
     * rather than off the row itself, which is what lets the outbox hold no
     * personal data and be pruned without touching a fulfilment record.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(TrackingEvent $event): array
    {
        $order = $event->order;

        $payload = array_filter([
            'event_name' => $event->event,
            'event_time' => $event->occurred_at->getTimestamp(),
            'event_id' => $event->deduplication_id,
            'event_source_url' => $event->source_url,
            'action_source' => self::ACTION_SOURCE,
            'custom_data' => $order === null ? [] : $this->customData($order),
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        /**
         * `user_data` is mandatory and travels even when it is empty — cast
         * to an object, since an empty PHP array encodes as `[]` and Meta
         * refuses the whole batch for one event shaped that way.
         */
        $userData = $this->userData($event, $order);

        $payload['user_data'] = $userData === [] ? new stdClass : $userData;

        return $payload;
    }

    /**
     * The match keys, hashed, beside the three things that must not be.
     *
     * A key the Order does not hold is left out rather than sent empty: Meta
     * reads a hashed blank as a value matching nobody and counts it against
     * the dataset's match quality.
     *
     * The country is not sent yet. It is one of the installation-wide
     * `user_data` decisions, which belong to the tracking settings group
     * rather than to a driver reading a config key behind its back.
     *
     * @return array<string, mixed>
     */
    private function userData(TrackingEvent $event, ?Order $order): array
    {
        $clickIds = $event->click_ids ?? [];

        $hashed = $order === null ? [] : array_filter([
            'ph' => self::hash($this->dialledDigits($order->customer_phone)),
            'em' => self::hash(Str::lower(trim((string) $order->customer_email))),
            'fn' => self::hash($this->namePart($order->customer_name, first: true)),
            'ln' => self::hash($this->namePart($order->customer_name, first: false)),

            /** Meta's city key wants no spaces and no punctuation; a District is the nearest thing an Order holds. */
            'ct' => self::hash((string) preg_replace('/[^a-z]/', '', Str::lower((string) $order->customer_district))),

            /**
             * A Customer is their phone number, so this is stable across
             * every Order one person places, and is what lets Meta recognise
             * a returning buyer as the same person.
             */
            'external_id' => self::hash($order->customer_id === null ? '' : 'customer-'.$order->customer_id),
        ], fn (?string $value): bool => $value !== null);

        return array_filter([
            ...$hashed,
            'client_ip_address' => $event->ip_address,
            'client_user_agent' => $event->user_agent,
            'fbc' => $clickIds['fbc'] ?? null,
            'fbp' => $clickIds['fbp'] ?? null,
        ], fn (mixed $value): bool => filled($value));
    }

    /**
     * What was bought and for how much.
     *
     * The value is the Order's total, which already carries the Delivery
     * Charge — the same figure the Merchant's own Dashboard calls Sales, so
     * the two never disagree. The currency is the installation's, read from
     * Settings rather than from config.
     *
     * @return array<string, mixed>
     */
    private function customData(Order $order): array
    {
        $lines = $order->lines;

        return [
            'value' => Money::toMajor($order->total),
            'currency' => Str::upper(app(GeneralSettings::class)->currencyCode),
            'content_type' => self::CONTENT_TYPE,
            'contents' => $lines->map(fn (OrderLine $line): array => [
                'id' => self::contentId($line),
                'quantity' => $line->quantity,
                'item_price' => Money::toMajor($line->unit_price),
            ])->values()->all(),
            'content_ids' => $lines->map(self::contentId(...))->values()->all(),

            /** The order number: the same string the event deduplicates on, so Events Manager reads as the Merchant's own admin does. */
            'order_id' => $order->order_number,

            'num_items' => (int) $lines->sum('quantity'),
            'delivery_category' => self::DELIVERY_CATEGORY,
        ];
    }

    /**
     * A line's Product as Meta identifies it: the Product's id while the
     * Product exists, and the name snapshotted on the line otherwise, so a
     * catalogue match survives a deletion as far as it can.
     */
    private static function contentId(OrderLine $line): string
    {
        return (string) ($line->product_id ?? $line->product_name);
    }

    /**
     * A hashed match key, or null where there is nothing to hash. Meta's
     * normalisation is the caller's; this only seals what it is given.
     */
    private static function hash(string $value): ?string
    {
        return $value === '' ? null : hash('sha256', $value);
    }

    /**
     * A phone number as Meta wants it hashed: digits only, country code
     * included, no leading zero and no punctuation. Our canonical form is
     * already E.164, so this is that with the `+` taken off.
     */
    private function dialledDigits(?string $phone): string
    {
        return (string) preg_replace('/\D+/', '', (string) $phone);
    }

    /**
     * The first or the last of a Customer's name, lower-cased. A single-word
     * name is a first name and no last name, rather than the same word twice.
     */
    private function namePart(?string $name, bool $first): string
    {
        $parts = preg_split('/\s+/', trim((string) $name), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return '';
        }

        if ($first) {
            return Str::lower($parts[0]);
        }

        return count($parts) > 1 ? Str::lower((string) end($parts)) : '';
    }

    /**
     * @param  array<string, mixed>  $answer
     */
    private function isThrottled(Response $response, array $answer): bool
    {
        if ($response->status() === self::HTTP_TOO_MANY_REQUESTS) {
            return true;
        }

        $code = $answer['error']['code'] ?? null;

        return is_int($code) && in_array($code, self::THROTTLED_ERROR_CODES, true);
    }

    /**
     * What Meta said was wrong, in the one place it says it, falling back to
     * the HTTP status for an answer that is not shaped like an error.
     *
     * @param  array<string, mixed>  $answer
     */
    private function reason(Response $response, array $answer): string
    {
        $message = $answer['error']['message'] ?? null;

        return is_string($message) && $message !== ''
            ? $message
            : "Facebook refused the batch with HTTP {$response->status()}.";
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $body = $response->json();

        return is_array($body) ? $body : ['body' => $response->body()];
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->asJson()
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT);
    }
}
