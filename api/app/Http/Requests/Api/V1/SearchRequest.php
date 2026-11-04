<?php

namespace App\Http\Requests\Api\V1;

use App\Actions\Search\SearchCursor;
use App\Actions\Search\SearchQuery;
use App\Actions\Search\SearchTuning;
use App\Enums\SearchSort;
use App\Rules\KeysetCursor;
use App\Rules\SlugFormat;
use App\Rules\Utf8;
use App\Support\CleanText;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * GET /search. Query string only (a body is ignored). A repeated scalar (`?q=a&q=b`) is last-wins, as everywhere in PHP;
 * array and object forms (`q[]=`, `q[a]=`) are 422.
 */
class SearchRequest extends CursorListRequest
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function authorize(): bool
    {
        return true; // public
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query();
    }

    /** The cursor's keys depend on the sort, which depends on whether a query was typed: read defensively, before validation. */
    protected function cursorShape(): array
    {
        $raw = $this->query('sort');
        $sort = is_string($raw) ? SearchSort::tryFrom($raw) : null;

        return SearchCursor::shape($sort ?? (self::parse($this->query('q')) !== null ? SearchSort::Relevance : SearchSort::Votes));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'bail', 'string', new Utf8, 'max:'.SearchTuning::MAX_QUERY_LENGTH],
            'topic' => ['sometimes', 'nullable', 'bail', 'string', new SlugFormat(2, 40), Rule::exists('topics', 'slug')],
            'sort' => ['sometimes', 'nullable', 'bail', 'string', Rule::in(SearchSort::values())],
            'per_page' => ['sometimes', 'bail', 'integer', 'between:1,'.static::MAX_PER_PAGE],
            'cursor' => ['sometimes', 'nullable', 'bail', 'string', new KeysetCursor($this->cursorShape())],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // Only once the plain rules passed: the checks below read the already-typed values.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $validator->getData();
            $cleaned = self::clean($data['q'] ?? null);
            $query = self::parse($data['q'] ?? null);
            $topic = isset($data['topic']) && is_string($data['topic']) ? $data['topic'] : null;
            $sortInput = $data['sort'] ?? null;
            $sort = is_string($sortInput) ? SearchSort::tryFrom($sortInput) : null;

            if ($cleaned !== null && $query === null) {
                $validator->errors()->add('q', 'Enter at least two letters or digits.');

                return;
            }

            if ($query === null && $topic === null) {
                $validator->errors()->add('q', 'Enter something to search for, or choose a topic.');

                return;
            }

            if ($sort === SearchSort::Relevance && $query === null) {
                $validator->errors()->add('sort', 'Sorting by relevance needs a search term.');

                return;
            }

            $cursor = $data['cursor'] ?? null;

            if (is_string($cursor) && $cursor !== '') {
                $effective = $sort ?? ($query !== null ? SearchSort::Relevance : SearchSort::Votes);
                $values = SearchCursor::decode($cursor);

                if ($values === null || ($values['h'] ?? null) !== SearchQuery::hashFor($query, $effective->value, $topic)) {
                    $validator->errors()->add('cursor', 'The cursor does not belong to this search.');
                }
            }
        }];
    }

    /** The cleaned text of `q`, or null when it is absent, not text, or only whitespace / invisible characters. */
    private static function clean(mixed $raw): ?string
    {
        $clean = is_string($raw) ? CleanText::cleanOrNull($raw) : null;

        return is_string($clean) ? $clean : null;
    }

    /** The parsed query, or null when `q` is absent or has no usable token. */
    private static function parse(mixed $raw): ?SearchQuery
    {
        $clean = self::clean($raw);

        if ($clean === null) {
            return null;
        }

        $query = SearchQuery::fromString($clean);

        return $query->isEmpty() ? null : $query;
    }

    /** The cleaned `q` as typed (validated to at most 100 characters); null when absent. */
    public function typed(): ?string
    {
        return self::clean($this->validated('q'));
    }

    public function searchQuery(): ?SearchQuery
    {
        return self::parse($this->validated('q'));
    }

    public function topic(): ?string
    {
        $topic = $this->validated('topic');

        return is_string($topic) ? $topic : null;
    }

    public function sort(): SearchSort
    {
        $sort = $this->validated('sort');

        return (is_string($sort) ? SearchSort::tryFrom($sort) : null) ?? ($this->searchQuery() !== null ? SearchSort::Relevance : SearchSort::Votes);
    }

    public function cursor(): ?string
    {
        $cursor = $this->validated('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }
}
