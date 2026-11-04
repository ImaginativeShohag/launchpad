<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\LinkKind;
use App\Http\Requests\Concerns\NormalisesUrls;
use App\Rules\Handle;
use App\Rules\SafeProfileUrl;
use App\Rules\Utf8;
use App\Support\CleanText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    use NormalisesUrls;

    /** Only strings are normalised; anything else reaches the validator untouched and is rejected there. */
    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('handle')) {
            $handle = $this->input('handle');
            $merge['handle'] = is_string($handle) ? Str::lower(trim($handle)) : $handle;
        }

        if ($this->has('name')) {
            $merge['name'] = CleanText::clean($this->input('name'));
        }

        if ($this->has('headline')) {
            $merge['headline'] = CleanText::cleanOrNull($this->input('headline'));
        }

        $links = $this->input('links');

        if (is_array($links)) {
            $merge['links'] = array_map(fn (mixed $link) => $this->cleanLink($link), $links);
        }

        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // At least one letter or number, so a name cannot be made of invisible or punctuation-only characters.
            'name' => ['sometimes', 'required', 'string', 'max:80', new Utf8, 'regex:/[\p{L}\p{N}]/u'],
            'handle' => ['sometimes', 'required', 'string', new Handle((string) $this->user()?->getKey())],
            'headline' => ['sometimes', 'nullable', 'string', 'max:120', new Utf8],
            'links' => ['sometimes', 'array', 'list', 'max:5'],
            'links.*' => ['required', 'array'],
            'links.*.kind' => ['required', 'string', Rule::enum(LinkKind::class)],
            'links.*.label' => ['nullable', 'string', 'max:40', new Utf8],
            'links.*.url' => ['required', 'string', 'max:2048', 'url:https', new SafeProfileUrl],
        ];
    }

    private function cleanLink(mixed $link): mixed
    {
        if (! is_array($link)) {
            return $link;
        }

        if (array_key_exists('label', $link)) {
            $link['label'] = CleanText::cleanOrNull($link['label']);
        }

        if (is_string($link['url'] ?? null)) {
            $link['url'] = $this->normaliseUrl(trim($link['url']));
        }

        return $link;
    }
}
