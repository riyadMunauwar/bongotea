<?php

namespace App\Http\Controllers;

use App\Http\Requests\OneTimeCodeRequest;
use App\Http\Requests\SignInRequest;
use App\Support\Customers\CustomerGuard;
use App\Support\Customers\OneTimeCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Signing in: a One-Time Code texted to a number, the code traded for this
 * browser being signed in as it, and signing out.
 *
 * Answers the My Orders Block's requests in JSON; the Block reloads the Page
 * once the browser's standing has changed, so what it draws is the server's.
 */
class SigningInController extends Controller
{
    public function __construct(private readonly OneTimeCodes $codes) {}

    public function code(OneTimeCodeRequest $request): JsonResponse
    {
        if (! $this->codes->send($request->phone())) {
            throw ValidationException::withMessages([
                'phone' => 'এই নম্বরে এক ঘণ্টায় '.OneTimeCodes::SENDS_PER_HOUR.'টির বেশি কোড পাঠানো যায় না। একটু পরে আবার চেষ্টা করুন।',
            ]);
        }

        return response()->json(['sent' => true]);
    }

    public function signIn(SignInRequest $request): JsonResponse
    {
        if (! $this->codes->redeem($request->phone(), $request->code())) {
            throw ValidationException::withMessages([
                'code' => 'কোডটি মেলেনি বা মেয়াদ শেষ। নতুন কোড নিন।',
            ]);
        }

        $this->guard()->signIn($request->phone());

        return response()->json(['signedIn' => true]);
    }

    public function signOut(): JsonResponse
    {
        $this->guard()->signOut();

        return response()->json(['signedIn' => false]);
    }

    private function guard(): CustomerGuard
    {
        /** @var CustomerGuard */
        return Auth::guard('customer');
    }
}
