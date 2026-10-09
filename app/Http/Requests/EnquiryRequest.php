<?php

namespace App\Http\Requests;

use App\Support\Bangla;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A question from the contact form: a name, a phone number in Bangla or
 * English digits, what it is about and what they said. Every message is the
 * buyer's, so every message is Bangla.
 */
class EnquiryRequest extends FormRequest
{
    /**
     * The field a person never sees and a form-filling script fills. Anything
     * in it means the question is thanked for and thrown away.
     */
    public const HONEYPOT = 'website';

    /** Anybody may ask. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * A filled honeypot is not validated at all: field errors would tell a
     * script what a person must type, where the controller's thank-you tells
     * it nothing.
     */
    public function validateResolved(): void
    {
        if (! $this->isFromABot()) {
            parent::validateResolved();
        }
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
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail): void {
                if (! PhoneNumber::isValid((string) $value)) {
                    $fail('সঠিক ১১ সংখ্যার মোবাইল নম্বর দিন।');
                }
            }],
            'topic' => ['nullable', 'string', 'max:60'],
            'message' => ['nullable', 'string', 'max:2000'],
            self::HONEYPOT => ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'আপনার নাম দিন।',
            'name.max' => 'নাম ১২০ অক্ষরের মধ্যে লিখুন।',
            'phone.required' => 'সঠিক ১১ সংখ্যার মোবাইল নম্বর দিন।',
            'topic.max' => 'বিষয়টি ৬০ অক্ষরের মধ্যে লিখুন।',
            'message.max' => 'মেসেজ ২০০০ অক্ষরের মধ্যে লিখুন।',
        ];
    }

    /** Whether a script filled the field no person sees. */
    public function isFromABot(): bool
    {
        return filled($this->input(self::HONEYPOT));
    }

    /** The number in its canonical form, once validated. */
    public function phone(): string
    {
        return (string) PhoneNumber::normalise($this->validated('phone'));
    }

    /**
     * @return array{name: string, phone: string, topic: ?string, message: ?string}
     */
    public function enquiry(): array
    {
        return [
            'name' => trim((string) $this->validated('name')),
            'phone' => $this->phone(),
            'topic' => filled($this->validated('topic')) ? trim((string) $this->validated('topic')) : null,
            'message' => filled($this->validated('message')) ? trim((string) $this->validated('message')) : null,
        ];
    }
}
