<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Instalação — Bipolar</title></head>
<body>
<main>
<h1>Instalação / atualização do banco de dados</h1>
@if($error)<p role="alert"><strong>{{ $error }}</strong></p>@endif
@if($done)
  <p role="status">Pronto! As tabelas foram criadas/atualizadas.</p>
  <pre>{{ $done }}</pre>
  <p><strong>Agora apague a linha INSTALLER_TOKEN do arquivo .env</strong> (Gerenciador de Arquivos do cPanel).</p>
@else
  <form method="post" action="{{ '/'.request()->path() }}">
    <label for="token">Código de instalação (o mesmo que você colocou em INSTALLER_TOKEN no .env)</label><br>
    <input id="token" name="token" type="password" required autocomplete="off" size="50">
    <button>Instalar / atualizar</button>
  </form>
@endif
</main></body></html>
