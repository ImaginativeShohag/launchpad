<?php

namespace App\Actions\Search;

use App\Support\CleanText;
use Normalizer;

/**
 * What a visitor typed, reduced to safe tokens. Nothing but letters, combining marks, digits and the joiners ZWNJ/ZWJ
 * survives, so no tsquery operator, quote or backslash can reach PostgreSQL, and a token never needs escaping. The
 * database folds accents and case itself (`launchpad_fold`); here only NFKC and lower case are applied.
 *
 * ZWNJ and ZWJ stay inside a token because PostgreSQL's parser keeps them inside one lexeme (Persian and Bengali words);
 * splitting on them would make a typed word differ from the indexed one.
 */
final class SearchQuery
{
    /** @param  list<string>  $tokens */
    private function __construct(private readonly array $tokens) {}

    /** @param  string  $q  already valid UTF-8 and at most 100 characters (the request guarantees it; this class stays safe if not) */
    public static function fromString(string $q): self
    {
        // Control and separator characters (newline, tab, U+2028 ...) are word breaks: CleanText would delete them and glue
        // "launch\npad" into "launchpad". Invalid UTF-8 makes preg_replace return null, which ends in an empty query.
        $spaced = preg_replace('/[\p{Cc}\p{Z}]+/u', ' ', $q);
        $cleaned = $spaced === null ? null : CleanText::clean($spaced);
        $normalised = is_string($cleaned) ? Normalizer::normalize($cleaned, Normalizer::FORM_KC) : false;

        if ($normalised === false || $normalised === '') {
            return new self([]);
        }

        $parts = preg_split('/[^\p{L}\p{M}\p{N}\x{200C}\x{200D}]+/u', mb_convert_case($normalised, MB_CASE_LOWER_SIMPLE, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);
        $tokens = [];

        foreach ($parts === false ? [] : $parts as $part) {
            // Trim, cut, then trim again: the cut can leave a joiner at the end.
            $token = trim(mb_substr(trim($part, "\u{200C}\u{200D}"), 0, SearchTuning::MAX_TOKEN_LENGTH), "\u{200C}\u{200D}");

            if (mb_strlen($token) < SearchTuning::MIN_TOKEN_LENGTH || preg_match('/[\p{L}\p{N}]/u', $token) !== 1) {
                continue;
            }

            $tokens[] = $token;

            if (count($tokens) === SearchTuning::MAX_TOKENS) {
                break;
            }
        }

        return new self($tokens);
    }

    /** @return list<string> */
    public function tokens(): array
    {
        return $this->tokens;
    }

    public function isEmpty(): bool
    {
        return $this->tokens === [];
    }

    /** @return list<string> every token but the last: matched as whole words */
    public function wholeTokens(): array
    {
        return array_slice($this->tokens, 0, -1);
    }

    /** The last token, matched as a prefix (as-you-type). Call only when !isEmpty(). */
    public function prefixToken(): string
    {
        return array_slice($this->tokens, -1)[0] ?? '';
    }

    /** The tokens joined by one space: the trigram arm, the exact-name term and `meta.query`. */
    public function phrase(): string
    {
        return implode(' ', $this->tokens);
    }

    public function allowsTypoMatch(): bool
    {
        return mb_strlen($this->phrase()) >= SearchTuning::MIN_TYPO_LENGTH;
    }

    /** Binds a cursor to this search: 12 hex characters of sha256(sort, phrase, topic). */
    public function hash(string $sort, ?string $topic): string
    {
        return self::hashFor($this, $sort, $topic);
    }

    /** Like hash(), for a topic-only search (no query): the phrase is empty. */
    public static function hashFor(?self $query, string $sort, ?string $topic): string
    {
        return substr(hash('sha256', $sort."\n".($query?->phrase() ?? '')."\n".($topic ?? '')), 0, 12);
    }
}
