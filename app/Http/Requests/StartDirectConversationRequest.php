<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StartDirectConversationRequest extends FormRequest
{
    /** People paste "@luis", type "Luis", or add a stray space. All the same handle. */
    protected function prepareForValidation(): void
    {
        $this->merge(['username' => User::normalizeUsername($this->input('username'))]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'regex:'.User::USERNAME_REGEX],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        // The shape of a username is not worth explaining here — anything that
        // fails the pattern simply cannot belong to anyone.
        return [
            'username.regex' => 'No one has that username.',
        ];
    }
}
