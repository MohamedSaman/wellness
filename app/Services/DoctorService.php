<?php

namespace App\Services;

use App\Exceptions\PosException;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The clinic's doctors (docs/PHARMACY.md, branch 18), paid in cash from the
 * tills: a name (unique in the shop, case aside), active or not, an
 * optional phone and notes. doctors.manage (the owner). Audited (Doctor is
 * an audited model). Never deleted: deactivate a doctor who has left, and
 * their payments keep their name.
 */
class DoctorService
{
    /** @param array<string, mixed> $data */
    public function create(array $data, User $user): Doctor
    {
        $this->assertMay($user);

        return Doctor::query()->create($this->clean($data));
    }

    /** @param array<string, mixed> $data */
    public function update(Doctor $doctor, array $data, User $user): Doctor
    {
        $this->assertMay($user);
        $doctor->update($this->clean($data, $doctor));

        return $doctor;
    }

    private function assertMay(User $user): void
    {
        if (! $user->can('doctors.manage')) {
            throw new PosException('You are not allowed to manage doctors.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, phone: string|null, notes: string|null, is_active: bool}
     */
    private function clean(array $data, ?Doctor $doctor = null): array
    {
        $name = Str::squish((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new PosException("Enter the doctor's name.");
        }

        if (mb_strlen($name) > 150) {
            throw new PosException('A name can have at most 150 characters.');
        }

        $taken = Doctor::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($doctor !== null, fn ($query) => $query->whereKeyNot($doctor->getKey()))
            ->exists();

        if ($taken) {
            throw new PosException("{$name} is already on the list.");
        }

        $phone = trim((string) ($data['phone'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));

        return [
            'name' => $name,
            'phone' => $phone === '' ? null : mb_substr($phone, 0, 50),
            'notes' => $notes === '' ? null : $notes,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
