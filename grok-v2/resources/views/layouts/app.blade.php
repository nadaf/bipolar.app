<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
{{-- htmx: sem estilos inline (CSP) e sem eval --}}
<meta name="htmx-config" content='{"includeIndicatorStyles":false,"allowEval":false,"allowScriptTags":false,"selfRequestsOnly":true}'>
<meta name="theme-color" content="#3b4f9a">
<link rel="manifest" href="/manifest.webmanifest">
<title>@yield('title', 'Bipolar — Diário de Humor')</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">
<a href="#conteudo" class="sr-only focus:not-sr-only focus:absolute focus:p-3 focus:bg-white">Pular para o conteúdo</a>
<header class="bg-white border-b border-slate-200">
  <div class="mx-auto max-w-2xl px-4 py-3 flex items-center justify-between">
    <span class="font-semibold">Bipolar</span>
    @auth
    <nav aria-label="Principal" class="flex items-center gap-4">
      <a class="btn-link" href="{{ route('diary.today') }}">Hoje</a>
      <a class="btn-link" href="{{ route('settings.alerts') }}">Alertas</a>
      <form method="post" action="{{ route('logout') }}">@csrf<button class="btn-link">Sair</button></form>
    </nav>
    @endauth
  </div>
</header>
<main id="conteudo" class="mx-auto max-w-2xl px-4 py-6">@yield('content')</main>
<footer class="mx-auto max-w-2xl px-4 pb-8 text-sm text-slate-700">
  Em crise? Ligue <a class="underline" href="tel:188">CVV 188</a> (24h, gratuito) ou <a class="underline" href="tel:192">SAMU 192</a>.
</footer>
</body>
</html>
