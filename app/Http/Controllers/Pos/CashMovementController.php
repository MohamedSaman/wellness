<?php

namespace App\Http\Controllers\Pos;

use App\Enums\CashMovementType;
use App\Exceptions\PosException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CashMovementsRequest;
use App\Http\Requests\Pos\ReprintCashMovementRequest;
use App\Http\Requests\Pos\StoreCashMovementRequest;
use App\Http\Requests\Pos\VoidCashMovementRequest;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Doctor;
use App\Models\Shift;
use App\Models\User;
use App\Services\CashMovementService;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cash paid out of or into the till's drawer, and doctors paid in cash
 * (cash_movements, docs/PHARMACY.md branch 18). The till lists its own open
 * shift's movements only; each one prints a slip, a reprint is logged and
 * marked REPRINT. Online only.
 */
class CashMovementController extends Controller
{
    /** The till's open shift, its movements, and what this user may record. */
    public function index(CashMovementsRequest $request, CashMovementService $movements): JsonResponse
    {
        $request->validated();

        /** @var User $user */
        $user = $request->user();
        $till = $request->device() ?? abort(422);
        $shift = Shift::query()->where('device_id', $till->getKey())->whereNull('closed_at')->latest('opened_at')->first();

        $payDoctors = $movements->doctorPaymentsEnabled() && $user->can('doctors.pay');

        return response()->json([
            'shift' => $shift === null ? null : ['uuid' => $shift->uuid],
            'movements' => $movements->openShiftMovements($till)->map(fn (CashMovement $movement): array => $movements->present($movement))->values(),
            'types' => $this->types($user, $movements),
            'can_void' => $user->can('cash.movements.void'),
            // Pay doctor (pharmacy, doctors.pay): who may be paid, and for reference only, today's OPD
            // bills and consultation fees (every OPD till) and today's payments to doctors (every till).
            'doctors' => $payDoctors ? Doctor::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])->map(fn (Doctor $doctor): array => ['id' => (int) $doctor->getKey(), 'name' => $doctor->name])->values() : [],
            'reference' => $payDoctors ? $movements->doctorReference() : null,
        ]);
    }

    public function store(StoreCashMovementRequest $request, CashMovementService $movements): JsonResponse
    {
        $request->validated();

        /** @var User $user */
        $user = $request->user();
        $doctor = $request->filled('doctor') ? Doctor::query()->find($request->integer('doctor')) : null;

        if ($request->filled('doctor') && $doctor === null) {
            throw new PosException('That doctor was not found.');
        }

        $movement = $movements->create(
            $request->device() ?? abort(422),
            $user,
            $request->movementType(),
            $request->amount(),
            $request->category(),
            $doctor,
            $request->validated('note'),
        );

        return response()->json([
            'movement' => $movements->present($movement->load(['user', 'doctor'])),
            'slip_url' => route('pos.cash-movements.slip', $movement),
        ], 201);
    }

    public function void(VoidCashMovementRequest $request, CashMovement $cashMovement, CashMovementService $movements): JsonResponse
    {
        $request->validated();

        /** @var User $user */
        $user = $request->user();
        $movement = $movements->void($cashMovement, $user, (string) $request->validated('reason'));

        return response()->json(['movement' => $movements->present($movement->load(['user', 'doctor']))]);
    }

    /** Logs a reprint of the slip; the page it returns prints itself, marked REPRINT. */
    public function reprint(ReprintCashMovementRequest $request, CashMovement $cashMovement, CashMovementService $movements): JsonResponse
    {
        $request->validated();

        /** @var User $user */
        $user = $request->user();
        abort_unless($movements->mayRead($user, $cashMovement), 403);
        $entry = $movements->recordReprint($cashMovement, $user, $request->device());

        return response()->json([
            'slip_url' => route('pos.cash-movements.reprinted', ['cashMovement' => $cashMovement, 'reprint' => $entry->getKey()]),
        ]);
    }

    /** The slip as first printed, when the movement is made: never marked. */
    public function slip(Request $request, CashMovement $cashMovement, CashMovementService $movements): Response
    {
        abort_unless($movements->mayRead($request->user(), $cashMovement), 403);

        return self::slipPage($cashMovement, $movements);
    }

    /** A logged reprint, marked REPRINT: only for the user who reprinted it. */
    public function reprinted(Request $request, CashMovement $cashMovement, int $reprint, CashMovementService $movements): Response
    {
        $entry = $movements->findReprint($cashMovement, $reprint);

        abort_if($entry === null || (int) $entry->user_id !== (int) $request->user()?->getKey(), 404);
        abort_unless($movements->mayRead($request->user(), $cashMovement), 403);

        return self::slipPage($cashMovement, $movements, $entry);
    }

    /** @internal also renders the admin's reprints */
    public static function slipPage(CashMovement $movement, CashMovementService $movements, ?AuditLog $reprint = null): Response
    {
        $receipts = app(ReceiptService::class);

        return Inertia::render('pos/Receipt', [
            'title' => ($reprint === null ? '' : 'Reprint ').$movement->type->label().' '.$movement->number,
            'shop' => $receipts->shop(),
            'sale' => null,
            'thermal' => $receipts->thermal('cash', $movements->slip($movement, $reprint)),
            'invoiceUrl' => null,
        ]);
    }

    /**
     * What this user may record, each with its categories and their limit.
     *
     * @return list<array{value: string, label: string, categories: list<array{value: string, label: string}>, limit: int|null}>
     */
    private function types(User $user, CashMovementService $movements): array
    {
        $types = [];

        foreach (CashMovementType::cases() as $type) {
            $allowed = $type === CashMovementType::DoctorPayment
                ? $movements->doctorPaymentsEnabled() && $user->can('doctors.pay')
                : $movements->enabled() && $user->can('cash.movements');

            if (! $allowed) {
                continue;
            }

            $types[] = [
                'value' => $type->value,
                'label' => $type->label(),
                'categories' => array_map(fn ($category): array => ['value' => $category->value, 'label' => $category->label()], $type->categories()),
                'limit' => $movements->limitFor($user, $type),
            ];
        }

        return $types;
    }
}
