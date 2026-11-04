<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\MemberDirectory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminUserIndexRequest;
use App\Http\Resources\Api\V1\AdminMemberResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MemberDirectoryController extends Controller
{
    public function __invoke(AdminUserIndexRequest $request, MemberDirectory $directory): AnonymousResourceCollection
    {
        $page = $directory->page($request->search(), $request->role(), $request->status(), $request->perPage())
            ->appends(array_filter([
                'q' => $request->validated('q'), 'role' => $request->validated('role'),
                'status' => $request->validated('status'), 'per_page' => $request->validated('per_page'),
            ], fn ($value) => $value !== null));

        return AdminMemberResource::collection($page);
    }
}
