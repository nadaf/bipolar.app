@extends('layouts.app')
@section('title', 'Convite — Bipolar')
@section('content')
<div class="mx-auto max-w-md">
<h1 class="text-2xl font-semibold mb-1">Convite para a equipe</h1>
<p class="mb-4">Você foi convidado(a) para a equipe de <strong>{{ $clinic }}</strong> no Bipolar, com o e-mail <strong>{{ $inv->email }}</strong>.</p>
<p class="hint mb-4">Como profissional, você só vê dados de pacientes que derem acesso a você, e cada acesso fica registrado para eles.</p>
<form method="post" action="{{ route('clinic.invite.accept', $token) }}" class="space-y-4 card" novalidate>
  @csrf
  @if($hasAccount)
    <p>Você já tem conta no Bipolar. Confirme com sua senha para aceitar.</p>
    <div><label for="password" class="label">Senha</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required class="input" @error('password') aria-invalid="true" aria-describedby="pw-erro" @enderror>
      @error('password')<p id="pw-erro" class="erro">{{ $message }}</p>@enderror</div>
    @if($needsCode)
    <div><label for="code" class="label">Código do app autenticador</label>
      <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required class="input" @error('code') aria-invalid="true" aria-describedby="cd-erro" @enderror>
      @error('code')<p id="cd-erro" class="erro">{{ $message }}</p>@enderror</div>
    @endif
  @else
    <p>Crie sua conta para aceitar.</p>
    <div><label for="name" class="label">Seu nome</label>
      <input id="name" name="name" autocomplete="name" required class="input" value="{{ old('name') }}" @error('name') aria-invalid="true" aria-describedby="nm-erro" @enderror>
      @error('name')<p id="nm-erro" class="erro">{{ $message }}</p>@enderror</div>
    <div><label for="password" class="label">Senha</label>
      <input id="password" name="password" type="password" autocomplete="new-password" required class="input" aria-describedby="pw-dica @error('password') pw-erro @enderror" @error('password') aria-invalid="true" @enderror>
      <p id="pw-dica" class="hint mt-1">Pelo menos 10 caracteres.</p>
      @error('password')<p id="pw-erro" class="erro">{{ $message }}</p>@enderror</div>
    <div><label for="password_confirmation" class="label">Repita a senha</label>
      <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="input"></div>
  @endif
  <button class="btn-primary w-full">Aceitar convite</button>
</form>
<p class="hint mt-4">Não esperava este convite? Basta ignorar; ele vence sozinho.</p>
</div>
@endsection
