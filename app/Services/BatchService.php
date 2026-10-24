<?php

namespace App\Services;

use App\Enums\BatchStatus;
use App\Exceptions\PosException;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Models\StockBatch;
use App\Models\StockLevel;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Data\BatchInput;
use App\Support\BatchExpiry;
use App\Support\CurrentShop;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The rules around batches and expiry (docs/PHARMACY.md §4.5, §6): what a
 * batch number and expiry must look like when stock comes in, which
 * batches FEFO may sell on a given day, and the alert when an unallocated
 * batch goes below zero. Stock itself only changes in StockService.
 */
class BatchService
{
    /** A printed expiry further out than this is a typing mistake (docs/PHARMACY.md §11). */
    public const MAX_EXPIRY_YEARS = 10;

    public function __construct(private readonly CurrentShop $currentShop) {}

    /** Today in the shop's own timezone: expiry is a calendar date at the shop. */
    public function today(): CarbonImmutable
    {
        return $this->dayOf(now());
    }

    /** The shop-local calendar day of a moment (a sale's sold_at). */
    public function dayOf(\DateTimeInterface $moment): CarbonImmutable
    {
        $shop = $this->currentShop->getOrFail();

        return CarbonImmutable::parse(CarbonImmutable::instance($moment)->setTimezone($this->timezone($shop))->format('Y-m-d'))->startOfDay();
    }

    /**
     * Days before expiry an item stops being sold: the medicine's own setting,
     * else the shop's min_days_to_expiry_for_sale (default 0: sellable up to
     * and including its expiry day). Pass it to StockBatch::sellableOn().
     */
    public function minDaysToExpiry(Product|ProductVariant $item): int
    {
        $product = $item instanceof ProductVariant ? $item->product : $item;
        $own = $product->drugDetail?->min_days_to_expiry;

        return max(0, (int) ($own ?? $this->currentShop->getOrFail()->setting('min_days_to_expiry_for_sale', 0)));
    }

    /**
     * The earliest expiry FEFO may sell on $saleDay: a batch is sellable when
     * it expires on or after this date.
     */
    public function saleCutoff(Product $product, CarbonInterface $saleDay): CarbonImmutable
    {
        return CarbonImmutable::parse(StockBatch::sellableCutoff($saleDay, $this->minDaysToExpiry($product)));
    }

    /**
     * Stock expiring within this many days is "short expiry": receiving it
     * needs the owner's go-ahead, and the Batches list shows it in amber.
     * Shop setting near_expiry_warning_days (default in config/pos.php: 180).
     */
    public function nearExpiryWarningDays(): int
    {
        return max(0, (int) $this->currentShop->getOrFail()->setting('near_expiry_warning_days'));
    }

    /** Batch numbers are compared and stored trimmed and in capitals ("b-12 " is B-12). */
    public static function normalizeBatchNo(string $batchNo): string
    {
        return Str::upper(Str::squish($batchNo));
    }

    /**
     * Checks a batch typed for new stock and returns its stored form.
     *
     * - The batch number is required (50 characters at most).
     * - For a receipt (Opening, Stock In, later goods received): the expiry
     *   must be after today and at most MAX_EXPIRY_YEARS away; one within
     *   near_expiry_warning_days needs acceptNearExpiry, and a user who may
     *   do that (batches.manage; no user = the system, e.g. an import).
     * - For a stock count ($receipt false) any real date is accepted: expired
     *   stock found on the shelf is recorded, so it can be written off.
     * - A batch number of this item already recorded with another expiry is
     *   a typing mistake on one of them, so it is refused.
     *
     * @return array{batch_no: string, expiry_date: string}
     *
     * @throws PosException
     */
    public function validateNew(ProductVariant $variant, BatchInput $input, ?User $user, bool $receipt = true): array
    {
        $batchNo = self::normalizeBatchNo($input->batchNo);

        if ($batchNo === '') {
            throw new PosException("{$variant->sku} tracks batches: enter the batch number printed on the pack.");
        }

        if (Str::length($batchNo) > 50) {
            throw new PosException('A batch number can have at most 50 characters.');
        }

        $expiry = BatchExpiry::parse($input->expiry);
        $today = $this->today();
        $shown = $expiry->format('d/m/Y');

        if ($expiry->greaterThan($today->addYears(self::MAX_EXPIRY_YEARS))) {
            throw new PosException("Batch {$batchNo} expires on {$shown}, more than ".self::MAX_EXPIRY_YEARS.' years away. Check the date on the pack.');
        }

        if ($receipt) {
            if ($expiry->lessThanOrEqualTo($today)) {
                throw new PosException("Batch {$batchNo} expired on {$shown}. Expired stock can't be received.");
            }

            $warningDays = $this->nearExpiryWarningDays();

            if ($expiry->lessThanOrEqualTo($today->addDays($warningDays))) {
                if (! $input->acceptNearExpiry) {
                    throw new PosException("Batch {$batchNo} expires on {$shown}, within {$warningDays} days. Tick \"Accept short expiry\" to receive it anyway (owner only).");
                }

                if ($user !== null && ! $user->can('batches.manage')) {
                    throw new PosException("Batch {$batchNo} expires on {$shown}, within {$warningDays} days. Only the owner can accept stock that close to expiry.");
                }
            }
        }

        $this->assertOneExpiry($variant, $batchNo, $expiry);

        return ['batch_no' => $batchNo, 'expiry_date' => $expiry->format('Y-m-d')];
    }

    /**
     * Correct a batch's number or expiry (a typo when it was received). Owner
     * only (batches.manage), with a reason. The same manufacturer batch at
     * every location (same number and expiry) is corrected together, so it
     * keeps one number and one expiry. Refused when a corrected row would
     * clash with another batch of the item at its location (that would merge
     * two batches' stock: count them instead), or when the new number is
     * already recorded with another expiry. Quantities and the ledger are
     * untouched; sale lines and transfers keep the number and expiry they
     * were made with. Each corrected row gets a `batch_corrected` audit entry.
     *
     * @return Collection<int, StockBatch> the corrected rows
     *
     * @throws PosException
     */
    public function correct(StockBatch $batch, string $batchNo, CarbonInterface|string $expiry, string $reason, User $user): Collection
    {
        if (! $user->can('batches.manage')) {
            throw new PosException('You do not have permission to correct batches.');
        }

        if (blank($reason)) {
            throw new PosException('Say why the batch is being corrected.');
        }

        if ($batch->is_unallocated) {
            throw new PosException('Unallocated stock has no batch number to correct.');
        }

        $batchNo = self::normalizeBatchNo($batchNo);

        if ($batchNo === '' || Str::length($batchNo) > 50) {
            throw new PosException('Enter the batch number printed on the pack (at most 50 characters).');
        }

        $newExpiry = BatchExpiry::parse($expiry);

        if ($newExpiry->greaterThan($this->today()->addYears(self::MAX_EXPIRY_YEARS))) {
            throw new PosException("Batch {$batchNo} would expire on {$newExpiry->format('d/m/Y')}, more than ".self::MAX_EXPIRY_YEARS.' years away. Check the date on the pack.');
        }

        $oldNo = $batch->batch_no;
        $oldExpiry = (string) $batch->expiry_date?->toDateString();

        if ($batchNo === $oldNo && $newExpiry->toDateString() === $oldExpiry) {
            throw new PosException('That is already the batch number and expiry.');
        }

        return DB::transaction(function () use ($batch, $batchNo, $newExpiry, $oldNo, $oldExpiry, $reason, $user): Collection {
            $sameBatch = fn () => StockBatch::query()
                ->where('product_variant_id', $batch->product_variant_id)
                ->where('is_unallocated', false)
                ->where('batch_no', $oldNo)
                ->whereDate('expiry_date', $oldExpiry);

            // Lock order as everywhere: stock levels by (location, variant), then their batches.
            $locationIds = $sameBatch()->orderBy('location_id')->pluck('location_id');

            foreach ($locationIds as $locationId) {
                StockLevel::query()->where('location_id', $locationId)->where('product_variant_id', $batch->product_variant_id)->lockForUpdate()->first();
            }

            $rows = $sameBatch()->with(['location', 'variant'])->orderBy('location_id')->lockForUpdate()->get();

            foreach ($rows as $row) {
                $clash = StockBatch::query()
                    ->where('product_variant_id', $row->product_variant_id)
                    ->where('location_id', $row->location_id)
                    ->where('is_unallocated', false)
                    ->whereKeyNot($rows->modelKeys())
                    ->where('batch_no', $batchNo)
                    ->whereDate('expiry_date', $newExpiry->toDateString())
                    ->exists();

                if ($clash) {
                    throw new PosException("{$row->location->name} already has batch {$batchNo} expiring {$newExpiry->format('m/Y')} of {$row->variant->sku}. Correcting would merge two batches: count them instead.");
                }
            }

            $otherExpiry = StockBatch::query()
                ->where('product_variant_id', $batch->product_variant_id)
                ->where('is_unallocated', false)
                ->whereKeyNot($rows->modelKeys())
                ->where('batch_no', $batchNo)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '!=', $newExpiry->toDateString())
                ->first();

            if ($otherExpiry !== null) {
                throw new PosException("Batch {$batchNo} is already recorded with expiry {$otherExpiry->expiry_date?->format('d/m/Y')}. A batch has one expiry.");
            }

            foreach ($rows as $row) {
                $row->update(['batch_no' => $batchNo, 'expiry_date' => $newExpiry->toDateString()]);

                app(AuditObserver::class)->record('batch_corrected', $row,
                    ['batch_no' => $oldNo, 'expiry_date' => $oldExpiry, 'location' => $row->location->code],
                    ['batch_no' => $batchNo, 'expiry_date' => $newExpiry->toDateString(), 'location' => $row->location->code, 'reason' => trim($reason)],
                    (int) $user->getKey(),
                );
            }

            return $rows;
        });
    }

    /**
     * A manufacturer batch has one expiry: the same number with another
     * expiry, at any location, is refused.
     *
     * @throws PosException
     */
    public function assertOneExpiry(ProductVariant $variant, string $batchNo, CarbonInterface $expiry): void
    {
        $other = StockBatch::query()
            ->where('product_variant_id', $variant->getKey())
            ->where('batch_no', $batchNo)
            ->where('is_unallocated', false)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '!=', $expiry->format('Y-m-d'))
            ->first();

        if ($other !== null) {
            throw new PosException("Batch {$batchNo} of {$variant->sku} is already recorded with expiry {$other->expiry_date?->format('d/m/Y')}. Check the pack: one of the two dates is wrong.");
        }
    }

    /** Why a picked batch id is not among a location's batches with stock (a refused pick). */
    public function notInStockMessage(int $batchId, int $variantId, int $locationId): string
    {
        $batch = StockBatch::query()->find($batchId);

        if ($batch === null || (int) $batch->product_variant_id !== $variantId) {
            return "Batch #{$batchId} is not a batch of this item.";
        }

        if ($batch->is_unallocated) {
            return 'The unallocated batch cannot be picked.';
        }

        $location = Location::query()->whereKey($locationId)->value('name');

        return "Batch {$batch->label()} has no stock at {$location}.";
    }

    /** Why a picked batch with stock may not be sold on $day (a refused pick). */
    public function notSellableMessage(StockBatch $batch, CarbonInterface $day, int $minDays): string
    {
        return match (true) {
            $batch->status !== BatchStatus::Active => "Batch {$batch->label()} is {$batch->status->value} and cannot be sold.",
            $batch->isExpiredOn($day) => "Batch {$batch->label()} had expired.",
            default => "Batch {$batch->label()} is within {$minDays} days of expiry and cannot be sold.",
        };
    }

    /**
     * Why stock at a location can't be sold on a day: quantities held in
     * expired batches, batches too close to expiry, and quarantined or
     * recalled ones. Shown when a sale is refused for lack of stock.
     *
     * @param  Collection<int, StockBatch>  $batches  the location's batches of the item
     * @return array{expired: float, near_expiry: float, blocked: float}
     */
    public function heldBack(Collection $batches, CarbonInterface $saleDay, CarbonInterface $cutoff): array
    {
        $held = ['expired' => 0.0, 'near_expiry' => 0.0, 'blocked' => 0.0];

        foreach ($batches as $batch) {
            if ($batch->is_unallocated || (float) $batch->quantity <= 0) {
                continue;
            }

            $key = match (true) {
                $batch->status !== BatchStatus::Active => 'blocked',
                $batch->isExpiredOn($saleDay) => 'expired',
                $batch->expiry_date !== null && $batch->expiry_date->toDateString() < $cutoff->format('Y-m-d') => 'near_expiry',
                default => null,
            };

            if ($key !== null) {
                $held[$key] = Quantity::normalize($held[$key] + (float) $batch->quantity);
            }
        }

        return $held;
    }

    /**
     * Unallocated batches that are not at zero: stock sold beyond the
     * recorded batches, waiting for the next receipt to settle it.
     *
     * @return Collection<int, StockBatch>
     */
    public function unallocatedStock(?Location $location = null): Collection
    {
        return StockBatch::query()
            ->with(['variant.product', 'location'])
            ->where('is_unallocated', true)
            ->where('quantity', '!=', 0)
            ->when($location !== null, fn ($query) => $query->where('location_id', $location?->getKey()))
            ->orderBy('quantity')
            ->get();
    }

    /**
     * An unallocated batch just went below zero: tell everyone who manages
     * batches (a database notification in the admin panel), once the
     * transaction that did it has committed. The dashboard widget keeps
     * showing it until it is settled.
     */
    public function alertUnallocated(StockBatch $batch): void
    {
        $shop = $this->currentShop->getOrFail();
        $batchId = (int) $batch->getKey();

        DB::afterCommit(function () use ($shop, $batchId): void {
            $this->currentShop->runAs($shop, function () use ($shop, $batchId): void {
                $batch = StockBatch::query()->with(['variant.product', 'location'])->find($batchId);

                if ($batch === null || (float) $batch->quantity >= 0) {
                    return;
                }

                $recipients = User::query()
                    ->where('shop_id', $shop->getKey())
                    ->where('is_active', true)
                    ->get()
                    ->filter(fn (User $user): bool => $user->can('batches.manage'));

                if ($recipients->isEmpty()) {
                    return;
                }

                $item = trim($batch->variant->product->name.' '.($batch->variant->name ?? ''));

                Notification::make()
                    ->title('Stock sold beyond its batches')
                    ->body(sprintf(
                        '%s (%s) at %s is %s below its recorded batches. The next delivery settles it; check for stock that was not booked in.',
                        $item,
                        $batch->variant->sku,
                        $batch->location->name,
                        Quantity::format(abs((float) $batch->quantity)).' '.$batch->variant->product->unit,
                    ))
                    ->warning()
                    ->sendToDatabase($recipients);
            });
        });
    }

    private function timezone(Shop $shop): string
    {
        return (string) ($shop->timezone ?: config('app.timezone'));
    }
}
