<?php

namespace App\Concerns;

use App\Models\User;
use App\Support\PasswordRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords: the stricter
     * rule for admin users, the till's for cashiers (App\Support\PasswordRules).
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(?User $user = null): array
    {
        return ['required', 'string', PasswordRules::forUser($user), 'confirmed'];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
