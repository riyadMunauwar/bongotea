<?php

namespace App\Http\Controllers;

use App\Http\Requests\EnquiryRequest;
use App\Models\Enquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The contact form's question, kept as an Enquiry for a User to call back.
 *
 * Answers the Contact Form Block in JSON. Every Enquiry raises a Staff Alert
 * the store pays for, so one number may ask a few times an hour (and one
 * address a few more, by the `enquiry` limiter on the route); a question with
 * the honeypot filled is thanked for exactly like a real one and kept nowhere,
 * so a script learns nothing from the answer.
 */
class EnquiryController extends Controller
{
    /** Questions one number may send in an hour. */
    public const PER_PHONE_PER_HOUR = 3;

    public function store(EnquiryRequest $request): JsonResponse
    {
        if ($request->isFromABot()) {
            return response()->json(['sent' => true]);
        }

        $allowed = RateLimiter::attempt('enquiry:'.$request->phone(), self::PER_PHONE_PER_HOUR, fn (): bool => true, 60 * 60);

        if (! $allowed) {
            throw ValidationException::withMessages([
                'phone' => 'এই নম্বর থেকে অনেকবার মেসেজ এসেছে। আমরা শীঘ্রই ফোন করব।',
            ]);
        }

        Enquiry::query()->create($request->enquiry());

        return response()->json(['sent' => true]);
    }
}
