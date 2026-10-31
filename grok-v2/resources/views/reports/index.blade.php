@extends('layouts.app')
@section('title', ($readOnly ? 'Relatório de '.$who : 'Relatórios').' — Bipolar')
@section('content')
@php
  use App\Domain\Mood\Scales;
  $fmt = fn ($d) => substr($d, 8, 2).'/'.substr($d, 5, 2).'/'.substr($d, 0, 4);
  $sig = ['depressivo' => ['Depressivo', '▼', 'badge-bad'], 'maniaco' => ['Mania/hipomania', '▲', 'badge-warn'], 'misto' => ['Misto', '◆', 'badge-warn'], 'neutro' => ['Sem sinal', '●', 'badge-ok']];
  $base = $readOnly ? route('care.show', $r['grant']->patient_id) : route('reports');
@endphp
@if($readOnly)<p class="no-print"><a href="{{ route('care.index') }}">‹ Pacientes</a></p>@endif
<div class="flex flex-wrap items-start justify-between gap-3">
  <div>
    <h1 class="text-xl font-semibold">{{ $readOnly ? 'Relatório de '.$who : 'Relatórios' }}</h1>
    <p class="hint">{{ $fmt($r['from']) }} a {{ $fmt($r['to']) }} · {{ $r['days_recorded'] }} dia(s) com registro</p>
    @if($readOnly)<p class="info mt-2 text-sm">Somente leitura. Categorias liberadas: {{ implode(', ', $granted) }}. Seu acesso fica registrado para o(a) paciente.</p>@endif
  </div>
  <div x-data="imprimir" class="no-print"><button type="button" class="btn-secondary" x-on:click="go">@include('partials.icon', ['name' => 'impressora']) Imprimir</button></div>
</div>
<p class="print-only">Bipolar — relatório de {{ $who }} · gerado em {{ now()->format('d/m/Y') }}</p>

<form method="get" action="{{ $base }}" class="card mt-4 no-print" aria-label="Filtrar período">
  <fieldset>
    <legend class="label">Período</legend>
    <div class="flex flex-wrap gap-2">
      @foreach(['7' => '7 dias', '30' => '30 dias', '90' => '90 dias', 'personalizado' => 'Personalizado'] as $k => $l)
        <label class="period-tab cursor-pointer has-checked:bg-blue-800 has-checked:text-white dark:has-checked:bg-blue-300 dark:has-checked:text-slate-950">
          <input type="radio" name="periodo" value="{{ $k }}" class="sr-only" @checked($preset === $k)> {{ $l }}</label>
      @endforeach
    </div>
  </fieldset>
  <div class="mt-3 flex flex-wrap items-end gap-3">
    <div><label for="de" class="label">De</label><input id="de" type="date" name="de" value="{{ $r['from'] }}" class="input"></div>
    <div><label for="ate" class="label">Até</label><input id="ate" type="date" name="ate" value="{{ $r['to'] }}" class="input"></div>
    <button class="btn-primary">Aplicar</button>
  </div>
  <p class="hint mt-2">As datas valem quando “Personalizado” está marcado (até 1 ano).</p>
</form>

<dl class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-2">
  <div class="stat"><dt>Humor médio</dt><dd>{{ $r['mood_avg'] === null ? '—' : ($r['mood_avg'] > 0 ? '+' : '').$r['mood_avg'] }}</dd></div>
  <div class="stat"><dt>Energia média</dt><dd>{{ $r['energy_avg'] === null ? '—' : ($r['energy_avg'] > 0 ? '+' : '').$r['energy_avg'] }}</dd></div>
  <div class="stat"><dt>Sono médio</dt><dd>{{ $r['sleep_avg'] === null ? '—' : str_replace('.', ',', $r['sleep_avg']).' h' }}</dd></div>
  <div class="stat"><dt>Dias registrados</dt><dd>{{ $r['days_recorded'] }}</dd></div>
</dl>

<section class="card mt-4" aria-labelledby="graf-t">
  <h2 id="graf-t" class="font-semibold mb-2">Gráfico de vida</h2>
  @include('reports._chart', ['series' => $r['series']])
  <details class="mt-3">
    <summary class="btn-link px-0 cursor-pointer">Ver dados do gráfico em tabela</summary>
    <div class="overflow-x-auto">
    <table class="mt-2 w-full text-sm">
      <caption class="sr-only">Média diária de humor e energia</caption>
      <thead><tr><th scope="col" class="text-left p-1">Dia</th><th scope="col" class="text-left p-1">Humor</th><th scope="col" class="text-left p-1">Energia</th></tr></thead>
      <tbody>
      @foreach($r['series'] as $p)@if($p['mood'] !== null || $p['energy'] !== null)
        <tr class="border-t border-slate-200 dark:border-slate-700"><td class="p-1">{{ $fmt($p['date']) }}</td><td class="p-1">{{ $p['mood'] ?? '—' }}</td><td class="p-1">{{ $p['energy'] ?? '—' }}</td></tr>
      @endif @endforeach
      </tbody>
    </table>
    </div>
  </details>
</section>

<section class="card mt-4" aria-labelledby="sin-t">
  <h2 id="sin-t" class="font-semibold">Sinais</h2>
  <p class="hint">Contagem de registros de período conforme os limiares de alerta (humor ≤ {{ $r['thresholds']['mood_low'] }} ou ≥ +{{ $r['thresholds']['mood_high'] }}; energia ≤ {{ $r['thresholds']['energy_low'] }} ou ≥ +{{ $r['thresholds']['energy_high'] }}).</p>
  <ul class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-2">
    @foreach($sig as $k => [$l, $s, $c])
      <li class="stat"><span class="badge {{ $c }}"><span aria-hidden="true">{{ $s }}</span>&nbsp;{{ $l }}</span><p class="text-xl font-semibold mt-1">{{ $r['signals'][$k] }}</p></li>
    @endforeach
  </ul>
</section>

<section class="mt-4" aria-labelledby="dias-t">
  <h2 id="dias-t" class="font-semibold mb-2">Dia a dia</h2>
  @forelse(array_reverse($r['days'], true) as $d => $day)
    <article class="card mb-2">
      <h3 class="font-semibold">{{ \App\Support\Dates::long($d) }}</h3>
      @foreach($day['periods'] ?? [] as $pk => $pv)
        <p class="text-sm mt-1"><span class="font-medium">{{ \App\Domain\Mood\Period::from($pk)->label() }}:</span>
          {{ collect([$pv['mood'] !== null ? 'humor '.mb_strtolower(Scales::label(Scales::MOOD, $pv['mood'])) : null, $pv['energy'] !== null ? 'energia '.mb_strtolower(Scales::label(Scales::ENERGY, $pv['energy'])) : null])->filter()->implode(', ') ?: 'sem humor/energia' }}
          @if($pv['signal'] !== 'neutro')<span class="badge {{ $sig[$pv['signal']][2] }} ms-1"><span aria-hidden="true">{{ $sig[$pv['signal']][1] }}</span>&nbsp;{{ $sig[$pv['signal']][0] }}</span>@endif
          @if(!empty($pv['note']))<br><span class="italic">“{{ $pv['note'] }}”</span>@endif</p>
      @endforeach
      @foreach($day['blocks'] ?? [] as $b)
        <p class="text-sm mt-1"><span class="font-medium">{{ $b['title'] }}@if($b['period']) ({{ mb_strtolower($b['period']) }})@endif:</span> {{ $b['summary'] ?: '—' }}</p>
      @endforeach
    </article>
  @empty
    <p class="hint">Sem registros neste período.</p>
  @endforelse
</section>
<p class="hint mt-4">{{ $r['disclaimer'] }}</p>
@endsection
