<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Agenda semanal</title>
<style>
@include('pdf.agenda.partials.styles')

.week-wrapper {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.week-block {
    page-break-inside: avoid;
    page-break-after: always;
}

.week-block:last-child {
    page-break-after: auto;
}

.week-grid {
    display: flex;
    border: 1px solid #9D9D9D;
    border-radius: 8px;
    overflow: hidden;
}

.time-col {
    width: 46px;
    flex-shrink: 0;
    border-right: 1px solid #e5e7eb;
}

.time-col .corner {
    height: 38px;
    border-bottom: 1px solid #e5e7eb;
    background: #f9fafb;
}

.hour-label {
    font-size: 9px;
    color: #9ca3af;
    text-align: right;
    padding-right: 6px;
    transform: translateY(-6px);
    border-top: 1px solid #f3f4f6;
}

.hour-label:first-child {
    border-top: none;
}

.day-col {
    flex: 1;
    border-right: 1px solid #e5e7eb;
}

.day-col:last-child {
    border-right: none;
}

.day-col-header {
    height: 38px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 600;
    color: #374151;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
    text-transform: uppercase;
}

.day-col-header .day-num {
    font-size: 13px;
}

.day-body {
    position: relative;
}

.hour-line {
    position: absolute;
    left: 0;
    right: 0;
    border-top: 1px solid #f3f4f6;
}

.event-block {
    position: absolute;
    border-radius: 4px;
    padding: 3px 5px;
    overflow: hidden;
    color: #fff;
    font-size: 8px;
    line-height: 1.25;
    box-shadow: 0 1px 2px rgba(0,0,0,0.12);
}

.event-block .ev-time {
    font-weight: 700;
    display: block;
    opacity: 0.9;
}

.event-block .ev-title {
    display: block;
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

<div class="week-wrapper">
    @foreach ($weeks as $week)
        @continue(! $week['has_events'] && count($weeks) > 1)
        @php
            $wkHours = $week['hours'] ?? $hours;
            $wkMinHour = $week['min_hour'] ?? $minHour;

            $maxHoursAreaHeight = 560;
            $hourHeight = (int) max(24, min(70, intdiv($maxHoursAreaHeight, max(count($wkHours), 1))));
        @endphp
        <div class="week-block">
            <div class="week-grid">
                <div class="time-col">
                    <div class="corner"></div>
                    @foreach ($wkHours as $h)
                        <div class="hour-label" style="height: {{ $hourHeight }}px;">{{ sprintf('%02d:00', $h) }}</div>
                    @endforeach
                </div>

                @foreach ($week['days'] as $day)
                    <div class="day-col">
                        <div class="day-col-header">
                            <span>{{ ucfirst($day['date']->isoFormat('ddd')) }}</span>
                            <span class="day-num">{{ $day['date']->format('d') }}</span>
                        </div>
                        <div class="day-body" style="height: {{ count($wkHours) * $hourHeight }}px;">
                            @for ($i = 0; $i < count($wkHours); $i++)
                                <div class="hour-line" style="top: {{ $i * $hourHeight }}px;"></div>
                            @endfor

                            @foreach ($day['events'] as $event)
                                @php
                                    [$sh, $sm] = array_map('intval', explode(':', $event['hora_inicio']));
                                    [$eh, $em] = array_map('intval', explode(':', $event['hora_fin']));
                                    $pxPerMinute = $hourHeight / 60;
                                    $top = ((($sh - $wkMinHour) * 60) + $sm) * $pxPerMinute;
                                    $height = max(((($eh - $sh) * 60) + ($em - $sm)) * $pxPerMinute, 18);
                                    $widthPct = 100 / $event['_cols'];
                                    $leftPct = $event['_col'] * $widthPct;
                                @endphp
                                <div class="event-block" style="
                                    top: {{ $top }}px;
                                    height: {{ $height }}px;
                                    left: calc({{ $leftPct }}% + 2px);
                                    width: calc({{ $widthPct }}% - 4px);
                                    background: {{ $event['color'] }};
                                ">
                                    <span class="ev-time">{{ substr($event['hora_inicio'], 0, 5) }} - {{ substr($event['hora_fin'], 0, 5) }}</span>
                                    <span class="ev-title">{{ $event['titulo'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
</body>
</html>
