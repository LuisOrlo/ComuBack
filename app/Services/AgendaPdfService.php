<?php

namespace App\Services;

use App\Support\Agenda\DayEventLayout;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class AgendaPdfService
{
    public function __construct(
        private readonly AgendaService $agendaService,
    ) {
    }

    /**
     * Genera el PDF de la agenda en formato mensual (grilla tipo calendario).
     */
    public function generateMonthPdf(Carbon $fechaInicio, Carbon $fechaFin, array $tiposEventos = [], string $titulo = 'AGENDA GENERAL'): string
    {
        $data = $this->agendaService->getEventsForPdf($fechaInicio->toDateString(), $fechaFin->toDateString(), $tiposEventos);

        $html = view('pdf.agenda.month', [
            'weeks'         => $data['weeks'],
            'leyenda'       => $data['leyenda'],
            'tiposActivos'  => $data['tipos_activos'],
            'fechaInicio'   => $data['fecha_inicio'],
            'fechaFin'      => $data['fecha_fin'],
            'totalEventos'  => $data['total_eventos'],
            'mesReferencia' => $fechaInicio->month,
            'titulo'        => $titulo,
        ])->render();

        return $this->renderPdf($html);
    }

    /**
     * Genera el PDF de la agenda en formato semanal (grilla horaria tipo Google Calendar).
     * Pensado para un rango de una semana (lunes a domingo), pero soporta varias
     * semanas paginando una por página.
     */
    public function generateWeekPdf(Carbon $fechaInicio, Carbon $fechaFin, array $tiposEventos = [], string $titulo = 'AGENDA GENERAL'): string
    {
        $data = $this->agendaService->getEventsForPdf($fechaInicio->toDateString(), $fechaFin->toDateString(), $tiposEventos);

        $weeks = collect($data['weeks'])->map(function (array $week) {
            foreach ($week['days'] as &$day) {
                $day['events'] = DayEventLayout::layout($day['events']);
            }
            unset($day);
            return $week;
        });

        $html = view('pdf.agenda.week', [
            'weeks'        => $weeks,
            'hours'        => $data['hours'],
            'minHour'      => $data['min_hour'],
            'leyenda'      => $data['leyenda'],
            'tiposActivos' => $data['tipos_activos'],
            'fechaInicio'  => $data['fecha_inicio'],
            'fechaFin'     => $data['fecha_fin'],
            'titulo'       => $titulo,
        ])->render();

        return $this->renderPdf($html);
    }

    public function generateDayPdf(Carbon $fechaInicio, Carbon $fechaFin, array $tiposEventos = [], string $titulo = 'AGENDA GENERAL'): string
    {
        $data = $this->agendaService->getEventsForPdf($fechaInicio->toDateString(), $fechaFin->toDateString(), $tiposEventos);
        $events = collect($data['weeks'])
            ->flatMap(fn (array $week) => collect($week['days']))
            ->filter(fn (array $day) => $day['date']->betweenIncluded($fechaInicio, $fechaFin))
            ->flatMap(fn (array $day) => $day['events'])
            ->sortBy([['fecha', 'asc'], ['hora_inicio', 'asc']])
            ->values();

        $html = view('pdf.agenda.day', [
            'events' => $events,
            'leyenda' => $data['leyenda'],
            'tiposActivos' => $data['tipos_activos'],
            'fechaInicio' => $data['fecha_inicio'],
            'fechaFin' => $data['fecha_fin'],
            'titulo' => $titulo,
        ])->render();

        return $this->renderPdf($html, 'portrait');
    }

    public function generateListPdf(Carbon $fechaInicio, Carbon $fechaFin, array $tiposEventos = [], string $titulo = 'AGENDA GENERAL'): string
    {
        $events = $this->agendaService->getEvents($fechaInicio->toDateString(), $fechaFin->toDateString(), $tiposEventos)
            ->groupBy('fecha');

        $html = view('pdf.agenda.list', [
            'eventsByDate' => $events,
            'leyenda' => AgendaService::eventTypes(),
            'tiposActivos' => $tiposEventos ?: array_keys(AgendaService::eventTypes()),
            'fechaInicio' => $fechaInicio->isoFormat('D [de] MMMM [de] YYYY'),
            'fechaFin' => $fechaFin->isoFormat('D [de] MMMM [de] YYYY'),
            'titulo' => $titulo,
        ])->render();

        return $this->renderPdf($html, 'portrait');
    }

    private function renderPdf(string $html, string $orientation = 'landscape'): string
    {
        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', $orientation);

        $pdf->getDomPDF()->getOptions()->set('defaultFont', 'DejaVu Sans');

        return $pdf->output();
    }
}
