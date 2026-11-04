<?php

namespace App\Policies;

use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ProductPolicy
{
    /** What the maker may change through PATCH while the product is in a status (spec section 5). */
    public const ALL_FIELDS = ['name', 'slug', 'tagline', 'description', 'website_url', 'links', 'topic_ids', 'screenshots'];

    public const LIVE_FIELDS = ['description', 'website_url', 'links'];

    /** @return list<string> */
    public static function editableFields(ProductStatus $status): array
    {
        return match ($status) {
            ProductStatus::Draft, ProductStatus::ChangesRequested => self::ALL_FIELDS,
            ProductStatus::Live => self::LIVE_FIELDS,
            default => [],
        };
    }

    /** The logo and screenshots change only while everything else may. */
    public static function mediaEditable(ProductStatus $status): bool
    {
        return $status === ProductStatus::Draft || $status === ProductStatus::ChangesRequested;
    }

    /**
     * Every maker action. Anyone else, staff included, sees a missing product: hidden and missing look the same.
     * (Reviewers and admins do not edit founders' products.)
     */
    public function manage(User $user, Product $product): Response
    {
        return $product->maker_id === $user->getKey() ? Response::allow() : Response::denyAsNotFound();
    }

    /** Class-level: reviewers and admins decide pending products. */
    public function review(User $user): bool
    {
        return $user->role->isStaff();
    }

    /** Staff may open any product that has left the draft stage. Drafts are the founder's private work (D5). */
    public function viewStaff(User $user, Product $product): Response
    {
        // Not-staff look the same as a missing product: route binding runs before authorisation, so a 403 here
        // would tell a member which ids exist (drafts included).
        if (! $user->role->isStaff()) {
            return Response::denyAsNotFound();
        }

        return $product->status === ProductStatus::Draft ? Response::denyAsNotFound() : Response::allow();
    }

    /** Class-level: take-down and restore are admin only. */
    public function takeDown(User $user): bool
    {
        return $user->role === Role::Admin;
    }

    public function restore(User $user): bool
    {
        return $user->role === Role::Admin;
    }
}
