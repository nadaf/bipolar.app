@extends('layouts.app')
@section('title', 'Verificação em duas etapas — Bipolar')
@section('content')
<p><a href="{{ route('account') }}#seguranca">‹ Conta</a></p>
<h1 class="text-xl font-semibold mt-2">Verificação em duas etapas</h1>
<p class="hint mt-1">Além da senha, o Bipolar pede um código que muda a cada 30 segundos no app autenticador do seu celular (Google Authenticator, Microsoft Authenticator, Aegis, 1Password…). Se alguém descobrir sua senha, ainda assim não entra.</p>
@if($required && !$on)<p class="warn mt-3" role="alert">Sua clínica exige a verificação em duas etapas para a equipe. Ligue para continuar usando o Bipolar.</p>@endif
@if($errors->any())<div class="alert mt-3" role="alert"><ul class="list-disc ps-5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

@if($codes)
<section class="card mt-4 border-amber-600" aria-labelledby="cod-t">
  <h2 id="cod-t" class="font-semibold">Guarde seus códigos de recuperação</h2>
  <p class="hint mt-1">Use um deles se perder o celular. Cada código vale uma vez. Eles <strong>não aparecem de novo</strong>: anote em papel ou guarde no gerenciador de senhas.</p>
  <ul class="codigos mt-3" aria-label="Códigos de recuperação">@foreach($codes as $c)<li><code>{{ $c }}</code></li>@endforeach</ul>
</section>
@endif

@if($on)
<section class="card mt-4" aria-labelledby="on-t">
  <h2 id="on-t" class="font-semibold"><span class="badge">Ligada</span> desde {{ $u->mfa_enabled_at?->format('d/m/Y') }}</h2>
  <p class="mt-2">Códigos de recuperação restantes: <strong>{{ $left }}</strong> de 10.</p>
  <h3 class="font-medium mt-4">Gerar novos códigos de recuperação</h3>
  <form method="post" action="{{ route('mfa.codes') }}" class="grid gap-3 sm:grid-cols-3 items-end mt-2">
    @csrf
    <div><label for="rc-senha" class="label">Sua senha</label><input id="rc-senha" name="password" type="password" autocomplete="current-password" class="input" required></div>
    <div><label for="rc-code" class="label">Código do app</label><input id="rc-code" name="code" inputmode="numeric" autocomplete="one-time-code" class="input" required></div>
    <div><button class="btn-secondary">Gerar novos códigos</button></div>
  </form>
  @unless($required)
  <h3 class="font-medium mt-6">Desligar</h3>
  <form method="post" action="{{ route('mfa.disable') }}" class="grid gap-3 sm:grid-cols-3 items-end mt-2">
    @csrf
    <div><label for="off-senha" class="label">Sua senha</label><input id="off-senha" name="password" type="password" autocomplete="current-password" class="input" required></div>
    <div><label for="off-code" class="label">Código do app ou de recuperação</label><input id="off-code" name="code" autocomplete="one-time-code" class="input" required></div>
    <div><button class="btn-danger">Desligar verificação</button></div>
  </form>
  @else
  <p class="hint mt-4">Sua clínica exige a verificação em duas etapas; ela não pode ser desligada.</p>
  @endunless
</section>
@elseif($setup)
<section class="card mt-4" aria-labelledby="qr-t">
  <h2 id="qr-t" class="font-semibold">Passo 2 de 3: ler o código QR</h2>
  <ol class="list-decimal ps-5 mt-2 space-y-1 text-sm">
    <li>No app autenticador, toque em <strong>+</strong> e em <strong>Ler código QR</strong>.</li>
    <li>Aponte a câmera para o quadrado abaixo.</li>
  </ol>
  <div class="qr-box mt-3">{!! $qr !!}</div>
  <details class="mt-3"><summary>Não consegue ler? Digite a chave</summary>
    <p class="mt-2 text-sm">Conta: <strong>{{ $u->email }}</strong> · Chave: <code class="chave">{{ trim(chunk_split((string) $secret, 4, ' ')) }}</code> · Tipo: baseado em tempo</p>
  </details>
  <h2 class="font-semibold mt-5" id="conf-t">Passo 3 de 3: confirmar</h2>
  <form method="post" action="{{ route('mfa.confirm') }}" class="flex flex-wrap items-end gap-2 mt-2" aria-labelledby="conf-t">
    @csrf
    <div><label for="conf-code" class="label">Código de 6 números do app</label>
      <input id="conf-code" name="code" class="input" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required
        @error('code') aria-invalid="true" aria-describedby="conf-erro" @enderror>
      @error('code')<p id="conf-erro" class="erro">{{ $message }}</p>@enderror</div>
    <button class="btn-primary">Ligar verificação</button>
  </form>
</section>
@else
<section class="card mt-4" aria-labelledby="ini-t">
  <h2 id="ini-t" class="font-semibold">Passo 1 de 3: confirmar que é você</h2>
  <p class="hint mt-1">Tenha à mão um app autenticador instalado no celular.</p>
  <form method="post" action="{{ route('mfa.begin') }}" class="flex flex-wrap items-end gap-2 mt-3">
    @csrf
    <div><label for="ini-senha" class="label">Sua senha</label><input id="ini-senha" name="password" type="password" autocomplete="current-password" class="input" required></div>
    <button class="btn-primary">Começar</button>
  </form>
</section>
@endif
<p class="hint mt-4">Chave de acesso (passkey): planejada para uma próxima versão.</p>
@endsection
