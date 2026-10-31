@php
  $u = auth()->user();
  $m = request()->attributes->get('membership');
  $role = $m?->role;
  $nav = match ($role) {
      'patient' => [
          ['diary.today', 'Registrar', 'registrar', ['diary.*']],
          ['history', 'Histórico', 'historico', ['history*']],
          ['reports', 'Relatórios', 'relatorios', ['reports*']],
          ['share', 'Compartilhar', 'compartilhar', ['share*']],
          ['account', 'Conta', 'conta', ['account*']],
      ],
      'professional' => [['care.index', 'Pacientes', 'pacientes', ['care.*']], ['account', 'Conta', 'conta', ['account*']]],
      'admin' => [['clinic', 'Clínica', 'clinica', ['clinic*']], ['care.index', 'Pacientes', 'pacientes', ['care.*']], ['account', 'Conta', 'conta', ['account*']]],
      default => [],
  };
  $extra = $role === 'patient' ? [['alerts', 'Alertas e lembretes', 'alertas', ['alerts*']], ['care.index', 'Quem me deu acesso', 'pacientes', ['care.*']]] : [];
  $isCurrent = fn (array $pats) => collect($pats)->contains(fn ($p) => request()->routeIs($p));
@endphp
<!doctype html>
<html lang="pt-BR" @if($u) data-tema="{{ $u->theme }}" @endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
@if($u)<meta name="owner" content="{{ hash('sha256', $u->id.'|'.config('app.key')) }}">@endif
{{-- htmx: sem estilos inline (CSP), sem eval; 409/422/423 trocam o fragmento (mensagem acessivel) --}}
<meta name="htmx-config" content='{"includeIndicatorStyles":false,"allowEval":false,"allowScriptTags":false,"selfRequestsOnly":true,"responseHandling":[{"code":"204","swap":false},{"code":"[23]..","swap":true},{"code":"409","swap":true,"error":false},{"code":"422","swap":true,"error":false},{"code":"423","swap":true,"error":false},{"code":"[45]..","swap":false,"error":true}]}'>
<meta name="theme-color" content="#3b4f9a" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#020617" media="(prefers-color-scheme: dark)">
<meta name="description" content="Diário de humor para pessoas com transtorno bipolar: registro por período, histórico e relatório para o profissional.">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Bipolar">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
@include('partials.apple-splash')
<meta property="og:title" content="Bipolar — Diário de Humor">
<meta property="og:description" content="Você no controle dos seus dados. Blog: blog.bipolar.app.br">
<meta property="og:image" content="{{ url('/og-image.png') }}">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<title>@yield('title', 'Bipolar — Diário de Humor')</title>
<script src="/theme-init.js"></script>
@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
{{-- abertura: so no app instalado e so na 1a carga da sessao (theme-init.js marca); decorativa --}}
<div id="abertura" aria-hidden="true"><img src="/favicon.svg" alt="" width="96" height="96"><span>Bipolar</span></div>
<a href="#conteudo" class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-white focus:p-3 focus:text-slate-900">Pular para o conteúdo</a>
<div class="{{ $nav ? 'shell' : '' }}">
  @if($nav)
  <aside class="sidebar" aria-label="Menu lateral">
    <p class="px-3 pb-4 text-lg font-bold">Bipolar</p>
    <nav aria-label="Principal">
      <ul class="space-y-1">
      @foreach(array_merge($nav, $extra) as [$r, $label, $icon, $pats])
        <li><a class="side-link" href="{{ route($r) }}" @if($isCurrent($pats)) aria-current="page" @endif>@include('partials.icon', ['name' => $icon]) {{ $label }}</a></li>
      @endforeach
      </ul>
    </nav>
    <div class="mt-auto space-y-2">
      @include('partials.theme-toggle')
      <form method="post" action="{{ route('logout') }}">@csrf<button class="side-link w-full">@include('partials.icon', ['name' => 'sair']) Sair</button></form>
    </div>
  </aside>
  <header class="topbar">
    <div class="flex items-center justify-between px-4 h-13">
      <span class="font-bold">Bipolar</span>
      @include('partials.theme-toggle')
    </div>
  </header>
  @else
  <header class="border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
    <div class="mx-auto max-w-3xl px-4 h-13 flex items-center justify-between">
      <span class="font-bold">Bipolar</span>
      @include('partials.theme-toggle')
    </div>
  </header>
  @endif
  <div>
    <main id="conteudo" class="main" tabindex="-1">
      @if(session('ok'))<p class="ok mb-4" role="status">{{ session('ok') }}</p>@endif
      @yield('content')
    </main>
    <footer class="crisis-line">
      Em crise? Ligue <a class="underline" href="tel:188">CVV 188</a> (24h, gratuito) ou <a class="underline" href="tel:192">SAMU 192</a>. Este app nunca avisa ninguém automaticamente.
    </footer>
  </div>
</div>
@if($nav)
<nav class="tabbar {{ count($nav) === 3 ? 'tabbar-3' : (count($nav) === 2 ? 'grid-cols-2' : '') }}" aria-label="Abas">
  @foreach($nav as [$r, $label, $icon, $pats])
    <a class="tab" href="{{ route($r) }}" @if($isCurrent($pats)) aria-current="page" @endif>@include('partials.icon', ['name' => $icon]) <span>{{ $label }}</span></a>
  @endforeach
</nav>
@endif
</body>
</html>
