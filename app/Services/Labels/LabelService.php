<?php

namespace App\Services\Labels;

use App\Enums\GoodsReceiptStatus;
use App\Enums\LabelField;
use App\Enums\LabelKind;
use App\Exceptions\PosException;
use App\Models\GoodsReceipt;
use App\Models\LabelPrint;
use App\Models\LabelQueueItem;
use App\Models\LabelTemplate;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockBatch;
use App\Models\User;
use App\Models\VariantUnit;
use App\Services\BarcodeService;
use App\Services\Data\LabelRequest;
use App\Support\Barcode;
use App\Support\CurrentShop;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Barcode labels (`labels` module, docs/PHARMACY.md §9, branch 7b).
 *
 * Templates: what a label looks like (size, sheet layout, fields), set up by
 * the owner (labels.manage). A shop starts with two (a roll and an A4
 * sheet) the first time labels are used; a template is only saved when the
 * sample labels fit it (LabelLayout).
 *
 * Labels are asked for as LabelRequests (item, unit, copies, batch) from a
 * product, a delivery (GRN), batches or the product list, and either
 * printed at once or collected in the user's print queue and printed in one
 * go (labels.print). Printing gives a pack without a barcode its in-store
 * barcode first (BarcodeService), so every label scans back to its item and
 * pack, then logs the print (label_prints). The labels themselves are laid
 * out when the print is opened (render()), with the item's name and price
 * as they are then.
 */
class LabelService
{
    /** Where labels were asked for, as the log shows it. */
    public const SOURCES = [
        'product' => 'Product',
        'delivery' => 'Delivery',
        'batches' => 'Batches',
        'products' => 'Product list',
        'queue' => 'Print queue',
    ];

    public function __construct(
        private readonly BarcodeService $barcodes,
        private readonly CurrentShop $currentShop,
    ) {}

    // ---------------------------------------------------------------- templates

    /** @return Collection<int, LabelTemplate> the default first */
    public function templates(): Collection
    {
        $this->ensureDefaults();

        return LabelTemplate::query()->orderByDesc('is_default')->orderBy('name')->get();
    }

    /** @return array<int, string> id => "name (size)" */
    public function templateOptions(): array
    {
        return $this->templates()
            ->mapWithKeys(fn (LabelTemplate $template): array => [(int) $template->getKey() => "{$template->name} ({$template->sizeLabel()})"])
            ->all();
    }

    public function defaultTemplate(): LabelTemplate
    {
        return $this->templates()->firstOrFail();
    }

    /**
     * A shop's first templates: a thermal roll (the default) and an A4 sheet.
     * A pharmacy prints medicine labels (38 × 25 mm roll, 5 × 13 sheet); any
     * other shop prints shelf and item labels (50 × 25 mm roll, 4 × 10 sheet).
     * Nothing is created while the module is off.
     */
    public function ensureDefaults(): void
    {
        if (! $this->shop()->hasModule('labels') || LabelTemplate::query()->exists()) {
            return;
        }

        $pharmacy = $this->shop()->hasModule('pharmacy');
        $roll = $pharmacy ? 'roll_38x25' : 'roll_50x25';
        $sheet = $pharmacy ? 'a4_5x13' : 'a4_4x10';

        DB::transaction(function () use ($pharmacy, $roll, $sheet): void {
            LabelTemplate::query()->create([
                ...$this->newTemplateAttributes($roll, $pharmacy),
                'name' => $pharmacy ? 'Medicine label' : 'Item label',
                'is_default' => true,
            ]);
            LabelTemplate::query()->create([
                ...$this->newTemplateAttributes($sheet, $pharmacy),
                'name' => 'A4 sticker sheet',
                'is_default' => false,
            ]);
        });
    }

    /** @return array<string, array<string, mixed>> */
    public static function presets(): array
    {
        return config('pos.labels.presets', []);
    }

    /** @return array<string, string> key => label */
    public static function presetOptions(): array
    {
        return array_map(fn (array $preset): string => (string) $preset['label'], self::presets());
    }

    /**
     * A preset's size and sheet layout as template attributes.
     *
     * @return array<string, mixed>
     */
    public static function presetAttributes(string $preset): array
    {
        $values = self::presets()[$preset] ?? throw new PosException('Unknown label size.');
        $sheet = $values['kind'] === LabelKind::Sheet->value;

        return [
            'preset' => $preset,
            'kind' => $values['kind'],
            'label_width' => (float) $values['label_width'],
            'label_height' => (float) $values['label_height'],
            'columns' => $sheet ? (int) $values['columns'] : 1,
            'rows' => $sheet ? (int) $values['rows'] : 1,
            'page_width' => $sheet ? (float) $values['page_width'] : null,
            'page_height' => $sheet ? (float) $values['page_height'] : null,
            'margin_top' => $sheet ? (float) $values['margin_top'] : 0.0,
            'margin_left' => $sheet ? (float) $values['margin_left'] : 0.0,
            'gap_x' => $sheet ? (float) $values['gap_x'] : 0.0,
            'gap_y' => $sheet ? (float) $values['gap_y'] : 0.0,
            // Sheet labels are small and die-cut exactly: a thinner blank edge.
            'padding' => $sheet ? 1.0 : 1.5,
            // Thermal label printers are mostly 203 dpi; office printers 600.
            'dpi' => $sheet ? 600 : 203,
        ];
    }

    /**
     * What a new template starts with: the preset, the shop's default fields
     * (LabelField::defaults()) and no shop name of its own.
     *
     * @return array<string, mixed>
     */
    public function newTemplateAttributes(string $preset, ?bool $pharmacy = null): array
    {
        return [
            ...self::presetAttributes($preset),
            'fields' => LabelField::defaults($pharmacy ?? $this->shop()->hasModule('pharmacy')),
            'shop_name' => null,
        ];
    }

    /**
     * Save a template (labels.manage). Refused when the labels don't fit
     * (sizes, sheet layout, or the sample labels at this size).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function saveTemplate(?LabelTemplate $template, array $attributes, User $user): LabelTemplate
    {
        $this->assertModule();
        $this->assertCan($user, 'labels.manage', 'Only the owner can set up label templates.');

        $template ??= new LabelTemplate;
        $template->fill($this->normalize($attributes));

        $problems = $this->problems($template);

        if ($problems !== []) {
            throw new PosException(implode(' ', array_unique($problems)));
        }

        return DB::transaction(function () use ($template): LabelTemplate {
            if ($template->is_default) {
                LabelTemplate::query()->whereKeyNot($template->getKey() ?? 0)->where('is_default', true)->update(['is_default' => false]);
            }

            $template->save();

            if (! LabelTemplate::query()->where('is_default', true)->exists()) {
                $template->update(['is_default' => true]);
            }

            return $template;
        });
    }

    /**
     * An unsaved template from the form's values, for the live preview.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function makeTemplate(array $attributes): LabelTemplate
    {
        return new LabelTemplate($this->normalize($attributes));
    }

    /** Delete a template (labels.manage); the last one stays. Past prints keep its name. */
    public function deleteTemplate(LabelTemplate $template, User $user): void
    {
        $this->assertCan($user, 'labels.manage', 'Only the owner can delete label templates.');

        if (LabelTemplate::query()->count() <= 1) {
            throw new PosException('Keep at least one label template.');
        }

        DB::transaction(function () use ($template): void {
            $template->delete();

            if (! LabelTemplate::query()->where('is_default', true)->exists()) {
                LabelTemplate::query()->orderBy('id')->firstOrFail()->update(['is_default' => true]);
            }
        });
    }

    /**
     * What stops a template from printing: sizes out of range, labels off
     * the sheet, or sample labels that don't fit (too much text, a barcode
     * too narrow to scan).
     *
     * @return list<string>
     */
    public function problems(LabelTemplate $template): array
    {
        $problems = [];
        $width = (float) $template->label_width;
        $height = (float) $template->label_height;
        $padding = (float) $template->padding;

        if ($width < 15 || $width > 150 || $height < 10 || $height > 150) {
            $problems[] = 'A label must be 15 to 150 mm wide and 10 to 150 mm high.';
        }

        if ($padding < 0 || $padding > 5 || 2 * $padding >= min($width, $height)) {
            $problems[] = 'The blank edge must be 0 to 5 mm and leave room on the label.';
        }

        if (! in_array($template->dpi, config('pos.labels.dpi', [203, 300, 600]), true)) {
            $problems[] = 'Choose the printer\'s resolution: 203, 300 or 600 dpi.';
        }

        if ($template->isSheet()) {
            $across = (float) $template->margin_left + $template->columns * $width + ($template->columns - 1) * (float) $template->gap_x;
            $down = (float) $template->margin_top + $template->rows * $height + ($template->rows - 1) * (float) $template->gap_y;

            if ($template->columns < 1 || $template->rows < 1 || $template->columns > 20 || $template->rows > 40) {
                $problems[] = 'A sheet has 1 to 20 labels across and 1 to 40 down.';
            } elseif ($across > (float) $template->page_width + 0.01 || $down > (float) $template->page_height + 0.01) {
                $problems[] = sprintf(
                    'The labels don\'t fit on the sheet: they need %s × %s mm with these margins and gaps, the page is %s × %s mm.',
                    self::mm($across), self::mm($down), self::mm((float) $template->page_width), self::mm((float) $template->page_height),
                );
            }
        }

        if ($problems !== []) {
            return $problems;
        }

        $layout = new LabelLayout($template);

        foreach ($this->samples($template) as $sample) {
            array_push($problems, ...$layout->lay($sample)['problems']);
        }

        return array_values(array_unique($problems));
    }

    /**
     * Sample labels for the preview and for checking a template: a long
     * medicine name with every field, or a long spare part, so a template
     * that fits these fits real labels.
     *
     * @return list<LabelData>
     */
    public function samples(LabelTemplate $template): array
    {
        $shop = $this->shop();
        $shopName = $this->shopName($template, $shop);
        $money = fn (int $minor): string => $shop->formatMoney($minor);

        if ($shop->hasModule('pharmacy')) {
            return [
                new LabelData('Co-amoxiclav (Amoxicillin + Clavulanic acid) Film-coated Tablets', Barcode::inStoreEan13(1234, Barcode::PACK_PREFIX), $shopName, '625 mg', 'Box of 30', price: $money(145_000), batchNo: 'AMX2407B', expiry: '03/2027'),
                new LabelData('Paracetamol 500 mg Tablets', Barcode::inStoreEan13(1), $shopName, '500 mg', 'Tablet', price: $money(500), batchNo: 'PCM118', expiry: '11/2027'),
            ];
        }

        return [
            new LabelData('Full Face Helmet Pro Racing Matte Black L', Barcode::inStoreEan13(1234), $shopName, pack: null, sku: 'HLM-FFPR-MB-L', partNumber: 'FF-PR-2291', price: $money(2_450_000)),
            new LabelData('Engine Oil 20W-50', Barcode::inStoreEan13(1235, Barcode::PACK_PREFIX), $shopName, pack: 'Can of 4', sku: 'OIL-2050', partNumber: 'EO-2050', price: $money(900_000)),
        ];
    }

    /**
     * The sample labels laid out on a template (the form's live preview).
     *
     * @return list<array<string, mixed>>
     */
    public function preview(LabelTemplate $template): array
    {
        $layout = new LabelLayout($template);

        return array_map(fn (LabelData $sample): array => $layout->lay($sample), $this->samples($template));
    }

    // ---------------------------------------------------------------- what to print

    /**
     * A posted delivery's labels: each line in the pack it came in, as many
     * as were received (paid + free, rounded up to whole labels), with the
     * line's batch and expiry.
     *
     * @return list<LabelRequest>
     */
    public function forReceipt(GoodsReceipt $receipt): array
    {
        if ($receipt->status !== GoodsReceiptStatus::Posted || $receipt->isReversal()) {
            throw new PosException('Labels are printed for a posted delivery.');
        }

        $requests = [];

        foreach ($receipt->items()->with('unit')->get() as $line) {
            $copies = (int) ceil(Quantity::normalize((float) $line->quantity + (float) $line->free_quantity) - 0.0001);

            if ($copies < 1) {
                continue;
            }

            $requests[] = new LabelRequest(
                variantId: (int) $line->product_variant_id,
                unitId: $line->unit === null || $line->unit->is_base ? null : (int) $line->variant_unit_id,
                quantity: $copies,
                stockBatchId: $line->stock_batch_id,
                batchNo: $line->batch_no,
                expiry: $line->expiry_date?->toDateString(),
            );
        }

        return $requests;
    }

    /**
     * Every active unit of a product's active variants, base unit first: one
     * label of the unit a scan of the item adds (its default sale unit), none
     * of the others until a quantity is typed.
     *
     * @return list<LabelRequest>
     */
    public function forProduct(Product $product): array
    {
        $requests = [];

        foreach ($product->variants()->with('units')->where('is_active', true)->get() as $variant) {
            foreach ($variant->units->where('is_active', true) as $unit) {
                $requests[] = new LabelRequest((int) $variant->getKey(), $unit->is_base ? null : (int) $unit->getKey(), $unit->is_default_sale ? 1 : 0);
            }
        }

        return $requests;
    }

    /**
     * Labels for batches (the Batches screen): in the item's largest pack
     * ($unit 'pack') or its base unit ('base'), one each or one per unit on
     * hand ($perUnitOnHand), with the batch and expiry. The unallocated
     * batch has no batch number to print and is left out.
     *
     * @param  iterable<StockBatch>  $batches
     * @return list<LabelRequest>
     */
    public function forBatches(iterable $batches, string $unit = 'pack', bool $perUnitOnHand = false): array
    {
        $requests = [];

        foreach ($batches as $batch) {
            if ($batch->is_unallocated) {
                continue;
            }

            $pack = $unit === 'pack'
                ? VariantUnit::query()->where('product_variant_id', $batch->product_variant_id)->where('is_base', false)->where('is_active', true)->orderByDesc('factor')->first()
                : null;
            $factor = $pack === null ? 1.0 : (float) $pack->factor;
            $copies = $perUnitOnHand ? (int) floor(Quantity::normalize((float) $batch->quantity / $factor) + 0.0001) : 1;

            if ($copies < 1) {
                continue;
            }

            $requests[] = new LabelRequest(
                variantId: (int) $batch->product_variant_id,
                unitId: $pack === null ? null : (int) $pack->getKey(),
                quantity: $copies,
                stockBatchId: (int) $batch->getKey(),
                batchNo: $batch->batch_no,
                expiry: $batch->expiry_date?->toDateString(),
            );
        }

        return $requests;
    }

    /**
     * Labels for products picked on the product list: each active variant's
     * own label ('items'), each active pack's ('packs') or both ('all'),
     * $copies of each.
     *
     * @param  iterable<Product>  $products
     * @return list<LabelRequest>
     */
    public function forProducts(iterable $products, string $units, int $copies): array
    {
        $requests = [];

        foreach ($products as $product) {
            foreach ($product->variants()->with('units')->where('is_active', true)->get() as $variant) {
                foreach ($variant->units->where('is_active', true) as $unit) {
                    if (($unit->is_base && $units === 'packs') || (! $unit->is_base && $units === 'items')) {
                        continue;
                    }

                    $requests[] = new LabelRequest((int) $variant->getKey(), $unit->is_base ? null : (int) $unit->getKey(), $copies);
                }
            }
        }

        return $requests;
    }

    // ---------------------------------------------------------------- queue and print

    /**
     * Put labels in the user's print queue; the same item, unit and batch
     * already there gets the copies added. Returns the labels added.
     *
     * @param  list<LabelRequest>  $requests
     */
    public function addToQueue(User $user, array $requests, string $source, ?string $sourceLabel = null): int
    {
        $this->assertModule();
        $this->assertCan($user, 'labels.print', 'You are not allowed to print labels.');
        $this->assertSource($source);

        $rows = $this->resolve($requests);
        $max = (int) config('pos.labels.max_copies', 1000);

        return DB::transaction(function () use ($user, $rows, $source, $sourceLabel, $max): int {
            $added = 0;

            foreach ($rows as $row) {
                $request = $row['request'];
                $existing = LabelQueueItem::query()
                    ->where('user_id', $user->getKey())
                    ->where('product_variant_id', $request->variantId)
                    ->where('variant_unit_id', $row['unit']?->getKey())
                    ->where('batch_no', $row['batch_no'])
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    $existing->update(['quantity' => min($max, $existing->quantity + $request->quantity)]);
                } else {
                    LabelQueueItem::query()->create([
                        'user_id' => $user->getKey(),
                        'product_variant_id' => $request->variantId,
                        'variant_unit_id' => $row['unit']?->getKey(),
                        'stock_batch_id' => $row['batch_id'],
                        'batch_no' => $row['batch_no'],
                        'expiry_date' => $row['expiry'],
                        'quantity' => $request->quantity,
                        'source' => $source,
                        'source_label' => $sourceLabel,
                    ]);
                }

                $added += $request->quantity;
            }

            return $added;
        });
    }

    /** Labels waiting in the user's queue. */
    public function queuedLabels(User $user): int
    {
        return (int) LabelQueueItem::query()->where('user_id', $user->getKey())->sum('quantity');
    }

    public function clearQueue(User $user): void
    {
        LabelQueueItem::query()->where('user_id', $user->getKey())->delete();
    }

    /** Print everything in the user's queue in one go, then empty it. */
    public function printQueue(User $user, LabelTemplate $template): LabelPrint
    {
        $items = LabelQueueItem::query()->where('user_id', $user->getKey())->orderBy('id')->get();

        if ($items->isEmpty()) {
            throw new PosException('The print queue is empty.');
        }

        $requests = array_values($items->map(fn (LabelQueueItem $item): LabelRequest => new LabelRequest(
            (int) $item->product_variant_id,
            $item->variant_unit_id,
            $item->quantity,
            $item->stock_batch_id,
            $item->batch_no,
            $item->expiry_date?->toDateString(),
        ))->all());

        $sources = $items->pluck('source_label')->filter()->unique()->values();

        return DB::transaction(function () use ($user, $template, $requests, $sources, $items): LabelPrint {
            $print = $this->print($user, $template, $requests, 'queue', $sources->isEmpty() ? null : mb_substr($sources->implode(', '), 0, 60));

            LabelQueueItem::query()->whereKey($items->modelKeys())->delete();

            return $print;
        });
    }

    /**
     * Print labels now (labels.print): packs without a barcode get their
     * in-store one, then the print is logged. Open it with render().
     *
     * @param  list<LabelRequest>  $requests
     */
    public function print(User $user, LabelTemplate $template, array $requests, string $source, ?string $sourceLabel = null): LabelPrint
    {
        $this->assertModule();
        $this->assertCan($user, 'labels.print', 'You are not allowed to print labels.');
        $this->assertSource($source);

        $rows = $this->resolve($requests);
        $total = array_sum(array_map(fn (array $row): int => $row['request']->quantity, $rows));

        if ($total > (int) config('pos.labels.max_labels_per_print', 2000)) {
            throw new PosException(sprintf('That is %d labels: print at most %d at a time.', $total, (int) config('pos.labels.max_labels_per_print', 2000)));
        }

        return DB::transaction(function () use ($user, $template, $rows, $source, $sourceLabel, $total): LabelPrint {
            $items = [];

            foreach ($rows as $row) {
                $variant = $row['variant'];
                $unit = $row['unit'];
                $barcode = $unit === null ? $this->barcodes->ensureForVariant($variant) : $this->barcodes->ensureForUnit($unit);

                $items[] = [
                    'variant_id' => (int) $variant->getKey(),
                    'unit_id' => $unit === null ? null : (int) $unit->getKey(),
                    'batch_id' => $row['batch_id'],
                    'batch_no' => $row['batch_no'],
                    'expiry' => $row['expiry'],
                    'quantity' => $row['request']->quantity,
                    'name' => $this->itemName($variant),
                    'unit' => $unit === null ? null : VariantUnit::describe($unit->name, $unit->factor),
                    'barcode' => $barcode,
                ];
            }

            return LabelPrint::query()->create([
                'user_id' => $user->getKey(),
                'label_template_id' => $template->getKey(),
                'template_name' => $template->name,
                'source' => $source,
                'source_label' => $sourceLabel === null ? null : mb_substr($sourceLabel, 0, 60),
                'labels_count' => $total,
                'items' => $items,
            ]);
        });
    }

    // ---------------------------------------------------------------- render

    /**
     * A print's labels laid out on its template (or another), in pages: a
     * roll prints one label a page; a sheet columns x rows, starting after
     * $skip labels already used on the first sheet.
     *
     * @return array{template: LabelTemplate, pages: list<list<array<string, mixed>|null>>, labels: int, problems: list<string>}
     */
    public function render(LabelPrint $print, ?LabelTemplate $template = null, int $skip = 0): array
    {
        $template ??= $print->template ?? $this->defaultTemplate();
        $shop = $this->shop();
        $layout = new LabelLayout($template);
        $priceList = $shop->defaultPriceList();

        $variants = ProductVariant::withTrashed()
            ->with(['product.drugDetail', 'units.prices'])
            ->whereKey(array_column($print->items, 'variant_id'))
            ->get()
            ->keyBy('id');

        $labels = [];
        $problems = [];

        foreach ($print->items as $item) {
            $variant = $variants->get($item['variant_id']);

            if ($variant === null) {
                continue;
            }

            $laid = $layout->lay($this->labelData($variant, $item, $template, $shop, $priceList));

            foreach ($laid['problems'] as $problem) {
                $problems[] = $problem;
            }

            for ($i = 0; $i < $item['quantity']; $i++) {
                $labels[] = $laid;
            }
        }

        $count = count($labels);

        if ($template->isSheet()) {
            $perPage = max(1, $template->perPage());
            $cells = [...array_fill(0, max(0, min($skip, $perPage - 1)), null), ...$labels];
            $pages = array_chunk($cells, $perPage);
        } else {
            $pages = array_map(fn (array $label): array => [$label], $labels);
        }

        return ['template' => $template, 'pages' => $pages, 'labels' => $count, 'problems' => array_values(array_unique($problems))];
    }

    /**
     * What one label says: the item's name, strength, pack, codes, the
     * unit's barcode and price (default price list) as they are now, and
     * the batch asked for.
     *
     * @param  array{unit_id: int|null, batch_no?: string|null, expiry?: string|null, barcode?: string|null}  $item
     */
    private function labelData(ProductVariant $variant, array $item, LabelTemplate $template, Shop $shop, PriceList $priceList): LabelData
    {
        $units = $variant->units;
        $unit = $item['unit_id'] === null ? $units->firstWhere('is_base', true) : $units->firstWhere('id', $item['unit_id']);
        $isPack = $unit !== null && ! $unit->is_base;
        $hasPacks = $units->contains(fn (VariantUnit $other): bool => ! $other->is_base && $other->is_active);
        $price = $unit?->priceOn($priceList);
        $expiry = blank($item['expiry'] ?? null) ? null : CarbonImmutable::parse((string) $item['expiry'])->format('m/Y');

        return new LabelData(
            name: $this->itemName($variant),
            barcode: $isPack ? ($unit->barcode ?? $item['barcode'] ?? null) : ($variant->barcode ?? $item['barcode'] ?? null),
            shopName: $this->shopName($template, $shop),
            strength: $variant->product->drugDetail?->strength(),
            pack: $isPack ? VariantUnit::describe($unit->name, $unit->factor) : ($hasPacks ? $unit?->name : null),
            sku: $variant->sku,
            partNumber: $variant->product->part_number,
            price: $price === null ? null : $shop->formatMoney($price),
            batchNo: $item['batch_no'] ?? null,
            expiry: $expiry,
        );
    }

    /** "Full Face Helmet – M / Black"; a single-variant item is its product's name. */
    private function itemName(ProductVariant $variant): string
    {
        return $variant->product->name.(filled($variant->name) ? ' – '.$variant->name : '');
    }

    private function shopName(LabelTemplate $template, Shop $shop): string
    {
        return filled($template->shop_name) ? (string) $template->shop_name : $shop->name;
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The items, units and batches asked for, checked: this shop's, active,
     * and between 1 and max_copies of each. Lines of 0 are left out.
     *
     * @param  list<LabelRequest>  $requests
     * @return list<array{request: LabelRequest, variant: ProductVariant, unit: VariantUnit|null, batch_id: int|null, batch_no: string|null, expiry: string|null}>
     */
    private function resolve(array $requests): array
    {
        $max = (int) config('pos.labels.max_copies', 1000);
        $rows = [];

        foreach ($requests as $request) {
            if ($request->quantity <= 0) {
                continue;
            }

            if ($request->quantity > $max) {
                throw new PosException("Print at most {$max} copies of one label at a time.");
            }

            $variant = ProductVariant::query()->with('product')->find($request->variantId)
                ?? throw new PosException(sprintf(
                    '%s has been deleted: take it off the labels to print.',
                    ProductVariant::withTrashed()->with('product')->find($request->variantId)?->product->name ?? 'An item',
                ));

            $unit = null;

            if ($request->unitId !== null) {
                $unit = VariantUnit::query()
                    ->where('product_variant_id', $variant->getKey())
                    ->where('is_active', true)
                    ->find($request->unitId)
                    ?? throw new PosException("A pack of {$variant->product->name} to label is not active.");

                $unit = $unit->is_base ? null : $unit;
            }

            $batch = $request->stockBatchId === null ? null : StockBatch::query()
                ->where('product_variant_id', $variant->getKey())
                ->find($request->stockBatchId);

            $rows[] = [
                'request' => $request,
                'variant' => $variant,
                'unit' => $unit,
                'batch_id' => $batch?->getKey(),
                'batch_no' => filled($request->batchNo) ? (string) $request->batchNo : ($batch === null || $batch->is_unallocated ? null : $batch->batch_no),
                'expiry' => filled($request->expiry) ? (string) $request->expiry : $batch?->expiry_date?->toDateString(),
            ];
        }

        if ($rows === []) {
            throw new PosException('Choose at least one label to print.');
        }

        return $rows;
    }

    /**
     * Template attributes from the form, cleaned: a roll has one label a
     * page and no sheet layout; fields are the known ones only.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $kind = LabelKind::tryFrom((string) ($data['kind'] ?? '')) ?? LabelKind::Roll;
        $sheet = $kind === LabelKind::Sheet;
        $number = fn (string $key, float $default = 0.0): float => round((float) ($data[$key] ?? $default), 2);
        $fields = [];

        foreach (LabelField::cases() as $field) {
            $fields[$field->value] = (bool) (($data['fields'] ?? [])[$field->value] ?? false);
        }

        $preset = $data['preset'] ?? null;

        return [
            'name' => trim((string) ($data['name'] ?? '')) ?: 'Labels',
            'kind' => $kind,
            'preset' => is_string($preset) && array_key_exists($preset, self::presets()) ? $preset : null,
            'label_width' => $number('label_width'),
            'label_height' => $number('label_height'),
            'columns' => $sheet ? (int) ($data['columns'] ?? 1) : 1,
            'rows' => $sheet ? (int) ($data['rows'] ?? 1) : 1,
            'page_width' => $sheet ? $number('page_width', 210) : null,
            'page_height' => $sheet ? $number('page_height', 297) : null,
            'margin_top' => $sheet ? $number('margin_top') : 0,
            'margin_left' => $sheet ? $number('margin_left') : 0,
            'gap_x' => $sheet ? $number('gap_x') : 0,
            'gap_y' => $sheet ? $number('gap_y') : 0,
            'padding' => $number('padding', 1.5),
            'dpi' => (int) ($data['dpi'] ?? 203),
            'fields' => $fields,
            'shop_name' => filled($data['shop_name'] ?? null) ? mb_substr(trim((string) $data['shop_name']), 0, 40) : null,
            'is_default' => (bool) ($data['is_default'] ?? false),
        ];
    }

    private function assertModule(): void
    {
        if (! $this->shop()->hasModule('labels')) {
            throw new PosException('Label printing is not switched on for this shop.');
        }
    }

    private function assertCan(User $user, string $permission, string $message): void
    {
        if (! $user->can($permission)) {
            throw new PosException($message);
        }
    }

    private function assertSource(string $source): void
    {
        if (! array_key_exists($source, self::SOURCES)) {
            throw new \InvalidArgumentException("Unknown label source {$source}.");
        }
    }

    private function shop(): Shop
    {
        return $this->currentShop->getOrFail();
    }

    private static function mm(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
