<?php

namespace App\Filament\Resources\LabelTemplates\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\LabelTemplates\LabelTemplateResource;
use App\Models\User;
use App\Services\Labels\LabelService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateLabelTemplate extends CreateRecord
{
    protected static string $resource = LabelTemplateResource::class;

    /** A new template starts as a 50 × 25 mm roll with the shop's default fields. */
    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $this->form->fill(EditLabelTemplate::formData(app(LabelService::class)->newTemplateAttributes('roll_50x25')));

        $this->callHook('afterFill');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        try {
            return app(LabelService::class)->saveTemplate(null, $data, $user);
        } catch (PosException $exception) {
            Notification::make()->title('The template can\'t be saved')->body($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }
}
