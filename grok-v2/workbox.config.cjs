// Service worker: so arquivos estaticos versionados. NUNCA cachear /api ou paginas com dados do paciente.
// splash/ (telas de abertura iOS) fica fora do precache: o iOS so le na instalacao.
module.exports = {
  globDirectory: 'public/',
  globPatterns: ['build/assets/*.{js,css,woff2,svg,png}', 'icons/*.{png,svg}', 'favicon.{ico,svg}', 'apple-touch-icon.png', 'manifest.webmanifest', 'offline.html'],
  swDest: 'public/sw.js',
  sourcemap: false,
  inlineWorkboxRuntime: true,
  cleanupOutdatedCaches: true,
  skipWaiting: false, // a nova versao espera o botao "Atualizar" (mensagem SKIP_WAITING)
  navigateFallback: '/offline.html',
  navigateFallbackDenylist: [/^\/api\//, /^\/instalar/],
  runtimeCaching: [
    { urlPattern: ({ url }) => url.pathname.startsWith('/api/'), handler: 'NetworkOnly' },
    { urlPattern: ({ request }) => request.mode === 'navigate', handler: 'NetworkOnly' },
  ],
};
