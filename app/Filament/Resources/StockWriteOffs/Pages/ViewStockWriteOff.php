<?php

namespace App\Filament\Resources\StockWriteOffs\Pages;

use App\Filament\Resources\StockWriteOffs\StockWriteOffResource;
use App\Models\StockWriteOff;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class ViewStockWriteOff extends ViewRecord
{
    protected static string $resource = StockWriteOffResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->loadMissing(['items.variant.product', 'location', 'user']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Print write-off note')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn (): string => route('stock.print.write-off', ['writeOff' => $this->writeOff()]).'?print=1', shouldOpenInNewTab: true),
        ];
    }

    private function writeOff(): StockWriteOff
    {
        /** @var StockWriteOff $record */
        $record = $this->getRecord();

        return $record;
    }
}
