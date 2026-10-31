(function () {
  // V5.1: aplica a escolha salva antes de pintar a página (sem piscar). Só aceita 'dark' ou 'light'.
  try { var t = localStorage.getItem('bp-theme'); if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t); } catch { /* armazenamento indisponível */ }
})();
