<?php

namespace App\Http\Controllers;

use App\Filament\Resources\LabelPrints\LabelPrintResource;
use App\Models\LabelPrint;
use App\Models\LabelTemplate;
use App\Services\Labels\LabelService;
use App\Support\CurrentShop;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * A print of labels for the browser's print dialog (?print=1 opens it): each
 * page exactly the template's label (a roll) or sheet, so any label printer
 * driver prints it as it is. On screen it is the print preview, with the
 * template to use, where to start on a part-used sheet (?start=), and what
 * could not be printed. Route model binding is tenant-scoped, so another
 * shop's print is a 404; the policy decides who may open it.
 */
class LabelPrintController extends Controller
{
    public function __invoke(Request $request, LabelPrint $labelPrint, LabelService $labels, CurrentShop $currentShop): View
    {
        abort_unless($request->user()?->can('view', $labelPrint) ?? false, 403);

        $template = $request->filled('template')
            ? LabelTemplate::query()->find($request->integer('template'))
            : null;

        // Where to start on a part-used sheet: label 1 is top left.
        $skip = max(0, $request->integer('start', 1) - 1);
        $rendered = $labels->render($labelPrint, $template, $skip);
        $back = (string) $request->query('back', '');

        return view('labels.print', [
            ...$rendered,
            'shop' => $currentShop->getOrFail(),
            'print' => $labelPrint,
            'templates' => $labels->templateOptions(),
            'skip' => min($skip, $rendered['template']->perPage() - 1),
            'back' => $back !== '' && str_starts_with($back, url('/').'/') ? $back : LabelPrintResource::getUrl('index'),
            'autoPrint' => $request->boolean('print'),
        ]);
    }
}
