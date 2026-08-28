<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Agenda mensual</title>
<style>
@include('pdf.agenda.partials.styles')

.calendar-month {
    width: 100%;
    border: 1px solid #9D9D9D;
    border-radius: 8px;
    border-collapse: collapse;
    table-layout: fixed;
}

.calendar-month th {
    padding: 8px 6px;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .04em;
    color: #464646;
    text-transform: uppercase;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
    border-right: 1px solid #e5e7eb;
    width: 14.28%;
}

.calendar-month th:last-child {
    border-right: none;
}

.calendar-month td {
    min-height: 92px;
    padding: 6px 8px;
    border-right: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
    vertical-align: top;
    width: 14.28%;
}

.calendar-month td:last-child {
    border-right: none;
}

.calendar-month tr {
    page-break-inside: avoid;
}

.day-cell.other-month {
    background: #fafafa;
}

.day-cell.other-month .day-number {
    color: #d1d5db;
}

.day-cell.today {
    background: #FBEBE8;
}

.day-cell.today .day-number {
    background: #D61A00;
    color: #fff;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    display: inline-block;
    text-align: center;
    line-height: 20px;
}

.day-number {
    font-size: 11px;
    font-weight: 600;
    color: #374151;
    display: block;
    text-align: right;
}

.events {
    margin-top: 4px;
}

.event-pill {
    display: block;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 8.5px;
    font-weight: 600;
    padding: 2px 5px;
    border-radius: 4px;
    color: #111827;
    background: #f9fafb;
    border-left: 3px solid #6b7280;
    margin-bottom: 2px;
}

.event-pill .time {
    font-weight: 700;
    display: inline;
    margin-right: 3px;
}

.event-pill .title {
    display: inline;
}

.event-more {
    font-size: 8px;
    color: #464646;
    padding-left: 4px;
}
</style>
</head>
<body>
@include('pdf.agenda.partials.header', [
    'titulo'      => $titulo,
    'fechaInicio' => $fechaInicio,
    'fechaFin'    => $fechaFin,
    'leyenda'     => $leyenda,
    'tiposActivos'=> $tiposActivos,
])

<table class="calendar-month">
    <thead>
        <tr>
            <th>Lun</th><th>Mar</th><th>Mié</th><th>Jue</th><th>Vie</th><th>Sáb</th><th>Dom</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($weeks as $week)
            <tr>
                @foreach ($week['days'] as $day)
                    @php
                        $isOtherMonth = $day['date']->month !== $mesReferencia;
                        $visibleEvents = array_slice($day['events'], 0, 4);
                        $extra = count($day['events']) - count($visibleEvents);
                    @endphp
                    <td class="day-cell {{ $isOtherMonth ? 'other-month' : '' }} {{ $day['is_today'] ? 'today' : '' }}">
                        <span class="day-number">{{ $day['date']->format('d') }}</span>
                        <div class="events">
                            @foreach ($visibleEvents as $event)
                                <div class="event-pill" style="border-left-color: {{ $event['color'] }}">
                                    <span class="time">{{ substr($event['hora_inicio'], 0, 5) }}</span>
                                    <span class="title">{{ $event['titulo'] }}</span>
                                </div>
                            @endforeach
                            @if ($extra > 0)
                                <div class="event-more">+{{ $extra }} más</div>
                            @endif
                        </div>
                    </td>
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
</body>
</html>
