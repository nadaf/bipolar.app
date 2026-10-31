<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Export\RecordExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\ExportController as WebExport;
use Illuminate\Http\Request;

/** GET export/entries?format=csv|pdf&from&to (paciente) e care/patients/{id}/export (concessao ativa). */
class ExportController extends Controller
{
    public function own(Request $request, RecordExport $x)
    {
        $v = $this->input($request);

        return WebExport::file($x->own($request->user(), $v['formato'], $v['de'], $v['ate']), $v['formato']);
    }

    public function care(Request $request, RecordExport $x, int $patientId)
    {
        $v = $this->input($request);
        $out = $x->granted($request->user(), $patientId, $v['formato'], $v['de'], $v['ate']);
        abort_if($out === null, 404);

        return WebExport::file($out, $v['formato']);
    }

    /** @return array{formato: string, de: string, ate: string} */
    private function input(Request $request): array
    {
        $v = $request->validate(['format' => ['required', 'in:csv,pdf'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);

        return ['formato' => $v['format'], 'de' => $v['from'], 'ate' => $v['to']];
    }
}
