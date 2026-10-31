@extends('layouts.app')
@section('title', 'Registro de hoje — Bipolar')
@section('content')
<div x-data="periodLock" data-date="{{ $date }}" data-period="{{ $period->value }}">
  <p class="lock-banner" role="status">
    Registrando: <strong>{{ $period->label() }}</strong> de <strong>{{ \Carbon\Carbon::parse($date)->format('d/m') }}</strong>
    <span x-show="changed" class="block text-sm">O relógio mudou de período. O registro continua travado neste período até você trocar.</span>
  </p>
  <nav aria-label="Período" class="flex gap-2 my-3">
    @foreach($periods as $p)
      <a class="period-tab" href="?data={{ $date }}&periodo={{ $p->value }}" @if($p === $period) aria-current="page" @endif>{{ $p->label() }}</a>
    @endforeach
  </nav>

  <form class="card space-y-6" hx-post="{{ route('diary.save') }}" hx-target="#status" hx-swap="innerHTML">
    @csrf
    <input type="hidden" name="entry_date" value="{{ $date }}">
    <input type="hidden" name="period" value="{{ $period->value }}">
    <input type="hidden" id="version" name="version" value="{{ $entry?->version }}">

    @include('diary._scale', ['name' => 'mood', 'legend' => 'Humor', 'scale' => $mood, 'value' => $entry?->mood,
      'hint' => 'Abaixo de zero: lado depressivo. Acima de zero: lado de euforia/mania. Zero: estável.'])
    @include('diary._scale', ['name' => 'energy', 'legend' => 'Energia', 'scale' => $energy, 'value' => $entry?->energy, 'optional' => true,
      'hint' => 'Baixa pode ser sinal de depressão; alta pode ser sinal de mania. Normal é o seu equilíbrio.'])
    @include('diary._scale', ['name' => 'anxiety', 'legend' => 'Ansiedade', 'scale' => $intensity, 'value' => $entry?->anxiety, 'optional' => true])
    @include('diary._scale', ['name' => 'irritability', 'legend' => 'Irritabilidade', 'scale' => $intensity, 'value' => $entry?->irritability, 'optional' => true])

    <div>
      <label for="sleep_hours" class="label">Horas de sono (opcional)</label>
      <input id="sleep_hours" name="sleep_hours" type="number" inputmode="decimal" min="0" max="24" step="0.5" class="input w-32" value="{{ $entry?->sleep_hours }}">
    </div>
    <div>
      <label for="note" class="label">Anotação (opcional, privada)</label>
      <textarea id="note" name="note" rows="3" maxlength="5000" class="input">{{ $entry?->note }}</textarea>
    </div>
    <button class="btn-primary w-full sm:w-auto">Salvar registro</button>
    <div id="status" role="status" aria-live="polite"></div>
  </form>
</div>
@endsection
