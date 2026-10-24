<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PicksShop;
use App\Models\User;
use App\Services\Backup\BackupException;
use App\Services\Backup\BackupService;
use App\Services\GoLiveResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Go-live morning (docs/deploy/GO-LIVE.md): removes the rehearsal's sales,
 * returns, shifts, their stock movements and the invoice numbering, keeping
 * products, suppliers, users, settings and templates; --include-stock also
 * removes all stock and supplier documents, for a fresh opening stock and
 * supplier invoice import. An owner signs it off with their password, then
 * types the shop's code and RESET; a backup is made first, and nothing is
 * removed if it fails. Interactive only.
 */
class PosGoLiveReset extends Command
{
    use PicksShop;

    protected $signature = 'pos:go-live-reset
        {--shop= : Shop id or code}
        {--include-stock : Also remove all stock and supplier documents (opening stock, goods received, orders, transfers, counts, payments, balances)}
        {--force : Reset even if a till says it still holds offline sales}';

    protected $description = 'Remove test transactions before go-live, keeping products, suppliers, users and settings';

    public function handle(GoLiveResetService $reset, BackupService $backups): int
    {
        if (! $this->input->isInteractive()) {
            $this->components->error('The go-live reset only runs interactively: an owner must confirm it.');

            return self::FAILURE;
        }

        $shop = $this->pickShop();

        if ($shop === null) {
            $this->components->error('Shop not found. Pass --shop=<id or code>.');

            return self::FAILURE;
        }

        $stock = (bool) $this->option('include-stock');
        $preview = $reset->preview($shop, $stock);

        $this->components->warn("Go-live reset of {$shop->name} ({$shop->code}). This removes for good:");
        $this->table(['Table', 'Rows'], array_map(fn (string $table, int $count): array => [$table, $count], array_keys($preview['counts']), $preview['counts']));
        $this->components->info('Kept: products, units, prices, barcodes, suppliers, users, locations, tills, settings, label and import templates.'
            .($stock ? '' : ' Stock received stays (only what the test sales and returns moved is undone); add --include-stock to remove it too.'));

        if ($preview['waiting_tills'] !== [] && ! $this->option('force')) {
            $this->components->error('Till(s) '.implode(', ', $preview['waiting_tills']).' still hold offline sales. Bring them online so they upload (System health shows 0), or reset with --force to lose them.');

            return self::FAILURE;
        }

        $email = text('Owner email');
        $secret = password('Owner password');
        $owner = User::query()->where('shop_id', $shop->getKey())->where('email', $email)->where('is_active', true)->first();

        if ($owner === null || ! Hash::check($secret, $owner->password) || ! $owner->hasRole('owner')) {
            $this->components->error('Not reset: that is not an active owner of this shop, or the password is wrong.');

            return self::FAILURE;
        }

        if (text("Type the shop code ({$shop->code}) to continue") !== $shop->code
            || text('Type RESET to remove the test transactions now') !== 'RESET') {
            $this->components->error('Not reset: not confirmed.');

            return self::FAILURE;
        }

        try {
            $this->components->info('Backup '.$backups->create('pre-go-live').' made first.');
        } catch (BackupException $exception) {
            $this->components->error('Not reset: the backup failed ('.$exception->getMessage().').');

            return self::FAILURE;
        }

        $removed = $reset->reset($shop, $owner, $stock);

        $this->table(['Table', 'Removed'], array_map(fn (string $table, int $count): array => [$table, $count], array_keys($removed), $removed));
        $this->components->info("{$shop->name} is ready for go-live. On each till: clear the browser's data for this site, sign in, and register the till for offline sales again (its old token is revoked).");

        return self::SUCCESS;
    }
}
