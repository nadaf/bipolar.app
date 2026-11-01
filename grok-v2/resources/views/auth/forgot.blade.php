@extends('layouts.app')
@section('title', 'Esqueci minha senha — Bipolar')
@section('content')
<div class="mx-auto max-w-md">
<h1 class="text-2xl font-semibold mb-1">Esqueci minha senha</h1>
<p class="hint mb-4">Digite o e-mail da sua conta. Enviaremos um link para criar uma senha nova (vale por 60 minutos).</p>
<form method="post" action="{{ route('password.forgot.send') }}" class="space-y-4 card" novalidate>
  @csrf
  <div>
    <label for="email" class="label">E-mail</label>
    <input id="email" name="email" type="email" autocomplete="username" required class="input" value="{{ old('email') }}"
      @error('email') aria-invalid="true" aria-describedby="email-erro" @enderror>
    @error('email')<p id="email-erro" class="erro">{{ $message }}</p>@enderror
  </div>
  <button class="btn-primary w-full">Enviar link</button>
</form>
<p class="mt-4 text-center"><a href="{{ route('login') }}">Voltar para Entrar</a></p>
</div>
@endsection
