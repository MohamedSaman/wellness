<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\VehicleMake;
use Illuminate\Http\JsonResponse;

class VehicleController extends Controller
{
    /** Makes with their models, for the "find parts for vehicle" filter. */
    public function __invoke(): JsonResponse
    {
        $makes = VehicleMake::query()
            ->with(['models' => fn ($q) => $q->select(['id', 'vehicle_make_id', 'name', 'year_from', 'year_to'])->orderBy('name')])
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['makes' => $makes]);
    }
}
