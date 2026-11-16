<?php

namespace App\Enums;

/**
 * Cash taken out of or put into a till's drawer during its open shift
 * (docs/PHARMACY.md, branch 18). Paid out and doctor payments come off the
 * shift's expected cash; paid in adds to it.
 */
enum CashMovementType: string
{
    case PaidOut = 'paid_out';
    case PaidIn = 'paid_in';
    case DoctorPayment = 'doctor_payment';

    public function label(): string
    {
        return match ($this) {
            self::PaidOut => 'Paid out',
            self::PaidIn => 'Paid in',
            self::DoctorPayment => 'Doctor payment',
        };
    }

    /** What the slip prints at its top. */
    public function heading(): string
    {
        return match ($this) {
            self::PaidOut => 'PAID OUT',
            self::PaidIn => 'PAID IN',
            self::DoctorPayment => 'DOCTOR PAYMENT',
        };
    }

    /** +1 into the drawer, -1 out of it. */
    public function direction(): int
    {
        return $this === self::PaidIn ? 1 : -1;
    }

    /**
     * The categories a movement of this type may take (a doctor payment has none).
     *
     * @return list<CashMovementCategory>
     */
    public function categories(): array
    {
        return match ($this) {
            self::PaidOut => [CashMovementCategory::RefundDifference, CashMovementCategory::Expense, CashMovementCategory::Other],
            self::PaidIn => [CashMovementCategory::FloatTopUp, CashMovementCategory::Other],
            self::DoctorPayment => [],
        };
    }

    /** @return array<string, string> value => label, for a select */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])->all();
    }
}
