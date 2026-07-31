<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Agenda mensual</title>
<style>
@include('pdf.agenda.partials.styles')

.calendar-month {
    border: 1px solid #9D9D9D;
    border-radius: 8px;
    overflow: hidden;
}

.weekday-header,
.week-row {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
}

.weekday-header div {
    padding: 8px 10px;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .04em;
    color: #464646;
    text-transform: uppercase;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
    border-right: 1px solid #e5e7eb;
}

.weekday-header div:last-child {
    border-right: none;
}

.week-row {
    page-break-inside: avoid;
}

.day-cell {
    min-height: 92px;
    min-width: 0;
    padding: 6px 8px;
    border-right: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
    position: relative;
    overflow: hidden;
}

.week-row .day-cell:last-child {
    border-right: none;
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
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.day-number {
    font-size: 11px;
    font-weight: 600;
    color: #374151;
    text-align: right;
    display: block;
}

.events {
    margin-top: 4px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.event-pill {
    display: flex;
    align-items: center;
    gap: 4px;
    min-width: 0;
    font-size: 8.5px;
    font-weight: 600;
    padding: 2px 5px;
    border-radius: 4px;
    background: var(--color);
    color: #fff;
    overflow: hidden;
    white-space: nowrap;
    box-shadow: 0 1px 2px rgba(0,0,0,0.12);
}

.event-pill .time {
    font-weight: 700;
    color: #fff;
    opacity: 0.9;
    flex-shrink: 0;
}

.event-pill .title {
    flex: 1;
    min-width: 0;
    color: #fff;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
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

<div class="calendar-month">
    <div class="weekday-header">
        <div>Lun</div><div>Mar</div><div>Mié</div><div>Jue</div><div>Vie</div><div>Sáb</div><div>Dom</div>
    </div>

    @foreach ($weeks as $week)
        <div class="week-row">
            @foreach ($week['days'] as $day)
                @php
                    $isOtherMonth = $day['date']->month !== $mesReferencia;
                    $visibleEvents = array_slice($day['events'], 0, 4);
                    $extra = count($day['events']) - count($visibleEvents);
                @endphp
                <div class="day-cell {{ $isOtherMonth ? 'other-month' : '' }} {{ $day['is_today'] ? 'today' : '' }}">
                    <span class="day-number">{{ $day['date']->format('d') }}</span>
                    <div class="events">
                        @foreach ($visibleEvents as $event)
                            <div class="event-pill" style="--color: {{ $event['color'] }}">
                                <span class="time">{{ substr($event['hora_inicio'], 0, 5) }}</span>
                                <span class="title">{{ $event['titulo'] }}</span>
                            </div>
                        @endforeach
                        @if ($extra > 0)
                            <div class="event-more">+{{ $extra }} más</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach
</div>
</body>
</html>
