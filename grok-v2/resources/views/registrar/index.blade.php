@extends('layouts.app')
@section('title', 'Registrar — Bipolar')
@section('content')
@php
  use App\Domain\Day\Blocks;
  $order = ['humor', 'energia', 'ansiedade', 'sono', 'estresse', 'alimentacao', 'medicacao', 'substancias', 'pensamentos', 'religiosidade', 'sexualidade', 'morte', 'anotacao', 'meudia'];
  $openSet = false;
  $hints = [
    'humor' => 'Abaixo de zero: lado depressivo. Acima de zero: lado de euforia/mania. Zero: estável.',
    'energia' => 'Baixa pode ser sinal de depressão; alta pode ser sinal de mania. Normal é o seu equilíbrio.',
  ];
@endphp
<div x-data="registrar">
  <div x-data="periodLock" data-period="{{ $period->value }}" data-date="{{ $date }}" data-tz="{{ auth()->user()->timezone }}">
    <section class="lock-banner" aria-label="Período travado">
      <p class="flex items-center gap-2" role="status">
        @include('partials.icon', ['name' => 'cadeado', 'class' => 'size-5 shrink-0'])
        <span>Registrando: <strong>{{ $lockLabel }}</strong></span>
      </p>
      <nav aria-label="Período deste dia" class="mt-2 flex flex-wrap items-center gap-2">
        @foreach($periods as $p)
          @php $future = $date === $today && $p->startsAtHour() > $nowHour; $done = in_array($p->value, $periodsDone, true); @endphp
          @if($future)
            <span class="period-tab" aria-disabled="true">{{ $p->label() }} <span class="sr-only">(ainda não começou)</span></span>
          @else
            <a class="period-tab" href="?data={{ $date }}&periodo={{ $p->value }}" @if($p === $period) aria-current="page" @endif>
              {{ $p->label() }}@if($done)<span aria-hidden="true">&nbsp;✓</span><span class="sr-only"> (registrado)</span>@endif
            </a>
          @endif
        @endforeach
        <details class="w-full sm:w-auto">
          <summary class="btn-link cursor-pointer">Outro dia</summary>
          <form method="get" action="{{ route('diary.today') }}" class="mt-2 flex flex-wrap items-end gap-2">
            <div>
              <label for="data" class="label">Dia</label>
              <input id="data" type="date" lang="pt-BR" name="data" max="{{ $today }}" value="{{ $date }}" class="input" required>
            </div>
            <div>
              <label for="periodo" class="label">Período</label>
              <select id="periodo" name="periodo" class="input">
                @foreach($periods as $p)<option value="{{ $p->value }}" @selected($p === $period)>{{ $p->label() }}</option>@endforeach
              </select>
            </div>
            <button class="btn-secondary">Abrir</button>
          </form>
        </details>
      </nav>
    </section>

    <dialog x-ref="dlg" class="modal" aria-labelledby="dlg-t" aria-describedby="dlg-d">
      <h2 id="dlg-t">O período mudou</h2>
      <p id="dlg-d" class="mt-2">Agora já é <strong x-text="agora"></strong>. Você continua registrando <strong x-text="travado"></strong> até escolher mudar.</p>
      <div class="mt-4 flex flex-wrap gap-2">
        <button type="button" class="btn-secondary" x-on:click="ficar">Continuar em <span x-text="travado"></span></button>
        <a class="btn-primary" x-bind:href="link">Registrar <span x-text="agora"></span></a>
      </div>
    </dialog>
  </div>

  <h1 class="sr-only">Registrar</h1>
  <p class="hint mt-4 mb-2">Abra um bloco, marque e toque em <strong>Salvar e continuar</strong>. Nada é obrigatório além do que você quiser registrar.</p>

  <div class="space-y-3" id="blocos">
  @foreach($order as $key)
    @php
      $isMood = isset($moodCards[$key]);
      if (! $isMood && ! in_array($key, $enabled, true)) continue;
      $spec = $isMood ? null : Blocks::get($key);
      $row = $isMood ? null : ($blocks[$key] ?? null);
      $private = ! $isMood && Blocks::stepUp($key);
      $locked = $private && ! $unlocked;
      if ($isMood) {
          $fieldsDone = $entry !== null && collect($moodCards[$key]['fields'])->contains(fn ($f) => $entry->getAttribute($f) !== null);
          $summary = null;
          if ($fieldsDone) {
              $summary = collect($moodCards[$key]['fields'])->map(fn ($f) => match ($f) {
                  'mood' => \App\Domain\Mood\Scales::label($scales['mood'], $entry->mood),
                  'energy' => \App\Domain\Mood\Scales::label($scales['energy'], $entry->energy),
                  'anxiety' => $entry->anxiety === null ? null : 'Ansiedade '.mb_strtolower($scales['intensity'][$entry->anxiety]),
                  'irritability' => $entry->irritability === null ? null : 'Irritabilidade '.mb_strtolower($scales['intensity'][$entry->irritability]),
              })->filter()->implode(' · ');
          }
      } else {
          $fieldsDone = $row !== null;
          $summary = $row ? ($locked ? 'Registrado — desbloqueie para ver' : (Blocks::summary($key, $row->data) ?: 'Salvo em branco')) : null;
      }
      $open = ! $openSet && ! $fieldsDone && ! $locked;
      if ($open) $openSet = true;
      $perPeriod = $isMood || Blocks::perPeriod($key);
    @endphp
    @if($isMood)
      @php
        $ff = '';
        foreach ($moodCards[$key]['fields'] as $f) {
            $ff .= view('registrar._scale', ['name' => $f, 'legend' => match ($f) { 'mood' => 'Como está seu humor neste período?', 'energy' => 'Como está sua energia?', 'anxiety' => 'Ansiedade', 'irritability' => 'Irritabilidade' },
                'scale' => match ($f) { 'mood' => $scales['mood'], 'energy' => $scales['energy'], default => $scales['intensity'] },
                'value' => $entry?->getAttribute($f), 'hint' => $hints[$key] ?? null])->render();
        }
      @endphp
      @include('registrar._card', ['key' => $key, 'title' => $moodCards[$key]['title'], 'open' => $open, 'summary' => $summary, 'perPeriod' => true,
        'periodLabel' => $period->label(), 'action' => route('diary.mood', $key), 'date' => $date, 'period' => $period->value,
        'version' => $entry?->version, 'formFields' => $ff])
    @else
      @php
        $ff = null;
        $slotHtml = '';
        if ($key === 'morte') {
            $slotHtml .= '<div class="info" role="note"><p class="font-semibold">Você não está sozinho(a).</p><p class="mt-1">CVV <a class="underline font-semibold" href="tel:188">188</a> (24h, gratuito, também por chat em cvv.org.br) · SAMU <a class="underline font-semibold" href="tel:192">192</a> em emergência.</p><p class="mt-1 text-sm">Este registro é só seu. O app nunca avisa ninguém automaticamente.</p></div>';
        }
        if ($locked) {
            $slotHtml .= view('registrar._unlock', ['key' => $key, 'date' => $date, 'period' => $period->value])->render();
        } else {
            $ff = '';
            foreach ($spec['fields'] as $fname => $f) {
                $ff .= view('registrar._field', ['f' => $f, 'fname' => $fname, 'bkey' => $key, 'value' => $row?->data[$fname] ?? null])->render();
            }
        }
      @endphp
      @component('registrar._card', ['key' => $key, 'title' => $spec['title'], 'open' => $open, 'summary' => $summary, 'perPeriod' => $perPeriod,
        'periodLabel' => $period->label(), 'private' => $private, 'intimate' => Blocks::intimate($key), 'action' => route('diary.block', $key),
        'date' => $date, 'period' => $period->value, 'version' => $row?->version] + ($ff !== null ? ['formFields' => $ff] : []))
        {!! $slotHtml !!}
      @endcomponent
    @endif
  @endforeach
  </div>

  <div class="save-bar no-print">
    <button type="button" class="btn-primary shrink-0" x-ref="salvarTudo" x-on:click="salvarTudo" x-bind:disabled="ocupado">Salvar tudo</button>
    <p class="text-sm" aria-live="polite" x-text="resumo">Salva de uma vez todos os blocos alterados.</p>
  </div>
  <p class="text-sm mt-3" aria-live="polite" x-text="filaMsg"></p>
  <p class="hint mt-4">Quer registrar sexualidade, pensamentos de morte ou desligar algum bloco? <a href="{{ route('account') }}#modulos">Conta → Módulos</a>.</p>
</div>
@endsection
