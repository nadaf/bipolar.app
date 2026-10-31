<p class="ok">Registro salvo: {{ $entry->period->label() }} de {{ $entry->entry_date->format('d/m') }}
 — humor {{ \App\Domain\Mood\Scales::label(\App\Domain\Mood\Scales::MOOD, $entry->mood) }}@if($entry->energy !== null), energia {{ \App\Domain\Mood\Scales::label(\App\Domain\Mood\Scales::ENERGY, $entry->energy) }}@endif.</p>
<input type="hidden" id="version" name="version" value="{{ $entry->version }}" hx-swap-oob="true">
