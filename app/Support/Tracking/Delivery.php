<?php

namespace App\Support\Tracking;

/**
 * What a Provider said about one batch, in the three words the outbox needs.
 *
 * The distinction that matters is between a refusal and an outage. A Provider
 * that refused the batch has told us something a human must fix — a wrong
 * token, a payload it will never take — so retrying is an infinite loop. A
 * Provider that could not be reached, or that answered 5xx, has told us
 * nothing at all, so the rows stay Pending and the next sweep asks again.
 * This mirrors the payment rule that a transport failure is "could not
 * start", never Failed (ADR-0013).
 */
class Delivery
{
    /**
     * @param  array<string, mixed>  $response  What the Provider actually said, kept for the record.
     */
    private function __construct(
        public readonly bool $accepted,
        public readonly bool $isFinal,
        public readonly ?string $error,
        public readonly array $response,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public static function accepted(array $response = []): self
    {
        return new self(accepted: true, isFinal: true, error: null, response: $response);
    }

    /**
     * The Provider refused the batch and will refuse it again: the rows are
     * Failed with what it said.
     *
     * @param  array<string, mixed>  $response
     */
    public static function refused(string $error, array $response = []): self
    {
        return new self(accepted: false, isFinal: true, error: $error, response: $response);
    }

    /**
     * The Provider could not be reached or could not answer: nothing is
     * decided, and the rows stay Pending for the next sweep.
     */
    public static function unreachable(string $error): self
    {
        return new self(accepted: false, isFinal: false, error: $error, response: []);
    }
}
