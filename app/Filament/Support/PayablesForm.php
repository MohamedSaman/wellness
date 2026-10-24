<?php

namespace App\Filament\Support;

use App\Enums\SupplierPaymentMethod;
use App\Exceptions\PosException;
use App\Models\Supplier;
use App\Models\SupplierCredit;
use App\Models\SupplierPayable;
use App\Models\User;
use App\Services\SupplierPayablesService;
use App\Services\SupplierService;
use App\Support\CurrentShop;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * The payables forms shared by the supplier page, the Payables screen and
 * the GRN page: a payment and what it pays (oldest due first, chosen
 * invoice by invoice, or kept as credit), allocating a credit later, and
 * moving a due date. Who may do what is checked again by the services.
 */
class PayablesForm
{
    /** Payables are shown: purchasing on, and the user reads them (payables.view) or manages purchasing. */
    public static function canSee(): bool
    {
        $user = PurchasingForm::user();

        return self::purchasing() && $user !== null && ($user->can('payables.view') || $user->can('purchasing.manage'));
    }

    /** Payments, allocations and due dates: purchasing.manage. */
    public static function canManage(): bool
    {
        return self::purchasing() && (PurchasingForm::user()?->can('purchasing.manage') ?? false);
    }

    private static function purchasing(): bool
    {
        return app(CurrentShop::class)->get()?->hasModule('purchasing') ?? false;
    }

    private static function service(): SupplierPayablesService
    {
        return app(SupplierPayablesService::class);
    }

    private static function user(): User
    {
        $user = PurchasingForm::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Record a payment and allocate it. Without $supplier (the Payables
     * screen) the form asks which supplier.
     *
     * @param  (Closure(): Supplier)|null  $supplier
     * @param  (Closure(Supplier): void)|null  $after
     */
    public static function paymentAction(?Closure $supplier = null, ?Closure $after = null): Action
    {
        $supplierId = fn (Get $get): ?int => $supplier !== null ? (int) $supplier()->getKey() : (filled($get('supplier_id')) ? (int) $get('supplier_id') : null);
        $amount = fn (Get $get): int => PurchasingForm::toMinor($get('amount'));

        return Action::make('recordPayment')
            ->label('Record payment')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (): bool => self::canManage())
            ->authorize(fn (): bool => self::canManage())
            ->modalDescription('A payment lowers what is owed and is set against the supplier\'s invoices. What it does not cover stays as the supplier\'s credit. Nothing is posted to any accounts.')
            ->fillForm(fn (): array => ['paid_on' => now()->toDateString(), 'method' => SupplierPaymentMethod::BankTransfer->value, 'allocate' => 'oldest'])
            ->schema([
                Select::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::fill($get, $set, $supplierId($get), $amount($get)))
                    ->visible($supplier === null),
                PurchasingForm::moneyField('amount', 'Amount')
                    ->required()
                    ->rule('gt:0')
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::fill($get, $set, $supplierId($get), $amount($get))),
                DatePicker::make('paid_on')->label('Paid on')->required()->maxDate(now()),
                Select::make('method')->options(SupplierPaymentMethod::options())->required()->native(false),
                TextInput::make('reference')->label('Reference')->maxLength(100)->placeholder('Cheque or transfer number'),
                Textarea::make('notes')->rows(2)->maxLength(500),
                ...self::allocationFields($supplierId, $amount),
            ])
            ->action(function (array $data, Action $action) use ($supplier, $after): void {
                $target = $supplier !== null ? $supplier() : Supplier::query()->findOrFail((int) $data['supplier_id']);

                $payment = self::attempt($action, fn () => app(SupplierService::class)->recordPayment(
                    $target,
                    PurchasingForm::toMinor($data['amount'] ?? null),
                    SupplierPaymentMethod::from((string) $data['method']),
                    self::user(),
                    $data['paid_on'] ?? null,
                    $data['reference'] ?? null,
                    $data['notes'] ?? null,
                    self::plan($data),
                ));

                $credit = $payment->credit;
                Notification::make()
                    ->title('Payment recorded')
                    ->body($credit === null ? null : self::outcome($credit))
                    ->success()
                    ->send();

                if ($after !== null) {
                    $after($target);
                }
            });
    }

    /**
     * Allocate what is left of a payment, return or opening credit to the supplier's invoices.
     *
     * @param  Closure(): Supplier  $supplier
     * @param  (Closure(Supplier): void)|null  $after
     */
    public static function allocateAction(Closure $supplier, ?Closure $after = null): Action
    {
        $supplierId = fn (): int => (int) $supplier()->getKey();
        $credit = fn (Get $get): ?SupplierCredit => filled($get('credit_id')) ? SupplierCredit::query()->where('supplier_id', $supplierId())->find((int) $get('credit_id')) : null;
        $amount = fn (Get $get): int => $credit($get)?->unallocated() ?? 0;

        return Action::make('allocateCredit')
            ->label('Allocate credit')
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->visible(fn (): bool => self::canManage()
                && self::service()->creditLeft($supplierId()) > 0
                && self::service()->openPayables($supplierId())->isNotEmpty())
            ->authorize(fn (): bool => self::canManage())
            ->modalDescription('Set a payment, a return\'s credit or an opening credit, or what is left of it, against unpaid invoices.')
            ->fillForm(fn (): array => ['credit_id' => self::service()->openCredits($supplierId())->first()?->getKey(), 'allocate' => 'oldest'])
            ->schema([
                Select::make('credit_id')
                    ->label('Credit')
                    ->options(fn (): array => self::service()->openCredits($supplierId())
                        ->mapWithKeys(fn (SupplierCredit $credit): array => [$credit->getKey() => $credit->label().': '.ShopMoney::format($credit->unallocated()).' left'])
                        ->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::fill($get, $set, $supplierId(), $amount($get))),
                ...self::allocationFields(fn (Get $get): int => $supplierId(), $amount, oldestOnly: true),
            ])
            ->action(function (array $data, Action $action) use ($supplier, $after): void {
                $target = $supplier();
                $credit = SupplierCredit::query()->where('supplier_id', $target->getKey())->findOrFail((int) $data['credit_id']);

                self::attempt($action, fn () => self::service()->allocate($credit, self::plan($data), self::user()));

                Notification::make()->title('Credit allocated')->body(self::outcome($credit->refresh()))->success()->send();

                if ($after !== null) {
                    $after($target);
                }
            });
    }

    /**
     * Move the due date of an open invoice (purchasing.manage, audited).
     *
     * @param  Closure(Model): (SupplierPayable|null)  $payableOf  the invoice of the action's record
     * @param  (Closure(): void)|null  $after
     */
    public static function dueDateAction(Closure $payableOf, ?Closure $after = null): Action
    {
        return Action::make('changeDueDate')
            ->label('Change due date')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('gray')
            ->visible(fn (Model $record): bool => self::canManage() && ($payableOf($record)?->status->isOpen() ?? false))
            ->authorize(fn (): bool => self::canManage())
            ->modalDescription('When the supplier agrees to be paid later (or earlier). Say why: the change and the reason are kept in the audit log.')
            ->fillForm(fn (Model $record): array => ['due_on' => $payableOf($record)?->due_on->toDateString()])
            ->schema([
                DatePicker::make('due_on')->label('Due on')->required(),
                TextInput::make('reason')->label('Why')->required()->maxLength(200)->placeholder('Agreed with the rep, ...'),
            ])
            ->action(function (array $data, Action $action, Model $record) use ($payableOf, $after): void {
                $payable = $payableOf($record);
                abort_if($payable === null, 404);

                $changed = self::attempt($action, fn (): SupplierPayable => self::service()->changeDueDate($payable, (string) $data['due_on'], (string) ($data['reason'] ?? ''), self::user()));

                Notification::make()->title('Now due on '.$changed->due_on->format('d/m/Y'))->success()->send();

                if ($after !== null) {
                    $after();
                }
            });
    }

    /**
     * How a payment or credit is allocated: oldest due first (worked out
     * when it is saved), invoice by invoice, or kept as credit.
     *
     * @param  Closure(Get): ?int  $supplierId
     * @param  Closure(Get): int  $amount
     * @return list<Component>
     */
    public static function allocationFields(Closure $supplierId, Closure $amount, bool $oldestOnly = false): array
    {
        return [
            Radio::make('allocate')
                ->label('Set against invoices')
                ->options([
                    'oldest' => 'Oldest due first',
                    'choose' => 'Choose invoices and amounts',
                    ...($oldestOnly ? [] : ['none' => 'Keep it all as credit for later']),
                ])
                ->required()
                ->live()
                ->afterStateUpdated(fn (Get $get, Set $set) => self::fill($get, $set, $supplierId($get), $amount($get))),
            Repeater::make('allocations')
                ->label('Invoices')
                ->visible(fn (Get $get): bool => $get('allocate') === 'choose')
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->columns(['default' => 1, 'md' => 12])
                ->schema([
                    Hidden::make('payable_id'),
                    Hidden::make('label'),
                    TextEntry::make('invoice')->hiddenLabel()->state(fn (Get $get): ?string => $get('label'))->columnSpan(['default' => 1, 'md' => 8]),
                    PurchasingForm::moneyField('amount', 'Pay')->hiddenLabel()->placeholder('0')->live(onBlur: true)->columnSpan(['default' => 1, 'md' => 4]),
                ]),
            TextEntry::make('allocation_hint')
                ->hiddenLabel()
                ->state(fn (Get $get): string => self::hint($get, $supplierId($get), $amount($get))),
        ];
    }

    /**
     * The invoices to allocate to, pre-filled oldest due first, when the user chooses.
     */
    public static function fill(Get $get, Set $set, ?int $supplierId, int $amount): void
    {
        if ($get('allocate') !== 'choose' || $supplierId === null) {
            return;
        }

        $plan = self::service()->suggest($supplierId, max($amount, 0));
        $rows = [];

        foreach (self::service()->openPayables($supplierId) as $payable) {
            $rows['p'.$payable->getKey()] = [
                'payable_id' => $payable->getKey(),
                'label' => self::rowLabel($payable),
                'amount' => isset($plan[(int) $payable->getKey()]) ? PurchasingForm::toInput($plan[(int) $payable->getKey()]) : null,
            ];
        }

        $set('allocations', $rows);
    }

    /**
     * The service's plan for the form's choice: null = oldest due first, [] = keep as credit.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, int>|null
     */
    public static function plan(array $data): ?array
    {
        if (($data['allocate'] ?? 'oldest') === 'none') {
            return [];
        }

        if (($data['allocate'] ?? 'oldest') !== 'choose') {
            return null;
        }

        $plan = [];

        foreach ((array) ($data['allocations'] ?? []) as $row) {
            $amount = PurchasingForm::toMinor($row['amount'] ?? null);

            if ($amount !== 0) {
                $plan[(int) $row['payable_id']] = $amount;
            }
        }

        return $plan;
    }

    /** "GRN-000003 · invoice HPL-1 · due 09/11/2026 · LKR 1,000.00 owed" (overdue: how late). */
    public static function rowLabel(SupplierPayable $payable): string
    {
        $late = $payable->daysOverdue(self::service()->today());

        return implode(' · ', array_filter([
            $payable->goods_receipt_id !== null ? $payable->documentLabel() : null,
            $payable->invoice_no === null ? ($payable->goods_receipt_id === null ? 'Opening balance' : null) : ($payable->goods_receipt_id === null ? 'Opening invoice ' : 'invoice ').$payable->invoice_no,
            'due '.$payable->due_on->format('d/m/Y').($late > 0 ? " ({$late} days late)" : ''),
            ShopMoney::format($payable->outstanding()).' owed',
        ]));
    }

    /** What the choice will do: which invoices it pays, and what stays as credit. */
    private static function hint(Get $get, ?int $supplierId, int $amount): string
    {
        if ($supplierId === null || $amount <= 0) {
            return 'Enter the amount to see what it pays.';
        }

        $plan = match ($get('allocate')) {
            'none' => [],
            'choose' => self::plan(['allocate' => 'choose', 'allocations' => $get('allocations') ?? []]) ?? [],
            default => self::service()->suggest($supplierId, $amount),
        };
        $allocated = array_sum($plan);

        if ($allocated > $amount) {
            return 'That is '.ShopMoney::format($allocated - $amount).' more than '.ShopMoney::format($amount).'.';
        }

        $invoices = count(array_filter($plan));
        $owed = self::service()->summary($supplierId)['outstanding'];

        return match (true) {
            $owed === 0 => 'No unpaid invoices: all '.ShopMoney::format($amount).' stays as credit.',
            $invoices === 0 => 'All '.ShopMoney::format($amount).' stays as credit.',
            default => 'Pays '.ShopMoney::format($allocated).' on '.$invoices.' '.str('invoice')->plural($invoices).($allocated < $amount ? '; '.ShopMoney::format($amount - $allocated).' stays as credit.' : '.'),
        };
    }

    /** "Paid 2 invoices; LKR 300.00 kept as credit." */
    private static function outcome(SupplierCredit $credit): string
    {
        $count = $credit->allocations()->whereNull('reversal_of_id')->distinct()->count('supplier_payable_id');
        $left = $credit->unallocated();

        return 'Set against '.$count.' '.str('invoice')->plural($count).($left > 0 ? '; '.ShopMoney::format($left).' kept as credit.' : '.');
    }

    /**
     * Run a service call; a business-rule failure is shown and the form stays open.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private static function attempt(Action $action, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            $action->halt();

            throw $exception;
        }
    }
}
