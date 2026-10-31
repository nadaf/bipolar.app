// Tema antes da pintura (arquivo externo: CSP sem inline). Preferencia salva > aparelho.
(function () {
  var d = document.documentElement;
  var t = d.getAttribute('data-tema') || '';
  try { if (!t) t = localStorage.getItem('tema') || 'sistema'; } catch (e) { t = t || 'sistema'; }
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  function apply() { d.classList.toggle('dark', t === 'escuro' || (t === 'sistema' && !!mq && mq.matches)); }
  d.setAttribute('data-tema-ativo', t);
  apply();
  // abertura do app: so na primeira carga da sessao
  try { if (sessionStorage.getItem('aberto')) d.classList.add('sem-abertura'); else sessionStorage.setItem('aberto', '1'); } catch (e) { d.classList.add('sem-abertura'); }
  if (mq && mq.addEventListener) mq.addEventListener('change', function () { t = d.getAttribute('data-tema-ativo') || 'sistema'; apply(); });
})();
