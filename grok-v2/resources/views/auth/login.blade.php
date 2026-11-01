@extends('layouts.app')
@section('title', 'Entrar — Bipolar')
@section('content')
<div class="mx-auto max-w-md">
<p class="mb-4 flex justify-center"><img src="/favicon.svg" alt="Bipolar" width="72" height="72" class="rounded-2xl"></p>
<h1 class="text-2xl font-semibold mb-1">Entrar</h1>
<p class="hint mb-4">Seu diário de humor, só seu. Você decide o que compartilhar.</p>
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
  <p class="text-center"><a href="{{ route('password.forgot') }}">Esqueci minha senha</a></p>
</form>
@if(\Illuminate\Support\Facades\Route::has('signup') && app(\App\Tenancy\HostTenant::class)->tenantId === null)
<p class="mt-4 text-center">Ainda não tem conta? <a href="{{ route('signup') }}">Criar conta</a></p>
@endif
</div>
@endsection
