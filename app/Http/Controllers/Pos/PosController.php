<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Shift;
use App\Models\Shop;
use App\Models\User;
use App\Services\ItemSearchService;
use App\Support\CurrentShop;
use App\Support\ShiftPresenter;
use App\Support\TillSelection;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosController extends Controller
{
    public function index(Request $request, CurrentShop $currentShop, ItemSearchService $items): Response
    {
        /** @var User $user */
        $user = $request->user();
        $shop = $currentShop->getOrFail();
        $till = TillSelection::remembered($request);

        // A till is fixed to its location: a cashier limited to some locations
        // sees only their tills, and a remembered till elsewhere is forgotten.
        if ($till !== null && ! $user->mayUseLocation((int) $till->location_id)) {
            TillSelection::forget();
            $till = null;
        }

        $tills = Device::query()
            ->where('is_active', true)
            ->with('location:id,code,name')
            ->orderBy('code')
            ->get(['id', 'uuid', 'code', 'name', 'location_id'])
            ->filter(fn (Device $device): bool => $user->mayUseLocation((int) $device->location_id))
            ->values();
        $openShifts = Shift::query()
            ->with('user')
            ->whereNull('closed_at')
            ->whereIn('device_id', $tills->modelKeys())
            ->get()
            ->keyBy('device_id');

        $shift = $till === null ? null : $openShifts->get($till->getKey());

        $pharmacy = $shop->hasModule('pharmacy');
        $till?->loadMissing('location:id,code,name');

        $currency = (string) $shop->currency;
        $quickCash = config("pos.quick_cash.{$currency}") ?? config('pos.quick_cash.default');

        return Inertia::render('pos/Index', [
            'shop' => [
                'name' => $shop->name,
                'currency' => $currency,
                'decimals' => (int) $shop->currency_decimals,
                'requireOpenShift' => (bool) $shop->setting('require_open_shift'),
                'quickCash' => $quickCash,
            ],
            'cashier' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'canAdmin' => $user->canAccessPanel(Filament::getPanel('admin')),
                'unlimitedDiscount' => $user->can('pos.discount.unlimited'),
                // No Discount button for a cashier who may give none (decided 2026-10-22);
                // CheckoutService still enforces the limit.
                'canDiscount' => $user->can('pos.discount.unlimited') || (float) $shop->setting('max_cashier_discount_percent') > 0,
                'canOverridePrice' => $user->can('pos.price_override'),
                'canBatchOverride' => $user->can('pos.batch_override'),
                'canOpdBill' => $user->can('pos.opd_bill'),
                // Stock requests from the till (multi_location): the OPD asks General Pharmacy.
                // Only at a till away from the default location, where stock is received and
                // which requests are sent to (decided 2026-10-22: DOC's till, not GEN's).
                'canRequestStock' => $shop->hasModule('multi_location') && $user->can('stock.request')
                    && $till !== null && (int) $till->location_id !== (int) $shop->defaultLocation()->getKey(),
            ],
            'features' => [
                'vehicleFitment' => $shop->hasModule('vehicle_fitment'),
                'pharmacy' => $pharmacy,
            ],
            'offline' => [
                'enabled' => $shop->hasModule('offline_sync'),
                'canRegister' => $user->can('tills.manage'),
            ],
            'tills' => $tills->map(fn (Device $device): array => [
                'uuid' => $device->uuid,
                'code' => $device->code,
                'name' => $device->name,
                'shiftOpen' => $openShifts->has($device->getKey()),
                'location' => $device->location?->name,
            ])->values(),
            'till' => $till === null ? null : [
                'uuid' => $till->uuid,
                'code' => $till->code,
                'name' => $till->name,
                'location' => $till->location === null ? null : [
                    'id' => (int) $till->location->getKey(),
                    'code' => $till->location->code,
                    'name' => $till->location->name,
                ],
                'opdBilling' => (bool) $till->opd_billing,
            ],
            'shift' => $shift === null ? null : ShiftPresenter::present($shift),
            'pharmacy' => $pharmacy ? $this->pharmacy($shop, $till, $items) : null,
        ]);
    }

    /**
     * Pharmacy checkout settings for the till (docs/PHARMACY.md §9, branch 8),
     * with the consultation item as this till sells it (null when unset or
     * not for sale here).
     *
     * @return array<string, mixed>
     */
    private function pharmacy(Shop $shop, ?Device $till, ItemSearchService $items): array
    {
        $consultation = $shop->setting('opd_consultation_variant_id');

        return [
            'rxPrescriber' => (string) $shop->setting('rx_prescriber', 'prompt'),
            'defaultPrescriber' => $shop->setting('default_prescriber'),
            'controlledRegister' => (bool) $shop->setting('controlled_register'),
            'bankTransferReferenceRequired' => (bool) $shop->setting('bank_transfer_reference_required'),
            'opdReceiptMedicines' => (string) $shop->setting('opd_receipt_medicines', 'names'),
            'opdBelowListWarningPercent' => max(0, min(100, (int) $shop->setting('opd_below_list_warning_percent', 100))),
            'opdConsultation' => $till === null || blank($consultation) ? null : $items->item($till, (int) $consultation),
        ];
    }
}
