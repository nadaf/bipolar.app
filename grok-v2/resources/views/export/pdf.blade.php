<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><title>Registros</title>
<style>
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color: #0f172a; }
  h1 { font-size: 14pt; margin: 0 0 4pt; }
  p { margin: 0 0 8pt; color: #334155; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 0.5pt solid #94a3b8; padding: 3pt 4pt; text-align: left; vertical-align: top; }
  th { background: #e2e8f0; }
  tr:nth-child(even) td { background: #f8fafc; }
  .rodape { margin-top: 10pt; font-size: 8pt; color: #475569; }
</style></head>
<body>
<h1>Registros de humor</h1>
<p>{{ $name }} · {{ $from }} a {{ $to }} · gerado em {{ $generated }}</p>
@if($rows->isEmpty())
<p>Nenhum registro neste período.</p>
@else
<table>
  <thead><tr>@foreach($headers as $h)<th>{{ $h }}</th>@endforeach</tr></thead>
  <tbody>@foreach($rows as $r)<tr>@foreach($r as $c)<td>{{ $c }}</td>@endforeach</tr>@endforeach</tbody>
</table>
@endif
<p class="rodape">Documento confidencial (LGPD). Escala de humor: -3 depressão grave a +3 mania. Em crise: CVV 188 · SAMU 192.</p>
</body></html>
