<?php

namespace App\Http\Controllers\Web;

use App\Domain\Account\StepUp;
use App\Domain\Day\Blocks;
use App\Domain\Day\DayBlockService;
use App\Domain\Day\StepUpRequired;
use App\Domain\Mood\MoodEntryService;
use App\Domain\Mood\Period;
use App\Domain\Mood\Scales;
use App\Http\Controllers\Controller;
use App\Http\Requests\MoodEntryRequest;
use App\Models\DayBlock;
use App\Models\MoodEntry;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Tela Registrar: periodo travado, cartoes que salvam sozinhos, "Salvar tudo". */
class RegistrarController extends Controller
{
    /** Cartoes de humor (gravam em mood_entries). */
    public const MOOD_CARDS = [
        'humor' => ['title' => 'Humor', 'fields' => ['mood']],
        'energia' => ['title' => 'Energia', 'fields' => ['energy']],
        'ansiedade' => ['title' => 'Ansiedade e irritabilidade', 'fields' => ['anxiety', 'irritability']],
    ];

    public function index(Request $request, DayBlockService $svc, StepUp $stepUp)
    {
        $user = $request->user();
        $now = CarbonImmutable::now($user->timezone);
        $date = Dates::valid($request->query('data')) ? (string) $request->query('data') : $now->toDateString();
        if ($date > $now->toDateString()) {
            $date = $now->toDateString();
        }
        $period = Period::tryFrom((string) $request->query('periodo')) ?? ($date === $now->toDateString() ? Period::forHour($now->hour) : Period::Noite);
        if ($date === $now->toDateString() && $period->startsAtHour() > $now->hour) {
            $period = Period::forHour($now->hour);
        }
        $unlocked = $stepUp->active($request);
        $day = $svc->day($user, $date, true);
        $entry = $day['entries']->first(fn (MoodEntry $e) => $e->period === $period);
        $blocks = [];
        foreach ($day['blocks'] as $b) {
            /** @var DayBlock $b */
            if ($b->period === 'dia' || $b->period === $period->value) {
                $blocks[$b->block] = $b;
            }
        }

        return view('registrar.index', [
            'date' => $date, 'period' => $period, 'entry' => $entry, 'blocks' => $blocks, 'unlocked' => $unlocked,
            'enabled' => Blocks::enabledKeys($user->modules), 'lockLabel' => Dates::lockLabel($date, $period, $user->timezone),
            'today' => $now->toDateString(), 'nowHour' => $now->hour, 'periods' => Period::cases(),
            'moodCards' => self::MOOD_CARDS, 'scales' => ['mood' => Scales::MOOD, 'energy' => Scales::ENERGY, 'intensity' => Scales::INTENSITY],
            'periodsDone' => $day['entries']->map(fn (MoodEntry $e) => $e->period->value)->all(),
        ]);
    }

    public function saveMood(Request $request, MoodEntryService $svc, string $card)
    {
        abort_unless(isset(self::MOOD_CARDS[$card]), 404);
        $fields = self::MOOD_CARDS[$card]['fields'];
        $rules = array_intersect_key((new MoodEntryRequest)->rules(), array_flip([...$fields, 'entry_date', 'period', 'version']));
        foreach ($fields as $f) {
            $rules[$f] = array_values(array_filter($rules[$f], fn ($r) => ! is_string($r) || ! str_starts_with($r, 'required_without_all')));
        }
        // o primeiro campo do cartao e obrigatorio aqui (o cartao salva sozinho)
        $rules[$fields[0]] = ['required', ...array_values(array_filter($rules[$fields[0]], fn ($r) => $r !== 'nullable'))];
        $v = Validator::make($request->all(), $rules, ['required' => 'Escolha uma opção antes de salvar.']);
        if ($v->fails()) {
            return $this->result('erro', $v->errors()->first(), 422, $card);
        }
        $data = collect($v->validated())->except('version')->all();
        try {
            $entry = $svc->upsert($request->user(), $data, $request->integer('version') ?: null);
        } catch (ConflictHttpException) {
            return $this->result('conflito', 'Este período foi alterado em outro aparelho. Recarregue a página para ver a versão atual antes de salvar de novo.', 409, $card);
        } catch (ValidationException $e) {
            return $this->result('erro', collect($e->errors())->flatten()->first(), 422, $card);
        }
        $parts = [];
        foreach ($fields as $f) {
            $val = $entry->getAttribute($f);
            $parts[] = match ($f) {
                'mood' => 'Humor: '.(Scales::label(Scales::MOOD, $val) ?? '—'),
                'energy' => 'Energia: '.(Scales::label(Scales::ENERGY, $val) ?? '—'),
                'anxiety' => 'Ansiedade: '.($val === null ? '—' : Scales::INTENSITY[$val]),
                'irritability' => 'Irritabilidade: '.($val === null ? '—' : Scales::INTENSITY[$val]),
            };
        }

        return view('registrar._result', ['tipo' => 'ok', 'msg' => 'Salvo: '.implode(' · ', $parts).'.', 'key' => $card,
            'summary' => implode(' · ', $parts), 'moodVersion' => $entry->version, 'version' => null]);
    }

    public function saveBlock(Request $request, DayBlockService $svc, StepUp $stepUp, string $block)
    {
        abort_unless(Blocks::exists($block), 404);
        $v = Validator::make($request->all(), ['entry_date' => ['required', 'date_format:Y-m-d'], 'period' => ['required', 'in:manha,tarde,noite'],
            'version' => ['nullable', 'integer', 'min:1'], 'data' => ['sometimes', 'array']] + Blocks::rules($block));
        if ($v->fails()) {
            return $this->result('erro', $v->errors()->first(), 422, $block);
        }
        try {
            $row = $svc->save($request->user(), (string) $request->input('entry_date'), (string) $request->input('period'), $block,
                (array) $request->input('data', []), $request->integer('version') ?: null, $stepUp->active($request));
        } catch (ConflictHttpException) {
            return $this->result('conflito', 'Este bloco foi alterado em outro aparelho. Recarregue a página para ver a versão atual.', 409, $block);
        } catch (StepUpRequired) {
            return $this->result('bloqueado', 'Bloco privado: desbloqueie com seu PIN ou senha para salvar. Nada foi enviado.', 423, $block);
        } catch (ValidationException $e) {
            return $this->result('erro', collect($e->errors())->flatten()->first(), 422, $block);
        }
        $summary = Blocks::summary($block, $row->data);
        $msg = 'Salvo'.($summary !== '' ? ': '.$summary : ' (em branco)').'.';
        $extra = null;
        if ($block === 'morte' && (int) ($row->data['intensidade'] ?? 0) >= 2) {
            $extra = 'Obrigado por registrar. Se estiver difícil agora, ligue para o CVV 188 (24h) ou SAMU 192. Ninguém foi avisado automaticamente.';
        }

        return view('registrar._result', ['tipo' => 'ok', 'msg' => $msg, 'extra' => $extra, 'key' => $block, 'summary' => $summary ?: 'Salvo',
            'version' => $row->version, 'moodVersion' => null]);
    }

    private function result(string $tipo, string $msg, int $status, string $key)
    {
        return response()->view('registrar._result', ['tipo' => $tipo, 'msg' => $msg, 'key' => $key, 'summary' => null, 'version' => null, 'moodVersion' => null], $status);
    }
}
