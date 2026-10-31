// Service worker: so arquivos estaticos versionados. NUNCA cachear /api ou paginas com dados do paciente.
// splash/ (telas de abertura iOS) fica fora do precache: o iOS so le na instalacao.
module.exports = {
  globDirectory: 'public/',
  globPatterns: ['build/assets/*.{js,css,woff2,svg,png}', 'icons/*.{png,svg}', 'favicon.{ico,svg}', 'apple-touch-icon.png', 'manifest.webmanifest', 'offline.html', 'offline.css', 'push-sw.js'],
  swDest: 'public/sw.js',
  sourcemap: false,
  inlineWorkboxRuntime: true,
  cleanupOutdatedCaches: true,
  importScripts: ['/push-sw.js'], // push + clique na notificacao (texto neutro)
  skipWaiting: false, // a nova versao espera o botao "Atualizar" (mensagem SKIP_WAITING)
  // Sem navigateFallback: ele serve offline.html para TODA navegacao (padrao app-shell), mesmo online.
  // Paginas vao sempre a rede; so quando a rede falha entra a pagina offline (precacheFallback).
  runtimeCaching: [
    { urlPattern: ({ url }) => url.pathname.startsWith('/api/'), handler: 'NetworkOnly' },
    { urlPattern: ({ request }) => request.mode === 'navigate', handler: 'NetworkOnly', options: { precacheFallback: { fallbackURL: '/offline.html' } } },
  ],
};
