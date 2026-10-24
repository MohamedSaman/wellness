<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Reports\Export\ReportExporter;
use App\Filament\Pages\Reports\ReportPage;
use App\Filament\Pages\Reports\ReportRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The print page of a report (docs/PHARMACY.md §9, branch 11): the report
 * named by its slug, with the filters of the page it was opened from (its
 * query string, and for a table report the columns it showed), laid out
 * for A4 by the same document as the PDF. ?print=1 opens the print dialog.
 * Whoever may open the report may print it (the page's canAccess()).
 */
class ReportPrintController extends Controller
{
    public function __invoke(Request $request, string $report, ReportExporter $exporter): View
    {
        $class = ReportRegistry::find($report) ?? abort(404);
        abort_unless($class::canAccess(), 403);

        $page = app($class);

        if ($page instanceof ReportPage && $request->filled('columns')) {
            $page->printColumns = array_values(array_filter(explode(',', (string) $request->query('columns'))));
        }

        $page->applyQuery($request->query());

        return $exporter->html($page->document(), autoPrint: $request->boolean('print'));
    }
}
