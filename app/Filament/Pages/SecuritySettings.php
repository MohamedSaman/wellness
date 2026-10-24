<?php

namespace App\Filament\Pages;

use App\Http\Middleware\AdminIpAllowlist;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\Shop;
use App\Models\User;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Security for an install on the public internet (docs/deploy/CLOUD.md):
 * the idle sign-out of the admin panel and the till, the addresses the
 * admin panel may be opened from, how long the audit log is kept, and who
 * has two-factor authentication. Saved in the shop's settings like Shop
 * settings; the shop is audited, so every change is in the audit log.
 */
class SecuritySettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Security';

    protected static ?string $title = 'Security';

    protected static ?string $slug = 'security';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('settings.manage');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $shop = $this->shop();

        $this->getSchema('form')->fill([
            'admin_idle_minutes' => (int) $shop->setting('admin_idle_minutes'),
            'till_idle_minutes' => (int) $shop->setting('till_idle_minutes'),
            'admin_ip_allowlist' => implode("\n", AdminIpAllowlist::normalize($shop->setting('admin_ip_allowlist'))),
            'audit_log_retention_days' => (int) $shop->setting('audit_log_retention_days'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Sign out when idle')
                    ->description('Minutes without a key press, click or scroll. 0 = never. An offline till stays signed in until it is back online, so billing never stops during an outage.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('admin_idle_minutes')
                            ->label('Admin panel and settings (minutes)')
                            ->numeric()->integer()->required()->minValue(0)->maxValue(1440),
                        TextInput::make('till_idle_minutes')
                            ->label('Till (minutes)')
                            ->numeric()->integer()->required()->minValue(0)->maxValue(1440)
                            ->helperText('Off (0) by default: a till signed out while the internet is down cannot be signed in again until it is back, so it could not bill.'),
                    ]),
                Section::make('Admin panel access')
                    ->description('Leave empty to allow the admin panel from anywhere (with two-factor authentication). The till is never limited.')
                    ->schema([
                        Textarea::make('admin_ip_allowlist')
                            ->label('Only from these addresses')
                            ->rows(4)
                            ->placeholder("203.0.113.7\n198.51.100.0/24")
                            ->helperText(fn (): string => 'One address or range per line. You are connecting from '.request()->ip().'. Locked out: run php artisan pos:admin-ip --clear on the server.'),
                    ]),
                Section::make('Audit log')
                    ->schema([
                        TextInput::make('audit_log_retention_days')
                            ->label('Keep audit log entries for (days)')
                            ->numeric()->integer()->required()->minValue(0)->maxValue(36500)
                            ->helperText('0 = keep everything. Otherwise at least 365. Older entries are deleted every night, except cost revaluations (the cost rebuild needs them).'),
                    ]),
                Section::make('Two-factor authentication')
                    ->description(fn (): string => (bool) config('pos.security.require_two_factor')
                        ? 'Required for: '.implode(', ', (array) config('pos.security.two_factor_roles')).'. Optional for everyone else (Settings → Security on their own account).'
                        : 'Not enforced on this install (POS_REQUIRE_2FA). Every user can turn it on under Settings → Security.')
                    ->schema([
                        View::make('filament.security-users')->viewData(fn (): array => ['users' => $this->users()]),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save security settings')->submit('save')->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        /** @var array<string, mixed> $data */
        $data = $this->getSchema('form')->getState();
        $shop = $this->shop();

        $lines = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string) ($data['admin_ip_allowlist'] ?? '')) ?: [])));
        $invalid = array_values(array_filter($lines, fn (string $line): bool => ! AdminIpAllowlist::isValid($line)));

        if ($invalid !== []) {
            throw ValidationException::withMessages(['data.admin_ip_allowlist' => 'Not an address or range: '.implode(', ', $invalid).'.']);
        }

        $allowlist = AdminIpAllowlist::normalize($lines);
        $probe = clone $shop;
        $probe->settings = [...($shop->settings ?? []), 'admin_ip_allowlist' => $allowlist];

        if (! AdminIpAllowlist::allows($probe, (string) request()->ip())) {
            throw ValidationException::withMessages(['data.admin_ip_allowlist' => 'This list would lock you out: add the address you are connecting from ('.request()->ip().').']);
        }

        $retention = (int) $data['audit_log_retention_days'];

        if ($retention > 0 && $retention < 365) {
            throw ValidationException::withMessages(['data.audit_log_retention_days' => 'Keep the audit log for at least 365 days, or 0 to keep it all.']);
        }

        $shop->update([
            'settings' => [
                ...($shop->settings ?? []),
                'admin_idle_minutes' => (int) $data['admin_idle_minutes'],
                'till_idle_minutes' => (int) $data['till_idle_minutes'],
                'admin_ip_allowlist' => $allowlist,
                'audit_log_retention_days' => $retention,
            ],
        ]);

        Notification::make()->title('Security settings saved')->success()->send();
    }

    /**
     * The shop's active users and whether each has two-factor authentication,
     * those who must have it first.
     *
     * @return list<array{name: string, email: string, roles: string, two_factor: bool, required: bool}>
     */
    private function users(): array
    {
        return array_values(User::query()
            ->where('shop_id', $this->shop()->getKey())
            ->where('is_active', true)
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name')->implode(', '),
                'two_factor' => $user->two_factor_confirmed_at !== null,
                'required' => RequireTwoFactor::isRequiredFor($user),
            ])
            ->sortByDesc('required')
            ->all());
    }

    private function shop(): Shop
    {
        return app(CurrentShop::class)->getOrFail();
    }
}
