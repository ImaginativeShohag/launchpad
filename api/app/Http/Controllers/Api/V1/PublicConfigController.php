<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;

class PublicConfigController extends Controller
{
    /**
     * The three product limits the forms show. Public and the same for everyone (no viewer, no cookie-dependent body), so a
     * short shared cache is allowed. Nothing else belongs here: every key added is world readable.
     */
    public function __invoke(Settings $settings): JsonResponse
    {
        return response()->json(['data' => [
            'max_topics' => (int) $settings->get(SettingKey::ProductMaxTopics),
            'max_screenshots' => (int) $settings->get(SettingKey::ProductMaxScreenshots),
            'max_launches_per_day' => (int) $settings->get(SettingKey::LaunchMaxPerMakerPerDay),
        ]])->header('Cache-Control', 'private, max-age=60')->header('Vary', 'Origin');
    }
}
