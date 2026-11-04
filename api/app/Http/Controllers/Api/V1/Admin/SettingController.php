<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateSettingRequest;
use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class SettingController extends Controller
{
    public function index(Settings $settings): JsonResponse
    {
        Gate::authorize('manage', Setting::class);

        return response()->json([
            'data' => array_map(fn (SettingKey $key) => $this->present($key, $settings), SettingKey::cases()),
        ]);
    }

    public function update(UpdateSettingRequest $request, string $key, Settings $settings): JsonResponse
    {
        $setting = $request->settingKey();

        $settings->set($setting, $request->settingValue(), $this->authenticated($request));

        return response()->json(['data' => $this->present($setting, $settings)]);
    }

    /** @return array{key: string, value: int|string, default: int|string, rules: list<string>} */
    private function present(SettingKey $key, Settings $settings): array
    {
        return [
            'key' => $key->value,
            'value' => $settings->get($key),
            'default' => $key->default(),
            'rules' => $key->rules(),
        ];
    }
}
