<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Rules\Utf8;
use App\Support\CleanText;
use Illuminate\Validation\Validator;

/** The body rules shared by creating and editing a comment: cleaned display text, 1 to 1000 characters, a letter/number/emoji, at most 3 links. */
trait ValidatesCommentBody
{
    public const MAX_LINKS = 3;

    protected function prepareForValidation(): void
    {
        // Non-strings and invalid UTF-8 pass through unchanged for the rules below to reject.
        $this->merge(['body' => CleanText::cleanMultiline($this->input('body'))]);
    }

    /** @return list<mixed> */
    protected function bodyRules(): array
    {
        return ['bail', 'required', 'string', 'max:1000', new Utf8, 'regex:/[\p{L}\p{N}\p{Extended_Pictographic}]/u'];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $body = $this->input('body');

            if (is_string($body) && self::countLinks($body) > self::MAX_LINKS) {
                $validator->errors()->add('body', 'A comment can contain at most '.self::MAX_LINKS.' links.');
            }
        }];
    }

    /**
     * Links in the text: `scheme://…`, `www.…` and bare domains (`label.tld`, tld of 2+ letters), counted on an NFKC
     * copy so fullwidth forms count too. The stored body stays the original. Prose such as "See you at 5pm." or
     * "version 3.5" has no letters-only tld after a dot, so it is not a link.
     */
    public static function countLinks(string $body): int
    {
        if (! mb_check_encoding($body, 'UTF-8')) {
            return 0;
        }

        $folded = \Normalizer::normalize($body, \Normalizer::FORM_KC);
        $folded = $folded === false ? $body : $folded;
        $pattern = '~\b[a-z][a-z0-9+.\-]*://\S*|\bwww\.\S*|(?<![\w@.\-])(?:[a-z0-9][a-z0-9\-]*\.)+[a-z]{2,}\b(?:/\S*)?~iu';

        return (int) preg_match_all($pattern, $folded);
    }

    /** The cleaned body; call only after validation. */
    public function body(): string
    {
        $body = $this->validated('body');

        return is_string($body) ? $body : '';
    }
}
