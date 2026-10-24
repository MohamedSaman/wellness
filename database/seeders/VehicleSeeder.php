<?php

namespace Database\Seeders;

use App\Enums\VehicleType;
use App\Models\VehicleMake;
use App\Support\InstallProfile;
use Illuminate\Database\Seeder;

/**
 * Starter vehicle list for spare-part fitment. Shared by all shops.
 * Extend it from the admin panel; this only covers common models.
 * Seeds nothing unless the install profile lists it (retail does, pharmacy
 * does not), even when run on its own with db:seed --class.
 */
class VehicleSeeder extends Seeder
{
    public function run(): void
    {
        if (! InstallProfile::current()->seeds(self::class)) {
            return;
        }

        $data = [
            'Bajaj' => [
                ['Pulsar 150', VehicleType::Motorcycle],
                ['Platina 100', VehicleType::Motorcycle],
                ['CT 100', VehicleType::Motorcycle],
                ['Discover 125', VehicleType::Motorcycle],
                ['RE 4S', VehicleType::ThreeWheeler],
            ],
            'Honda' => [
                ['Dio', VehicleType::Scooter],
                ['CB Shine', VehicleType::Motorcycle],
                ['Hornet 160R', VehicleType::Motorcycle],
            ],
            'Yamaha' => [
                ['FZ-S', VehicleType::Motorcycle],
                ['Ray ZR', VehicleType::Scooter],
            ],
            'TVS' => [
                ['Apache RTR 160', VehicleType::Motorcycle],
                ['Ntorq 125', VehicleType::Scooter],
                ['King', VehicleType::ThreeWheeler],
            ],
            'Hero' => [
                ['Splendor Plus', VehicleType::Motorcycle],
                ['Pleasure+', VehicleType::Scooter],
            ],
            'Suzuki' => [
                ['Gixxer', VehicleType::Motorcycle],
                ['Wagon R', VehicleType::Car],
            ],
            'Toyota' => [
                ['Corolla', VehicleType::Car],
                ['Hilux', VehicleType::Van],
            ],
        ];

        foreach ($data as $makeName => $models) {
            $make = VehicleMake::query()->firstOrCreate(['name' => $makeName]);

            foreach ($models as [$name, $type]) {
                $make->models()->firstOrCreate(['name' => $name], ['type' => $type]);
            }
        }
    }
}
