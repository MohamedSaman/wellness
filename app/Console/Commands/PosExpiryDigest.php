<?php

namespace App\Console\Commands;

use App\Filament\Resources\ExpiringStock\ExpiringStockResource;
use App\Models\Shop;
use App\Models\User;
use App\Notifications\ExpiryDigest;
use App\Services\ExpiryService;
use App\Support\CurrentShop;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The daily expiry digest (docs/PHARMACY.md §9, branch 10): for every shop
 * with the pharmacy module, once its own clock has passed 07:00, a database
 * notification to each active user who may write stock off
 * (stock.write_off, the owner) with what has expired and what expires
 * within the warning window, per location, valued at batch cost. Nothing
 * to report: nothing is sent. Sent at most once per person per shop day,
 * so the scheduler runs it every hour (routes/console.php) and a server
 * that was off at 07:00 catches up. The server must run the scheduler
 * (`* * * * * php artisan schedule:run`, docs/SETUP-GUIDE.md).
 */
class PosExpiryDigest extends Command
{
    public const SEND_FROM_HOUR = 7;

    protected $signature = 'pos:expiry-digest
        {--shop= : Only this shop (its code)}
        {--now : Send now, whatever the time at the shop}';

    protected $description = 'Send the daily expiry digest (expired and expiring stock) to the owners of pharmacy shops';

    public function handle(CurrentShop $currentShop, ExpiryService $expiry): int
    {
        $shops = Shop::query()
            ->when(filled($this->option('shop')), fn ($query) => $query->where('code', (string) $this->option('shop')))
            ->get();

        foreach ($shops as $shop) {
            $currentShop->runAs($shop, function () use ($shop, $expiry): void {
                if (! $shop->hasModule('pharmacy')) {
                    return;
                }

                $local = CarbonImmutable::now($shop->timezone ?: (string) config('app.timezone'));

                if (! $this->option('now') && $local->hour < self::SEND_FROM_HOUR) {
                    return;
                }

                $summary = $expiry->summary();

                if ($summary['expired']['batches'] === 0 && $summary['soon']['batches'] === 0) {
                    $this->components->info("{$shop->name}: nothing expired or expiring.");

                    return;
                }

                $sent = 0;

                foreach ($this->recipients($shop) as $user) {
                    if ($this->alreadySentToday($user, $local)) {
                        continue;
                    }

                    $user->notify(new ExpiryDigest(
                        $summary,
                        fn (int $minor): string => $shop->formatMoney($minor),
                        ExpiringStockResource::getUrl('index', ['tab' => ExpiryService::EXPIRED], panel: 'admin'),
                        ExpiringStockResource::getUrl('index', ['tab' => ExpiryService::SOON], panel: 'admin'),
                    ));
                    $sent++;
                }

                $this->components->info("{$shop->name}: expiry digest sent to {$sent} user(s).");
            });
        }

        return self::SUCCESS;
    }

    /** @return iterable<User> */
    private function recipients(Shop $shop): iterable
    {
        return User::query()
            ->where('shop_id', $shop->getKey())
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => $user->can('stock.write_off'));
    }

    /** One digest per person per shop day ($local is now in the shop's timezone). */
    private function alreadySentToday(User $user, CarbonImmutable $local): bool
    {
        return DatabaseNotification::query()
            ->where('type', ExpiryDigest::class)
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where('created_at', '>=', $local->startOfDay()->utc())
            ->exists();
    }
}
