<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['body' => trim((string) $this->input('body'))]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        // Attachments arrive in a later phase; until then a message is its text.
        //
        // `reply_to_message_id` is only shape-checked here. Whether the sender
        // may quote that particular message is an authorization question, not
        // a validation one, and it is answered in the controller against the
        // conversation being posted to — which this request cannot see.
        return [
            'body' => ['required', 'string', 'max:4000'],
            'reply_to_message_id' => ['nullable', 'ulid'],
        ];
    }
}
