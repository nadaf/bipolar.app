/* Mostrar/ocultar senha e indicador de força (sem dependências) */
(function () {
  'use strict';
  document.querySelectorAll('.pw-toggle').forEach(function (b) {
    b.addEventListener('click', function () {
      var inp = document.getElementById(b.dataset.target);
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      b.textContent = show ? 'Ocultar' : 'Mostrar';
      b.setAttribute('aria-pressed', show ? 'true' : 'false');
      b.setAttribute('aria-label', (show ? 'Ocultar' : 'Mostrar') + ' senha');
    });
    b.setAttribute('aria-label', 'Mostrar senha');
  });
  var LEVELS = ['Muito fraca', 'Fraca', 'Razoável', 'Boa', 'Forte'];
  function score(pw) {
    if (!pw) return -1;
    var s = 0;
    if (pw.length >= 8) s++;
    if (pw.length >= 12) s++;
    if (pw.length >= 16) s++;
    var kinds = [/[a-z]/, /[A-Z]/, /[0-9]/, /[^A-Za-z0-9]/].filter(function (r) { return r.test(pw); }).length;
    if (kinds >= 3) s++;
    if (/^(.)\1+$/.test(pw) || /^(123|abc|qwe|senha|password)/i.test(pw)) s = Math.min(s, 1);
    if (pw.length < 8) s = 0;
    return Math.min(s, 4);
  }
  document.querySelectorAll('input[data-meter]').forEach(function (inp) {
    var out = document.getElementById(inp.dataset.meter);
    var bar = inp.closest('.field').querySelector('.pw-meter');
    var t;
    inp.addEventListener('input', function () {
      var s = score(inp.value);
      bar.dataset.level = String(s);
      clearTimeout(t);
      t = setTimeout(function () {
        out.textContent = s < 0 ? '' : 'Força da senha: ' + LEVELS[s] + (inp.value.length < 8 ? ' (mínimo de 8 caracteres)' : '');
      }, 400);
    });
  });
  var err = document.getElementById('form-errors');
  if (err) err.focus();
})();
