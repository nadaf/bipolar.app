{{-- Liga/desliga as animacoes do app (salvo no aparelho). Tambem segue o "reduzir movimento" do sistema. --}}
<button type="button" class="btn-secondary motion-toggle !min-h-11 !px-3 no-print" x-data="motionToggle" x-on:click="toggle" x-bind:aria-pressed="pressed" aria-pressed="false">
  <span x-text="label">Reduzir animações</span>
</button>
