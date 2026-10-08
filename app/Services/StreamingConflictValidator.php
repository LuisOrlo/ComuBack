<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class StreamingConflictValidator
{
    /**
     * Valida si alguno de los técnicos en $personaIds tiene solapamiento de agenda
     * en la fecha y rango horario especificados.
     *
     * @param array<string> $personaIds Lista de UUIDs de personal a verificar
     * @param string $fecha Formato Y-m-d
     * @param string $horaInicio Formato H:i
     * @param string $horaFin Formato H:i
     * @param string|null $excludeStreamingId UUID del streaming a excluir (en caso de edición)
     * @return array{valido: bool, conflictos: array<int, array<string, mixed>>}
     */
    public function validarDisponibilidadStaff(
        array $personaIds,
        string $fecha,
        string $horaInicio,
        string $horaFin,
        ?string $excludeStreamingId = null
    ): array {
        $filteredIds = array_values(array_filter(array_unique($personaIds)));

        if (empty($filteredIds)) {
            return ['valido' => true, 'conflictos' => []];
        }

        // 1. Conflictos con otros servicios de streaming activos
        $streamingQuery = DB::table('services.asignaciones_personal as ap')
            ->join('services.servicios_streaming as ss', 'ap.servicio_streaming_id', '=', 'ss.id')
            ->join('people.personas as p', 'ap.persona_id', '=', 'p.id')
            ->whereIn('ap.persona_id', $filteredIds)
            ->where('ss.fecha_evento', $fecha)
            ->where('ss.estado', '!=', 'cancelado')
            ->where('ss.hora_inicio', '<', $horaFin)
            ->where('ss.hora_fin', '>', $horaInicio);

        if ($excludeStreamingId) {
            $streamingQuery->where('ss.id', '!=', $excludeStreamingId);
        }

        $streamingConflicts = $streamingQuery->select([
            'ap.persona_id',
            DB::raw("TRIM(CONCAT(p.nombres, ' ', COALESCE(p.apellidos, ''))) as persona_nombre"),
            DB::raw("COALESCE(ss.titulo, 'Servicio de Streaming') as actividad"),
            'ss.hora_inicio',
            'ss.hora_fin',
            DB::raw("'Streaming' as tipo_actividad"),
        ])->get();

        // 2. Conflictos con reservas de podcast activas
        $podcastConflicts = DB::table('services.asignaciones_personal as ap')
            ->join('services.reservas_podcast as rp', 'ap.reserva_podcast_id', '=', 'rp.id')
            ->join('people.personas as p', 'ap.persona_id', '=', 'p.id')
            ->whereIn('ap.persona_id', $filteredIds)
            ->where('rp.fecha_reserva', $fecha)
            ->where('rp.estado', '!=', 'cancelado')
            ->where('rp.hora_inicio', '<', $horaFin)
            ->where('rp.hora_fin', '>', $horaInicio)
            ->select([
                'ap.persona_id',
                DB::raw("TRIM(CONCAT(p.nombres, ' ', COALESCE(p.apellidos, ''))) as persona_nombre"),
                DB::raw("COALESCE(rp.titulo, 'Cabina de Podcast') as actividad"),
                'rp.hora_inicio',
                'rp.hora_fin',
                DB::raw("'Podcast' as tipo_actividad"),
            ])->get();

        // 3. Conflictos con reservas de cabina de radio activas (como operador o asignado)
        $radioConflicts = DB::table('services.reservas_radio as rr')
            ->join('people.personas as p', 'rr.operador_id', '=', 'p.id')
            ->whereIn('rr.operador_id', $filteredIds)
            ->where('rr.fecha_reserva', $fecha)
            ->where('rr.estado', '!=', 'cancelado')
            ->where('rr.hora_inicio', '<', $horaFin)
            ->where('rr.hora_fin', '>', $horaInicio)
            ->select([
                'rr.operador_id as persona_id',
                DB::raw("TRIM(CONCAT(p.nombres, ' ', COALESCE(p.apellidos, ''))) as persona_nombre"),
                DB::raw("COALESCE(rr.observaciones, 'Cabina de Radio') as actividad"),
                'rr.hora_inicio',
                'rr.hora_fin',
                DB::raw("'Radio' as tipo_actividad"),
            ])->get();

        // 4. Conflictos con clases académicas activas (donde la persona es instructor)
        $claseConflicts = DB::table('academic.clases as c')
            ->join('people.personas as p', 'c.instructor_id', '=', 'p.id')
            ->whereIn('c.instructor_id', $filteredIds)
            ->where('c.fecha_clase', $fecha)
            ->where('c.hora_inicio', '<', $horaFin)
            ->where('c.hora_fin', '>', $horaInicio)
            ->select([
                'c.instructor_id as persona_id',
                DB::raw("TRIM(CONCAT(p.nombres, ' ', COALESCE(p.apellidos, ''))) as persona_nombre"),
                DB::raw("'Clase Académica' as actividad"),
                'c.hora_inicio',
                'c.hora_fin',
                DB::raw("'Clase' as tipo_actividad"),
            ])->get();

        $allConflicts = $streamingConflicts
            ->concat($podcastConflicts)
            ->concat($radioConflicts)
            ->concat($claseConflicts);

        if ($allConflicts->isNotEmpty()) {
            return [
                'valido' => false,
                'conflictos' => $allConflicts->values()->toArray(),
            ];
        }

        return [
            'valido' => true,
            'conflictos' => [],
        ];
    }
}
