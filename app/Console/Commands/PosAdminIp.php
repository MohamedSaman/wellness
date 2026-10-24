<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\PicksShop;
use App\Http\Middleware\AdminIpAllowlist;
use Illuminate\Console\Command;

/**
 * The admin panel's address allowlist (Administration → Security) from the
 * server, for an owner who locked themselves out:
 *   php artisan pos:admin-ip               show it
 *   php artisan pos:admin-ip --add=1.2.3.4 add an address or range
 *   php artisan pos:admin-ip --clear       allow anywhere again
 */
class PosAdminIp extends Command
{
    use PicksShop;

    protected $signature = 'pos:admin-ip {--add= : An address or range to allow} {--clear : Remove every address (the admin panel opens from anywhere)} {--shop= : Shop id or code}';

    protected $description = "Show or change the admin panel's IP allowlist";

    public function handle(): int
    {
        $shop = $this->pickShop();

        if ($shop === null) {
            $this->components->error('Shop not found. Pass --shop=<id or code>.');

            return self::FAILURE;
        }

        $list = AdminIpAllowlist::normalize($shop->setting('admin_ip_allowlist'));
        $add = $this->option('add');

        if ($this->option('clear')) {
            $list = [];
        } elseif (is_string($add)) {
            if (! AdminIpAllowlist::isValid(trim($add))) {
                $this->components->error("[{$add}] is not an IP address or range.");

                return self::FAILURE;
            }

            $list = AdminIpAllowlist::normalize([...$list, $add]);
        }

        if ($this->option('clear') || is_string($add)) {
            $shop->update(['settings' => [...($shop->settings ?? []), 'admin_ip_allowlist' => $list]]);
        }

        $list === []
            ? $this->components->info("{$shop->name}: the admin panel opens from any address.")
            : $this->components->info("{$shop->name}: the admin panel opens only from ".implode(', ', $list).'.');

        return self::SUCCESS;
    }
}
