<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><title>Agenda diaria</title><style>
@include('pdf.agenda.partials.styles')
.day-section { margin-bottom: 18px; page-break-inside: avoid; }.day-heading { display: table; width: 100%; margin: 2px 0 9px; padding-bottom: 8px; border-bottom: 1px solid #dce9ff; }
.date-number { display: table-cell; width: 34px; height: 34px; padding-top: 6px; border-radius: 8px; background: #fd761a; color: #fff; text-align: center; font-size: 15px; font-weight: 800; }.date-copy { display: table-cell; padding-left: 10px; vertical-align: middle; }.date-copy strong { display: block; font-size: 12px; color: #0b1c30; text-transform: capitalize; }.date-copy span { display: block; margin-top: 2px; font-size: 8px; color: #45464d; }
.day-list { border-left: 3px solid #fd761a; }.day-event { display: table; width: 100%; padding: 10px 8px; border-bottom: 1px solid #dce9ff; }.day-event:last-child { border-bottom: 0; }.event-time { display: table-cell; width: 76px; vertical-align: top; font-size: 11px; font-weight: 700; }.event-content { display: table-cell; padding-left: 10px; vertical-align: top; }.event-type { display: inline-block; padding: 2px 6px; border-radius: 9px; font-size: 7px; font-weight: 700; }.event-title { margin: 4px 0 3px; font-size: 12px; font-weight: 700; color: #0b1c30; }.event-meta { font-size: 8px; color: #45464d; }.empty { padding: 32px; text-align: center; color: #76777d; border: 1px dashed #c6c6cd; border-radius: 8px; }
</style></head><body>
@include('pdf.agenda.partials.header', compact('titulo', 'fechaInicio', 'fechaFin', 'leyenda', 'tiposActivos'))
@php($eventsByDate = $events->groupBy('fecha'))
@forelse($eventsByDate as $date => $dayEvents)
  @php($dateObject = \Carbon\Carbon::parse($date))
  <div class="day-section agenda-card"><div class="day-heading"><span class="date-number">{{ $dateObject->format('d') }}</span><span class="date-copy"><strong>{{ $dateObject->isoFormat('dddd, D [de] MMMM [de] YYYY') }}</strong><span>{{ $dayEvents->count() }} {{ $dayEvents->count() === 1 ? 'compromiso' : 'compromisos' }} programados</span></span></div>
    <div class="day-list">@foreach($dayEvents as $event)<div class="day-event"><div class="event-time" style="color: {{ $event['text_color'] }};">{{ substr($event['hora_inicio'], 0, 5) }}</div><div class="event-content"><span class="event-type" style="background: {{ $event['soft_color'] }}; color: {{ $event['text_color'] }};">{{ $event['tipo_label'] }}</span><div class="event-title">{{ $event['titulo'] }}</div><div class="event-meta">{{ collect([$event['aula_nombre'], $event['instructor_nombre'], $event['participantes_count'] !== null ? $event['participantes_count'].' participantes' : null])->filter()->join(' · ') }}</div></div></div>@endforeach</div>
  </div>
@empty <div class="empty">No hay eventos programados para este día.</div>
@endforelse
</body></html>
