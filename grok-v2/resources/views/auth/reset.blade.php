@extends('layouts.app')
@section('title', 'Nova senha — Bipolar')
@section('content')
<div class="mx-auto max-w-md">
<h1 class="text-2xl font-semibold mb-1">Criar senha nova</h1>
@if($valid)
<form method="post" action="{{ route('password.update') }}" class="space-y-4 card" novalidate>
  @csrf
  <input type="hidden" name="token" value="{{ $token }}">
  <div>
    <label for="password" class="label">Senha nova (mínimo 10 caracteres)</label>
    <input id="password" name="password" type="password" autocomplete="new-password" required minlength="10" class="input"
      @error('password') aria-invalid="true" aria-describedby="senha-erro" @enderror>
    @error('password')<p id="senha-erro" class="erro">{{ $message }}</p>@enderror
  </div>
  <div>
    <label for="password_confirmation" class="label">Repita a senha nova</label>
    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="input">
  </div>
  <button class="btn-primary w-full">Salvar senha nova</button>
</form>
<p class="mt-4 text-center"><a href="{{ route('login') }}">Voltar para Entrar</a></p>
@else
<p class="warn" role="alert">Este link expirou ou já foi usado. Peça um link novo.</p>
<p class="mt-4"><a class="btn-primary inline-flex" href="{{ route('password.forgot') }}">Pedir link novo</a></p>
<p class="mt-4"><a href="{{ route('login') }}">Voltar para Entrar</a></p>
@endif
</div>
@endsection
