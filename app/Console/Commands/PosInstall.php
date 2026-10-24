<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Models\User;
use App\Services\ShopSetupService;
use App\Support\CurrentShop;
use App\Support\InstallProfile;
use App\Support\PasswordRules;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Onboard a new client shop from an install profile (config/pos.php): shop,
 * the profile's locations and roles, Retail price list, first till (T1), the
 * owner account, and the profile's modules switched on in Pennant. After
 * this, modules change only with pos:module. Run once per client:
 *   php artisan pos:install --profile=pharmacy
 */
class PosInstall extends Command
{
    protected $signature = 'pos:install {--profile= : Install profile (retail|pharmacy); asked for when left out, POS_PROFILE is the default}';

    protected $description = 'Create a new shop with its owner account, first till and default settings';

    public function handle(CurrentShop $currentShop, ShopSetupService $setup): int
    {
        $profileName = (string) ($this->option('profile') ?? select('Install profile', array_combine(InstallProfile::names(), InstallProfile::names()), default: (string) config('pos.profile')));

        if (! in_array($profileName, InstallProfile::names(), true)) {
            $this->components->error("Unknown install profile [{$profileName}]. Available: ".implode(', ', InstallProfile::names()).'.');

            return self::FAILURE;
        }

        $profile = InstallProfile::named($profileName);

        (new RolesAndPermissionsSeeder)->forProfile($profile)->run();

        $shopName = text('Shop name', required: true);
        $code = Str::upper(text('Short shop code', default: Str::upper(Str::substr(Str::slug($shopName, ''), 0, 8)), required: true));
        $currency = select('Currency', ['LKR' => 'LKR - Sri Lankan Rupee', 'PGK' => 'PGK - Papua New Guinea Kina', 'GBP' => 'GBP - Pound Sterling', 'USD' => 'USD - US Dollar'], default: 'LKR');
        $timezone = text('Timezone', default: match ($currency) {
            'PGK' => 'Pacific/Port_Moresby',
            'GBP' => 'Europe/London',
            default => 'Asia/Colombo',
        });

        $ownerName = text('Owner name', required: true);
        $ownerEmail = text('Owner email', required: true, validate: fn ($v) => Validator::make(['e' => $v], ['e' => 'email|unique:users,email'])->errors()->first('e'));
        $ownerPassword = password('Owner password (min 12 characters)', required: true, validate: fn ($v) => Validator::make(['p' => $v], ['p' => [Password::min(PasswordRules::ADMIN_MIN), PasswordRules::forAdmin(true)]])->errors()->first('p'));

        DB::transaction(function () use ($currentShop, $setup, $profile, $shopName, $code, $currency, $timezone, $ownerName, $ownerEmail, $ownerPassword): void {
            $shop = Shop::query()->create([
                'name' => $shopName,
                'code' => $code,
                'business_type' => $profile->businessType(),
                'currency' => $currency,
                'timezone' => $timezone,
            ]);

            $setup->provision($shop, $profile);
            $currentShop->set($shop);

            $owner = new User;
            $owner->forceFill([
                'shop_id' => $shop->getKey(),
                'name' => $ownerName,
                'email' => $ownerEmail,
                'password' => Hash::make($ownerPassword),
                'email_verified_at' => now(),
            ])->save();

            $owner->assignRole('owner');
        });

        $this->components->info("Shop \"{$shopName}\" is ready ({$profile->name} profile, modules on: ".implode(', ', $profile->modules()).'). '
            ."Log in as {$ownerEmail} and turn on two-factor authentication.");

        return self::SUCCESS;
    }
}
