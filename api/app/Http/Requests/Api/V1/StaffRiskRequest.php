<?php

namespace App\Http\Requests\Api\V1;

use App\Models\RiskSignal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The anti-cheat reads that take no parameters (signal detail, member summary, product integrity). Staff only, judged
 * before any id is looked up: a non-staff caller gets the one 403 whether the id exists or not. Any query string is ignored.
 */
class StaffRiskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('review', RiskSignal::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
