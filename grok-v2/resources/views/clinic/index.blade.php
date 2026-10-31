@extends('layouts.app')
@section('title', 'Clínica — Bipolar')
@section('content')
<h1 class="text-xl font-semibold">Clínica</h1>
<p class="hint mt-1">Profissionais vinculados. O vínculo não dá acesso a pacientes: cada paciente decide quem vê o quê.</p>
<section class="card mt-4" aria-labelledby="pro-t">
  <h2 id="pro-t" class="font-semibold mb-2">Profissionais</h2>
  @forelse($pros as $p)
    @if($loop->first)<ul class="divide-y divide-slate-200 dark:divide-slate-700">@endif
    <li class="py-2 flex flex-wrap items-center justify-between gap-2">
      <span><span class="font-medium block">{{ $p->name }}</span><span class="text-sm">{{ $p->email }}</span></span>
      <form method="post" action="{{ route('clinic.remove', $p->id) }}" x-data="confirmarEnvio" x-on:submit="pedir">@csrf
        <button class="btn-secondary" aria-label="Remover {{ $p->name }}" x-ref="gatilho">Remover</button>
        <dialog x-ref="dlg" class="card m-auto max-w-sm" aria-labelledby="rm-t-{{ $p->id }}" aria-describedby="rm-d-{{ $p->id }}" x-on:close="voltar">
          <h3 id="rm-t-{{ $p->id }}" class="font-semibold">Remover {{ $p->name }} da clínica?</h3>
          <p id="rm-d-{{ $p->id }}" class="hint mt-1">A pessoa deixa de aparecer na clínica. A conta dela e os acessos que os pacientes deram continuam; dá para vincular de novo depois.</p>
          <div class="mt-4 flex flex-wrap gap-2">
            <button type="button" class="btn-danger" x-on:click="confirmar">Remover</button>
            <button type="button" class="btn-secondary" x-on:click="cancelar" autofocus>Cancelar</button>
          </div>
        </dialog>
      </form>
    </li>
    @if($loop->last)</ul>@endif
  @empty
    <p class="hint">Nenhum profissional vinculado.</p>
  @endforelse
</section>
<section class="card mt-4" aria-labelledby="add-t">
  <h2 id="add-t" class="font-semibold mb-2">Vincular profissional</h2>
  <form method="post" action="{{ route('clinic.add') }}" class="flex flex-wrap items-end gap-2">
    @csrf
    <div class="grow"><label for="pro-email" class="label">E-mail da conta do profissional</label>
      <input id="pro-email" name="email" type="email" class="input" required value="{{ old('email') }}" @error('email') aria-invalid="true" aria-describedby="pro-erro" @enderror>
      @error('email')<p id="pro-erro" class="erro">{{ $message }}</p>@enderror</div>
    <button class="btn-primary">Vincular</button>
  </form>
  <p class="hint mt-2">A pessoa precisa ter criado conta antes. Convite por e-mail: em breve.</p>
</section>
<section class="card mt-4" aria-labelledby="pac-t">
  <h2 id="pac-t" class="font-semibold mb-1">E os pacientes?</h2>
  <p class="hint">Quem vincula é o próprio paciente, para proteger a privacidade dele: a clínica não adiciona pacientes nem vê registros.</p>
  <ol class="mt-2 list-decimal ps-5 space-y-1 text-sm">
    <li>O paciente cria a conta dele no Bipolar.</li>
    <li>No app, ele abre <strong>Compartilhar</strong>, escolhe o tipo de profissional e o que quer mostrar, e cria o convite.</li>
    <li>Ele envia o link ao profissional, que entra com a conta vinculada a esta clínica e aceita.</li>
  </ol>
</section>
@endsection
