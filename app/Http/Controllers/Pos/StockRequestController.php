<?php

namespace App\Http\Controllers\Pos;

use App\Exceptions\PosException;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\StockLevel;
use App\Models\StockRequest;
use App\Models\StockRequestItem;
use App\Models\User;
use App\Models\VariantUnit;
use App\Services\Data\RequestLine;
use App\Services\ReorderService;
use App\Services\StockRequestService;
use App\Support\CurrentShop;
use App\Support\Quantity;
use App\Support\TillSelection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock requests from the till (multi_location, docs/PHARMACY.md §9,
 * branch 10): a cashier at the OPD, who has no admin panel, asks General
 * Pharmacy for stock, sees their location's requests and cancels one
 * nothing has been sent for. Sending stock is the owner's, in the admin
 * panel. Every rule is StockRequestService's.
 */
class StockRequestController extends Controller
{
    public function __construct(
        private readonly StockRequestService $requests,
        private readonly CurrentShop $currentShop,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $shop = $this->currentShop->getOrFail();
        $locations = $this->requestingLocations($user);
        $default = (int) $shop->defaultLocation()->getKey();
        $till = TillSelection::remembered($request);
        $tillLocation = $till === null ? null : (int) $till->location_id;

        return Inertia::render('pos/StockRequests', [
            'shop' => ['name' => $shop->name],
            'locations' => $locations->map(fn (Location $location): array => ['id' => (int) $location->getKey(), 'name' => $location->name])->values(),
            'sources' => Location::query()->where('is_active', true)->ordered()->get()
                ->map(fn (Location $location): array => ['id' => (int) $location->getKey(), 'name' => $location->name])->values(),
            'defaultFrom' => $default,
            'defaultFor' => $locations->contains(fn (Location $location): bool => (int) $location->getKey() === $tillLocation)
                ? $tillLocation
                : ($locations->first(fn (Location $location): bool => (int) $location->getKey() !== $default) ?? $locations->first())?->getKey(),
            'requests' => $this->recent($user),
        ]);
    }

    /** Items to ask for, with what the asking and the sending location hold. */
    public function items(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:100'],
            'for' => ['nullable', 'integer'],
            'from' => ['nullable', 'integer'],
        ]);
        $term = trim((string) $data['q']);
        $like = '%'.addcslashes($term, '%_\\').'%';

        $variants = ProductVariant::query()
            ->with(['product', 'units' => fn ($units) => $units->where('is_active', true)->orderByDesc('is_base')->orderBy('sort_order')->orderBy('id')])
            ->whereHas('product', fn (Builder $product) => $product->where('track_stock', true)->where('is_active', true))
            ->where(fn (Builder $query) => $query
                ->where('sku', 'like', $like)
                ->orWhere('barcode', $term)
                ->orWhereHas('units', fn (Builder $units) => $units->where('barcode', $term))
                ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', $like)))
            ->orderBy('sku')
            ->limit(20)
            ->get();

        return response()->json(['items' => $this->present($variants, $data['for'] ?? null, $data['from'] ?? null)]);
    }

    /** Lines up to par for a location's low stock (ReorderService). */
    public function suggest(Request $request, ReorderService $reorder): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['for' => ['required', 'integer'], 'from' => ['nullable', 'integer']]);
        $location = $this->requestingLocations($user)->firstWhere('id', (int) $data['for'])
            ?? throw ValidationException::withMessages(['for' => "You can't request stock for that location."]);

        $lines = $reorder->requestLines($location);
        $items = collect($this->present(
            ProductVariant::query()->with(['product', 'units' => fn ($units) => $units->where('is_active', true)->orderByDesc('is_base')->orderBy('sort_order')->orderBy('id')])
                ->whereKey(array_map(fn (RequestLine $line): int => $line->variantId, $lines))
                ->get(),
            (int) $location->getKey(),
            $data['from'] ?? null,
        ))->keyBy('id');

        return response()->json(['lines' => array_values(array_filter(array_map(fn (RequestLine $line): ?array => $items->has($line->variantId)
            ? ['item' => $items->get($line->variantId), 'unitId' => null, 'quantity' => $line->quantity]
            : null, $lines)))]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'from' => ['required', 'integer'],
            'for' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:'.StockRequestService::MAX_LINES],
            'lines.*.variant_id' => ['required', 'integer'],
            'lines.*.unit_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $stockRequest = $this->requests->create(
                Location::query()->findOrFail((int) $data['from']),
                Location::query()->findOrFail((int) $data['for']),
                array_values(array_map(fn (array $line): RequestLine => new RequestLine(
                    (int) $line['variant_id'],
                    (float) $line['quantity'],
                    isset($line['unit_id']) ? (int) $line['unit_id'] : null,
                ), $data['lines'])),
                $user,
                $data['note'] ?? null,
            );
        } catch (PosException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['request' => ['number' => $stockRequest->request_number]], 201);
    }

    public function cancel(Request $request, StockRequest $stockRequest): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->can('view', $stockRequest), 403);

        try {
            $this->requests->cancel($stockRequest, $user);
        } catch (PosException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @return Collection<int, Location> active locations the user may ask stock for */
    private function requestingLocations(User $user): Collection
    {
        return Location::query()->where('is_active', true)->ordered()->get()
            ->filter(fn (Location $location): bool => $user->mayUseLocation($location))
            ->values();
    }

    /** @return list<array<string, mixed>> the latest requests the user may see */
    private function recent(User $user): array
    {
        $timezone = $this->currentShop->getOrFail()->timezone ?: (string) config('app.timezone');

        return array_values($this->requests->visibleTo(StockRequest::query(), $user)
            ->with(['items.variant.product', 'fromLocation', 'toLocation', 'requester'])
            ->latest('requested_at')
            ->latest('id')
            ->limit(30)
            ->get()
            ->map(fn (StockRequest $stockRequest): array => [
                'uuid' => $stockRequest->uuid,
                'number' => $stockRequest->request_number,
                'status' => $stockRequest->status->value,
                'statusLabel' => $stockRequest->status->label(),
                'requestedAt' => $stockRequest->requested_at->setTimezone($timezone)->format('d/m/Y H:i'),
                'requestedBy' => $stockRequest->requester?->name,
                'for' => $stockRequest->toLocation->name,
                'from' => $stockRequest->fromLocation->name,
                'note' => $stockRequest->note,
                'canCancel' => $this->requests->mayCancel($stockRequest, $user),
                'printUrl' => route('stock.print.request', $stockRequest),
                'items' => $stockRequest->items->map(fn (StockRequestItem $item): array => [
                    'name' => trim($item->variant->product->name.' '.($item->variant->name ?? '')),
                    'quantity' => Quantity::format((float) $item->quantity).' '.$item->unit_name,
                    'sent' => Quantity::format((float) $item->sent_base_quantity),
                    'outstanding' => Quantity::format($item->outstandingBase()),
                    'baseUnit' => (string) $item->variant->product->unit,
                ])->values()->all(),
            ])
            ->all());
    }

    /**
     * @param  EloquentCollection<int, ProductVariant>  $variants
     * @return list<array<string, mixed>>
     */
    private function present(EloquentCollection $variants, mixed $forId, mixed $fromId): array
    {
        $levels = StockLevel::query()
            ->whereIn('product_variant_id', $variants->modelKeys())
            ->whereIn('location_id', array_filter([(int) $forId, (int) $fromId]))
            ->get(['product_variant_id', 'location_id', 'quantity'])
            ->groupBy('product_variant_id');
        $at = fn (ProductVariant $variant, mixed $locationId): float => Quantity::normalize((float) ($levels->get($variant->getKey())?->firstWhere('location_id', (int) $locationId)->quantity ?? 0));

        return array_values($variants->map(fn (ProductVariant $variant): array => [
            'id' => (int) $variant->getKey(),
            'name' => trim($variant->product->name.' '.($variant->name ?? '')),
            'sku' => $variant->sku,
            'baseUnit' => (string) $variant->product->unit,
            'units' => $variant->units->map(fn (VariantUnit $unit): array => [
                'id' => (int) $unit->getKey(),
                'name' => $unit->is_base ? $unit->name : VariantUnit::describe($unit->name, (string) $unit->factor),
                'factor' => (float) $unit->factor,
                'isBase' => (bool) $unit->is_base,
            ])->values()->all(),
            'onHandHere' => $at($variant, $forId),
            'onHandThere' => $at($variant, $fromId),
        ])->all());
    }
}
