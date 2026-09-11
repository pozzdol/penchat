<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The two things a person may change about themselves today. Photo and email
 * are their own problems — an upload and a credential change — and neither
 * belongs in a form that saves on every keystroke's worth of typing.
 */
class UpdateProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'username' => User::normalizeUsername($this->input('username')),
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => User::nameRules(),
            // Ignoring themselves: saving the form without touching the handle
            // must not trip over the handle they already hold.
            'username' => User::usernameRules($this->user()->id),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return User::identityMessages();
    }
}
