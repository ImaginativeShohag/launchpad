<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** Authorisation only: the maker, or the uniform 404. Used by endpoints without a body. */
class OwnedProductRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->route('product'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
