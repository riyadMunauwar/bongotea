<?php

namespace App\Support\Couriers;

use Illuminate\Http\Client\Response;

/**
 * One answer from Pathao, read through the envelope every one of its calls
 * arrives in: a message, a type, a code, and then either the `data` that was
 * asked for or a bag of field errors.
 *
 * Pathao's own shape and nobody else's, which is why it lives beside its
 * driver rather than above the three of them — Steadfast words an answer its
 * own way and reads it its own way (ADR-0013). It is one class and not two
 * readings because the token grant and the booking answer in the same
 * envelope, and a refusal read one way at one call and another way at the
 * other is a wording an operator would meet twice.
 *
 * Total, like every other reading of something that arrived over the wire: a
 * body that is not an object at all is an empty one, and a key that is not
 * there is null. Nothing here throws.
 */
final class PathaoAnswer
{
    /**
     * @param  array<string, mixed>  $body
     */
    private function __construct(
        private readonly array $body,
        private readonly int $status,
    ) {}

    public static function of(Response $response): self
    {
        $body = $response->json();

        return new self(is_array($body) ? $body : [], $response->status());
    }

    /**
     * The answer as it arrived, for the record kept on the Shipment.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** Whatever is at this path, untouched: `access_token`, `data.delivery_fee`. */
    public function get(string $path): mixed
    {
        return data_get($this->body, $path);
    }

    /**
     * Whatever is at this path as a word, or null where Pathao left it out or
     * answered with something that is not one.
     */
    public function text(string $path): ?string
    {
        $value = $this->get($path);

        return is_scalar($value) && filled($value) ? trim((string) $value) : null;
    }

    /**
     * Why Pathao said no, in Pathao's own words wherever it gave any.
     *
     * It words a refusal two ways — a sentence in `message`, or a bag of field
     * errors under `errors` — so both are read, and the HTTP status stands in
     * where it said nothing at all. An operator reads this and acts on it, so
     * it must never come back blank.
     */
    public function reason(): string
    {
        $message = $this->text('message');
        $bag = $this->get('errors');

        $errors = collect(is_array($bag) ? $bag : [])
            ->flatten()
            ->filter(fn (mixed $error): bool => is_string($error) && filled($error));

        return match (true) {
            $message !== null => $message,
            $errors->isNotEmpty() => $errors->implode(' '),
            default => "HTTP {$this->status}.",
        };
    }
}
