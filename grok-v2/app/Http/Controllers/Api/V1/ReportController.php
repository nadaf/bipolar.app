<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reports\ReportBuilder;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function summary(Request $request, ReportBuilder $reports)
    {
        $v = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        [$from, $to] = ReportBuilder::range($request->user(), 'personalizado', $v['from'], $v['to']);
        $r = $reports->build($request->user(), $from, $to, 255);
        unset($r['days']);

        return $r;
    }
}
