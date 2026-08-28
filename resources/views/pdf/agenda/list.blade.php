<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Agenda en lista</title>
<style>
@include('pdf.agenda.partials.styles')
.date-title { font-size: 12px; font-weight: 700; color: #111827; margin: 16px 0 6px; text-transform: capitalize; }
.agenda-table { width: 100%; border-collapse: collapse; font-size: 9px; }
.agenda-table th { background: #f3f4f6; color: #4b5563; text-align: left; padding: 7px; text-transform: uppercase; letter-spacing: .04em; }
.agenda-table td { padding: 7px; border-bottom: 1px solid #e5e7eb; vertical-align: top; color: #374151; }
.type-marker { display: inline-block; width: 7px; height: 7px; border-radius: 50%; margin-right: 4px; }
.event-name { font-weight: 700; color: #111827; }
.empty { padding: 32px; text-align: center; color: #6b7280; border: 1px dashed #d1d5db; border-radius: 8px; }
</style>
</head>
<body>
@include('pdf.agenda.partials.header', compact('titulo', 'fechaInicio', 'fechaFin', 'leyenda', 'tiposActivos'))
@forelse($eventsByDate as $date => $events)
  <h2 class="date-title">{{ \Carbon\Carbon::parse($date)->isoFormat('dddd, D [de] MMMM [de] YYYY') }}</h2>
  <table class="agenda-table">
    <thead><tr><th>Horario</th><th>Tipo</th><th>Evento</th><th>Ubicación / responsable</th><th>Estado</th></tr></thead>
    <tbody>
      @foreach($events as $event)
        <tr>
          <td>{{ substr($event['hora_inicio'], 0, 5) }} - {{ substr($event['hora_fin'], 0, 5) }}</td>
          <td><span class="type-marker" style="background: {{ $event['color'] }}"></span>{{ $event['tipo_label'] }}</td>
          <td class="event-name">{{ $event['titulo'] }}</td>
          <td>{{ $event['aula_nombre'] ?? $event['instructor_nombre'] ?? '—' }}</td>
          <td>{{ $event['estado'] ? ucfirst(str_replace('_', ' ', $event['estado'])) : '—' }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
@empty
  <div class="empty">No hay eventos para el período seleccionado.</div>
@endforelse
</body>
</html>
