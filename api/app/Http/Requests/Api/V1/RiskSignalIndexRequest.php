<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\RiskSignalKind;
use App\Models\RiskSignal;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * The risk-signal list: `status` (open by default), `kind`, `user`, `product`, `min_weight`, `from`/`to`, `per_page` and a
 * shape-checked cursor. Staff only, judged before anything is validated. Reads the query string only.
 */
class RiskSignalIndexRequest extends CursorListRequest
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

    /** The cursor carries the ordering columns of the status; an unusable status falls back to the open shape and fails on its own rule. */
    protected function cursorShape(): array
    {
        return match ($this->query('status')) {
            'reviewed' => ['reviewed_at' => 'date', 'id' => 'ulid'],
            'all' => ['detected_at' => 'date', 'id' => 'ulid'],
            default => ['weight' => 'int', 'detected_at' => 'date', 'id' => 'ulid'],
        };
    }

    protected function extraRules(): array
    {
        return [
            'status' => ['sometimes', 'bail', 'string', Rule::in(['open', 'reviewed', 'all'])],
            'kind' => ['sometimes', 'bail', 'string', Rule::in(RiskSignalKind::values())],
            'user' => ['sometimes', 'bail', 'string', 'ulid'],
            'product' => ['sometimes', 'bail', 'string', 'ulid'],
            'min_weight' => ['sometimes', 'bail', 'integer', 'between:0,100'],
            'from' => ['sometimes', 'bail', 'string', $this->day()],
            'to' => ['sometimes', 'bail', 'string', $this->day()],
        ];
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // Only reached when the shape rules passed for both (an invalid one already has its error).
            $from = $this->query('from');
            $to = $this->query('to');

            if ($validator->errors()->isEmpty() && is_string($from) && is_string($to) && $from > $to) {
                $validator->errors()->add('from', 'The from date must be on or before the to date.');
            }
        }];
    }

    /** `open` unless another status was asked for (and validated). */
    public function status(): string
    {
        $status = $this->validated('status');

        return $status === 'reviewed' || $status === 'all' ? $status : 'open';
    }

    /** @return array{kind: ?string, user: ?string, product: ?string, min_weight: ?int, from: ?string, to: ?string} */
    public function filters(): array
    {
        $text = fn (string $key): ?string => is_string($v = $this->validated($key)) ? $v : null;
        $weight = $this->validated('min_weight');

        return [
            'kind' => $text('kind'), 'user' => $text('user'), 'product' => $text('product'),
            'min_weight' => is_numeric($weight) ? (int) $weight : null,
            'from' => $text('from'), 'to' => $text('to'),
        ];
    }

    /** A real calendar day, written `YYYY-MM-DD` (Postgres rejects what Carbon would roll over, and year 0). */
    private function day(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1 || (int) $m[1] < 1900 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                $fail('The :attribute must be a date written YYYY-MM-DD.');
            }
        };
    }
}
