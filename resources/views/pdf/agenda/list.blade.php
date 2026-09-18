<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><title>Agenda en lista</title><style>
@include('pdf.agenda.partials.styles')
.date-title { margin: 15px 0 6px; font-size: 12px; font-weight: 700; color: #0b1c30; text-transform: capitalize; }.agenda-table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 8px; }.agenda-table thead { background: #eff4ff; color: #45464d; }.agenda-table th { padding: 8px 7px; text-align: left; font-size: 7px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }.agenda-table th:first-child { border-radius: 8px 0 0 8px; }.agenda-table th:last-child { border-radius: 0 8px 8px 0; }.agenda-table td { padding: 9px 7px; border-bottom: 1px solid #dce9ff; vertical-align: middle; color: #45464d; }.agenda-table .time { font-weight: 700; color: #0b1c30; white-space: nowrap; }.event-type { display: inline-block; padding: 3px 6px; border-radius: 9px; font-size: 7px; font-weight: 700; white-space: nowrap; }.event-name { font-weight: 700; color: #0b1c30; }.empty { padding: 32px; text-align: center; color: #76777d; border: 1px dashed #c6c6cd; border-radius: 8px; }
</style></head><body>
@include('pdf.agenda.partials.header', compact('titulo', 'fechaInicio', 'fechaFin', 'leyenda', 'tiposActivos'))
@forelse($eventsByDate as $date => $events)
  <div class="agenda-card"><h2 class="date-title">{{ \Carbon\Carbon::parse($date)->isoFormat('dddd, D [de] MMMM [de] YYYY') }}</h2>
  <table class="agenda-table"><thead><tr><th>Hora</th><th>Servicio</th><th>Actividad / evento</th><th>Aula / ciudad</th><th>Responsable</th></tr></thead><tbody>
  @foreach($events as $event)<tr><td class="time">{{ substr($event['hora_inicio'], 0, 5) }} - {{ substr($event['hora_fin'], 0, 5) }}</td><td><span class="event-type" style="background: {{ $event['soft_color'] }}; color: {{ $event['text_color'] }};">{{ $event['tipo_label'] }}</span></td><td class="event-name">{{ $event['titulo'] }}</td><td>{{ $event['aula_nombre'] ?? $event['ciudad_nombre'] ?? '—' }}</td><td>{{ $event['instructor_nombre'] ?? '—' }}</td></tr>@endforeach
  </tbody></table></div>
@empty <div class="empty">No hay eventos para el período seleccionado.</div>
@endforelse
</body></html>
