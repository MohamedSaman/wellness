<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\RegisterTillRequest;
use App\Http\Requests\Pos\SelectTillRequest;
use App\Support\TillSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class TillController extends Controller
{
    public function store(SelectTillRequest $request): RedirectResponse
    {
        $request->validated();

        TillSelection::remember($request->device() ?? abort(422));

        return to_route('pos.index');
    }

    /**
     * Give this browser a device token for the till, so it can keep selling
     * offline and sync later. The plain token is returned once and kept only
     * in the browser; the owner can revoke it in the admin panel.
     */
    public function register(RegisterTillRequest $request): JsonResponse
    {
        $request->validated();

        $device = $request->device() ?? abort(422);
        $agent = Str::limit((string) $request->userAgent(), 150);
        $token = $device->issueSyncToken("{$request->user()?->name} · {$agent}");

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
