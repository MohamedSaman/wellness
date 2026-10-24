<?php

namespace App\Console\Commands;

use App\Exceptions\PosException;
use App\Models\Shop;
use App\Services\ModuleService;
use Illuminate\Console\Command;

/**
 * Switch hidden modules on/off per shop:
 *   php artisan pos:module list --shop=1
 *   php artisan pos:module enable purchasing --shop=1
 *   php artisan pos:module disable purchasing --shop=1
 *
 * Enabling a module also enables the modules it needs (pharmacy needs
 * suppliers, purchasing and multi_location); disabling one that another
 * enabled module needs is refused. See config('pos.module_requires').
 */
class PosModule extends Command
{
    protected $signature = 'pos:module {action : list|enable|disable} {module? : Module key from config/pos.php} {--shop= : Shop id or code}';

    protected $description = 'List, enable or disable optional modules for a shop';

    public function handle(ModuleService $modules): int
    {
        $shop = $this->resolveShop();

        if ($shop === null) {
            $this->components->error('Shop not found. Pass --shop=<id or code>.');

            return self::FAILURE;
        }

        $action = $this->argument('action');

        if ($action === 'list') {
            $rows = [];

            foreach ($modules->all() as $key => $label) {
                $rows[] = [$key, $label, $shop->hasModule((string) $key) ? 'ON' : 'off'];
            }

            $this->table(['Module', 'Description', 'Status'], $rows);

            return self::SUCCESS;
        }

        $module = (string) $this->argument('module');

        if (! $modules->exists($module)) {
            $this->components->error("Unknown module [{$module}]. Available: ".implode(', ', array_keys($modules->all())));

            return self::FAILURE;
        }

        if (! in_array($action, ['enable', 'disable'], true)) {
            $this->components->error('Action must be list, enable or disable.');

            return self::FAILURE;
        }

        if ($action === 'enable') {
            $alsoOn = $modules->enable($shop, $module);

            if ($alsoOn !== []) {
                $this->components->info('Also switched on, because '.$module.' needs them: '.implode(', ', $alsoOn).'.');
            }
        } else {
            try {
                $modules->disable($shop, $module);
            } catch (PosException $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        $this->components->info("Module [{$module}] {$action}d for {$shop->name}.");

        return self::SUCCESS;
    }

    private function resolveShop(): ?Shop
    {
        $value = $this->option('shop');

        if ($value === null) {
            return Shop::query()->count() === 1 ? Shop::query()->first() : null;
        }

        return Shop::query()->where('id', $value)->orWhere('code', $value)->first();
    }
}
