<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\LinkKind;
use App\Http\Requests\Concerns\NormalisesUrls;
use App\Models\Product;
use App\Rules\ProductSlug;
use App\Rules\SafeProfileUrl;
use App\Rules\Utf8;
use App\Support\CleanText;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    use NormalisesUrls;

    /** The maker only; anyone else gets the same 404 as for a missing product, before any validation runs. */
    public function authorize(): Response
    {
        return Gate::inspect('manage', $this->route('product'));
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('name')) {
            $merge['name'] = CleanText::clean($this->input('name'));
        }
        if ($this->has('slug')) {
            $slug = $this->input('slug');
            $merge['slug'] = is_string($slug) ? Str::lower(trim($slug)) : $slug;
        }
        if ($this->has('tagline')) {
            $merge['tagline'] = CleanText::cleanOrNull($this->input('tagline'));
        }
        if ($this->has('description')) {
            $merge['description'] = CleanText::cleanMultilineOrNull($this->input('description'));
        }
        if ($this->has('website_url')) {
            $url = CleanText::cleanOrNull($this->input('website_url'));
            $merge['website_url'] = is_string($url) ? $this->normaliseUrl($url) : $url;
        }

        $links = $this->input('links');
        if (is_array($links)) {
            $merge['links'] = array_map(fn (mixed $link) => $this->cleanLink($link), $links);
        }

        $screenshots = $this->input('screenshots');
        if (is_array($screenshots)) {
            $merge['screenshots'] = array_map(function (mixed $item) {
                if (is_array($item) && array_key_exists('alt_text', $item)) {
                    $item['alt_text'] = CleanText::cleanOrNull($item['alt_text']);
                }

                return $item;
            }, $screenshots);
        }

        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:60', new Utf8, 'regex:/[\p{L}\p{N}]/u'],
            'slug' => ['sometimes', 'required', new ProductSlug($product instanceof Product ? $product->getKey() : null)],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:80', new Utf8],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000', new Utf8],
            'website_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:https', new SafeProfileUrl],
            'links' => ['sometimes', 'array', 'list', 'max:5'],
            'links.*' => ['required', 'array'],
            'links.*.kind' => ['required', 'string', Rule::in(LinkKind::productKinds())],
            'links.*.url' => ['required', 'string', 'max:2048', 'url:https', new SafeProfileUrl],
            // The real limit is the product.max_topics setting, enforced by the action on the locked row.
            'topic_ids' => ['sometimes', 'array', 'list', 'max:10'],
            'topic_ids.*' => ['required', 'string', 'ulid', 'distinct'],
            'screenshots' => ['sometimes', 'array', 'list', 'max:20'],
            'screenshots.*' => ['required', 'array'],
            'screenshots.*.id' => ['required', 'string', 'ulid'],
            'screenshots.*.alt_text' => ['nullable', 'string', 'max:200', new Utf8],
        ];
    }

    private function cleanLink(mixed $link): mixed
    {
        if (! is_array($link)) {
            return $link;
        }

        if (is_string($link['url'] ?? null)) {
            $link['url'] = $this->normaliseUrl(trim($link['url']));
        }

        return $link;
    }
}
