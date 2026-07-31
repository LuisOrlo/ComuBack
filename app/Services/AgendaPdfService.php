<?php

namespace App\Services;

use App\Support\Agenda\DayEventLayout;
use Carbon\Carbon;
use Spatie\Browsershot\Browsershot;

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

    private function renderPdf(string $html): string
    {
        return Browsershot::html($html)
            ->format('A4')
            ->landscape()
            ->margins(8, 8, 8, 8)
            ->showBackground()
            ->waitUntilNetworkIdle()
            ->pdf();
    }
}
