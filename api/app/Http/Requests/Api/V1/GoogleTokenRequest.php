<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\CleansDeviceName;
use Illuminate\Foundation\Http\FormRequest;

class GoogleTokenRequest extends FormRequest
{
    use CleansDeviceName;

    protected function prepareForValidation(): void
    {
        $this->cleanDeviceName();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id_token' => ['required', 'string', 'max:4096'],
            'device_name' => $this->deviceNameRules(),
        ];
    }
}
