<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Topic;
use App\Rules\SlugFormat;
use App\Rules\StrictInteger;
use App\Rules\Utf8;
use App\Support\CleanText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage', Topic::class);
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('slug')) {
            $slug = $this->input('slug');
            $merge['slug'] = is_string($slug) ? Str::lower(trim($slug)) : $slug;
        }
        if ($this->has('name')) {
            $merge['name'] = CleanText::clean($this->input('name'));
        }
        if ($this->has('emoji')) {
            $merge['emoji'] = CleanText::cleanOrNull($this->input('emoji'));
        }
        if ($this->has('description')) {
            $merge['description'] = CleanText::cleanOrNull($this->input('description'));
        }

        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $topic = $this->route('topic');

        return [
            'slug' => ['sometimes', 'bail', 'required', 'string', new SlugFormat(2, 40),
                Rule::unique('topics', 'slug')->ignore($topic instanceof Topic ? $topic->getKey() : null)],
            'name' => ['sometimes', 'required', 'string', 'max:60', new Utf8, 'regex:/[\p{L}\p{N}]/u'],
            'emoji' => ['sometimes', 'nullable', 'string', 'max:8', new Utf8],
            'description' => ['sometimes', 'nullable', 'string', 'max:280', new Utf8],
            'position' => ['sometimes', 'bail', 'required', new StrictInteger, 'integer', 'between:0,1000'],
        ];
    }
}
