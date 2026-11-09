<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Services\PatientService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PatientSearchController extends Controller
{
    /**
     * The till's patient search (docs/PHARMACY.md, branch 13): name and age
     * only, never the notes. Offline the till searches its own copy.
     */
    public function __invoke(Request $request, PatientService $patients): JsonResponse
    {
        $input = $request->validate(['q' => ['required', 'string', 'max:100']]);

        return response()->json(['patients' => $patients->search($input['q'])]);
    }
}
