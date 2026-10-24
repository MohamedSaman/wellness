<?php

namespace App\Http\Controllers\Pos\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Sync\SyncSalesRequest;
use App\Services\OfflineSaleSyncService;
use Illuminate\Http\JsonResponse;

class SaleSyncController extends Controller
{
    /** Upload sales made offline. Safe to repeat: each sale's uuid is recorded once. */
    public function __invoke(SyncSalesRequest $request, OfflineSaleSyncService $sync): JsonResponse
    {
        return response()->json(['results' => $sync->sync($request->device(), $request->sales(), $request->deviceTime())]);
    }
}
