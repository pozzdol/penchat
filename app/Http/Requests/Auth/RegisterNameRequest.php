<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'username' => ['required', 'string', 'regex:'.User::USERNAME_REGEX, Rule::unique('users', 'username')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'username.regex' => 'Use 3 to 20 letters, numbers or underscores, starting with a letter.',
            'username.unique' => 'That username is taken.',
        ];
    }
}
