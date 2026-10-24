<?php

namespace App\Filament\Resources\LabelTemplates\Pages;

use App\Filament\Resources\LabelTemplates\LabelTemplateResource;
use App\Services\Labels\LabelService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLabelTemplates extends ListRecords
{
    protected static string $resource = LabelTemplateResource::class;

    /** A shop's first visit sets up its starting templates (a roll and an A4 sheet). */
    public function mount(): void
    {
        parent::mount();

        app(LabelService::class)->ensureDefaults();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
