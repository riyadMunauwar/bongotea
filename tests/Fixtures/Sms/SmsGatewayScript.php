<?php

namespace Tests\Fixtures\Sms;

use App\Enums\SmsGateway;

/**
 * One SMS Gateway's documented request and response shapes, scripted for the
 * faked HTTP client, so the gateway tests run once per gateway as a dataset
 * and a later gateway adds a row rather than a file — the seam
 * `GatewayScript` and `CourierScript` already are.
 */
abstract class SmsGatewayScript
{
    abstract public function gateway(): SmsGateway;

    /** Choose this gateway for the store with its credentials filled, the way a Merchant would. */
    abstract public function makeUsable(): void;

    /** Fake the gateway taking the text and answering with its own reference for it. */
    abstract public function fakeAccepted(): void;

    /** The reference `fakeAccepted()` answers with. */
    abstract public function acceptedReference(): string;

    /** Fake the gateway refusing the installation's credentials. */
    abstract public function fakeAuthFailure(): void;

    /** Fake the gateway refusing the text itself, in the words it uses. */
    abstract public function fakeValidationError(): void;

    /** What `fakeValidationError()` says. */
    abstract public function validationMessage(): string;

    /** Fake the gateway unreachable for every call. */
    abstract public function fakeOutage(): void;

    /** Fake the gateway answering with a server error and nothing else. */
    abstract public function fakeServerError(): void;

    /** Fake the gateway declining to take the call this minute. */
    abstract public function fakeRateLimited(): void;

    /** Assert exactly one text went out, to this number in the gateway's own form, saying this. */
    abstract public function assertSent(string $phone, string $message): void;

    /**
     * Every message the gateway was asked to send, in order, keyed by the
     * number in the gateway's own form.
     *
     * @return list<array{to: string, message: string}>
     */
    abstract public function sent(): array;
}
