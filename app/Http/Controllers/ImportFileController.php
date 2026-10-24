<?php

namespace App\Http\Controllers;

use App\Enums\ImportType;
use App\Exceptions\PosException;
use App\Models\ImportRun;
use App\Models\User;
use App\Services\Import\ImportService;
use App\Support\CurrentShop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads for imports (imports.manage): a template made for this shop
 * (its locations, modules and suppliers), and for a run in the log its
 * uploaded file and error report. Runs are found through the tenant scope,
 * so another shop's run is a 404.
 */
class ImportFileController extends Controller
{
    public function template(Request $request, string $type, ImportService $imports, CurrentShop $currentShop): BinaryFileResponse
    {
        $user = $this->importer($request);
        $importType = ImportType::tryFrom($type) ?? abort(404);

        try {
            $path = $imports->template($importType, $user);
        } catch (PosException) {
            abort(404);
        }

        return response()
            ->download($path, $importType->templateName($currentShop->getOrFail()).'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
            ->deleteFileAfterSend();
    }

    public function file(Request $request, ImportRun $run): StreamedResponse
    {
        $this->importer($request);

        return Storage::disk(ImportService::DISK)->download($run->file_path, $run->file_name);
    }

    public function report(Request $request, ImportRun $run): StreamedResponse
    {
        $this->importer($request);
        abort_if($run->error_report_path === null || ! Storage::disk(ImportService::DISK)->exists($run->error_report_path), 404);

        $name = Str::beforeLast($run->file_name, '.').' - errors.xlsx';

        return Storage::disk(ImportService::DISK)->download($run->error_report_path, $name);
    }

    private function importer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can('imports.manage'), 403);

        return $user;
    }
}
