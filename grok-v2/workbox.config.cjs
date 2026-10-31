// Service worker: so arquivos estaticos versionados. NUNCA cachear /api ou paginas com dados do paciente.
module.exports = {
  globDirectory: 'public/',
  globPatterns: ['build/assets/*.{js,css,woff2,svg,png}', 'icons/*.png', 'manifest.webmanifest', 'offline.html'],
  swDest: 'public/sw.js',
  sourcemap: false,
  inlineWorkboxRuntime: true,
  cleanupOutdatedCaches: true,
  navigateFallback: '/offline.html',
  navigateFallbackDenylist: [/^\/api\//, /^\/instalar/],
  runtimeCaching: [
    { urlPattern: ({ url }) => url.pathname.startsWith('/api/'), handler: 'NetworkOnly' },
    { urlPattern: ({ request }) => request.mode === 'navigate', handler: 'NetworkOnly' },
  ],
};
