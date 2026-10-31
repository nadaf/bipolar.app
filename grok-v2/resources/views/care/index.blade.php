@extends('layouts.app')
@section('title', 'Pacientes — Bipolar')
@section('content')
<h1 class="text-xl font-semibold">{{ request()->attributes->get('membership')?->role === 'patient' ? 'Quem me deu acesso' : 'Pacientes' }}</h1>
<p class="hint mt-1">Pessoas que compartilharam registros com você. Acesso somente leitura, nas categorias que cada uma escolheu. Cada consulta fica visível para ela.</p>
@forelse($grants as $g)
  @if($loop->first)<ul class="mt-4 space-y-2">@endif
  <li><a class="card flex items-center justify-between gap-3 no-underline text-inherit hover:border-blue-800" href="{{ route('care.show', $g->patient_id) }}">
    <span><span class="font-semibold block">{{ $names[$g->patient_id] ?? 'Paciente' }}</span>
      <span class="text-sm">{{ implode(', ', array_map(fn ($n) => $scopes[$n], \App\Domain\Care\CareGrantService::names($g->scope_mask))) }}</span></span>
    @include('partials.icon', ['name' => 'chev', 'class' => 'size-5 -rotate-90 shrink-0'])
  </a></li>
  @if($loop->last)</ul>@endif
@empty
  <div class="card mt-4"><p>Ninguém compartilhou com você ainda.</p><p class="hint mt-1">Peça ao paciente para criar um convite em <strong>Compartilhar</strong> e abra o link recebido com esta conta.</p></div>
@endforelse
@endsection
