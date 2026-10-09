<?php

namespace App\Support\Customers;

use App\Models\OneTimeCode;
use App\Support\Settings\StoreSettings;
use App\Support\Sms\SendText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One-Time Codes: four digits by SMS whose only use is Signing in.
 *
 * Good once and for five minutes, and kept only as a hash. A number is sent
 * at most three an hour, because every one is a text the store pays for; a
 * code is thrown away after five wrong guesses, because four digits fall to
 * ten thousand tries and nothing else stands between a guesser and someone's
 * Orders — and a number guessed at ten times in a day takes no code until
 * the day is out. Each number holds one code at a time: a new one replaces
 * the last.
 *
 * Numbers arrive here already in their canonical form.
 */
class OneTimeCodes
{
    public const DIGITS = 4;

    public const VALID_MINUTES = 5;

    public const SENDS_PER_HOUR = 3;

    public const GUESSES = 5;

    /**
     * Wrong guesses a number takes in a day, across every code and every
     * address: without it, three codes an hour of five guesses each walk
     * the whole ten thousand within a month from enough addresses.
     */
    public const WRONG_GUESSES_PER_DAY = 10;

    public function __construct(private readonly StoreSettings $store) {}

    /**
     * Text the number a fresh code, unless it has had its three this hour.
     */
    public function send(string $phone): bool
    {
        $allowed = RateLimiter::attempt('one-time-code:'.$phone, self::SENDS_PER_HOUR, fn (): bool => true, 60 * 60);

        if (! $allowed) {
            return false;
        }

        $code = str_pad((string) random_int(0, 10 ** self::DIGITS - 1), self::DIGITS, '0', STR_PAD_LEFT);

        OneTimeCode::query()->updateOrCreate(['phone' => $phone], [
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::VALID_MINUTES),
            'failed_attempts' => 0,
        ]);

        SendText::dispatch($phone, "{$this->store->name}: আপনার কোড {$code}। ".self::VALID_MINUTES.' মিনিটের মধ্যে ব্যবহার করুন।');

        return true;
    }

    /**
     * Whether the code is the one last sent to the number and still good —
     * using it up when it is. Under a lock, so one code cannot sign in twice
     * and two wrong guesses at once both count.
     */
    public function redeem(string $phone, string $code): bool
    {
        $guesses = 'one-time-code-guesses:'.$phone;

        if (RateLimiter::tooManyAttempts($guesses, self::WRONG_GUESSES_PER_DAY)) {
            return false;
        }

        return DB::transaction(function () use ($phone, $code, $guesses): bool {
            $stored = OneTimeCode::query()->where('phone', $phone)->lockForUpdate()->first();

            if ($stored === null) {
                return false;
            }

            if ($stored->expires_at->isPast() || $stored->failed_attempts >= self::GUESSES) {
                $stored->delete();

                return false;
            }

            if (! Hash::check($code, $stored->code_hash)) {
                $stored->increment('failed_attempts');
                RateLimiter::hit($guesses, 60 * 60 * 24);

                return false;
            }

            $stored->delete();

            return true;
        });
    }
}
