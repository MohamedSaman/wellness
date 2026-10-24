<?php

namespace App\Filament\Resources\Users\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->getRecord();
        $data['role'] = $user->getRoleNames()->first();
        $data['is_active'] = (bool) $user->is_active;
        $data['default_location_id'] = $user->default_location_id;
        // Only the stored rows: an empty list shows as empty, not as the default location it implies.
        $data['allowed_location_ids'] = $user->allowedLocationIds();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        try {
            return $this->updateUser($record, $data);
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function updateUser(User $record, array $data): User
    {
        return DB::transaction(function () use ($record, $data): User {
            $isSelf = $record->is(auth()->user());

            $record->fill(array_intersect_key($data, array_flip(['name', 'email', 'password'])));

            // Owners cannot lock themselves out by disabling or demoting their own account.
            if (! $isSelf) {
                $record->forceFill(['is_active' => (bool) $data['is_active']]);
            }

            UserResource::applyDefaultLocation($record, $data);
            $record->save();

            if (! $isSelf) {
                $record->syncRoles([$data['role']]);
            }

            UserResource::applyAllowedLocations($record, $data);

            return $record;
        });
    }
}
