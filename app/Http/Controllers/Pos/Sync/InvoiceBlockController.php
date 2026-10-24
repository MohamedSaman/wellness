<?php

namespace App\Http\Controllers\Pos\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\Sync\ReserveInvoiceBlockRequest;
use App\Services\OfflineSaleSyncService;
use Illuminate\Http\JsonResponse;

class InvoiceBlockController extends Controller
{
    /** Reserve invoice numbers the till can use while offline. */
    public function __invoke(ReserveInvoiceBlockRequest $request, OfflineSaleSyncService $sync): JsonResponse
    {
        $device = $request->device();
        $block = $sync->reserveInvoiceBlock($device);

        return response()->json([
            'block' => [
                'code' => $device->code,
                'first' => $block->first_number,
                'last' => $block->last_number,
            ],
        ], 201);
    }
}
