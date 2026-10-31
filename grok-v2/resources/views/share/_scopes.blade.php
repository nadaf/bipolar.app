<fieldset>
  <legend class="label">O que essa pessoa pode ver</legend>
  <p class="hint mb-2">Somente leitura. Categorias privadas vêm desmarcadas e pedem desbloqueio.</p>
  <div class="grid gap-2 sm:grid-cols-2">
  @foreach($scopes as $k => $l)
    @php $sens = in_array($k, $sensitive, true); @endphp
    <label class="check {{ $sens ? 'border-violet-700 dark:border-violet-400' : '' }}"><input type="checkbox" name="scope[]" value="{{ $k }}" @checked(in_array($k, $checked, true))>
      <span>{{ $l }}@if($sens) <span class="badge badge-muted ms-1">privado</span>@endif</span></label>
  @endforeach
  </div>
</fieldset>
