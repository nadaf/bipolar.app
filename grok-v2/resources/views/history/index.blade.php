@extends('layouts.app')
@section('title', 'Histórico — Bipolar')
@section('content')
@php $meses = ['janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro']; @endphp
<h1 class="text-xl font-semibold">Histórico</h1>
<div class="mt-3 flex items-center justify-between gap-2">
  <a class="btn-secondary" href="?mes={{ $prev }}" aria-label="Mês anterior">‹ <span class="hidden sm:inline">Anterior</span></a>
  <h2 class="text-lg font-semibold" aria-live="polite">{{ ucfirst($meses[$first->month - 1]) }} de {{ $first->year }}</h2>
  @if($next)<a class="btn-secondary" href="?mes={{ $next }}" aria-label="Próximo mês"><span class="hidden sm:inline">Próximo</span> ›</a>@else<span class="w-11"></span>@endif
</div>
<p class="hint mt-2">Cada dia mostra três marcas — manhã, tarde e noite — com o humor registrado: ▼ depressivo, ● estável, ▲ elevado, · sem registro.</p>
<table class="mt-3 w-full table-fixed" aria-label="Calendário de {{ $meses[$first->month - 1] }}">
  <thead><tr>@foreach(['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'] as $w)<th scope="col" class="cal-head">{{ $w }}</th>@endforeach</tr></thead>
  <tbody>
  @foreach($weeks as $week)
    <tr>
    @foreach($week as $d)
      <td class="p-0.5 align-top">
      @if($d === null)
        <span class="cal-day vazio" aria-hidden="true"></span>
      @else
        @php
          $ds = $d->toDateString(); $has = isset($map[$ds]) || isset($blockDays[$ds]); $fut = $d->gt($today);
          $desc = collect($periods)->map(fn ($p) => $p->label().': '.(isset($map[$ds][$p->value]) && $map[$ds][$p->value]->mood !== null ? \App\Domain\Mood\Scales::label(\App\Domain\Mood\Scales::MOOD, $map[$ds][$p->value]->mood) : 'sem humor'))->implode('; ');
        @endphp
        @if($fut)
          <span class="cal-day futuro"><span>{{ $d->day }}</span></span>
        @else
          <a class="cal-day" href="{{ route('history.day', $ds) }}" @if($d->isSameDay($today)) aria-current="date" @endif
             aria-label="{{ $d->day }}: {{ $has ? $desc : 'sem registro' }}">
            <span class="text-sm font-semibold">{{ $d->day }}</span>
            <span class="dots">@foreach($periods as $p)@include('partials.dot', ['v' => $map[$ds][$p->value]->mood ?? null])@endforeach</span>
          </a>
        @endif
      @endif
      </td>
    @endforeach
    </tr>
  @endforeach
  </tbody>
</table>
<section class="mt-6" aria-labelledby="dias-t">
  <h2 id="dias-t" class="font-semibold mb-2">Dias com registro neste mês</h2>
  @forelse($list as $ds)
    @if($loop->first)<ul class="grid gap-2 sm:grid-cols-2">@endif
    <li><a class="side-link border border-slate-200 dark:border-slate-700" href="{{ route('history.day', $ds) }}">{{ \App\Support\Dates::long($ds) }}
      <span class="ms-auto dots">@foreach($periods as $p)@include('partials.dot', ['v' => $map[$ds][$p->value]->mood ?? null])@endforeach</span></a></li>
    @if($loop->last)</ul>@endif
  @empty
    <p class="hint">Nenhum registro neste mês. <a href="{{ route('diary.today') }}">Registrar agora</a>.</p>
  @endforelse
</section>
@endsection
