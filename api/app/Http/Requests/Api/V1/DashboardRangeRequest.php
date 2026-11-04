<?php

namespace App\Http\Requests\Api\V1;

use App\Actions\Dashboard\Window;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Validator;

/**
 * `from` and `to` (Y-m-d, UTC, real calendar dates) of the referrer read. Query string only: a JSON body is ignored. The
 * window itself (defaults, caps, clamp) is built after the plain rules passed and its errors are added to the fields.
 */
class DashboardRangeRequest extends DashboardRequest
{
    /** Years the dashboard accepts: a launch can never be earlier, and far-future years only produce empty windows. */
    private const YEARS = [2000, 2099];

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['from' => $this->dateRules(), 'to' => $this->dateRules()];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // Only once the plain rules passed: the window reads the already-checked strings.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $window = $this->makeWindow($validator->getData());

            foreach ($window->errors() as $field => $message) {
                $validator->errors()->add($field, $message);
            }

            $this->attributes->set('dashboard_window', $window);
        }];
    }

    public function window(): Window
    {
        $window = $this->attributes->get('dashboard_window');

        if (! $window instanceof Window) {
            throw new \LogicException('The dashboard window is read before validation.');
        }

        return $window;
    }

    /** @param  array<string, mixed>  $data */
    protected function makeWindow(array $data): Window
    {
        return Window::range($this->product(), self::text($data['from'] ?? null), self::text($data['to'] ?? null), CarbonImmutable::now('UTC'));
    }

    protected static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** @return list<mixed> */
    private function dateRules(): array
    {
        return [
            'sometimes', 'bail', 'string', 'regex:/\A\d{4}-\d{2}-\d{2}\z/', 'date_format:Y-m-d',
            function (string $attribute, mixed $value, Closure $fail): void {
                [$year, $month, $day] = array_map('intval', explode('-', (string) $value));

                if (! checkdate($month, $day, $year) || $year < self::YEARS[0] || $year > self::YEARS[1]) {
                    $fail('The '.$attribute.' must be a real calendar date between '.self::YEARS[0].' and '.self::YEARS[1].'.');
                }
            },
        ];
    }
}
