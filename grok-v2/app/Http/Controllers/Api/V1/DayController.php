<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Account\StepUp;
use App\Domain\Day\Blocks;
use App\Domain\Day\DayBlockService;
use App\Http\Controllers\Controller;
use App\Http\Resources\MoodEntryResource;
use App\Models\DayBlock;
use Illuminate\Http\Request;

/** Dia completo (humor por periodo + blocos) e gravacao de um bloco. */
class DayController extends Controller
{
    public function show(Request $request, DayBlockService $svc, StepUp $stepUp, string $date)
    {
        $this->assertDate($date);
        $d = $svc->day($request->user(), $date, $stepUp->active($request));

        return ['data' => [
            'date' => $date,
            'entries' => MoodEntryResource::collection($d['entries'])->resolve($request),
            'blocks' => $d['blocks']->map(fn (DayBlock $b) => self::block($b))->values(),
            'locked' => $d['locked'],
        ]];
    }

    public function save(Request $request, DayBlockService $svc, StepUp $stepUp, string $date, string $block)
    {
        $this->assertDate($date);
        abort_unless(Blocks::exists($block), 404);
        $request->validate(['period' => ['nullable', 'in:manha,tarde,noite'], 'version' => ['nullable', 'integer', 'min:1'],
            'data' => ['present', 'array']] + Blocks::rules($block));
        $row = $svc->save($request->user(), $date, (string) $request->input('period', 'dia'), $block,
            (array) $request->input('data', []), $request->integer('version') ?: null, $stepUp->active($request));
        $body = ['data' => self::block($row)];
        if ($block === 'morte') {
            $body['crisis_resources'] = Blocks::CRISIS;
        }

        return response()->json($body, $row->wasRecentlyCreated ? 201 : 200);
    }

    /** @return array<string, mixed> */
    public static function block(DayBlock $b): array
    {
        return ['block' => $b->block, 'period' => $b->period, 'version' => $b->version, 'data' => $b->data,
            'summary' => Blocks::summary($b->block, $b->data), 'updated_at' => $b->updated_at?->toIso8601String()];
    }

    private function assertDate(string $date): void
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)), 404);
    }
}
