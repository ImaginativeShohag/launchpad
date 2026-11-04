<?php

namespace App\Http\Requests\Api\V1;

use App\Actions\AntiCheat\StaffProductLookup;
use App\Enums\VoteStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * The votes of one product, for staff. authorize() judges the role (403, identical for any id), then looks the product up
 * (404 for a missing, hidden or draft one), all BEFORE any parameter is validated. Reads the query string only.
 */
class ProductVotesRequest extends CursorListRequest
{
    public function authorize(): bool
    {
        $caller = $this->user();

        if (! $caller instanceof User) {
            return false;
        }

        $this->attributes->set('staff_product', app(StaffProductLookup::class)->find($caller, (string) $this->route('product')));

        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return $this->query();
    }

    protected function cursorShape(): array
    {
        return ['voted_at' => 'date', 'id' => 'ulid'];
    }

    protected function extraRules(): array
    {
        return [
            'status' => ['sometimes', 'bail', 'string', Rule::in(VoteStatus::values())],
            'user' => ['sometimes', 'bail', 'string', 'ulid'],
        ];
    }

    /** The authorised product (valid once authorize() has passed). */
    public function product(): Product
    {
        $product = $this->attributes->get('staff_product');

        if (! $product instanceof Product) {
            throw new \LogicException('The product is read before authorisation.');
        }

        return $product;
    }

    public function status(): ?string
    {
        $status = $this->validated('status');

        return is_string($status) ? $status : null;
    }

    public function voter(): ?string
    {
        $user = $this->validated('user');

        return is_string($user) ? $user : null;
    }
}
