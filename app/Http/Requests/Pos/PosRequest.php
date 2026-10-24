<?php

namespace App\Http\Requests\Pos;

use App\Models\Device;
use App\Models\User;
use App\Support\CurrentShop;
use App\Support\Money;
use App\Support\TillSelection;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by the POS endpoints: only users who may check out, and tills must
 * belong to the user's own shop and stand at a location the user may use.
 */
abstract class PosRequest extends FormRequest
{
    /** Money typed by a person: whole or with decimals, never negative. */
    protected const MONEY = 'regex:/^\d{1,10}(\.\d{1,4})?$/';

    private ?Device $till = null;

    /** @return array<string, mixed> */
    abstract public function rules(): array;

    public function authorize(): bool
    {
        return $this->user()?->can('pos.checkout') ?? false;
    }

    /**
     * Requests that use a till list `device` in rules(); when given, it must be
     * a till of the user's shop at a location the user may use.
     */
    public function withValidator(Validator $validator): void
    {
        if (! array_key_exists('device', $this->rules())) {
            return;
        }

        $validator->after(function (Validator $validator): void {
            // A missing required till is already an error; an optional one may be left out.
            if ($validator->errors()->has('device') || ! $this->filled('device')) {
                return;
            }

            $device = $this->device();
            $user = $this->user();

            if ($device === null) {
                $validator->errors()->add('device', 'This till is not registered for your shop or has been disabled.');
            } elseif ($user instanceof User && ($refusal = TillSelection::refusal($device, $user)) !== null) {
                $validator->errors()->add('device', $refusal);
            }
        });
    }

    public function device(): ?Device
    {
        return $this->till ??= TillSelection::find($this->string('device')->toString());
    }

    /** Converts what the cashier typed ("1,250.50") to minor units. */
    protected function toMinor(mixed $amount): int
    {
        return Money::toMinor((string) $amount, (int) app(CurrentShop::class)->getOrFail()->currency_decimals);
    }
}
