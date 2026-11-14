<?php

namespace Tests\Feature\Pos;

use App\Console\Commands\PosPatientsTitleCase;
use App\Enums\PaymentMethod;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Services\CheckoutService;
use App\Services\Data\CartLine;
use App\Services\Data\CheckoutData;
use App\Services\Data\PatientInput;
use App\Services\Data\PaymentInput;
use App\Services\OfflineSaleSyncService;
use App\Services\PatientService;
use Illuminate\Support\Str;
use Tests\Feature\Pos\Concerns\WorksWithCheckout;

/**
 * Patients' names saved in Title Case (docs/PHARMACY.md, branch 16): on a
 * new patient online or from an offline sale, on an edit, and for the
 * names saved before with pos:patients-title-case (dry run first). A word
 * typed in mixed case is kept; an offline sale keeps the name it printed;
 * search still ignores case.
 */
class PatientTitleCaseTest extends PharmacyTestCase
{
    use WorksWithCheckout;

    private ProductVariant $labTest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->labTest = $this->openPriceItem('CBS', 50_000, 100_000);
    }

    public function test_the_rule(): void
    {
        $this->assertSame('Nimal Perera', PatientService::formatName('nimal PERERA'));
        $this->assertSame('Anne-Marie O\'Brien', PatientService::formatName("  anne-marie   o'brien "));
        $this->assertSame('A.B. Perera', PatientService::formatName('a.b. perera'));
        $this->assertSame('K. M. Silva', PatientService::formatName('K. M. SILVA'));
        $this->assertSame('McDonald DeSilva', PatientService::formatName('McDonald DeSilva'));
        $this->assertSame('Émile Zola', PatientService::formatName('ÉMILE zola'));
        $this->assertSame('Baby Of Kumari 2', PatientService::formatName('baby of kumari 2'));
        $this->assertNull(PatientService::formatName('   '));
    }

    public function test_a_new_patient_online_is_saved_and_printed_in_title_case(): void
    {
        $sale = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($this->labTest->getKey(), 1)],
            payments: [new PaymentInput(PaymentMethod::Cash, 100_000)],
            uuid: (string) Str::uuid(),
            patient: new PatientInput((string) Str::uuid(), 'nimal PERERA', 408),
        ));

        $this->assertSame('Nimal Perera', $sale->patient?->name);
        $this->assertSame('Nimal Perera', $sale->customer_name);
    }

    public function test_a_patient_added_offline_is_saved_in_title_case_and_the_sale_keeps_what_it_printed(): void
    {
        $patient = ['uuid' => (string) Str::uuid(), 'name' => 'kamala PERERA', 'age_months' => 732];
        app(OfflineSaleSyncService::class)->reserveInvoiceBlock($this->till);
        $this->app['auth']->forgetGuards();

        $this->withToken($this->till->issueSyncToken('Counter browser')->plainTextToken)
            ->postJson(route('pos.sync.sales'), ['device_time' => now()->toIso8601String(), 'sales' => [[
                'uuid' => (string) Str::uuid(),
                'invoice_no' => 'T1-900001',
                'sold_at' => now()->subMinutes(20)->toIso8601String(),
                'cashier_id' => $this->cashier->getKey(),
                'lines' => [['variant_id' => $this->labTest->getKey(), 'quantity' => 1, 'unit_price' => 50_000]],
                'payments' => [['method' => 'cash', 'amount' => 50_000]],
                'grand_total' => 50_000,
                'patient' => $patient,
            ]]])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'synced');

        $this->assertSame('Kamala Perera', Patient::query()->where('uuid', $patient['uuid'])->value('name'));
        $this->assertSame('kamala PERERA', Sale::query()->sole()->customer_name);
    }

    public function test_an_edited_name_is_saved_in_title_case(): void
    {
        $patient = Patient::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Sunil']);

        app(PatientService::class)->update($patient, $this->owner, ['name' => 'sunil FERNANDO-mcdonald']);
        $this->assertSame('Sunil Fernando-Mcdonald', $patient->fresh()?->name);

        app(PatientService::class)->update($patient, $this->owner, ['name' => 'Sunil McDonald']);
        $this->assertSame('Sunil McDonald', $patient->fresh()?->name);
    }

    public function test_search_ignores_case(): void
    {
        Patient::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Nimal Perera']);

        foreach (['NIMAL', 'nimal', 'pErErA nim'] as $term) {
            $this->assertSame(['Nimal Perera'], Patient::query()->search($term)->pluck('name')->all(), $term);
            $this->assertSame('Nimal Perera', app(PatientService::class)->search($term)[0]['name'] ?? null, $term);
        }
    }

    public function test_the_command_lists_first_with_dry_run_then_changes_and_logs(): void
    {
        $lower = Patient::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'nimal perera']);
        $upper = Patient::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'KAMALA SILVA']);
        $right = Patient::query()->create(['uuid' => (string) Str::uuid(), 'name' => 'Ian McDonald']);
        $sale = app(CheckoutService::class)->checkout(new CheckoutData(
            device: $this->till,
            cashier: $this->cashier,
            lines: [new CartLine($this->labTest->getKey(), 1)],
            payments: [new PaymentInput(PaymentMethod::Cash, 100_000)],
            uuid: (string) Str::uuid(),
            patient: new PatientInput($lower->uuid),
        ));
        $audits = AuditLog::query()->count();

        $this->artisan('pos:patients-title-case', ['--dry-run' => true])
            ->expectsOutputToContain('nimal perera  →  Nimal Perera')
            ->expectsOutputToContain('KAMALA SILVA  →  Kamala Silva')
            ->expectsOutputToContain('would change 2 patient names')
            ->assertSuccessful();

        $this->assertSame('nimal perera', $lower->fresh()?->name);
        $this->assertSame('KAMALA SILVA', $upper->fresh()?->name);
        $this->assertSame($audits, AuditLog::query()->count());

        $this->artisan('pos:patients-title-case')
            ->expectsOutputToContain('changed 2 patient names')
            ->assertSuccessful();

        $this->assertSame('Nimal Perera', $lower->fresh()?->name);
        $this->assertSame('Kamala Silva', $upper->fresh()?->name);
        $this->assertSame('Ian McDonald', $right->fresh()?->name);
        // Bills keep the name they recorded.
        $this->assertSame('nimal perera', $sale->fresh()?->customer_name);

        $changed = AuditLog::query()->where('auditable_type', $lower->getMorphClass())->where('auditable_id', $lower->getKey())->where('event', 'updated')->sole();
        $this->assertSame('nimal perera', $changed->old_values['name']);
        $this->assertSame('Nimal Perera', $changed->new_values['name']);

        $summary = AuditLog::query()->where('event', PosPatientsTitleCase::EVENT)->sole();
        $this->assertSame($this->shop->getKey(), $summary->shop_id);
        $this->assertSame(2, $summary->new_values['count']);
        $this->assertEqualsCanonicalizing([$lower->getKey(), $upper->getKey()], $summary->new_values['patient_ids']);

        $this->artisan('pos:patients-title-case')->expectsOutputToContain('changed 0 patient names')->assertSuccessful();
        $this->assertSame(1, AuditLog::query()->where('event', PosPatientsTitleCase::EVENT)->count());
    }
}
