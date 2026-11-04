<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesCommentBody;
use Illuminate\Foundation\Http\FormRequest;

class EditCommentRequest extends FormRequest
{
    use ValidatesCommentBody;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['body' => $this->bodyRules()];
    }
}
