<?php

use App\Http\Requests\Api\V1\CursorListRequest;
use App\Http\Requests\Api\V1\UserProductsRequest;
use Illuminate\Validation\ValidationException;

/** A list with its own page sizes, as the comment lists will have (20 / 50). */
class CommentProbeSmallPageRequest extends CursorListRequest
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function authorize(): bool
    {
        return true;
    }

    protected function cursorShape(): array
    {
        return ['created_at' => 'date', 'id' => 'ulid'];
    }
}

function commentProbeRequest(string $class, array $query): CursorListRequest
{
    $request = $class::create('/x', 'GET', $query);
    $request->setContainer(app())->setRedirector(app('redirect'));
    $request->validateResolved();

    return $request;
}

it('lets a subclass override the default and the maximum page size', function () {
    // Given a list that overrides 20 / 50
    // When a request without per_page and one with 50 are validated
    $default = commentProbeRequest(CommentProbeSmallPageRequest::class, []);
    $max = commentProbeRequest(CommentProbeSmallPageRequest::class, ['per_page' => '50']);

    // Then the default is 20 and 50 is allowed
    expect($default->perPage())->toBe(20)->and($max->perPage())->toBe(50);

    // And 51 is refused
    expect(fn () => commentProbeRequest(CommentProbeSmallPageRequest::class, ['per_page' => '51']))->toThrow(ValidationException::class);
});

it('keeps the existing lists at 25 by default and 100 at most', function () {
    // Given a list that does not override anything
    // When a request without per_page, one with 100 and one with 101 are validated
    $default = commentProbeRequest(UserProductsRequest::class, []);
    $max = commentProbeRequest(UserProductsRequest::class, ['per_page' => '100']);

    // Then the default is 25 and 100 is allowed
    expect($default->perPage())->toBe(25)->and($max->perPage())->toBe(100);

    // And 101 is refused
    expect(fn () => commentProbeRequest(UserProductsRequest::class, ['per_page' => '101']))->toThrow(ValidationException::class);
});
