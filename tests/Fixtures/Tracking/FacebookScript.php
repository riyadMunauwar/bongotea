<?php

namespace Tests\Fixtures\Tracking;

use App\Enums\TrackingProvider;
use App\Models\Destination;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Facebook's Conversions API as it is documented: one POST per batch to
 * `graph.facebook.com/{version}/{dataset}/events`, carrying `data` and
 * `access_token`, answered with `events_received` — or with an `error`
 * object naming what was wrong.
 */
class FacebookScript extends ProviderScript
{
    public const DATASET = '110022003300440';

    public const ACCESS_TOKEN = 'EAAtest-token-facebook';

    private const ADDRESS = 'graph.facebook.com/*';

    /** Whether the outage the fake stands in for is over; see `fakeOutage()`. */
    private bool $reachable = true;

    public function provider(): TrackingProvider
    {
        return TrackingProvider::Facebook;
    }

    public function makeDestination(): Destination
    {
        return Destination::factory()->create([
            'label' => 'Brand Ads',
            'provider' => TrackingProvider::Facebook,
            'dataset' => self::DATASET,
            'access_token' => self::ACCESS_TOKEN,
        ]);
    }

    public function batchLimit(): int
    {
        return 1000;
    }

    public function acceptanceWindowSeconds(): int
    {
        return 7 * 86400;
    }

    public function purchaseEvent(): string
    {
        return 'Purchase';
    }

    public function pageViewEvent(): string
    {
        return 'PageView';
    }

    /**
     * @return list<string>
     */
    public function clickIdNames(): array
    {
        return ['fbc', 'fbp'];
    }

    /**
     * Meta's own two shapes: `fb.{subdomain index}.{creation time}.{fbclid}`
     * for the click, and the same envelope around the random number the Pixel
     * makes up for the browser.
     *
     * @return array<string, string>
     */
    public function browserClickIds(): array
    {
        return [
            'fbc' => 'fb.1.1757000000000.IwAR0abcdefghijklmnop',
            'fbp' => 'fb.1.1757000000000.1098765432',
        ];
    }

    public function carriesBaseCodeFor(Destination $destination, string $html): bool
    {
        return Str::contains($html, 'connect.facebook.net/en_US/fbevents.js')
            && Str::contains($html, 'fbq("init", "'.$destination->dataset.'");');
    }

    /**
     * Every `fbq("track", …)` the snippet makes, read back into the shape a
     * delivered event arrives in: the event's name, its parameters where it
     * has any, and the `eventID` that deduplicates it against the server's
     * copy.
     *
     * The arguments are read by decoding them as a JSON list, which is what
     * they are once the call around them is stripped — so a nested basket is
     * read as reliably as a flat value, and a Product named after a piece of
     * markup is read as what the browser will actually see.
     *
     * @return list<array<string, mixed>>
     */
    public function browserEventsIn(string $html): array
    {
        preg_match_all('/^\s*fbq\("track", (.+)\);$/m', $html, $calls);

        $events = [];

        foreach ($calls[1] as $arguments) {
            $decoded = json_decode('['.$arguments.']', true);

            Assert::assertIsArray($decoded, "A tracked event was not readable as arguments: [{$arguments}].");

            $events[] = array_filter([
                'event_name' => $decoded[0] ?? null,
                'custom_data' => $decoded[1] ?? null,
                'event_id' => $decoded[2]['eventID'] ?? null,
            ], fn (mixed $value): bool => $value !== null);
        }

        return $events;
    }

    public function fakeAccepted(): void
    {
        Http::fake([self::ADDRESS => $this->acceptance()]);
    }

    /**
     * What the Conversions API answers a batch it took: how many events it
     * received and the trace id support asks for.
     */
    private function acceptance(): PromiseInterface
    {
        return Http::response([
            'events_received' => 1,
            'messages' => [],
            'fbtrace_id' => 'A1bCdEfGhIjKlMn',
        ]);
    }

    public function fakeRefused(string $message = 'Invalid OAuth access token.'): void
    {
        Http::fake([
            self::ADDRESS => Http::response([
                'error' => [
                    'message' => $message,
                    'type' => 'OAuthException',
                    'code' => 190,
                    'fbtrace_id' => 'A1bCdEfGhIjKlMn',
                ],
            ], 400),
        ]);
    }

    public function fakeServerError(): void
    {
        Http::fake([self::ADDRESS => Http::response('', 503)]);
    }

    /**
     * Meta answers its own rate limits with a 400 carrying code 4 as often as
     * with a 429, which is why the status alone cannot tell a throttle from a
     * real refusal.
     */
    public function fakeThrottled(): void
    {
        Http::fake([
            self::ADDRESS => Http::response([
                'error' => [
                    'message' => '(#4) Application request limit reached',
                    'type' => 'OAuthException',
                    'code' => 4,
                    'fbtrace_id' => 'A1bCdEfGhIjKlMn',
                ],
            ], 400),
        ]);
    }

    public function fakeOutage(): void
    {
        $this->reachable = false;

        Http::fake([
            self::ADDRESS => fn (): PromiseInterface => $this->reachable
                ? $this->acceptance()
                : throw new ConnectionException('cURL error 28: Operation timed out.'),
        ]);
    }

    public function endOutage(): void
    {
        $this->reachable = true;
    }

    public function assertBatchesSent(int $count): void
    {
        Http::assertSentCount($count);
    }

    public function assertAddressedTo(Destination $destination): void
    {
        Http::assertSent(fn (Request $request): bool => Str::contains($request->url(), "/{$destination->dataset}/events"));
    }

    public function assertAuthorisedWith(string $accessToken): void
    {
        Http::assertSent(fn (Request $request): bool => ($request->data()['access_token'] ?? null) === $accessToken);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sentEvents(): array
    {
        $events = [];

        foreach ($this->batches() as $batch) {
            foreach ($batch as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @return list<int>
     */
    public function batchSizes(): array
    {
        return array_map(count(...), $this->batches());
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function eventNameOf(array $event): ?string
    {
        return $event['event_name'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function deduplicationIdOf(array $event): ?string
    {
        return $event['event_id'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function orderReferenceOf(array $event): ?string
    {
        return $event['custom_data']['order_id'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function actionSourceOf(array $event): ?string
    {
        return $event['action_source'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function eventTimeOf(array $event): ?int
    {
        return isset($event['event_time']) ? (int) $event['event_time'] : null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function sourceUrlOf(array $event): ?string
    {
        return $event['event_source_url'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function valueOf(array $event): ?float
    {
        return isset($event['custom_data']['value']) ? (float) $event['custom_data']['value'] : null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function currencyOf(array $event): ?string
    {
        return $event['custom_data']['currency'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<array{id: string, quantity: int, price: float}>
     */
    public function contentsOf(array $event): array
    {
        return array_map(fn (array $item): array => [
            'id' => (string) $item['id'],
            'quantity' => (int) $item['quantity'],
            'price' => (float) $item['item_price'],
        ], $event['custom_data']['contents'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function carriesMatchKeyBlock(array $event): bool
    {
        return array_key_exists('user_data', $event);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, string>
     */
    public function hashedMatchKeysOf(array $event): array
    {
        $userData = $event['user_data'] ?? [];

        $keys = [
            'phone' => 'ph',
            'email' => 'em',
            'firstName' => 'fn',
            'lastName' => 'ln',
            'city' => 'ct',
            'externalId' => 'external_id',
        ];

        $found = [];

        foreach ($keys as $ours => $theirs) {
            if (isset($userData[$theirs])) {
                $found[$ours] = $userData[$theirs];
            }
        }

        return $found;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array{ip: string|null, userAgent: string|null, clickIds: array<string, string>}
     */
    public function plainSignalsOf(array $event): array
    {
        $userData = $event['user_data'] ?? [];

        return [
            'ip' => $userData['client_ip_address'] ?? null,
            'userAgent' => $userData['client_user_agent'] ?? null,
            'clickIds' => array_filter([
                'fbc' => $userData['fbc'] ?? null,
                'fbp' => $userData['fbp'] ?? null,
            ], fn (?string $value): bool => $value !== null),
        ];
    }

    public function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * Meta's own normalisation, which every hashed key goes through first:
     * trimmed and lower-cased, a phone reduced to dialled digits, a city
     * stripped of everything but letters.
     */
    public function normalise(string $key, string $value): string
    {
        return match ($key) {
            'phone' => (string) preg_replace('/\D+/', '', $value),
            'city' => (string) preg_replace('/[^a-z]/', '', Str::lower($value)),
            default => Str::lower(trim($value)),
        };
    }

    /**
     * Every batch's `data`, in the order they were sent.
     *
     * @return list<list<array<string, mixed>>>
     */
    private function batches(): array
    {
        $batches = [];

        Http::recorded(function (Request $request) use (&$batches): bool {
            $batches[] = $request->data()['data'] ?? [];

            return true;
        });

        Assert::assertIsArray($batches);

        return $batches;
    }
}
