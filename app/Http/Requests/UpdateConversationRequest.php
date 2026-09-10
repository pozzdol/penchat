<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateConversationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        // Every field optional: the panel sends one switch at a time.
        return [
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:60'],
            'members_can_add' => ['sometimes', 'boolean'],
            'admins_can_promote' => ['sometimes', 'boolean'],
        ];
    }
}
