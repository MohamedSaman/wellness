<?php

namespace App\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

/**
 * The daily expiry digest (docs/PHARMACY.md §9, branch 10): what has expired
 * and what expires within the shop's warning window, with counts and value
 * at batch cost per location. A database notification shown in the admin
 * panel's bell; sent by pos:expiry-digest at most once a day per person.
 */
class ExpiryDigest extends Notification
{
    /**
     * @param  array{today: CarbonImmutable, days: int, expired: array{batches: int, value: int, locations: list<array{location: string, batches: int, value: int}>}, soon: array{batches: int, value: int, locations: list<array{location: string, batches: int, value: int}>}}  $summary
     * @param  callable(int): string  $money
     */
    public function __construct(
        public readonly array $summary,
        private readonly mixed $money,
        private readonly string $expiredUrl,
        private readonly string $soonUrl,
    ) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Expiry digest, '.$this->summary['today']->format('d/m/Y'))
            ->body($this->body())
            ->icon('heroicon-o-clock')
            ->color($this->summary['expired']['batches'] > 0 ? 'danger' : 'warning')
            ->actions(array_values(array_filter([
                $this->summary['expired']['batches'] > 0 ? Action::make('expired')->label('Expired')->url($this->expiredUrl)->markAsRead() : null,
                $this->summary['soon']['batches'] > 0 ? Action::make('soon')->label('Expiring soon')->url($this->soonUrl)->markAsRead() : null,
            ])))
            ->getDatabaseMessage();
    }

    /** "Expired: 2 batches, LKR 1,200.00 at cost (General Pharmacy 1, Doctor's Medicines 1). ..." */
    public function body(): string
    {
        $parts = [];

        foreach (['expired' => 'Expired', 'soon' => 'Expiring within '.$this->summary['days'].' days'] as $key => $label) {
            $list = $this->summary[$key];

            if ($list['batches'] === 0) {
                continue;
            }

            $where = implode(', ', array_map(fn (array $row): string => "{$row['location']} {$row['batches']}", $list['locations']));
            $parts[] = sprintf('%s: %d %s, %s at cost (%s).', $label, $list['batches'], $list['batches'] === 1 ? 'batch' : 'batches', ($this->money)($list['value']), $where);
        }

        return implode(' ', $parts);
    }
}
