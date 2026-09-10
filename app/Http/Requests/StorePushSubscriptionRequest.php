<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePushSubscriptionRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        // The endpoint has to be an https: URL a push service handed out.
        // Anything else is either a mistake or someone pointing the server at
        // a host of their choosing, and neither should reach the sender.
        return [
            'endpoint' => ['required', 'string', 'url:https', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ];
    }
}
