<?php

namespace App\Filament\Resources\Users\Pages;

use App\Exceptions\PosException;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\CurrentShop;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return $this->createUser($data);
        } catch (PosException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            throw new Halt;
        }
    }

    /** @param  array<string, mixed>  $data */
    private function createUser(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);

            // shop_id and is_active are not mass assignable: the shop always comes from the signed-in owner.
            $user->forceFill([
                'shop_id' => app(CurrentShop::class)->getOrFail()->getKey(),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
            UserResource::applyDefaultLocation($user, $data);
            $user->save();

            $user->syncRoles([$data['role']]);

            UserResource::applyAllowedLocations($user, $data);

            return $user;
        });
    }
}
