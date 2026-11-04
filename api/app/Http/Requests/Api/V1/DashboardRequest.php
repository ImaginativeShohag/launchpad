<?php

namespace App\Http\Requests\Api\V1;

use App\Actions\Dashboard\DashboardAccess;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base of the dashboard reads. authorize() resolves the caller's product (maker, or staff on the staff prefix): a stranger is
 * the uniform 404 and a non-staff caller on a staff route is 403, both BEFORE any parameter is validated. The summary and
 * rank reads take no parameters and ignore any query string and body.
 */
class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $caller = $this->user();

        if (! $caller instanceof User) {
            return false;
        }

        $product = app(DashboardAccess::class)->forRequest($this, $caller, (string) $this->route('product'));
        $this->attributes->set('dashboard_product', $product);

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }

    /** The authorised product (valid once authorize() has passed). */
    public function product(): Product
    {
        $product = $this->attributes->get('dashboard_product');

        if (! $product instanceof Product) {
            throw new \LogicException('The dashboard product is read before authorisation.');
        }

        return $product;
    }
}
