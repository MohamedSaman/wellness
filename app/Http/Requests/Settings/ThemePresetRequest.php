<?php

namespace App\Http\Requests\Settings;

use App\Support\ThemePresets;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Settings → Appearance → Clinic colour theme: the owner only, one of ThemePresets' keys. */
class ThemePresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('change-theme');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'preset' => ['required', 'string', Rule::in(ThemePresets::keys())],
        ];
    }
}
