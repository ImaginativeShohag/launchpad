<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Export\DownloadExport;
use App\Actions\Export\ReadExports;
use App\Actions\Export\RequestExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExportRequest;
use App\Http\Resources\Api\V1\ExportResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The maker's exports of one product. Ids are plain strings on purpose: the actions authorise before they look anything up. */
class ExportController extends Controller
{
    /** 202 for a new export, 200 when one was already in flight. */
    public function store(ExportRequest $request, RequestExport $export): JsonResponse
    {
        $result = $export->handle($this->actor($request), $request->productId());

        return ExportResource::make($result['export'])->response()->setStatusCode($result['created'] ? 202 : 200);
    }

    public function index(ExportRequest $request, ReadExports $read): AnonymousResourceCollection
    {
        return ExportResource::collection($read->latest($this->actor($request), $request->productId()));
    }

    public function show(ExportRequest $request, ReadExports $read): JsonResponse
    {
        $export = $read->one($this->actor($request), $request->productId(), $request->exportId());
        $response = ExportResource::make($export)->response();

        if ($export->status->isInFlight()) {
            $response->header('Retry-After', '5');
        }

        return $response;
    }

    /** Streams the zip of a ready export. 404 for anything that is not the caller's, 409 not finished, 410 expired. */
    public function download(ExportRequest $request, DownloadExport $download): StreamedResponse
    {
        $file = $download->handle($this->actor($request), $request->productId(), $request->exportId());

        $stream = $file['stream'];

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, $file['filename'], ['Content-Type' => 'application/zip']);
    }

    private function actor(ExportRequest $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new \LogicException('The export routes sit behind auth:sanctum.');
        }

        return $user;
    }
}
