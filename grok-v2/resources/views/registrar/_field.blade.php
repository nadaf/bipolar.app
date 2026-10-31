{{-- Campo de bloco gerado do catalogo App\Domain\Day\Blocks. $f = definicao, $value = valor atual. --}}
@php $nm = "data[$fname]"; $id = "f-$bkey-$fname"; @endphp
@switch($f['type'])
  @case('intensity')
    @include('registrar._scale', ['name' => $nm, 'id' => $id, 'legend' => $f['label'], 'scale' => \App\Domain\Mood\Scales::INTENSITY, 'value' => $value])
    @break
  @case('bipolar')
    @php $sc = []; foreach ($f['labels'] as $k => $l) { $sc[$k] = [$l, $k < 0 ? str_repeat('▼', -$k) : ($k > 0 ? str_repeat('▲', $k) : '●')]; } @endphp
    @include('registrar._scale', ['name' => $nm, 'id' => $id, 'legend' => $f['label'], 'scale' => $sc, 'value' => $value])
    @break
  @case('choice')
    <fieldset class="scale">
      <legend class="label">{{ $f['label'] }}</legend>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
      @foreach($f['options'] as $ov => $ol)
        <label class="check radio"><input type="radio" name="{{ $nm }}" value="{{ $ov }}" @checked($value !== null && (string) $value === (string) $ov)> {{ $ol }}</label>
      @endforeach
      </div>
    </fieldset>
    @break
  @case('multi')
    <fieldset class="scale">
      <legend class="label">{{ $f['label'] }}</legend>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
      @foreach($f['options'] as $ov => $ol)
        <label class="check"><input type="checkbox" name="{{ $nm }}[]" value="{{ $ov }}" @checked(in_array($ov, (array) $value, true))> {{ $ol }}</label>
      @endforeach
      </div>
    </fieldset>
    @break
  @case('bool')
    <label class="check"><input type="checkbox" name="{{ $nm }}" value="1" @checked((bool) $value)> {{ $f['label'] }}</label>
    @break
  @case('text')
    <div>
      <label class="label" for="{{ $id }}">{{ $f['label'] }}</label>
      <textarea id="{{ $id }}" name="{{ $nm }}" rows="{{ $f['rows'] ?? 2 }}" maxlength="{{ $f['max'] }}" class="input">{{ $value }}</textarea>
    </div>
    @break
  @case('time')
    <div>
      <label class="label" for="{{ $id }}">{{ $f['label'] }}</label>
      <input id="{{ $id }}" type="time" name="{{ $nm }}" value="{{ $value }}" class="input input-sm">
    </div>
    @break
  @default
    <div>
      <label class="label" for="{{ $id }}">{{ $f['label'] }}</label>
      <input id="{{ $id }}" type="number" inputmode="{{ $f['type'] === 'decimal' ? 'decimal' : 'numeric' }}" name="{{ $nm }}" value="{{ $value }}"
        min="{{ $f['min'] }}" max="{{ $f['max'] }}" step="{{ $f['step'] ?? 1 }}" class="input input-sm">
    </div>
@endswitch
