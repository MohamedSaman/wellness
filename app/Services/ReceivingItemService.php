<?php

namespace App\Services;

use App\Enums\TaxCategory;
use App\Exceptions\PosException;
use App\Exceptions\PossibleDuplicateItem;
use App\Models\DrugDetail;
use App\Models\Generic;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VariantUnit;
use App\Observers\AuditObserver;
use App\Services\Data\QuickItemData;
use App\Support\CurrentShop;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Items on purchase order and goods received lines (purchasing module):
 * finding one, and creating one that is not in the catalog yet while the
 * document is being entered (docs/PHARMACY.md §4.9, as built).
 *
 * Who creates: products.manage creates a normal item. Someone who may only
 * order or receive goods (purchasing.receive or purchasing.manage) creates
 * it flagged needs_review: it can be ordered and received, but the till
 * can't find or sell it until someone with products.manage reviews it
 * (and sets its prices). Either way an item created without a price can't
 * be sold yet.
 *
 * Before creating: a barcode already on another item or pack is refused;
 * an item with the same generic names, strength and dosage form, or a very
 * similar name, is a warning the user answers (use the existing item, or
 * create anyway).
 *
 * The creation is audited against its document (created_while_receiving):
 * at once when the document already exists, else when the new document is
 * first saved (link()).
 */
class ReceivingItemService
{
    public const SEARCH_LIMIT = 50;

    /** Names this close (0 to 100) to an existing item's are a possible duplicate. */
    private const SIMILAR_NAME_PERCENT = 85;

    public function __construct(
        private readonly CurrentShop $currentShop,
        private readonly ProductCatalogService $catalog,
        private readonly AuditObserver $audit,
    ) {}

    /**
     * Stocked items matching the search, for an order or GRN line, keyed by
     * variant id; a barcode scanned in finds only its item, and a pack's
     * barcode keys it "variant:unit" so the line also gets the pack.
     * Several words must all match: "amox 500" finds Amoxil 500 mg by its
     * generic and strength. Items waiting for review are included (they can
     * be received), marked as such.
     *
     * @return array<string, string>
     */
    public function search(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $exact = ProductVariant::query()->with('product')->where('barcode', $term)->first();

        if ($exact !== null && $exact->product->track_stock) {
            return [(string) $exact->getKey() => $this->label($exact)];
        }

        $pack = VariantUnit::query()->with('variant.product')->where('barcode', $term)->where('is_active', true)->first();

        if ($pack !== null && $pack->variant !== null && $pack->variant->product->track_stock) {
            return [$pack->product_variant_id.':'.$pack->getKey() => $this->label($pack->variant, $pack)];
        }

        $query = ProductVariant::query()
            ->with('product.drugDetail')
            ->whereHas('product', fn (Builder $product) => $product->where('track_stock', true));

        foreach (array_slice(preg_split('/\s+/', $term) ?: [], 0, 5) as $word) {
            $query->where(fn (Builder $variant) => $variant
                ->where('sku', 'like', "%{$word}%")
                ->orWhereHas('product', fn (Builder $product) => $this->productMatches($product, $word)));
        }

        return $query
            ->orderBy(Product::query()->select('name')->whereColumn('products.id', 'product_variants.product_id'))
            ->orderBy('sku')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->mapWithKeys(fn (ProductVariant $variant): array => [(string) $variant->getKey() => $this->label($variant)])
            ->all();
    }

    /** "PANADOL-500 — Panadol 500 mg Tablets", with the pack and "needs review" when they apply. */
    public function label(ProductVariant $variant, ?VariantUnit $pack = null): string
    {
        return trim("{$variant->sku} — {$variant->product->name} ".($variant->name ?? ''))
            .($pack === null ? '' : ' · '.VariantUnit::describe($pack->name, (string) $pack->factor))
            .($variant->product->needs_review ? ' · needs review' : '');
    }

    /** The item or pack already using a barcode, as "SKU — name", or null when it is free. */
    public function barcodeHolder(?string $barcode): ?string
    {
        $barcode = trim((string) $barcode);

        if ($barcode === '') {
            return null;
        }

        $variant = ProductVariant::withTrashed()->with('product')->where('barcode', $barcode)->first();

        if ($variant !== null) {
            return $this->label($variant);
        }

        $pack = VariantUnit::query()->with('variant.product')->where('barcode', $barcode)->first();

        return $pack?->variant === null ? null : $this->label($pack->variant, $pack);
    }

    /**
     * Items the new one may duplicate: the same generic names, strength and
     * dosage form (a medicine), or a name that differs only in case,
     * spacing, punctuation or a letter or two.
     *
     * @return Collection<int, ProductVariant>
     */
    public function possibleDuplicates(QuickItemData $data): Collection
    {
        $productIds = $this->similarNames($data->name);

        $drug = $data->drug;

        if ($drug !== null && $drug->strengthValue !== null) {
            $generics = $this->genericKey($drug->genericNames);
            $unit = Str::lower(trim((string) $drug->strengthUnit));

            $sameDrug = DrugDetail::query()
                ->with('product.generics')
                ->where('dosage_form', $drug->dosageForm)
                ->where('strength_value', $drug->strengthValue)
                ->get()
                ->filter(fn (DrugDetail $detail): bool => Str::lower(trim((string) $detail->strength_unit)) === $unit
                    && $detail->product !== null
                    && $this->genericKey($detail->product->generics->map(fn (Generic $generic): string => $generic->name)->values()->all()) === $generics)
                ->pluck('product_id')
                ->all();

            $productIds = [...$productIds, ...$sameDrug];
        }

        return ProductVariant::query()
            ->with('product')
            ->whereIn('product_id', array_unique($productIds))
            ->orderBy('id')
            ->get()
            ->unique('product_id')
            ->take(5)
            ->values();
    }

    /**
     * Create an item from an order or GRN line. Refused for a barcode already
     * in use; a possible duplicate is refused unless $createAnyway.
     *
     * @param  GoodsReceipt|PurchaseOrder|null  $document  the document being edited; null while it is new (link() it when saved)
     */
    public function create(QuickItemData $data, User $user, ?Model $document = null, bool $createAnyway = false): ProductVariant
    {
        $manages = $user->can('products.manage');

        if (! $manages && ! $user->can('purchasing.receive') && ! $user->can('purchasing.manage')) {
            throw new PosException('You do not have permission to create items.');
        }

        if (! $this->currentShop->getOrFail()->hasModule('purchasing')) {
            throw new PosException('Creating items while receiving needs the purchasing module.');
        }

        $name = trim($data->name);

        if ($name === '') {
            throw new PosException('Give the new item a name.');
        }

        foreach ([$data->barcode, ...array_map(fn (array $pack): ?string => $pack['barcode'] ?? null, $data->packs)] as $barcode) {
            $holder = $this->barcodeHolder($barcode);

            if ($holder !== null) {
                throw new PosException('The barcode '.trim((string) $barcode)." is already on {$holder}: pick that item instead.");
            }
        }

        if (! $createAnyway) {
            $matches = $this->possibleDuplicates($data);

            if ($matches->isNotEmpty()) {
                throw new PossibleDuplicateItem($matches);
            }
        }

        $product = array_filter([
            'name' => $name,
            'unit' => trim($data->unit) === '' ? 'pcs' : trim($data->unit),
            'category_id' => $data->categoryId,
            'brand_id' => $data->brandId,
            'tax_category' => $data->taxCategory,
        ], fn (mixed $value): bool => $value !== null) + [
            'needs_review' => ! $manages,
            'created_while_receiving' => true,
        ];

        // Prices and cost only from someone who may set them; the rest waits for the review.
        $prices = $manages && $user->can('prices.manage');
        $packs = array_map(fn (array $pack): array => [
            'name' => $pack['name'],
            'factor' => $pack['factor'],
            'barcode' => $pack['barcode'] ?? null,
            ...($prices && isset($pack['price']) ? ['price' => $pack['price']] : []),
        ], $data->packs);

        $variant = [
            'barcode' => blank($data->barcode) ? null : trim((string) $data->barcode),
            'price' => $prices ? $data->price : null,
            'allow_unit_sale' => $data->allowUnitSale,
            'packs' => $packs,
        ];

        if ($data->cost !== null && $user->can('cost.view')) {
            $variant['cost'] = $data->cost;
        }

        return DB::transaction(function () use ($product, $variant, $manages, $user, $data, $document): ProductVariant {
            $created = $this->catalog->create($product, [$variant], user: $manages ? $user : null, drug: $data->drug);
            /** @var ProductVariant $item */
            $item = $created->variants()->with('product')->firstOrFail();

            if ($document !== null) {
                $this->link($document, [(int) $item->getKey()], $user);
            }

            return $item;
        });
    }

    /**
     * Record which order or GRN the items created while it was being entered
     * belong to, once it is saved: each such item not yet linked gets the
     * document and a created_while_receiving audit entry naming it.
     *
     * @param  GoodsReceipt|PurchaseOrder  $document
     * @param  iterable<int>  $variantIds  the document's items
     */
    public function link(Model $document, iterable $variantIds, ?User $user = null): void
    {
        $ids = collect($variantIds)->map(fn ($id): int => (int) $id)->unique()->values()->all();

        if ($ids === []) {
            return;
        }

        $products = Product::query()
            ->where('created_while_receiving', true)
            ->whereNull('created_on_id')
            ->whereIn('id', ProductVariant::query()->select('product_id')->whereKey($ids))
            ->get();

        foreach ($products as $product) {
            // A plain update: linking is not an edit of the item (and must not touch it for the tills).
            Product::query()->whereKey($product->getKey())->toBase()->update([
                'created_on_type' => $document->getMorphClass(),
                'created_on_id' => $document->getKey(),
            ]);

            $this->audit->record('created_while_receiving', $product, [], [
                'document' => $this->documentLabel($document),
                'document_uuid' => $document->getAttribute('uuid'),
                'needs_review' => $product->needs_review,
            ], $user?->getKey());
        }
    }

    /**
     * An admin has checked an item created while receiving (and set its
     * prices): the till can find and sell it from now on.
     */
    public function markReviewed(Product $product, User $user): Product
    {
        if (! $user->can('products.manage')) {
            throw new PosException('You do not have permission to review items.');
        }

        if (! $product->needs_review) {
            throw new PosException("{$product->name} has already been reviewed.");
        }

        $product->update(['needs_review' => false, 'reviewed_at' => now(), 'reviewed_by' => $user->getKey()]);

        return $product;
    }

    /** "goods received note GRN-000003", "purchase order PO-000002", or a draft not yet numbered. */
    public function documentLabel(Model $document): string
    {
        return match (true) {
            $document instanceof GoodsReceipt => 'goods received note '.($document->grn_number ?? 'draft'),
            $document instanceof PurchaseOrder => 'purchase order '.$document->po_number,
            default => class_basename($document).' #'.$document->getKey(),
        };
    }

    /** A word of the search against the item's name, generic names, brand name or strength ("500", "500mg"). */
    /** @param  Builder<Product>  $product */
    private function productMatches(Builder $product, string $word): void
    {
        $product->where(function (Builder $query) use ($word): void {
            $query->where('name', 'like', "%{$word}%")
                ->orWhereHas('generics', fn (Builder $generic) => $generic->where('generics.name', 'like', "%{$word}%"))
                ->orWhereHas('drugDetail', function (Builder $detail) use ($word): void {
                    $detail->where('brand_name', 'like', "%{$word}%");

                    if (preg_match('/^(\d+(?:\.\d+)?)\s*([a-z%\/]*)$/i', $word, $strength) === 1) {
                        $detail->orWhere(fn (Builder $q) => $q
                            ->where('strength_value', Quantity::normalize($strength[1]))
                            ->when($strength[2] !== '', fn (Builder $unit) => $unit->where('strength_unit', 'like', "{$strength[2]}%")));
                    }
                });
        });
    }

    /**
     * Products whose name is the same as this one once case, spacing and
     * punctuation are ignored, or within a letter or two of it.
     *
     * @return list<int>
     */
    private function similarNames(string $name): array
    {
        $normal = $this->normalName($name);
        $words = array_values(array_filter(explode(' ', $normal), fn (string $word): bool => Str::length($word) >= 3));

        if ($words === []) {
            return [];
        }

        return array_values(Product::query()
            ->where('name', 'like', "%{$words[0]}%")
            ->limit(500)
            ->get(['id', 'name'])
            ->filter(function (Product $product) use ($normal): bool {
                $other = $this->normalName($product->name);

                if ($other === $normal) {
                    return true;
                }

                similar_text($normal, $other, $percent);

                return $percent >= self::SIMILAR_NAME_PERCENT && levenshtein($normal, $other) <= max(2, intdiv(Str::length($normal), 10));
            })
            ->map(fn (Product $product): int => (int) $product->getKey())
            ->all());
    }

    private function normalName(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower($name))));
    }

    /** @param  array<int, string>  $names  in any order: the key is sorted */
    private function genericKey(array $names): string
    {
        $names = array_map(fn (string $name): string => $this->normalName($name), $names);
        sort($names);

        return implode('+', $names);
    }

    /** Medicines default to exempt, anything else to standard (as in the product form). */
    public static function defaultTaxCategory(bool $medicine): TaxCategory
    {
        return $medicine ? TaxCategory::Exempt : TaxCategory::Standard;
    }
}
