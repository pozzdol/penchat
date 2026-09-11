<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SendEmailChangeCodeRequest extends FormRequest
{
    /** Same normalisation sign-in does: the unique index is case-sensitive. */
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        /*
         * Uniqueness is checked here, and that is a considered departure from
         * the sign-in flow, which answers identically for a known and an
         * unknown address so nobody can enumerate accounts.
         *
         * The difference is who is asking. Sign-in is unauthenticated and open
         * to the internet; this is a named person who is already inside, and
         * for them "is this address taken" is a question about five colleagues
         * they already know. Weighed against telling somebody only *after*
         * they have fetched a code that the address was never available, the
         * clear answer wins.
         */
        return [
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'That address is already used by another account.',
        ];
    }
}
