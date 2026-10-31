@extends('layouts.app')
@section('title', 'Dia do histórico — Bipolar')
@section('content')
@php use App\Domain\Mood\Scales; @endphp
<p><a href="{{ route('history', ['mes' => substr($date, 0, 7)]) }}">‹ Voltar ao calendário</a></p>
<h1 class="text-xl font-semibold mt-2">{{ ucfirst($title) }}</h1>
<div class="mt-4 grid gap-3 sm:grid-cols-3">
@foreach($periods as $p)
  @php $e = $entries[$p->value] ?? null; @endphp
  <section class="card" aria-labelledby="p-{{ $p->value }}">
    <h2 id="p-{{ $p->value }}" class="font-semibold">{{ $p->label() }}</h2>
    @if($e)
      <dl class="mt-2 space-y-1 text-sm">
        @if($e->mood !== null)<div><dt class="inline font-medium">Humor:</dt> <dd class="inline">{{ Scales::label(Scales::MOOD, $e->mood) }}</dd></div>@endif
        @if($e->energy !== null)<div><dt class="inline font-medium">Energia:</dt> <dd class="inline">{{ Scales::label(Scales::ENERGY, $e->energy) }}</dd></div>@endif
        @if($e->anxiety !== null)<div><dt class="inline font-medium">Ansiedade:</dt> <dd class="inline">{{ Scales::INTENSITY[$e->anxiety] }}</dd></div>@endif
        @if($e->irritability !== null)<div><dt class="inline font-medium">Irritabilidade:</dt> <dd class="inline">{{ Scales::INTENSITY[$e->irritability] }}</dd></div>@endif
      </dl>
    @else<p class="hint mt-2">Sem registro.</p>@endif
    <a class="btn-link px-0" href="{{ route('diary.today', ['data' => $date, 'periodo' => $p->value]) }}">{{ $e ? 'Editar' : 'Registrar' }} {{ mb_strtolower($p->label()) }}</a>
  </section>
@endforeach
</div>
<section class="card mt-4" aria-labelledby="blocos-t">
  <h2 id="blocos-t" class="font-semibold">Outros registros</h2>
  @if($blocks->isEmpty())<p class="hint mt-2">Nada além do humor neste dia.</p>@else
  <ul class="mt-2 divide-y divide-slate-200 dark:divide-slate-700">
    @foreach($blocks as $b)
      <li class="py-2"><span class="font-medium">{{ \App\Domain\Day\Blocks::SPEC[$b->block]['title'] }}</span>
        @if($b->period !== 'dia')<span class="badge badge-muted ms-1">{{ \App\Domain\Mood\Period::from($b->period)->label() }}</span>@endif
        <p class="text-sm">{{ \App\Domain\Day\Blocks::summary($b->block, $b->data) ?: 'Salvo em branco' }}</p></li>
    @endforeach
  </ul>@endif
  @if(!empty($locked))<p class="info mt-3 flex items-center gap-2">@include('partials.icon', ['name' => 'cadeado', 'class' => 'size-4']) Há {{ count($locked) }} registro(s) privado(s) neste dia. <a href="{{ route('diary.today', ['data' => $date]) }}">Desbloquear no Registrar</a></p>@endif
</section>
@endsection
