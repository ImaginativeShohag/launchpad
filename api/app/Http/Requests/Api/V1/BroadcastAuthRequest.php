<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class BroadcastAuthRequest extends FormRequest
{
    /**
     * Only plain strings reach Laravel's broadcaster (it string-handles both fields and would 500 on
     * an array). The socket id is Pusher-protocol shaped: two dot-separated integers.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channel_name' => ['bail', 'required', 'string', 'max:200'],
            'socket_id' => ['bail', 'required', 'string', 'max:64', 'regex:/\A\d{1,20}\.\d{1,20}\z/'],
        ];
    }
}
