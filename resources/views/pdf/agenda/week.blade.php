<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><title>Agenda semanal</title><style>
@include('pdf.agenda.partials.styles')
.week-block { page-break-inside: avoid; page-break-after: always; }.week-block:last-child { page-break-after: auto; }
.week-title { margin: 0 0 10px; padding-bottom: 7px; border-bottom: 1px solid #dce9ff; font-size: 12px; font-weight: 700; color: #0b1c30; }
.week-grid { width: 100%; border-collapse: separate; border-spacing: 5px; table-layout: fixed; }.week-grid td { padding: 0; vertical-align: top; width: 14.28%; }
.day-header { padding: 7px 4px; border-radius: 9px; background: #e5eeff; text-align: center; color: #0b1c30; }.day-header.today { background: #ffdbca; color: #783200; }
.day-name { display: block; font-size: 8px; font-weight: 700; text-transform: uppercase; }.day-number { display: block; font-size: 15px; font-weight: 800; }
.day-events { min-height: 255px; padding-top: 5px; }.week-event { display: block; margin-bottom: 5px; padding: 6px; border-radius: 8px; font-size: 7.5px; line-height: 1.25; }
.week-event .time { display: block; margin-bottom: 2px; font-size: 7px; font-weight: 700; }.week-event .title { display: block; font-weight: 700; }.week-event .meta { display: block; margin-top: 2px; font-size: 7px; }.empty-day { padding: 12px 3px; color: #76777d; font-size: 8px; text-align: center; }
</style></head><body>
@include('pdf.agenda.partials.header', compact('titulo', 'fechaInicio', 'fechaFin', 'leyenda', 'tiposActivos'))
@foreach ($weeks as $week)
  @continue(! $week['has_events'] && count($weeks) > 1)
  <div class="week-block agenda-card"><p class="week-title">Semana del {{ $week['start']->isoFormat('D [de] MMMM') }} al {{ $week['end']->isoFormat('D [de] MMMM [de] YYYY') }}</p>
    <table class="week-grid"><tbody><tr>@foreach ($week['days'] as $day)<td>
      <div class="day-header {{ $day['is_today'] ? 'today' : '' }}"><span class="day-name">{{ $day['date']->isoFormat('ddd') }}</span><span class="day-number">{{ $day['date']->format('d') }}</span></div>
      <div class="day-events">@forelse ($day['events'] as $event)<div class="week-event" style="background: {{ $event['soft_color'] }}; color: {{ $event['text_color'] }};"><span class="time">{{ substr($event['hora_inicio'], 0, 5) }} - {{ substr($event['hora_fin'], 0, 5) }}</span><span class="title">{{ $event['titulo'] }}</span>@if($event['aula_nombre'])<span class="meta">{{ $event['aula_nombre'] }}</span>@endif</div>@empty <div class="empty-day">Sin eventos</div>@endforelse</div>
    </td>@endforeach</tr></tbody></table>
  </div>
@endforeach
</body></html>
