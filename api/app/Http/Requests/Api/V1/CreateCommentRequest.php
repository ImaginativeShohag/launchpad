<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesCommentBody;
use App\Rules\Utf8;
use Illuminate\Foundation\Http\FormRequest;

class CreateCommentRequest extends FormRequest
{
    use ValidatesCommentBody;

    /** The route guards (auth, active, verified) do the work. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'body' => $this->bodyRules(),
            // Any string that is not an eligible comment of this product is the uniform 404 from the action.
            'parent_id' => ['sometimes', 'bail', 'string', 'max:64', new Utf8],
        ];
    }

    public function parentId(): ?string
    {
        $parent = $this->validated('parent_id');

        return is_string($parent) ? $parent : null;
    }
}
