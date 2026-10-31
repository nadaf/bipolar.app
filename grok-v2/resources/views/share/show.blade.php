@extends('layouts.app')
@section('title', 'Acesso compartilhado — Bipolar')
@section('content')
@php use App\Domain\Care\CareGrantService as S; @endphp
<p><a href="{{ route('share') }}">‹ Compartilhar</a></p>
<h1 class="text-xl font-semibold mt-2">{{ $name ?? 'Convite #'.$g->id }} <span class="badge {{ ['ativo' => 'badge-ok', 'pendente' => 'badge-warn'][$status] ?? 'badge-muted' }} align-middle">{{ S::STATUS_LABELS[$status] }}</span></h1>
<p class="hint">{{ $kinds[$g->grantee_kind] ?? $g->grantee_kind }}@if($g->data_from) · registros desde {{ \Carbon\Carbon::parse($g->data_from)->format('d/m/Y') }}@endif @if($g->expires_at) · válido até {{ $g->expires_at->format('d/m/Y') }}@endif</p>

@if($link)
<section class="info mt-4" aria-labelledby="link-t" x-data="copiar">
  <h2 id="link-t" class="font-semibold">Link do convite</h2>
  <p class="text-sm">Envie para a pessoa. Ele aparece só agora, vale 7 dias e funciona uma vez.</p>
  <label for="link" class="sr-only">Link do convite</label>
  <input id="link" class="input mt-2 font-mono text-sm" readonly value="{{ $link }}">
  <button type="button" class="btn-secondary mt-2" data-texto="{{ $link }}" x-on:click="go">Copiar link</button>
  <span role="status" class="ms-2" x-text="msg"></span>
</section>
@endif

@if(in_array($status, ['ativo', 'pendente'], true))
<section class="card mt-4" aria-labelledby="perm-t">
  <h2 id="perm-t" class="font-semibold mb-3">Permissões</h2>
  @if($errors->any())<div class="alert mb-3" role="alert"><ul class="list-disc ps-5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
  <form method="post" action="{{ route('share.update', $g->id) }}" class="space-y-4">
    @csrf
    @include('share._scopes', ['checked' => $current])
    <div><label for="expires_at" class="label">Acesso válido até</label>
      <input id="expires_at" type="date" lang="pt-BR" name="expires_at" class="input sm:w-60" value="{{ $g->expires_at?->toDateString() }}" min="{{ now()->addDay()->toDateString() }}"></div>
    <button class="btn-primary">Salvar permissões</button>
  </form>
  <form method="post" action="{{ route('share.revoke', $g->id) }}" class="mt-6 border-t border-slate-200 dark:border-slate-700 pt-4">
    @csrf
    <p class="mb-2">Revogar corta o acesso na hora. A pessoa não é avisada pelo app.</p>
    <button class="btn-danger">Revogar acesso</button>
  </form>
</section>
@endif

<section class="card mt-4" aria-labelledby="aud-t">
  <h2 id="aud-t" class="font-semibold mb-2">Quem acessou e quando</h2>
  @forelse($log as $a)
    @if($loop->first)<ol class="divide-y divide-slate-200 dark:divide-slate-700 text-sm">@endif
    <li class="py-2 flex justify-between gap-3"><span>{{ S::ACTION_LABELS[$a->action] ?? $a->action }}</span><time datetime="{{ $a->created_at?->toIso8601String() }}" class="text-slate-700 dark:text-slate-300">{{ $a->created_at?->timezone(auth()->user()->timezone)->format('d/m/Y H:i') }}</time></li>
    @if($loop->last)</ol>@endif
  @empty
    <p class="hint">Nenhum registro ainda.</p>
  @endforelse
</section>
@endsection
