<?php

namespace App\Http\Controllers\Web;

use App\Domain\Account\StepUp;
use App\Domain\Day\Blocks;
use App\Domain\Day\DayBlockService;
use App\Domain\Mood\Period;
use App\Http\Controllers\Controller;
use App\Models\DayBlock;
use App\Models\MoodEntry;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Historico: calendario do mes com pontos por periodo + detalhe do dia. */
class HistoryController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        $today = Dates::today($u->timezone);
        $mes = (string) $request->query('mes', $today->format('Y-m'));
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) !== 1) {
            $mes = $today->format('Y-m');
        }
        $first = CarbonImmutable::parse($mes.'-01');
        $last = $first->endOfMonth();
        $entries = MoodEntry::query()->ownedBy($u)->whereBetween('entry_date', [$first->toDateString(), $last->toDateString()])->get();
        $blockDays = DayBlock::query()->ownedBy($u)->whereBetween('entry_date', [$first->toDateString(), $last->toDateString()])
            ->get(['entry_date'])->map(fn (DayBlock $b) => $b->entry_date->format('Y-m-d'))->unique()->flip();
        $map = [];
        foreach ($entries as $e) {
            $map[$e->entry_date->format('Y-m-d')][$e->period->value] = $e;
        }
        $weeks = [];
        $cursor = $first->subDays($first->dayOfWeek);
        while ($cursor->lte($last)) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $week[] = $cursor->month === $first->month ? $cursor : null;
                $cursor = $cursor->addDay();
            }
            $weeks[] = $week;
        }
        $list = collect($map)->keys()->merge($blockDays->keys())->unique()->sortDesc()->values();

        return view('history.index', ['first' => $first, 'weeks' => $weeks, 'map' => $map, 'blockDays' => $blockDays, 'today' => $today,
            'prev' => $first->subMonth()->format('Y-m'), 'next' => $first->addMonth()->lte($today) ? $first->addMonth()->format('Y-m') : null,
            'list' => $list, 'periods' => Period::cases()]);
    }

    public function day(Request $request, DayBlockService $svc, StepUp $stepUp, string $date)
    {
        abort_unless(Dates::valid($date), 404);
        $d = $svc->day($request->user(), $date, $stepUp->active($request));
        $entries = $d['entries']->keyBy(fn (MoodEntry $e) => $e->period->value);
        $blocks = $d['blocks']->sortBy(fn (DayBlock $b) => array_search($b->block, array_keys(Blocks::SPEC), true))->values();

        return view('history.day', ['date' => $date, 'entries' => $entries, 'blocks' => $blocks, 'locked' => $d['locked'], 'periods' => Period::cases(),
            'title' => Dates::long($date)]);
    }
}
