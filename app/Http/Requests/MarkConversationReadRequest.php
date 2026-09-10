<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkConversationReadRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'message_id' => ['required', 'ulid'],
        ];
    }
}
