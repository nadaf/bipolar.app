@extends('layouts.app')
@section('title', 'Exportar — Bipolar')
@section('content')
<p><a href="{{ $who ? route('care.index') : route('reports') }}">‹ {{ $who ? 'Pacientes' : 'Relatórios' }}</a></p>
<h1 class="text-xl font-semibold mt-2">Exportar registros{{ $who ? ' de '.$who : '' }}</h1>
<p class="hint mt-1">{{ $who ? 'Só entram as categorias que o(a) paciente liberou para você. A exportação fica registrada para ele(a).' : 'Seus registros de humor, energia, sono e anotações, para abrir em planilha ou levar à consulta.' }}</p>
@if($errors->any())<div class="alert mt-3" role="alert"><ul class="list-disc ps-5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif
<form method="post" action="{{ $action }}" class="card mt-4 space-y-4">
  @csrf
  <div class="grid gap-3 sm:grid-cols-2">
    <div><label for="de" class="label">De</label><input id="de" name="de" type="date" class="input" required value="{{ old('de', $de) }}"></div>
    <div><label for="ate" class="label">Até</label><input id="ate" name="ate" type="date" class="input" required value="{{ old('ate', $ate) }}"></div>
  </div>
  <fieldset><legend class="label">Formato</legend>
    <div class="grid gap-2 sm:grid-cols-2">
      <label class="check"><input type="radio" name="formato" value="csv" @checked(old('formato', 'csv') === 'csv')> <span><strong>Planilha (CSV)</strong><br><span class="hint">Abre no Excel, LibreOffice e Google Planilhas.</span></span></label>
      <label class="check"><input type="radio" name="formato" value="pdf" @checked(old('formato') === 'pdf')> <span><strong>Documento (PDF)</strong><br><span class="hint">Tabela pronta para imprimir ou enviar.</span></span></label>
    </div></fieldset>
  <button class="btn-primary">Baixar arquivo</button>
  <p class="hint">O arquivo tem nome neutro (bipolar-registros-data) e contém dados de saúde: guarde com cuidado. Máximo de 3 anos por arquivo.</p>
</form>
@endsection
