<?php

namespace App\Support\Agenda;

class DayEventLayout
{
    /**
     * Calcula la posición (columna) de cada evento del día para poder
     * dibujarlos lado a lado cuando se solapan en el tiempo, igual que
     * hace Google Calendar en la vista semanal.
     *
     * Agrega a cada evento:
     *  - _start / _end : minutos desde medianoche
     *  - _col          : índice de columna dentro de su grupo de solapamiento
     *  - _cols         : cantidad total de columnas de ese grupo
     */
    public static function layout(array $events): array
    {
        if (empty($events)) {
            return [];
        }

        $items = collect($events)
            ->map(function (array $event, int $idx) {
                $event['_start'] = self::toMinutes($event['hora_inicio']);
                $event['_end']   = self::toMinutes($event['hora_fin']);
                $event['_idx']   = $idx;
                return $event;
            })
            ->sortBy('_start')
            ->values();

        // 1) Agrupar eventos que se solapan en clusters consecutivos.
        $clusters = [];
        $current = [];
        $currentEnd = null;

        foreach ($items as $item) {
            if ($current && $item['_start'] >= $currentEnd) {
                $clusters[] = $current;
                $current = [];
                $currentEnd = null;
            }
            $current[] = $item;
            $currentEnd = $currentEnd === null
                ? $item['_end']
                : max($currentEnd, $item['_end']);
        }
        if ($current) {
            $clusters[] = $current;
        }

        // 2) Dentro de cada cluster, asignar columnas por "interval graph coloring".
        $result = [];
        foreach ($clusters as $cluster) {
            $columnEnds = [];
            $columnOf = [];

            foreach ($cluster as $item) {
                $placed = false;
                foreach ($columnEnds as $col => $end) {
                    if ($item['_start'] >= $end) {
                        $columnEnds[$col] = $item['_end'];
                        $columnOf[$item['_idx']] = $col;
                        $placed = true;
                        break;
                    }
                }
                if (! $placed) {
                    $columnEnds[] = $item['_end'];
                    $columnOf[$item['_idx']] = count($columnEnds) - 1;
                }
            }

            $totalCols = count($columnEnds);
            foreach ($cluster as $item) {
                $item['_col']  = $columnOf[$item['_idx']];
                $item['_cols'] = $totalCols;
                $result[] = $item;
            }
        }

        return $result;
    }

    private static function toMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return ($h * 60) + $m;
    }
}
