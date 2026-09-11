<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class RegisterNameRequest extends FormRequest
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
        // Shared with the settings form. Nobody is being ignored here: there
        // is no row yet to collide with.
        return [
            'name' => User::nameRules(),
            'username' => User::usernameRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return User::identityMessages();
    }
}
