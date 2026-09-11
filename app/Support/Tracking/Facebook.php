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

    /** What Meta calls the load of a page, reported by the base code itself. */
    private const PAGE_VIEW = 'PageView';

    /** Meta's click identifier, derived by the browser from an ad click's `fbclid` and kept in the `_fbc` cookie. */
    private const CLICK_ID = 'fbc';

    /** Meta's browser identifier, set by the Pixel itself in the `_fbp` cookie. */
    private const BROWSER_ID = 'fbp';

    /** Where Meta serves the Pixel library from: the one address a page carrying the snippet reaches out to. */
    private const LIBRARY = 'https://connect.facebook.net/en_US/fbevents.js';

    /**
     * Meta's own base code, verbatim.
     *
     * It defines the `fbq` queue so calls made before the library has
     * arrived are not lost, returns early if a copy of itself already ran,
     * and appends the library asynchronously so nothing on the page waits on
     * it. Verbatim because it is what Meta's own diagnostics recognise, and
     * because a hand-trimmed base code is a bug nobody finds until the
     * Merchant's events stop arriving.
     *
     * There is no `<noscript>` image beside it. The half of this that has to
     * survive a browser running no script is the server's, and it already
     * does; a picture request from a page carrying a Customer's details is a
     * cost with no return.
     */
    private const LOADER = "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','".self::LIBRARY."');";

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
            TrackingMoment::Visit => [self::PAGE_VIEW, 'ViewContent'],
            TrackingMoment::CheckoutStarted => ['InitiateCheckout'],
            TrackingMoment::Capture, TrackingMoment::Paid => ['Purchase'],
        };
    }

    /**
     * Meta's two: the click identifier a browser derives from an ad click's
     * `fbclid` and keeps in `_fbc`, and the browser identifier the Pixel
     * itself sets in `_fbp`. Both are sent to the Conversions API plain,
     * because Meta matches on them itself.
     *
     * @return list<string>
     */
    public function clickIdNames(): array
    {
        return [self::CLICK_ID, self::BROWSER_ID];
    }

    public function loadEvent(): string
    {
        return self::PAGE_VIEW;
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
     * The Pixel: the base code, the `PageView` it reports for the page it is
     * written into, and one `fbq("track", …)` per event given.
     *
     * Each event is sent with the same `custom_data` its server copy carries
     * and with the deduplication id as Meta's `eventID`, which is the whole
     * of how the two copies of one Purchase are counted once.
     *
     * The `PageView` is reported once. A caller that has a `PageView` of its
     * own to report — a Page load, which the beacon writes a server copy of
     * — gets that one and not a second bare one, because two `PageView`s
     * from one load is a doubled figure on the Merchant's own Dashboard and
     * only one of them would deduplicate.
     *
     * Where the Page reports no `PageView` at all, the bare one is not fired
     * either: the base code still loads, so the Pixel is initialised and its
     * browser identifier is set for the events that *are* reported, and the
     * one event the Merchant switched off is the one thing it does not send.
     *
     * @param  list<BrowserEvent>  $events
     */
    public function script(Destination $destination, array $events, bool $reportsLoad): string
    {
        if (blank($destination->dataset)) {
            return '';
        }

        $lines = [
            self::LOADER,
            $this->call('init', $destination->dataset),
        ];

        if ($reportsLoad && ! $this->reportsPageView($events)) {
            $lines[] = $this->call('track', self::PAGE_VIEW);
        }

        foreach ($events as $event) {
            $lines[] = $this->track($event);
        }

        return implode("\n", $lines);
    }

    /**
     * Whether the caller is reporting the page's own load itself.
     *
     * @param  list<BrowserEvent>  $events
     */
    private function reportsPageView(array $events): bool
    {
        foreach ($events as $event) {
            if ($event->event === self::PAGE_VIEW) {
                return true;
            }
        }

        return false;
    }

    /**
     * One tracked event: what it reports, and the `eventID` its server copy
     * carries too.
     *
     * An event about no Order reports no parameters — as an empty object
     * rather than an empty array, since `fbq` reads its third argument as
     * one, and because that is exactly what its server copy sends.
     */
    private function track(BrowserEvent $event): string
    {
        $parameters = $event->order === null
            ? $this->encode(new stdClass)
            : $this->encode($this->customData($event->order, $event->contentDetail));

        return sprintf(
            'fbq(%s, %s, %s, {"eventID": %s});',
            $this->encode('track'),
            $this->encode($event->event),
            $parameters,
            $event->deduplicationIdSource(),
        );
    }

    /**
     * One `fbq` call, every argument JSON-encoded.
     *
     * Encoded rather than pasted in, under the same flags Blade's `@json`
     * uses, so that a Product named after a piece of markup cannot end the
     * element it is written into.
     *
     * @param  string  $subject  what the call is about: a dataset to initialise, or an event to track
     */
    private function call(string $method, string $subject): string
    {
        return 'fbq('.$this->encode($method).', '.$this->encode($subject).');';
    }

    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
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
    public function payloadFor(TrackingEvent $event): array
    {
        $order = $event->order;

        $payload = array_filter([
            'event_name' => $event->event,
            'event_time' => $event->occurred_at->getTimestamp(),
            'event_id' => $event->deduplication_id,
            'event_source_url' => $event->source_url,
            'action_source' => self::ACTION_SOURCE,
            'custom_data' => $order === null ? [] : $this->customData($order, $event->content_detail),
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
            self::CLICK_ID => $clickIds[self::CLICK_ID] ?? null,
            self::BROWSER_ID => $clickIds[self::BROWSER_ID] ?? null,
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
     * A Page may withhold the content detail — what was bought, by name and
     * by id — and still report the money. That is what a Merchant selling
     * something Meta's business tool terms restrict needs: Meta keeps
     * optimising on value and currency, the catalogue match is what is given
     * up, and no product name leaves the installation. The count and the
     * delivery category stay: neither says what the parcel holds.
     *
     * @return array<string, mixed>
     */
    private function customData(Order $order, bool $contentDetail): array
    {
        $lines = $order->lines;

        return array_filter([
            'value' => Money::toMajor($order->total),
            'currency' => Str::upper(app(GeneralSettings::class)->currencyCode),
            'content_type' => $contentDetail ? self::CONTENT_TYPE : null,
            'contents' => $contentDetail ? $lines->map(fn (OrderLine $line): array => [
                'id' => self::contentId($line),
                'quantity' => $line->quantity,
                'item_price' => Money::toMajor($line->unit_price),
            ])->values()->all() : null,
            'content_ids' => $contentDetail ? $lines->map(self::contentId(...))->values()->all() : null,

            /** The order number: the same string the event deduplicates on, so Events Manager reads as the Merchant's own admin does. */
            'order_id' => $order->order_number,

            'num_items' => (int) $lines->sum('quantity'),
            'delivery_category' => self::DELIVERY_CATEGORY,
        ], fn (mixed $value): bool => $value !== null);
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
