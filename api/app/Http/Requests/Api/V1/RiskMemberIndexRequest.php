<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SettingKey;
use App\Models\RiskSignal;
use App\Support\Settings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Members by risk: `min_score` (default the flag score), `suspended`, `per_page` and a cursor. Staff only, judged first. */
class RiskMemberIndexRequest extends CursorListRequest
{
    public function authorize(): bool
    {
        return Gate::allows('review', RiskSignal::class);
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query();
    }

    protected function cursorShape(): array
    {
        return ['risk_score' => 'int', 'id' => 'ulid'];
    }

    protected function extraRules(): array
    {
        return [
            'min_score' => ['sometimes', 'bail', 'integer', 'between:1,100'],
            'suspended' => ['sometimes', 'bail', 'string', Rule::in(['true', 'false'])],
        ];
    }

    public function minScore(): int
    {
        $value = $this->validated('min_score');

        return is_numeric($value) ? (int) $value : app(Settings::class)->integer(SettingKey::AnticheatFlagRiskScore);
    }

    /** `true` / `false`, or null for both. */
    public function suspended(): ?bool
    {
        return match ($this->validated('suspended')) {
            'true' => true,
            'false' => false,
            default => null,
        };
    }
}
