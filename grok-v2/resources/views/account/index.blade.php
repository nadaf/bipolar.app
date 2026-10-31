@extends('layouts.app')
@section('title', 'Conta — Bipolar')
@section('content')
<h1 class="text-xl font-semibold">Conta</h1>
@if($errors->any())<div class="alert mt-3" role="alert"><ul class="list-disc ps-5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<nav aria-label="Seções da conta" class="mt-3 flex flex-wrap gap-2 text-sm">
  @foreach(['perfil' => 'Perfil', 'aparencia' => 'Aparência'] + ($patient ? ['modulos' => 'Módulos'] : []) + ['seguranca' => 'Segurança', 'email' => 'E-mail'] + ($patient ? ['dados' => 'Meus dados'] : []) + ['excluir' => 'Excluir conta'] as $id => $l)
    <a class="period-tab" href="#{{ $id }}">{{ $l }}</a>
  @endforeach
</nav>

<section id="perfil" class="card mt-4" aria-labelledby="perfil-t">
  <h2 id="perfil-t" class="font-semibold mb-3">Perfil</h2>
  <form method="post" action="{{ route('account.profile') }}" class="space-y-4">
    @csrf
    <div><label for="name" class="label">Nome</label><input id="name" name="name" class="input" autocomplete="name" value="{{ old('name', $u->name) }}" required></div>
    <div><label for="timezone" class="label">Fuso horário</label>
      <select id="timezone" name="timezone" class="input">
        @foreach(array_unique(array_merge([$u->timezone], $zones)) as $z)<option value="{{ $z }}" @selected($u->timezone === $z)>{{ str_replace(['America/', '_'], ['', ' '], $z) }}</option>@endforeach
      </select>
      <p class="hint mt-1">Define quando começa manhã, tarde e noite.</p></div>
    <button class="btn-primary">Salvar perfil</button>
  </form>
</section>

<section id="aparencia" class="card mt-4" aria-labelledby="ap-t">
  <h2 id="ap-t" class="font-semibold mb-3">Aparência</h2>
  <form method="post" action="{{ route('theme') }}" class="space-y-3">
    @csrf
    <fieldset><legend class="label">Tema</legend>
      <div class="grid gap-2 sm:grid-cols-3">
      @foreach($themes as $k => $l)<label class="check"><input type="radio" name="theme" value="{{ $k }}" @checked($u->theme === $k)> {{ $l }}</label>@endforeach
      </div></fieldset>
    <button class="btn-primary">Salvar tema</button>
    <p class="hint">Também dá para trocar pelo botão de sol/lua no topo.</p>
  </form>
</section>

@if($patient)
<section id="modulos" class="card mt-4" aria-labelledby="mod-t">
  <h2 id="mod-t" class="font-semibold mb-1">Módulos do Registrar</h2>
  <p class="hint mb-3">Blocos opcionais. Os marcados com cadeado pedem PIN ou senha para abrir.</p>
  <form method="post" action="{{ route('account.profile') }}" class="space-y-3">
    @csrf
    <input type="hidden" name="modulos_form" value="1">
    @foreach($modules as $k => $info)
      <label class="check"><input type="checkbox" name="modules[]" value="{{ $k }}" @checked(\App\Domain\Day\Blocks::enabledFor($k, $u->modules))>
        {{ $info }}</label>
    @endforeach
    <p class="hint">Pensamentos de morte fica desligado até você ligar. O app nunca avisa ninguém automaticamente; em crise: CVV 188 · SAMU 192.</p>
    <button class="btn-primary">Salvar módulos</button>
  </form>
</section>
@endif

<section id="instalar" class="card mt-4" aria-labelledby="inst-t">
  <h2 id="inst-t" class="font-semibold mb-1">Instalar o app</h2>
  <p class="hint">Instalado, o Bipolar abre como um aplicativo, em tela cheia, e o registro feito sem internet é enviado quando a rede voltar.</p>
  <p class="ok mt-2" data-pwa-instalado hidden>O app já está instalado neste aparelho.</p>
  <button type="button" class="btn-primary mt-3" data-pwa-instalar hidden>Instalar no aparelho</button>
  <div class="mt-3 text-sm" data-pwa-manual>
    <p><strong>iPhone ou iPad (Safari):</strong> toque em Compartilhar <span aria-hidden="true">(□↑)</span> e depois em <strong>Adicionar à Tela de Início</strong>.</p>
    <p class="mt-1"><strong>Android (Chrome):</strong> abra o menu <span aria-hidden="true">⋮</span> e toque em <strong>Instalar app</strong> ou <strong>Adicionar à tela inicial</strong>.</p>
  </div>
</section>
<section id="seguranca" class="card mt-4" aria-labelledby="seg-t">
  <h2 id="seg-t" class="font-semibold mb-3">Segurança</h2>
  <p class="mb-2">Desbloqueio de blocos privados: <strong>{{ $unlocked ? 'ativo nesta sessão (10 min)' : 'bloqueado' }}</strong>.</p>
  @if($unlocked)
    <form method="post" action="{{ route('stepup.lock') }}" class="mb-4">@csrf<input type="hidden" name="voltar" value="/conta#seguranca"><button class="btn-secondary">Bloquear agora</button></form>
  @endif
  <h3 class="font-medium mt-2">PIN do app</h3>
  <p class="hint mb-2">{{ $u->pin_hash ? 'PIN definido.' : 'Sem PIN: os blocos privados pedem a senha.' }} O PIN tem de 4 a 8 números.</p>
  <form method="post" action="{{ route('account.pin') }}" class="grid gap-3 sm:grid-cols-3 items-end">
    @csrf
    <div><label for="pin" class="label">Novo PIN</label><input id="pin" name="pin" type="password" inputmode="numeric" autocomplete="off" class="input" pattern="[0-9]{4,8}"></div>
    <div><label for="pin_confirmation" class="label">Repita o PIN</label><input id="pin_confirmation" name="pin_confirmation" type="password" inputmode="numeric" autocomplete="off" class="input"></div>
    <div><label for="pin-senha" class="label">Sua senha</label><input id="pin-senha" name="password" type="password" autocomplete="current-password" class="input" required></div>
    <div class="sm:col-span-3"><button class="btn-primary">Salvar PIN</button> <span class="hint">Deixe o PIN em branco para remover.</span></div>
  </form>
  <h3 class="font-medium mt-6">Verificação em duas etapas</h3>
  <p class="hint">Em breve: aplicativo autenticador e chave de acesso (passkey).</p>
</section>

<section id="email" class="card mt-4" aria-labelledby="em-t">
  <h2 id="em-t" class="font-semibold mb-1">E-mail</h2>
  <p class="mb-3">Atual: <strong>{{ $u->email }}</strong>@if($u->pending_email)<br><span class="badge badge-warn mt-1">Aguardando confirmação: {{ $u->pending_email }}</span>@endif</p>
  <form method="post" action="{{ route('account.email') }}" class="grid gap-3 sm:grid-cols-2 items-end">
    @csrf
    <div><label for="novo-email" class="label">Novo e-mail</label><input id="novo-email" name="email" type="email" autocomplete="email" class="input" required></div>
    <div><label for="em-senha" class="label">Sua senha</label><input id="em-senha" name="password" type="password" autocomplete="current-password" class="input" required></div>
    <div class="sm:col-span-2"><button class="btn-primary">Enviar confirmação</button>
      <p class="hint mt-1">Passo 1: enviamos um link ao novo endereço. Passo 2: abra o link logado(a) para concluir.</p></div>
  </form>
</section>

@if($patient)
<section id="dados" class="card mt-4" aria-labelledby="dad-t">
  <h2 id="dad-t" class="font-semibold mb-1">Meus dados</h2>
  <p class="hint mb-3">Baixe tudo o que você registrou (arquivo JSON). Seus dados são seus (LGPD).</p>
  <form method="post" action="{{ route('account.export') }}" class="space-y-3">
    @csrf
    <label class="check"><input type="checkbox" name="sensiveis" value="1" @disabled(!$unlocked)> Incluir blocos privados (sexualidade, pensamentos de morte)</label>
    @unless($unlocked)<p class="hint">Para incluir os privados, desbloqueie com PIN ou senha:</p>@endunless
    <button class="btn-primary">Baixar meus dados</button>
  </form>
  @unless($unlocked)
  <form method="post" action="{{ route('stepup') }}" class="mt-3 flex flex-wrap items-end gap-2">
    @csrf
    <input type="hidden" name="voltar" value="/conta#dados">
    <input type="hidden" name="method" value="{{ $u->pin_hash ? 'pin' : 'password' }}">
    <div><label for="su-exp" class="label">{{ $u->pin_hash ? 'PIN' : 'Senha' }}</label>
      <input id="su-exp" name="secret" type="password" class="input" required autocomplete="{{ $u->pin_hash ? 'off' : 'current-password' }}"></div>
    <button class="btn-secondary">Desbloquear</button>
  </form>
  @endunless
</section>
@endif

<section id="excluir" class="card mt-4 border-red-800 dark:border-red-400" aria-labelledby="ex-t">
  <h2 id="ex-t" class="font-semibold mb-1">Excluir conta</h2>
  <p class="mb-3">Apaga sua conta e todos os seus registros, sem volta. Antes, se quiser, baixe seus dados.</p>
  <form method="post" action="{{ route('account.delete') }}" class="grid gap-3 sm:grid-cols-2 items-end" x-data="confirmaPalavra">
    @csrf
    <div><label for="palavra" class="label">Digite EXCLUIR</label><input id="palavra" name="palavra" class="input" autocomplete="off" data-palavra="EXCLUIR" x-on:input="check" required></div>
    <div><label for="ex-senha" class="label">Sua senha</label><input id="ex-senha" name="password" type="password" autocomplete="current-password" class="input" required></div>
    <div class="sm:col-span-2"><button class="btn-danger" x-bind:disabled="bloqueado">Excluir minha conta</button></div>
  </form>
</section>
@endsection
