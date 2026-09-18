<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Agenda mensual</title>
<style>
@include('pdf.agenda.partials.styles')
.calendar-month { width: 100%; border-collapse: separate; border-spacing: 5px; table-layout: fixed; }
.calendar-month th { padding: 7px 4px; font-size: 9px; font-weight: 700; letter-spacing: .06em; color: #45464d; text-transform: uppercase; text-align: center; }
.calendar-month td { padding: 0; vertical-align: top; width: 14.28%; }
.day-card { min-height: 92px; padding: 7px; border-radius: 10px; background: #eff4ff; }
.day-card.other-month { background: #f3f6fc; opacity: .55; }
.day-card.today { background: #ffdbca; }
.day-number { display: block; margin-bottom: 5px; font-size: 10px; font-weight: 700; color: #0b1c30; }
.today .day-number { color: #9d4300; }
.event-pill { display: block; overflow: hidden; margin-bottom: 3px; padding: 4px 5px; border-radius: 6px; font-size: 7.6px; line-height: 1.2; }
.event-pill .time { display: block; margin-bottom: 1px; font-size: 7px; font-weight: 700; }
.event-pill .title { display: block; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; font-weight: 600; }
.event-more { padding: 2px 3px; font-size: 7px; color: #45464d; }
</style>
</head>
<body>
@include('pdf.agenda.partials.header', compact('titulo', 'fechaInicio', 'fechaFin', 'leyenda', 'tiposActivos'))
<div class="agenda-card"><table class="calendar-month">
  <thead><tr><th>Lun</th><th>Mar</th><th>Mié</th><th>Jue</th><th>Vie</th><th>Sáb</th><th>Dom</th></tr></thead>
  <tbody>
  @foreach ($weeks as $week)
    <tr>
    @foreach ($week['days'] as $day)
      @php
        $isOtherMonth = $day['date']->month !== $mesReferencia;
        $visibleEvents = array_slice($day['events'], 0, 3);
        $extra = count($day['events']) - count($visibleEvents);
      @endphp
      <td><div class="day-card {{ $isOtherMonth ? 'other-month' : '' }} {{ $day['is_today'] ? 'today' : '' }}">
        <span class="day-number">{{ $day['date']->format('d') }}</span>
        @foreach ($visibleEvents as $event)
          <div class="event-pill" style="background: {{ $event['soft_color'] }}; color: {{ $event['text_color'] }};"><span class="time">{{ substr($event['hora_inicio'], 0, 5) }}</span><span class="title">{{ $event['titulo'] }}</span></div>
        @endforeach
        @if ($extra > 0)<div class="event-more">+{{ $extra }} más</div>@endif
      </div></td>
    @endforeach
    </tr>
  @endforeach
  </tbody>
</table></div>
</body>
</html>
