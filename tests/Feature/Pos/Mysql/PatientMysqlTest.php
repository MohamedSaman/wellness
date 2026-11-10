<?php

namespace Tests\Feature\Pos\Mysql;

use App\Enums\PaymentMethod;
use App\Models\Patient;
use App\Models\Sale;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PatientInput;
use App\Services\Data\PaymentInput;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * A new patient named by several tills at once (docs/PHARMACY.md, branch
 * 13): a till's retries and another till's sale to the same patient it
 * synced reach the server together. The unique uuid makes one record;
 * every sale links to it and none fails.
 */
#[Group('mysql')]
class PatientMysqlTest extends MysqlTestCase
{
    use WorksWithCheckout;

    public function test_racing_sales_create_one_new_patient(): void
    {
        $panadol = $this->medicine();
        $this->receive($panadol, 'B1', '12/2029', 100);
        $patient = (string) Str::uuid();

        $outcomes = $this->race(4, function () use ($panadol, $patient): void {
            app(CheckoutService::class)->checkout(new CheckoutData(
                device: $this->till,
                cashier: $this->cashier,
                lines: [new CartLine($panadol->getKey(), 1)],
                payments: [new PaymentInput(PaymentMethod::Cash, 100_000)],
                uuid: (string) Str::uuid(),
                patient: new PatientInput($patient, 'Nimal Perera', 408),
            ));
        });

        $this->assertSame(['ok', 'ok', 'ok', 'ok'], $outcomes);
        $record = Patient::query()->sole();
        $this->assertSame($patient, $record->uuid);
        $this->assertSame(4, Sale::query()->where('patient_id', $record->getKey())->count());
        $this->assertSame('1992-10-18', $record->birth_date?->toDateString());
    }
}
