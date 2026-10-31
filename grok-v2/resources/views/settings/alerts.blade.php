@extends('layouts.app')
@section('title', 'Alertas e lembretes — Bipolar')
@section('content')
<h1 class="text-xl font-semibold mb-4">Alertas e lembretes</h1>
<h2 class="text-lg font-semibold mb-1">Limiares de sinal</h2>
<p class="mb-4 hint">Escolha a partir de qual nível um registro aparece como <strong>sinal</strong> nos seus relatórios.
  Baixo conta como sinal <strong>depressivo</strong> (▼); alto conta como sinal de <strong>mania</strong> (▲).
  Nada é enviado a ninguém automaticamente. Padrão: −2 e +2.</p>
<form class="card space-y-5" method="post" action="{{ route('alerts.save') }}"
      hx-post="{{ route('alerts.save') }}" hx-target="#alert-status" hx-swap="innerHTML">
  @csrf
  @foreach([['mood', 'Humor', $mood], ['energy', 'Energia', $energy]] as [$k, $label, $scale])
  <fieldset>
    <legend class="label">{{ $label }}</legend>
    <div class="grid gap-3 sm:grid-cols-2">
      <div>
        <label for="{{ $k }}_low" class="label">▼ Sinal baixo a partir de</label>
        <select id="{{ $k }}_low" name="{{ $k }}_low" class="input">
          @foreach([-1, -2, -3] as $v)
            @php $i = $scale[$v]; $txt = is_array($i) ? $i[0] : $i; @endphp
            <option value="{{ $v }}" @selected($t[$k.'_low'] === $v)>{{ $v }} · {{ $txt }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label for="{{ $k }}_high" class="label">▲ Sinal alto a partir de</label>
        <select id="{{ $k }}_high" name="{{ $k }}_high" class="input">
          @foreach([1, 2, 3] as $v)
            @php $i = $scale[$v]; $txt = is_array($i) ? $i[0] : $i; @endphp
            <option value="{{ $v }}" @selected($t[$k.'_high'] === $v)>+{{ $v }} · {{ $txt }}</option>
          @endforeach
        </select>
      </div>
    </div>
  </fieldset>
  @endforeach
  <button class="btn-primary w-full sm:w-auto">Salvar alertas</button>
  <div id="alert-status" role="status" aria-live="polite"></div>
</form>
<section class="mt-8" aria-labelledby="lem-t">
  <h2 id="lem-t" class="text-lg font-semibold mb-1">Lembretes de registro</h2>
  <p class="hint mb-3">Marque em quais dias e períodos quer ser lembrado(a). Cada quadrado é um lembrete.</p>
  <form method="post" action="{{ route('alerts.reminders') }}" class="card space-y-5">
    @csrf
    <div class="overflow-x-auto">
    <table class="grid-rem">
      <caption class="sr-only">Grade de lembretes por dia da semana e período</caption>
      <thead><tr><th scope="col"><span class="sr-only">Período</span></th>
        @foreach(\App\Domain\Reminders\ReminderService::WEEKDAYS as $wi => $w)<th scope="col" class="text-sm"><abbr title="{{ \App\Domain\Reminders\ReminderService::WEEKDAYS_LONG[$wi] }}">{{ $w }}</abbr></th>@endforeach
      </tr></thead>
      <tbody>
      @foreach($periods as $p)
        <tr><th scope="row" class="text-sm text-left pe-2">{{ $p->label() }}</th>
        @foreach(\App\Domain\Reminders\ReminderService::WEEKDAYS_LONG as $wi => $wl)
          <td><label class="rem-cell"><input type="checkbox" class="sr-only" name="cel[{{ $wi }}-{{ $p->value }}]" value="1" @checked(isset($cells[$wi.'-'.$p->value]))>
            <span class="sr-only">{{ $wl }}, {{ mb_strtolower($p->label()) }}</span><span class="on" aria-hidden="true">✓</span><span class="off" aria-hidden="true">–</span></label></td>
        @endforeach
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
    <fieldset>
      <legend class="label">Horário de cada período</legend>
      <div class="flex flex-wrap gap-3">
      @foreach($periods as $p)
        <div><label for="hora-{{ $p->value }}" class="label font-normal">{{ $p->label() }}</label>
          <input id="hora-{{ $p->value }}" type="time" name="hora[{{ $p->value }}]" value="{{ $times[$p->value] }}" class="input input-sm"></div>
      @endforeach
      </div>
    </fieldset>
    <div>
      <label for="canal" class="label">Como avisar</label>
      <select id="canal" name="canal" class="input sm:w-72">
        @foreach(\App\Domain\Reminders\ReminderService::CHANNELS as $ck => $cl)<option value="{{ $ck }}" @selected($channel === $ck)>{{ $cl }}</option>@endforeach
      </select>
      <p class="hint mt-1">Beta: a grade fica salva; o envio dos lembretes ainda está sendo ligado.</p>
    </div>
    <button class="btn-primary w-full sm:w-auto">Salvar lembretes</button>
  </form>
</section>
<p class="mt-4 hint">Pensamentos de morte nunca geram aviso automático a terceiros. Se precisar agora: CVV 188 · SAMU 192.</p>
@endsection
