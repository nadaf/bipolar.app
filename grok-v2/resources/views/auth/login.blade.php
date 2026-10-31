@extends('layouts.app')
@section('title', 'Entrar — Bipolar')
@section('content')
<h1 class="text-2xl font-semibold mb-4">Entrar</h1>
<form method="post" action="/entrar" class="space-y-4 card" novalidate>
  @csrf
  <div>
    <label for="email" class="label">E-mail</label>
    <input id="email" name="email" type="email" autocomplete="username" required class="input" value="{{ old('email') }}"
      @error('email') aria-invalid="true" aria-describedby="email-erro" @enderror>
    @error('email')<p id="email-erro" class="erro">{{ $message }}</p>@enderror
  </div>
  <div>
    <label for="password" class="label">Senha</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required class="input">
  </div>
  <button class="btn-primary w-full">Entrar</button>
</form>
@endsection
