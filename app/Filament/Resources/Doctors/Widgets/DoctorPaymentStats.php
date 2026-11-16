<?php

namespace App\Filament\Resources\Doctors\Widgets;

use App\Enums\CashMovementType;
use App\Filament\Support\ShopMoney;
use App\Models\CashMovement;
use App\Models\Doctor;
use App\Support\CurrentShop;
use App\Support\ReportPeriod;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * A doctor's page's cards (docs/PHARMACY.md, branch 18): paid this month,
 * last month and in all, and the last payment (when and how much), on the
 * shop's days; voided payments apart.
 */
class DoctorPaymentStats extends StatsOverviewWidget
{
    public ?Doctor $record = null;

    protected function getColumns(): int|array
    {
        return ['default' => 2, 'md' => 4];
    }

    protected function getStats(): array
    {
        $shop = app(CurrentShop::class)->getOrFail();
        $doctor = $this->record ?? abort(404);
        $payments = fn (): Builder => CashMovement::query()
            ->where('doctor_id', $doctor->getKey())
            ->where('type', CashMovementType::DoctorPayment->value)
            ->whereNull('voided_at');
        $in = fn (ReportPeriod $period): int => (int) $payments()
            ->where('created_at', '>=', $period->startUtc())
            ->where('created_at', '<', $period->endUtc())
            ->sum('amount');
        /** @var CashMovement|null $last */
        $last = $payments()->with('device')->latest('created_at')->latest('id')->first();

        return [
            Stat::make('This month', ShopMoney::format($in(ReportPeriod::thisMonth($shop)))),
            Stat::make('Last month', ShopMoney::format($in(ReportPeriod::lastMonth($shop)))),
            Stat::make('Last payment', $last === null ? 'Never' : ShopMoney::format($last->amount))
                ->description($last === null ? null : $last->created_at->setTimezone($shop->timezone ?: 'UTC')->format('d/m/Y H:i').' · '.$last->device?->code),
            Stat::make('All time', ShopMoney::format((int) $payments()->sum('amount'))),
        ];
    }
}
