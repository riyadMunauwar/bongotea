<?php

namespace App\Http\Requests;

use App\Support\Bangla;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Asks for a One-Time Code: a phone number, in Bangla or English digits.
 * Every message is the buyer's, so every message is Bangla.
 */
class OneTimeCodeRequest extends FormRequest
{
    /** Signing in is what this begins; there is nobody to authorize yet. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => Bangla::latinDigits(trim($this->input('phone')))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail): void {
                if (! PhoneNumber::isValid((string) $value)) {
                    $fail('সঠিক ১১ সংখ্যার মোবাইল নম্বর দিন।');
                }
            }],
        ];
    }

    /**
     * The number in its canonical form, once validated.
     */
    public function phone(): string
    {
        return (string) PhoneNumber::normalise($this->validated('phone'));
    }
}
