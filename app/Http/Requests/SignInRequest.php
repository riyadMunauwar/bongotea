<?php

namespace App\Http\Requests;

use App\Support\Bangla;
use App\Support\Customers\OneTimeCodes;

/**
 * Signs in: the phone number a One-Time Code was sent to, and the code.
 */
class SignInRequest extends OneTimeCodeRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        if (is_string($this->input('code'))) {
            $this->merge(['code' => Bangla::latinDigits(trim($this->input('code')))]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'code' => ['required', 'string', 'digits:'.OneTimeCodes::DIGITS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.*' => '৪ সংখ্যার কোডটি দিন।',
        ];
    }

    public function code(): string
    {
        return $this->validated('code');
    }
}
