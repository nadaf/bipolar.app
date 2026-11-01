<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><meta name="referrer" content="no-referrer"><title>Trazer dados da versão antiga — Bipolar</title>
<script src="/theme-init.js"></script>
@vite(['resources/css/app.css'])</head>
<body>
<header><div class="mx-auto max-w-3xl px-4 h-13 flex items-center"><a href="/" class="brand font-semibold">Bipolar</a></div></header>
<main id="conteudo" class="main migrar space-y-4">
<h1>Trazer os dados da versão antiga (0.5)</h1>
<p>O banco antigo só é <strong>lido</strong>; nada é apagado ou alterado nele. Pode clicar quantas vezes precisar: o que já foi copiado não é copiado de novo.</p>
@if($message)<p role="status"><strong>{{ $message }}</strong></p>@endif
@if(! $message && $appKeyProblem)<p role="alert"><strong>{{ $appKeyProblem }}</strong></p>@endif
@if(! $message && $oldKeyProblem)<p role="alert"><strong>{{ $oldKeyProblem }}</strong></p>@endif
@if(! $message)
<h2>Situação</h2>
<ul>
  <li>Banco antigo (linhas OLD_DB_ no .env): {{ $noOld ? 'não configurado' : 'configurado' }}</li>
  <li>Chave atual (APP_KEY): {{ $appKeyProblem ? 'com problema (veja o aviso)' : 'ok' }}</li>
  <li>Chave antiga (OLD_APP_KEY, opcional): {{ $oldKeyProblem ? 'com problema (veja o aviso)' : 'ok ou não usada' }}</li>
  @if($refreshing)<li><strong>Cópia de novo em andamento: clique em "Continuar a cópia de novo".</strong></li>@endif
</ul>
@endif
@if($error)<p role="alert"><strong>{{ $error }}</strong></p>@endif
@if($totals)
  <h2>Resumo (só números)</h2>
  <ul>
    @if($totals['old_accounts'] !== null)<li>Contas na versão antiga: {{ $totals['old_accounts'] }}</li>@endif
    <li>Contas com os dados no 2.0: {{ $totals['accounts'] }}</li>
    <li>Contas não copiadas: {{ $totals['accounts_skipped'] }} (o motivo está na lista abaixo, em "Contas")</li>
    <li>Registros de humor no 2.0 dessas contas: {{ $totals['entries'] }}</li>
    <li>Marcadores do dia no 2.0 dessas contas: {{ $totals['blocks'] }}</li>
  </ul>
@endif
@if($report)
  <h2>{{ $report['done'] ? 'Pronto! Tudo foi copiado.' : 'Ainda falta uma parte: clique em "Continuar".' }}</h2>
  <ul>
  @foreach($report['steps'] as $s)
    <li><strong>{{ $s['label'] }}</strong>: {{ $s['copied'] }} copiados, {{ $s['skipped'] }} ignorados{{ $s['done'] ? '' : ' (em andamento)' }}
      @if($s['notes'])<ul>@foreach($s['notes'] as $why => $n)<li>{{ $n }} — {{ $why }}</li>@endforeach</ul>@endif
    </li>
  @endforeach
  </ul>
  @if($notCopied)
    <h2>Não existe no 2.0 (fica só no banco antigo)</h2>
    <ul>@foreach($notCopied as $what => $n)<li>{{ $what }}: {{ $n }}</li>@endforeach</ul>
  @endif
@endif
@if($repair)
    <h2>Conferência dos dados{{ $repair['done'] ? '' : ' (em andamento: clique em "Consertar dados" de novo)' }}</h2>
    <ul>
      <li>{{ $repair['ok'] }} itens abrem normalmente</li>
      <li>{{ $repair['fixed'] }} consertados com a chave antiga (OLD_APP_KEY)</li>
      @if($repair['cleared'])<li><strong>{{ $repair['cleared'] }} anotações/marcadores não puderam ser lidos sem a chave antiga e foram limpos</strong> (ainda estão no banco antigo; copie de novo para trazer)</li>@endif
      @if($repair['native'])<li><strong>{{ $repair['native'] }} anotações/marcadores criados no 2.0 não abrem com a chave atual nem com a OLD_APP_KEY.</strong> Se tiver a APP_KEY antiga, coloque em OLD_APP_KEY e clique em "Consertar dados". Se não tiver, eles não podem ser recuperados: use "Limpar os que não abrem".</li>@endif
    </ul>
@endif
@if($report)
  @if($report['done'])<p>Agora entre com uma conta antiga para conferir e depois apague as linhas OLD_DB_ do .env.</p>@endif
@endif
@if(! $message)
  @if($noOld)<p role="status"><strong>Faltam as linhas OLD_DB_ no arquivo .env (dados do banco antigo). Sem elas dá para usar só "Consertar dados". Veja o LEIA-ME.</strong></p>@endif
  <form method="post" action="/migrar-dados">
    <label for="token">Código (o mesmo do INSTALLER_TOKEN no .env)</label><br>
    <input id="token" name="token" type="password" required autocomplete="off" size="50" value="{{ $token }}">
    @unless($noOld)
      @if($refreshing)<button name="acao" value="recopiar">Continuar a cópia de novo</button>
      @else<button name="acao" value="copiar">{{ $report && ! $report['done'] ? 'Continuar' : 'Copiar dados da versão antiga' }}</button>
      <button name="acao" value="recopiar">Copiar tudo de novo (não precisa de chave)</button>@endif
    @endunless
    <button name="acao" value="consertar">Consertar dados</button>
    @if($repair && $repair['native'])<button name="acao" value="limpar">Limpar os {{ $repair['native'] }} que não abrem</button>@endif
  </form>
@endif
</main></body></html>
