{{-- Alterna Seguir o aparelho -> Claro -> Escuro. Salva no aparelho e (logado) na conta. --}}
<button type="button" class="btn-secondary !min-h-11 !px-3 no-print" x-data="themeToggle" x-on:click="next" data-url="{{ auth()->check() ? route('theme') : '' }}"
  aria-live="polite">
  <span class="sr-only">Tema:</span>
  <span x-text="label">Tema</span>
</button>
