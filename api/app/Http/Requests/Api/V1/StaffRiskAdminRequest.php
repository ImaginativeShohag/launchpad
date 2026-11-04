<?php

namespace App\Http\Requests\Api\V1;

use App\Models\RiskSignal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The anti-cheat writes only admins may do (void votes, suspend). The role is judged before any id is looked up and before
 * anything is validated, so a reviewer or member gets the one 403 whatever the id or the body.
 */
class StaffRiskAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('intervene', RiskSignal::class);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
