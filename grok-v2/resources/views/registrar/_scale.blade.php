{{-- Escala segmentada acessivel: radios nativos, rotulo + simbolo (cor nunca sozinha). --}}
@php $id = $id ?? str_replace(['[', ']'], ['-', ''], $name); @endphp
<fieldset class="scale" x-data="scalePicker">
  <legend class="label">{{ $legend }}</legend>
  @isset($hint)<p class="hint mb-2" id="{{ $id }}-dica">{{ $hint }}</p>@endisset
  <div class="{{ count($scale) === 4 ? 'scale-options-4' : 'scale-options' }}">
    @foreach($scale as $v => $info)
      @php $text = is_array($info) ? $info[0] : $info; $sym = is_array($info) ? $info[1] : null; @endphp
      <label class="scale-option" data-level="{{ $sym !== null ? $v : '' }}" data-text="{{ $text }}">
        <input type="radio" name="{{ $name }}" value="{{ $v }}" class="sr-only" x-on:change="pick"
          @checked($value !== null && (string) $value === (string) $v) @isset($hint) aria-describedby="{{ $id }}-dica" @endisset>
        @if($sym !== null)
          <span class="scale-chip" aria-hidden="true">{{ $sym }}</span>
          <span>{{ $v > 0 ? '+'.$v : $v }} · {{ $text }}</span>
        @else
          <span class="int-chip" aria-hidden="true">{{ $v }}</span><span>{{ $text }}</span>
        @endif
      </label>
    @endforeach
  </div>
  <p class="text-sm mt-1">Selecionado: <strong x-text="label">{{ $value !== null && isset($scale[$value]) ? (is_array($scale[$value]) ? $scale[$value][0] : $scale[$value]) : 'nenhum' }}</strong></p>
</fieldset>
