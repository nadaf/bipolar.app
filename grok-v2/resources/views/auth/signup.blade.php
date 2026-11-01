@extends('layouts.app')
@section('title', 'Criar conta — Bipolar')
@section('content')
<div class="mx-auto max-w-md">
<p class="mb-4 flex justify-center"><img src="/favicon.svg" alt="Bipolar" width="72" height="72" class="rounded-2xl"></p>
<h1 class="text-2xl font-semibold mb-4">Criar conta</h1>
@if($errors->any())<div class="alert mb-4" role="alert"><p class="font-semibold">Confira os campos:</p><ul class="list-disc ps-5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<form method="post" action="{{ route('signup') }}" class="card space-y-4" novalidate>
  @csrf
  <fieldset>
    <legend class="label">Como você vai usar</legend>
    <div class="grid gap-2">
      <label class="check"><input type="radio" name="account_type" value="patient" @checked(old('account_type', 'patient') === 'patient')> Para registrar meu próprio humor</label>
      <label class="check"><input type="radio" name="account_type" value="professional" @checked(old('account_type') === 'professional')> Sou profissional de saúde (acompanhar pacientes)</label>
    </div>
  </fieldset>
  <div><label for="name" class="label">Nome</label><input id="name" name="name" class="input" autocomplete="name" required value="{{ old('name') }}"></div>
  <div><label for="email" class="label">E-mail</label><input id="email" name="email" type="email" class="input" autocomplete="email" required value="{{ old('email') }}"></div>
  <div>
    <label for="password" class="label">Senha</label>
    <input id="password" name="password" type="password" class="input" autocomplete="new-password" required minlength="10" aria-describedby="senha-dica">
    <p id="senha-dica" class="hint mt-1">Pelo menos 10 caracteres.</p>
  </div>
  <div><label for="password_confirmation" class="label">Repita a senha</label><input id="password_confirmation" name="password_confirmation" type="password" class="input" autocomplete="new-password" required></div>
  <button class="btn-primary w-full">Criar conta</button>
</form>
<p class="mt-4 text-center">Já tem conta? <a href="{{ route('login') }}">Entrar</a></p>
</div>
@endsection
