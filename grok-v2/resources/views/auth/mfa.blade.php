@extends('layouts.app')
@section('title', 'Verificação — Bipolar')
@section('content')
<div class="mx-auto max-w-md">
<h1 class="text-2xl font-semibold mb-1">Verificação em duas etapas</h1>
<p class="hint mb-4">Abra o app autenticador do celular e digite o código de 6 números que aparece para o Bipolar.</p>
<form method="post" action="{{ route('mfa.challenge.check') }}" class="space-y-4 card" novalidate>
  @csrf
  <div>
    <label for="code" class="label">Código</label>
    <input id="code" name="code" class="input" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="20"
      aria-describedby="code-dica @error('code') code-erro @enderror" @error('code') aria-invalid="true" @enderror>
    <p id="code-dica" class="hint mt-1">Sem o celular? Use um dos códigos de recuperação (ex.: abcde-12345). Cada um vale uma vez.</p>
    @error('code')<p id="code-erro" class="erro">{{ $message }}</p>@enderror
  </div>
  <button class="btn-primary w-full">Confirmar e entrar</button>
</form>
<p class="mt-4 text-center"><a href="{{ route('login') }}">Voltar para Entrar</a></p>
</div>
@endsection
