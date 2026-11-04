<?php

namespace App\Http\Requests\Api\V1;

use App\Rules\Utf8;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The view beacon's optional JSON body: `{"referrer": "<document.referrer>"}`. Only a JSON object is read: a query string,
 * a form body, a text body and a JSON list or scalar all mean "no referrer", never an error.
 */
class ProductViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<array-key, mixed> */
    public function validationData(): array
    {
        if (! $this->isJson()) {
            return [];
        }

        /** @var array<array-key, mixed> $json */
        $json = $this->json()->all();

        return array_is_list($json) ? [] : $json;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'referrer' => ['sometimes', 'bail', 'nullable', 'string', 'max:2048', new Utf8],
        ];
    }
}
