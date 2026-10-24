<?php

namespace App\Filament\Resources\ImportRuns\Pages;

use App\Enums\ImportType;
use App\Filament\Resources\ImportRuns\ImportRunResource;
use App\Support\CurrentShop;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListImportRuns extends ListRecords
{
    protected static string $resource = ImportRunResource::class;

    protected ?string $subheading = 'Load items, suppliers, opening stock and opening invoices from Excel or CSV. Each file is checked first; nothing is written until you confirm, and then all of it or none of it.';

    protected function getHeaderActions(): array
    {
        $shop = app(CurrentShop::class)->getOrFail();

        return [
            ActionGroup::make(collect(ImportType::options($shop))
                ->map(fn (string $label, string $type): Action => Action::make("template_{$type}")
                    ->label($label)
                    ->url(route('imports.template', $type)))
                ->values()
                ->all())
                ->label('Download a template')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->button()
                ->color('gray'),
            Action::make('new')
                ->label('Import a file')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->url(ImportFile::getUrl()),
        ];
    }
}
