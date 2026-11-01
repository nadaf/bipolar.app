<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><meta name="referrer" content="no-referrer"><title>Trazer dados da versão antiga — Bipolar</title></head>
<body>
<main>
<h1>Trazer os dados da versão antiga (0.5)</h1>
<p>O banco antigo só é <strong>lido</strong>; nada é apagado ou alterado nele. Pode clicar quantas vezes precisar: o que já foi copiado não é copiado de novo.</p>
@if($message)<p role="status"><strong>{{ $message }}</strong></p>@endif
@if($error)<p role="alert"><strong>{{ $error }}</strong></p>@endif
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
  @if($report['done'])<p>Agora entre com uma conta antiga para conferir e depois apague as linhas OLD_DB_ do .env.</p>@endif
@endif
@if(! $message)
  <form method="post" action="/migrar-dados">
    <label for="token">Código (o mesmo do INSTALLER_TOKEN no .env)</label><br>
    <input id="token" name="token" type="password" required autocomplete="off" size="50" value="{{ $token }}">
    <button>{{ $report && ! $report['done'] ? 'Continuar' : 'Copiar dados da versão antiga' }}</button>
  </form>
@endif
</main></body></html>
