<?php

namespace App\Http\Controllers\Pos\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Sync\CatalogSyncRequest;
use App\Services\CatalogSyncService;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    /** The till's offline catalog: everything, or only what changed since its last sync. */
    public function __invoke(CatalogSyncRequest $request, CatalogSyncService $catalog): JsonResponse
    {
        $request->validated();

        return response()->json($catalog->changes($request->device(), $request->since(), $request->afterId()));
    }
}
