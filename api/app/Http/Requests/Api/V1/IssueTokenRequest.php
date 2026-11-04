<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\CleansDeviceName;
use Illuminate\Foundation\Http\FormRequest;

class IssueTokenRequest extends FormRequest
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
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:200'],
            'device_name' => $this->deviceNameRules(),
        ];
    }
}
