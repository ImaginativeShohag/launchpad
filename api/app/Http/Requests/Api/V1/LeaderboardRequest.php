<?php

namespace App\Http\Requests\Api\V1;

use App\Actions\Ranking\Leaderboard;
use App\Rules\SlugFormat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Query-string only: a body is ignored. */
class LeaderboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period' => ['bail', 'required', 'string', Rule::in(Leaderboard::PERIODS)],
            'topic' => ['bail', 'sometimes', 'string', new SlugFormat(2, 40), Rule::exists('topics', 'slug')],
        ];
    }

    public function period(): string
    {
        return $this->validated('period');
    }

    public function topic(): ?string
    {
        $topic = $this->validated('topic');

        return is_string($topic) ? $topic : null;
    }
}
