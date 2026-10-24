<?php

namespace App\Filament\Support;

use App\Models\Location;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VariantUnit;
use App\Services\ReceivingItemService;
use App\Support\CurrentShop;
use App\Support\Money;
use App\Support\Quantity;
use App\Support\UnitCost;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Livewire\Component as Livewire;

/**
 * Fields and helpers shared by the purchasing screens (orders, goods
 * received, supplier returns): the supplier, location, item and unit
 * pickers, money typed in the shop's currency, expiry as printed, and the
 * live line totals. Every rule is enforced again by the services.
 */
class PurchasingForm
{
    public static function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function multiLocation(): bool
    {
        return app(CurrentShop::class)->getOrFail()->hasModule('multi_location');
    }

    /** The location chosen, or (one-location shops, where the field is hidden) the user's work location. */
    public static function location(mixed $id): Location
    {
        if (filled($id)) {
            return Location::query()->findOrFail((int) $id);
        }

        $shop = app(CurrentShop::class)->getOrFail();

        return self::user()?->workLocation($shop) ?? $shop->defaultLocation();
    }

    public static function supplierField(): Select
    {
        return Select::make('supplier_id')
            ->label('Supplier')
            // Active suppliers, plus the one already on the record (it may have been deactivated since).
            ->options(fn (mixed $state): array => Supplier::options() + (filled($state) ? Supplier::query()->whereKey((int) $state)->pluck('name', 'id')->all() : []))
            ->searchable()
            ->required();
    }

    /**
     * Where new orders and goods received notes start: the shop's default
     * location when it receives centrally (shop setting
     * receive_at_default_location, on for pharmacies), else the user's.
     */
    public static function receivingLocation(): Location
    {
        $shop = app(CurrentShop::class)->getOrFail();

        return $shop->setting('receive_at_default_location') ? $shop->defaultLocation() : self::location(null);
    }

    /** @param  bool  $receiving  orders and goods received start at the receiving location */
    public static function locationField(string $label = 'Location', bool $receiving = false): Select
    {
        return Select::make('location_id')
            ->label($label)
            ->options(fn (): array => Location::options())
            ->default(fn (): int => (int) ($receiving ? self::receivingLocation() : self::location(null))->getKey())
            ->required(fn (): bool => self::multiLocation())
            ->visible(fn (): bool => self::multiLocation())
            ->native(false);
    }

    /**
     * The item of a line: found by name, generic, brand, strength, SKU or
     * any unit's barcode. A scanned pack barcode picks the item and that
     * pack (its option is keyed "variant:unit"; the unit is kept in
     * picked_unit_id for resetLine()). With $creates, an item that is not
     * in the catalog can be created from the line (QuickItemForm).
     *
     * @param  bool  $creates  orders and goods received: offer "Create new item"
     */
    public static function itemField(bool $creates = false): Select
    {
        $field = Select::make('variant_id')
            ->label('Item')
            ->required()
            ->searchable()
            ->getSearchResultsUsing(function (string $search): array {
                session()->put(QuickItemForm::SEARCH_SESSION_KEY, $search);

                return app(ReceivingItemService::class)->search($search);
            })
            ->getOptionLabelUsing(fn ($value): ?string => ($variant = ProductVariant::query()->with('product')->find((int) $value)) === null ? null : app(ReceivingItemService::class)->label($variant))
            ->noSearchResultsMessage($creates ? 'No item matches. Use "Create new item" to add it.' : 'No item matches.')
            ->live()
            ->afterStateUpdated(function (mixed $state, Set $set): void {
                if (is_string($state) && str_contains($state, ':')) {
                    [$variantId, $unitId] = array_map(intval(...), explode(':', $state, 2));
                    $set('variant_id', $variantId);
                    $set('picked_unit_id', $unitId);
                }
            });

        if (! $creates) {
            return $field;
        }

        return $field
            ->createOptionForm(fn (): array => QuickItemForm::schema())
            ->createOptionUsing(fn (array $data, Livewire $livewire): string => QuickItemForm::save($data, $livewire))
            ->createOptionAction(fn (Action $action): Action => $action
                ->label('Create new item')
                ->button()
                ->modalHeading('New item')
                ->modalDescription(fn (): ?string => self::user()?->can('products.manage') ? null : 'It can be ordered and received at once; an admin reviews it and sets its prices before it can be sold.')
                ->modalSubmitActionLabel('Create and use on this line')
                ->modalWidth('4xl')
                ->fillForm(fn (): array => QuickItemForm::prefill())
                ->visible(fn (): bool => (bool) self::user()?->canAny(['products.manage', 'purchasing.receive', 'purchasing.manage'])));
    }

    /** Where a scanned pack barcode's unit waits for resetLine(); never saved. */
    public static function pickedUnitField(): Hidden
    {
        return Hidden::make('picked_unit_id')->dehydrated(false);
    }

    /** The units an item is bought in (orders, goods received), or any active unit (returns). */
    public static function unitField(bool $bought = true): Select
    {
        return Select::make('unit_id')
            ->label('Unit')
            ->options(fn (Get $get): array => self::unitOptions($get('variant_id'), $bought))
            ->placeholder('Base unit')
            ->live();
    }

    /** @return array<int, string> */
    public static function unitOptions(mixed $variantId, bool $bought = true): array
    {
        if (! filled($variantId)) {
            return [];
        }

        return VariantUnit::query()
            ->where('product_variant_id', (int) $variantId)
            ->where('is_active', true)
            ->when($bought, fn ($query) => $query->where('is_purchasable', true))
            ->orderByDesc('is_base')->orderBy('sort_order')->orderBy('id')
            ->get()
            ->mapWithKeys(fn (VariantUnit $unit): array => [(int) $unit->getKey() => $unit->is_base ? $unit->name : VariantUnit::describe($unit->name, (string) $unit->factor)])
            ->all();
    }

    /**
     * Money in the shop's currency: a decimal text input, not a number input,
     * so no spinner takes room from the amount next to the currency prefix.
     */
    public static function moneyField(string $name, string $label, bool $allowNegative = false): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->inputMode('decimal')
            ->rules(['numeric', ...($allowNegative ? [] : ['min:0'])])
            ->prefix(fn (): string => ShopMoney::currency());
    }

    /**
     * The grid of a repeater line: stacked on small screens, 12 columns from
     * lg. Laid out (span()) in two or three rows up to 2xl and wider rows
     * above, so a long item name, "Capsule", "1000" and "12,500.00" all show
     * at laptop widths with the sidebar open.
     *
     * @return array<string, int>
     */
    public static function lineGrid(): array
    {
        return ['default' => 1, 'lg' => 12];
    }

    /**
     * A field's share of a line: $lg of 12 columns on laptops, $wide from 2xl.
     *
     * @return array<string, int>
     */
    public static function span(int $lg, int $wide): array
    {
        return ['default' => 1, 'lg' => $lg, '2xl' => $wide];
    }

    /** A typed amount ("1,250.50") in minor units; blank is 0. */
    public static function toMinor(mixed $value): int
    {
        return filled($value) ? Money::toMinor((string) $value, ShopMoney::decimals()) : 0;
    }

    public static function toInput(int $minor): string
    {
        return Money::toInputString($minor, ShopMoney::decimals());
    }

    /** An expiry as printed: "03/2027" for the last day of a month, else the full date. */
    public static function expiryInput(?CarbonInterface $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return $date->isLastOfMonth() ? $date->format('m/Y') : $date->format('d/m/Y');
    }

    public static function factor(mixed $variantId, mixed $unitId): float
    {
        if (! filled($variantId)) {
            return 1.0;
        }

        $unit = filled($unitId)
            ? VariantUnit::query()->where('product_variant_id', (int) $variantId)->find((int) $unitId)
            : VariantUnit::query()->where('product_variant_id', (int) $variantId)->where('is_base', true)->first();

        return (float) ($unit->factor ?? 1);
    }

    public static function baseUnitName(mixed $variantId): string
    {
        return filled($variantId) ? (string) (ProductVariant::query()->with('product')->find((int) $variantId)->product->unit ?? 'unit') : 'unit';
    }

    /**
     * "Line LKR 855.00 · LKR 7.13 a Tablet (free goods included)" for a
     * goods received line (its own discount and tax, before the invoice's);
     * or for an order line just the line total.
     */
    public static function lineHint(Get $get, bool $receipt): ?string
    {
        $quantity = (float) ($get('quantity') ?: 0);

        if ($quantity <= 0 && (float) ($get('free_quantity') ?: 0) <= 0) {
            return null;
        }

        $total = Money::multiply(self::toMinor($get('unit_cost')), $quantity) - ($receipt ? self::toMinor($get('discount')) - self::toMinor($get('tax')) : 0);

        $hint = 'Line '.ShopMoney::format($total);

        if (! $receipt) {
            return $hint;
        }

        $base = Quantity::normalize(($quantity + (float) ($get('free_quantity') ?: 0)) * self::factor($get('variant_id'), $get('unit_id')));

        if ($base <= 0) {
            return $hint;
        }

        return $hint.' · '.ShopMoney::format(UnitCost::toMinor(UnitCost::perUnit($total, $base))).' a '.self::baseUnitName($get('variant_id'))
            .((float) ($get('free_quantity') ?: 0) > 0 ? ' (free goods included)' : '');
    }

    /**
     * Fill in the unit cost from the item's last purchase cost, scaled to the
     * unit chosen (a box of 10 at 9.00 a tablet is 90.00), unless one is typed.
     */
    public static function suggestCost(Get $get, Set $set): void
    {
        if (filled($get('unit_cost')) || ! filled($get('variant_id'))) {
            return;
        }

        $variant = ProductVariant::query()->find((int) $get('variant_id'));
        $lastCost = (int) ($variant->last_cost_price ?? $variant->cost_price ?? 0);

        if ($lastCost > 0) {
            $set('unit_cost', self::toInput((int) round($lastCost * self::factor($get('variant_id'), $get('unit_id')))));
        }
    }

    /** When the item changes, its unit and batch fields start again (with the pack scanned, if any). */
    public static function resetLine(Set $set, ?Get $get = null): void
    {
        $set('unit_id', $get !== null && filled($get('picked_unit_id')) ? (int) $get('picked_unit_id') : null);
        $set('picked_unit_id', null);
        $set('batch_id', null);
    }
}
