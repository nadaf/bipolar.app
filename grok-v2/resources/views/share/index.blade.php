@extends('layouts.app')
@section('title', 'Compartilhar — Bipolar')
@section('content')
<h1 class="text-xl font-semibold">Compartilhar</h1>
<p class="hint mt-1">Convide seu psicólogo(a), psiquiatra ou alguém da família para ver seus registros. Você escolhe o que mostrar, até quando, e pode revogar a qualquer momento.</p>

<section class="mt-4" aria-labelledby="ac-t">
  <h2 id="ac-t" class="font-semibold mb-2">Acessos</h2>
  @forelse($grants as $g)
    @php $st = \App\Domain\Care\CareGrantService::status($g); @endphp
    @if($loop->first)<ul class="space-y-2">@endif
    <li><a href="{{ route('share.show', $g->id) }}" class="card flex items-center justify-between gap-3 no-underline text-inherit hover:border-blue-800">
      <span><span class="font-semibold block">{{ $names[$g->grantee_user_id] ?? 'Convite #'.$g->id }}</span>
        <span class="text-sm">{{ $kinds[$g->grantee_kind] ?? $g->grantee_kind }} · {{ count(\App\Domain\Care\CareGrantService::names($g->scope_mask)) }} categoria(s)@if($g->expires_at) · até {{ $g->expires_at->format('d/m/Y') }}@endif</span></span>
      <span class="badge {{ ['ativo' => 'badge-ok', 'pendente' => 'badge-warn'][$st] ?? 'badge-muted' }}">{{ \App\Domain\Care\CareGrantService::STATUS_LABELS[$st] }}</span>
    </a></li>
    @if($loop->last)</ul>@endif
  @empty
    <p class="hint">Você ainda não compartilhou com ninguém.</p>
  @endforelse
</section>

<section class="card mt-6" aria-labelledby="novo-t">
  <h2 id="novo-t" class="font-semibold mb-3">Novo convite</h2>
  @if($errors->any())<div class="alert mb-3" role="alert"><ul class="list-disc ps-5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
  <form method="post" action="{{ route('share.store') }}" class="space-y-4">
    @csrf
    <div><label for="kind" class="label">Quem é</label>
      <select id="kind" name="grantee_kind" class="input sm:w-80">@foreach($kinds as $k => $l)<option value="{{ $k }}" @selected(old('grantee_kind') === $k)>{{ $l }}</option>@endforeach</select></div>
    @include('share._scopes', ['checked' => old('scope', ['humor', 'sono', 'estresse_alimentacao'])])
    <div class="grid gap-3 sm:grid-cols-2">
      <div><label for="data_from" class="label">Mostrar registros a partir de <span class="font-normal">(opcional)</span></label><input id="data_from" type="date" name="data_from" class="input" value="{{ old('data_from') }}"></div>
      <div><label for="expires_at" class="label">Acesso válido até <span class="font-normal">(opcional)</span></label><input id="expires_at" type="date" name="expires_at" class="input" value="{{ old('expires_at') }}" min="{{ now()->addDay()->toDateString() }}"></div>
    </div>
    <button class="btn-primary">Criar convite</button>
    <p class="hint">Você recebe um link para enviar como preferir. Ele vale por 7 dias e só funciona uma vez.</p>
  </form>
</section>
@endsection
