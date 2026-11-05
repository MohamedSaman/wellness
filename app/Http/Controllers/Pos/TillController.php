<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\PrepareTillRequest;
use App\Http\Requests\Pos\RegisterTillRequest;
use App\Http\Requests\Pos\SelectTillRequest;
use App\Models\Device;
use App\Services\TillOfflineService;
use App\Support\TillSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Sanctum\NewAccessToken;

class TillController extends Controller
{
    public function store(SelectTillRequest $request): RedirectResponse
    {
        $request->validated();

        TillSelection::remember($request->device() ?? abort(422));

        return to_route('pos.index');
    }

    /**
     * The owner, at the till: approve it for offline sales and give this
     * browser its device token, so it can keep selling offline and sync later.
     * The plain token is returned once and kept only in the browser; the owner
     * can revoke it in the admin panel.
     */
    public function register(RegisterTillRequest $request, TillOfflineService $tills): JsonResponse
    {
        $request->validated();

        $device = $request->device() ?? abort(422);

        return $this->tokenResponse($device, $tills->register($device, $request->user() ?? abort(403), $request->userAgent()));
    }

    /**
     * The till preparing itself in the background, with whoever is signed in:
     * only a till the owner approved, and only for its one browser
     * (TillOfflineService::claim()).
     */
    public function prepare(PrepareTillRequest $request, TillOfflineService $tills): JsonResponse
    {
        $request->validated();

        $device = $request->device() ?? abort(422);

        return $this->tokenResponse($device, $tills->claim($device, $request->user() ?? abort(403), $request->userAgent()));
    }

    private function tokenResponse(Device $device, NewAccessToken $token): JsonResponse
    {
        return response()->json([
            'token' => $token->plainTextToken,
            'till' => ['uuid' => $device->uuid, 'code' => $device->code],
        ], 201);
    }

    public function destroy(): RedirectResponse
    {
        TillSelection::forget();

        return to_route('pos.index');
    }
}
