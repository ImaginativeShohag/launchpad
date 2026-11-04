<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\SettingKey;
use App\Models\Setting;
use App\Support\Settings;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateSettingRequest extends FormRequest
{
    /** Authorised before validation (and before the 404 for an unknown key), so outsiders learn nothing. */
    public function authorize(): bool
    {
        return Gate::allows('manage', Setting::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // A routed request always carries the key; Scramble evaluates the rules without a route, so it gets the generic shape.
        if ($this->route('key') === null) {
            return ['value' => ['required']];
        }

        $key = $this->settingKey();

        // Laravel's `integer` rule also accepts `true` and `3.0`; only real integers and digit strings pass.
        $shape = is_int($key->default())
            ? function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d{1,10}$/', $value) === 1)) {
                    $fail('The :attribute must be a whole number.');
                }
            }
        : function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value)) {
                $fail('The :attribute must be a string.');
            }
        };

        return ['value' => [$shape, ...$key->rules()]];
    }

    /**
     * Checks that need more than the key's own rules: the domain list entries, and the order of the two integrity ratios
     * (against the stored other value). They run only after the shape and range rules passed, and after `authorize()`.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $key = $this->settingKey();
            $value = $this->input('value');
            // The shape rules passed, so a string key holds a string here.
            if (! is_string($value)) {
                return;
            }

            if ($key === SettingKey::AnticheatDisposableDomains && Settings::parseDomains($value) === null) {
                $validator->errors()->add('value', 'The value must be 1 to '.SettingKey::DOMAINS_MAX_ENTRIES.' lowercase domains separated by commas.');
            }

            $settings = app(Settings::class);

            if ($key === SettingKey::AnticheatProductWatchRatio && (float) $value <= $settings->decimal(SettingKey::AnticheatProductAlertRatio)) {
                $validator->errors()->add('value', 'The watch ratio must stay above the alert ratio.');
            }

            if ($key === SettingKey::AnticheatProductAlertRatio && (float) $value >= $settings->decimal(SettingKey::AnticheatProductWatchRatio)) {
                $validator->errors()->add('value', 'The alert ratio must stay below the watch ratio.');
            }
        }];
    }

    /** The key from the URL; only keys in the enum exist. */
    public function settingKey(): SettingKey
    {
        $key = $this->route('key');
        $setting = is_string($key) ? SettingKey::tryFrom($key) : null;
        abort_if($setting === null, 404);

        return $setting;
    }

    /** The validated value in the type the key stores (int or string). */
    public function settingValue(): int|string
    {
        $value = $this->validated('value');

        return is_int($this->settingKey()->default()) ? (int) $value : (string) $value;
    }
}
