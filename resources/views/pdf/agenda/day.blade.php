<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Agenda diaria</title>
<style>
@include('pdf.agenda.partials.styles')
.day-list { border-left: 3px solid #D61A00; margin: 16px 0; }
.day-event { padding: 11px 12px; border-bottom: 1px solid #e5e7eb; display: table; width: 100%; }
.day-event:last-child { border-bottom: 0; }
.event-time { display: table-cell; width: 94px; vertical-align: top; font-size: 12px; font-weight: 700; color: #374151; }
.event-color { display: table-cell; width: 5px; padding: 0; background: #6b7280; }
.event-content { display: table-cell; padding-left: 12px; vertical-align: top; }
.event-type { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
.event-title { font-size: 13px; font-weight: 700; color: #111827; margin: 2px 0 5px; }
.event-meta { font-size: 10px; color: #4b5563; }
.empty { padding: 32px; text-align: center; color: #6b7280; border: 1px dashed #d1d5db; border-radius: 8px; }
</style>
</head>
<body>
@include('pdf.agenda.partials.header', compact('titulo', 'fechaInicio', 'fechaFin', 'leyenda', 'tiposActivos'))
@if($events->isEmpty())
  <div class="empty">No hay eventos programados para este día.</div>
@else
  <div class="day-list">
    @foreach($events as $event)
      <div class="day-event">
        <div class="event-time">{{ substr($event['hora_inicio'], 0, 5) }} - {{ substr($event['hora_fin'], 0, 5) }}</div>
        <div class="event-color" style="background: {{ $event['color'] }}"></div>
        <div class="event-content">
          <div class="event-type" style="color: {{ $event['color'] }}">{{ $event['tipo_label'] }}</div>
          <div class="event-title">{{ $event['titulo'] }}</div>
          <div class="event-meta">
            @if($event['aula_nombre']) {{ $event['aula_nombre'] }} @endif
            @if($event['aula_nombre'] && $event['instructor_nombre']) · @endif
            @if($event['instructor_nombre']) {{ $event['instructor_nombre'] }} @endif
            @if($event['participantes_count'] !== null) · {{ $event['participantes_count'] }} participantes @endif
          </div>
        </div>
      </div>
    @endforeach
  </div>
@endif
</body>
</html>
