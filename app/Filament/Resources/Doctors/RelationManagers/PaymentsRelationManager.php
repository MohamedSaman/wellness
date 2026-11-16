<?php

namespace App\Filament\Resources\Doctors\RelationManagers;

use App\Enums\CashMovementType;
use App\Filament\Support\ShopMoney;
use App\Models\CashMovement;
use App\Models\Shop;
use App\Support\CurrentShop;
use App\Support\ReportPeriod;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * A doctor's payment history (docs/PHARMACY.md, branch 18): every cash
 * payment made to them, newest first: when (shop time), the amount, the
 * till, its location, the shift (to its summary), who paid and the note;
 * voided payments left out. The period filter works on the shop's days
 * (Today, Yesterday, This week, This month, Last month, or dates), and the
 * amount's total is the period's. Read only.
 */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $shop = fn () => app(CurrentShop::class)->getOrFail();
        $timezone = fn (): string => $shop()->timezone ?: 'UTC';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['device', 'location', 'user', 'shift'])
                ->where('type', CashMovementType::DoctorPayment->value)
                ->whereNull('voided_at'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Date')->dateTime('d/m/Y H:i', $timezone)->sortable(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn (int $state): string => (string) ShopMoney::format($state))
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Period total')->formatStateUsing(fn ($state): string => (string) ShopMoney::format((int) $state))),
                TextColumn::make('device.code')->label('Till'),
                TextColumn::make('location.name')->label('Location')->wrap(),
                TextColumn::make('shift.opened_at')
                    ->label('Shift')
                    ->dateTime('d/m/Y H:i', $timezone)
                    ->url(fn (CashMovement $record): ?string => auth()->user()?->can('shifts.manage') && $record->shift !== null
                        ? route('pos.shift.summary', ['shift' => $record->shift->uuid, 'format' => 'a4']) : null, shouldOpenInNewTab: true)
                    ->color('primary')
                    ->tooltip('When the shift opened; opens its summary'),
                TextColumn::make('user.name')->label('Paid by'),
                TextColumn::make('note')->wrap()->placeholder('-'),
                TextColumn::make('number')->label('No.')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        Select::make('range')
                            ->label('Period')
                            ->options(['all' => 'All time', ...ReportPeriod::PRESETS])
                            ->default('all')
                            ->live(),
                        DatePicker::make('from')->visible(fn (Get $get): bool => $get('range') === 'custom'),
                        DatePicker::make('until')->visible(fn (Get $get): bool => $get('range') === 'custom'),
                    ])
                    ->query(function (Builder $query, array $data) use ($shop): Builder {
                        $period = self::period($shop(), $data);

                        return $period === null ? $query : $query
                            ->where('cash_movements.created_at', '>=', $period->startUtc())
                            ->where('cash_movements.created_at', '<', $period->endUtc());
                    })
                    ->indicateUsing(function (array $data) use ($shop): ?string {
                        $period = self::period($shop(), $data);

                        return $period === null ? null : 'Period: '.$period->label();
                    }),
            ]);
    }

    /**
     * The period the filter asks for on the shop's days, or null for all time.
     *
     * @param  array<string, mixed>  $data
     */
    public static function period(Shop $shop, array $data): ?ReportPeriod
    {
        $range = (string) ($data['range'] ?? 'all');

        if ($range === 'custom') {
            $from = filled($data['from'] ?? null) ? (string) $data['from'] : null;
            $until = filled($data['until'] ?? null) ? (string) $data['until'] : null;

            return $from === null && $until === null ? null : ReportPeriod::between($shop, substr($from ?? '2000-01-01', 0, 10), substr($until ?? now($shop->timezone)->format('Y-m-d'), 0, 10), clamp: false);
        }

        return ReportPeriod::preset($shop, $range);
    }
}
