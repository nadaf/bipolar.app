{{-- Pagina de erro independente (sem auth/banco): texto em pt-BR, sem dado de saude, com caminho de volta. --}}
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>@yield('title') — Bipolar</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
@vite(['resources/css/app.css'])
</head>
<body>
  <header class="border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
    <div class="mx-auto max-w-3xl px-4 h-13 flex items-center">
      <a href="/" class="font-semibold no-underline text-slate-900 dark:text-slate-100">Bipolar</a>
    </div>
  </header>
  <main id="conteudo" class="main">
    <div class="mx-auto max-w-md card">
      <h1 class="text-2xl font-semibold mb-2">@yield('title')</h1>
      <p class="mb-4">@yield('message')</p>
      <p><a class="btn-primary inline-flex" href="@yield('href', '/')">@yield('action', 'Ir para o início')</a></p>
    </div>
  </main>
  <footer class="crisis-line">
    Em crise? Ligue <a class="underline" href="tel:188">CVV 188</a> (24h, gratuito) ou <a class="underline" href="tel:192">SAMU 192</a>. Este app nunca avisa ninguém automaticamente.
  </footer>
</body>
</html>
