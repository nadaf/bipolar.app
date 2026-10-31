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
      <form method="post" action="{{ route('clinic.remove', $p->id) }}">@csrf<button class="btn-secondary" aria-label="Remover {{ $p->name }}">Remover</button></form>
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
@endsection
