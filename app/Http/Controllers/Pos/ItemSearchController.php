<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\SearchItemsRequest;
use App\Services\ItemSearchService;
use Illuminate\Http\JsonResponse;

class ItemSearchController extends Controller
{
    public function __invoke(SearchItemsRequest $request, ItemSearchService $search): JsonResponse
    {
        $input = $request->validated();

        return response()->json($search->search(
            $request->device() ?? abort(422),
            $input['q'] ?? null,
            isset($input['make_id']) ? (int) $input['make_id'] : null,
            isset($input['model_id']) ? (int) $input['model_id'] : null,
            isset($input['year']) ? (int) $input['year'] : null,
        ));
    }
}
