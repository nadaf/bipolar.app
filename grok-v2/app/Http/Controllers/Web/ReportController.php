<?php

namespace App\Http\Controllers\Web;

use App\Domain\Account\StepUp;
use App\Domain\Reports\ReportBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index(Request $request, ReportBuilder $reports, StepUp $stepUp)
    {
        $u = $request->user();
        $preset = (string) $request->query('periodo', '30');
        [$from, $to] = ReportBuilder::range($u, $preset, $request->query('de'), $request->query('ate'));
        $r = $reports->build($u, $from, $to, 255, $stepUp->active($request));

        return view('reports.index', ['r' => $r, 'preset' => in_array($preset, ['7', '30', '90', 'personalizado'], true) ? $preset : '30',
            'who' => $u->name, 'readOnly' => false]);
    }
}
