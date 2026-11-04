<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The export routes read no input at all: the ids come from the path, the action checks ownership (the uniform 404), and a
 * body or query string of any shape (array, object, null) is ignored, never validated and never a 500.
 */
class ExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return [];
    }

    public function productId(): string
    {
        $id = $this->route('product');

        return is_string($id) ? $id : '';
    }

    public function exportId(): string
    {
        $id = $this->route('export');

        return is_string($id) ? $id : '';
    }
}
