{{-- Controle segmentado acessivel: radios nativos (teclado/leitor de tela), rotulo + simbolo (cor nunca sozinha). --}}
<fieldset class="scale" x-data="scalePicker" data-initial="{{ $value ?? '' }}">
  <legend class="label">{{ $legend }} @if(!empty($optional))<span class="text-slate-600 font-normal">(opcional)</span>@endif</legend>
  @isset($hint)<p class="text-sm text-slate-700 mb-2" id="{{ $name }}-dica">{{ $hint }}</p>@endisset
  <div class="scale-options" role="presentation">
    @foreach($scale as $v => $info)
      @php $text = is_array($info) ? $info[0] : $info; $sym = is_array($info) ? $info[1] : ''; @endphp
      <label class="scale-option" data-level="{{ $v }}">
        <input type="radio" name="{{ $name }}" value="{{ $v }}" class="sr-only peer" x-on:change="pick"
          @checked((string)($value ?? '') === (string)$v) @if(empty($optional)) required @endif
          @isset($hint) aria-describedby="{{ $name }}-dica" @endisset>
        <span class="scale-chip" aria-hidden="true">{{ $sym }}</span>
        <span class="scale-text">{{ $v > 0 ? '+'.$v : $v }} · {{ $text }}</span>
      </label>
    @endforeach
  </div>
  <p class="text-sm mt-1" aria-live="polite">Selecionado: <strong x-text="label">{{ isset($value) && $value !== null ? (is_array($scale[$value]) ? $scale[$value][0] : $scale[$value]) : 'nenhum' }}</strong></p>
</fieldset>
