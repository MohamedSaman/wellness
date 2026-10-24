<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\PayablesForm;
use App\Filament\Support\PurchasingForm;
use App\Filament\Support\ShopMoney;
use App\Models\Supplier;
use App\Models\User;
use App\Services\SupplierPayablesService;
use App\Services\SupplierService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class ViewSupplier extends ViewRecord
{
    protected static string $resource = SupplierResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->supplier()->name;
    }

    private function supplier(): Supplier
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Supplier, 404);

        return $record;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function getHeaderActions(): array
    {
        $reload = function (Supplier $supplier): void {
            $this->redirect(SupplierResource::getUrl('view', ['record' => $supplier]));
        };

        return [
            PayablesForm::paymentAction(fn (): Supplier => $this->supplier(), $reload),
            PayablesForm::allocateAction(fn (): Supplier => $this->supplier(), $reload),
            $this->statementAction(),
            ActionGroup::make([
                $this->openingBalanceAction(),
                $this->openingInvoiceAction(),
                $this->reverseOpeningBalanceAction(),
            ])
                ->label('Opening balance')
                ->icon(Heroicon::OutlinedFlag)
                ->color('gray')
                ->button()
                ->visible(fn (): bool => SupplierResource::managesPayables()),
            EditAction::make()
                ->using(fn (Model $record, array $data, Action $action): Supplier => SupplierResource::attempt(
                    $action,
                    fn (): Supplier => app(SupplierService::class)->update($this->supplier(), $data, $this->user()),
                )),
        ];
    }

    /** What the supplier was owed at go-live, as a lump sum: entered once; a mistake is reversed, then entered again. */
    private function openingBalanceAction(): Action
    {
        $allowed = fn (): bool => SupplierResource::managesPayables();

        return Action::make('openingBalance')
            ->label('Opening balance')
            ->icon(Heroicon::OutlinedFlag)
            ->color('gray')
            ->visible(fn (): bool => $allowed() && app(SupplierService::class)->openingBalance($this->supplier()) === null)
            ->authorize($allowed)
            ->modalDescription('What was owed to this supplier when the shop started using the system, as one amount. It is entered once and can\'t be edited: a mistake is reversed and entered again. Unpaid invoices can be entered one by one instead (Opening invoice).')
            ->fillForm(fn (): array => ['as_of' => now()->toDateString(), 'due_on' => $this->lumpDueOn(now()->toDateString())])
            ->schema([
                PurchasingForm::moneyField('amount', 'Amount owed', allowNegative: true)
                    ->required()
                    ->helperText('A credit the supplier owed the shop is entered as a negative amount (it has no due date).'),
                DatePicker::make('as_of')
                    ->label('As of')
                    ->required()
                    ->maxDate(now())
                    ->live()
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('due_on', $this->lumpDueOn($state))),
                DatePicker::make('due_on')
                    ->label('Due on')
                    ->helperText(fn (): string => 'The as-of date + '.(int) ($this->supplier()->payment_terms_days ?? 0).' days\' terms; change it if the supplier\'s statement says otherwise. Blank: the same.'),
                Textarea::make('note')->rows(2)->maxLength(500)->placeholder('From the old books, statement of ...'),
            ])
            ->action(function (array $data, Action $action): void {
                SupplierResource::attempt($action, fn () => app(SupplierService::class)->recordOpeningBalance(
                    $this->supplier(),
                    Money::toMinor((string) $data['amount'], ShopMoney::decimals()),
                    $this->user(),
                    $data['as_of'] ?? null,
                    $data['note'] ?? null,
                    dueOn: $data['due_on'] ?? null,
                ));

                Notification::make()->title('Opening balance entered')->success()->send();
                $this->redirect(SupplierResource::getUrl('view', ['record' => $this->supplier()]));
            });
    }

    private function reverseOpeningBalanceAction(): Action
    {
        $allowed = fn (): bool => SupplierResource::managesPayables();

        return Action::make('reverseOpeningBalance')
            ->label('Reverse opening balance')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (): bool => $allowed() && app(SupplierService::class)->openingBalance($this->supplier()) !== null)
            ->authorize($allowed)
            ->modalDescription('An entry with the amount negated takes the opening balance back out. Then enter the right one.')
            ->schema([Textarea::make('reason')->label('Why')->required()->rows(2)->maxLength(500)])
            ->action(function (array $data, Action $action): void {
                $entry = app(SupplierService::class)->openingBalance($this->supplier());
                abort_if($entry === null, 404);

                SupplierResource::attempt($action, fn () => app(SupplierService::class)->reverseOpeningBalance($entry, (string) $data['reason'], $this->user()));

                Notification::make()->title('Opening balance reversed')->success()->send();
                $this->redirect(SupplierResource::getUrl('view', ['record' => $this->supplier()]));
            });
    }

    /** A lump sum's default due date: its as-of date + the supplier's terms. */
    private function lumpDueOn(?string $asOf): ?string
    {
        return blank($asOf) ? null : app(SupplierPayablesService::class)->dueOn($this->supplier(), (string) $asOf)->toDateString();
    }

    /**
     * An unpaid invoice from before go-live, with its number, date and due
     * date (blank: the invoice date + the supplier's terms). Paid like a GRN.
     */
    private function openingInvoiceAction(): Action
    {
        $allowed = fn (): bool => SupplierResource::managesPayables();

        return Action::make('openingInvoice')
            ->label('Opening invoice')
            ->icon(Heroicon::OutlinedDocumentText)
            ->visible($allowed)
            ->authorize($allowed)
            ->modalDescription('An invoice from before the shop used the system that is still unpaid, as on the supplier\'s statement. It is paid like a goods received note. Never edited: a mistake is reversed from the Invoices tab and entered again.')
            ->fillForm(['as_of' => now()->toDateString()])
            ->schema([
                TextInput::make('invoice_no')->label('Invoice no.')->required()->maxLength(50),
                DatePicker::make('invoice_date')->label('Invoice date')->required()->maxDate(now()),
                PurchasingForm::moneyField('amount', 'Amount owed')->required()->rule('gt:0'),
                DatePicker::make('due_on')
                    ->label('Due on')
                    ->helperText(fn (): string => 'Blank: the invoice date + '.(int) ($this->supplier()->payment_terms_days ?? 0).' days\' terms.'),
                DatePicker::make('as_of')->label('Entered as of')->required()->maxDate(now())->helperText('The go-live date.'),
                Textarea::make('note')->rows(2)->maxLength(500),
            ])
            ->action(function (array $data, Action $action): void {
                SupplierResource::attempt($action, fn () => app(SupplierService::class)->recordOpeningBalance(
                    $this->supplier(),
                    PurchasingForm::toMinor($data['amount'] ?? null),
                    $this->user(),
                    $data['as_of'] ?? null,
                    $data['note'] ?? null,
                    (string) $data['invoice_no'],
                    $data['invoice_date'] ?? null,
                    $data['due_on'] ?? null,
                ));

                Notification::make()->title('Opening invoice '.$data['invoice_no'].' entered')->success()->send();
                $this->redirect(SupplierResource::getUrl('view', ['record' => $this->supplier()]));
            });
    }

    /** The supplier statement for a period: printable (and saved as PDF from the print dialog), or as PDF or Excel. */
    private function statementAction(): Action
    {
        return Action::make('statement')
            ->label('Statement')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->visible(fn (): bool => SupplierResource::showsBalance())
            ->authorize(fn (): bool => SupplierResource::showsBalance())
            ->modalSubmitActionLabel('Open statement')
            ->fillForm(fn (): array => ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString(), 'format' => 'print'])
            ->schema([
                DatePicker::make('from')->required(),
                DatePicker::make('to')->required()->afterOrEqual('from'),
                Select::make('format')->label('As')->options(['print' => 'Page to print', 'pdf' => 'PDF', 'xlsx' => 'Excel'])->required()->selectablePlaceholder(false),
            ])
            ->action(function (array $data): void {
                $url = route('purchasing.print.statement', [
                    'supplier' => $this->supplier(), 'from' => $data['from'], 'to' => $data['to'],
                    ...(in_array($data['format'] ?? 'print', ['pdf', 'xlsx'], true) ? ['format' => $data['format']] : []),
                ]);

                $this->js('window.open('.json_encode($url).', "_blank")');
            });
    }
}
