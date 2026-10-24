<?php

namespace App\Filament\Resources\LabelTemplates\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\LabelTemplates\LabelTemplateResource;
use App\Models\LabelTemplate;
use App\Models\User;
use App\Services\Labels\LabelService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class EditLabelTemplate extends EditRecord
{
    protected static string $resource = LabelTemplateResource::class;

    /**
     * Template attributes as the form holds them: sizes as numbers, the kind
     * as its value, a template without a preset as "Custom size".
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function formData(array $attributes): array
    {
        $data = [];

        foreach ($attributes as $key => $value) {
            $data[$key] = match (true) {
                $value instanceof BackedEnum => $value->value,
                in_array($key, ['label_width', 'label_height', 'page_width', 'page_height', 'margin_top', 'margin_left', 'gap_x', 'gap_y', 'padding'], true) && $value !== null => (float) $value,
                default => $value,
            };
        }

        $data['preset'] = $data['preset'] ?? 'custom';

        return $data;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var LabelTemplate $template */
        $template = $this->getRecord();

        return self::formData([...$data, 'kind' => $template->kind, 'fields' => $template->fields]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('delete')
                ->label('Delete')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('Past prints keep the template\'s name in the print log.')
                ->authorize(fn (): bool => auth()->user()?->can('labels.manage') ?? false)
                ->action(function (Action $action): void {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);

                    /** @var LabelTemplate $template */
                    $template = $this->getRecord();

                    try {
                        app(LabelService::class)->deleteTemplate($template, $user);
                    } catch (PosException $exception) {
                        Notification::make()->title($exception->getMessage())->danger()->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->title('Template deleted')->success()->send();
                    $this->redirect(LabelTemplateResource::getUrl('index'));
                }),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $record instanceof LabelTemplate, 403);

        try {
            return app(LabelService::class)->saveTemplate($record, $data, $user);
        } catch (PosException $exception) {
            Notification::make()->title('The template can\'t be saved')->body($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
