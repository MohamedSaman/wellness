<?php

namespace App\Filament\Resources\ImportRuns;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Filament\Resources\ImportRuns\Pages\ImportFile;
use App\Filament\Resources\ImportRuns\Pages\ListImportRuns;
use App\Filament\Resources\PermissionResource;
use App\Models\ImportRun;
use App\Support\CurrentShop;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The import log (Administration -> Imports, imports.manage): every file
 * uploaded, who and when, its type, status and counts, with the file and
 * its error report to download. New imports start from here.
 */
class ImportRunResource extends PermissionResource
{
    protected static ?string $model = ImportRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Imports';

    protected static ?string $modelLabel = 'import';

    protected static ?int $navigationSort = 20;

    public static function table(Table $table): Table
    {
        $shop = app(CurrentShop::class)->getOrFail();

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Uploaded')->dateTime('d/m/Y H:i', $shop->timezone)->sortable(),
                TextColumn::make('user.name')->label('By'),
                TextColumn::make('type')->formatStateUsing(fn (ImportType $state): string => $state->labelFor($shop)),
                TextColumn::make('file_name')->label('File')->limit(40)->tooltip(fn (ImportRun $record): string => $record->file_name),
                TextColumn::make('status')->badge()->formatStateUsing(fn (ImportStatus $state): string => $state->label())->color(fn (ImportStatus $state): string => $state->color()),
                TextColumn::make('total_rows')->label('Rows')->numeric(),
                TextColumn::make('counts')->label('Result')->state(fn (ImportRun $record): string => $record->countsSummary())->wrap(),
                TextColumn::make('finished_at')->label('Finished')->dateTime('d/m/Y H:i', $shop->timezone)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('message')->label('Why it failed')->limit(60)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->options(ImportType::options($shop)),
                SelectFilter::make('status')->options(collect(ImportStatus::cases())->mapWithKeys(fn (ImportStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordUrl(fn (ImportRun $record): string => ImportFile::getUrl(['run' => $record->uuid]))
            ->recordActions([
                Action::make('report')
                    ->label('Error report')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->url(fn (ImportRun $record): string => route('imports.report', $record))
                    ->visible(fn (ImportRun $record): bool => $record->error_report_path !== null),
                Action::make('file')
                    ->label('File')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (ImportRun $record): string => route('imports.file', $record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImportRuns::route('/'),
            'import' => ImportFile::route('/import'),
        ];
    }
}
