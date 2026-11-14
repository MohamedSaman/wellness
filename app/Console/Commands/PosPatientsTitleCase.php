<?php

namespace App\Console\Commands;

use App\Models\Patient;
use App\Models\Shop;
use App\Observers\AuditObserver;
use App\Services\PatientService;
use App\Support\CurrentShop;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Puts the patients' names saved before branch 16 in Title Case, as new and
 * edited names now are (PatientService::formatName(); docs/PHARMACY.md,
 * branch 16). One-off. Each name changed is audited as any change of a
 * patient, and the run writes one patients_title_cased entry per shop;
 * --dry-run lists what would change and writes nothing. Bills keep the
 * name they recorded (sales are never edited).
 */
class PosPatientsTitleCase extends Command
{
    public const EVENT = 'patients_title_cased';

    protected $signature = 'pos:patients-title-case {--dry-run : List the names that would change, change nothing}';

    protected $description = 'Save every patient\'s name in Title Case, as new and edited names are';

    public function handle(CurrentShop $currentShop, PatientService $patients, AuditObserver $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');

        foreach (Shop::query()->orderBy('id')->get() as $shop) {
            $changes = $currentShop->runAs($shop, function () use ($patients, $dryRun): array {
                $changes = [];

                Patient::query()->orderBy('id')->chunkById(500, function ($chunk) use ($patients, $dryRun, &$changes): void {
                    foreach ($chunk as $patient) {
                        /** @var Patient $patient */
                        $before = $patient->name;
                        $after = $dryRun ? PatientService::formatName($before) : $patients->retitle($patient);

                        if ($after !== null && $after !== $before) {
                            $changes[] = ['id' => (int) $patient->getKey(), 'from' => $before, 'to' => $after];
                        }
                    }
                });

                return $changes;
            });

            foreach ($changes as $change) {
                $this->line(sprintf('  #%d  %s  →  %s', $change['id'], $change['from'], $change['to']));
            }

            $this->components->info(sprintf('%s: %s %d patient name%s.', $shop->name, $dryRun ? 'would change' : 'changed', count($changes), count($changes) === 1 ? '' : 's'));

            if (! $dryRun && $changes !== []) {
                $currentShop->runAs($shop, fn () => $audit->record(self::EVENT, $shop, [], ['count' => count($changes), 'patient_ids' => array_column($changes, 'id')]));
                Log::info('pos:patients-title-case', ['shop' => $shop->code, 'changed' => count($changes)]);
            }
        }

        return self::SUCCESS;
    }
}
