<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InstructorConflictValidator
{
    /**
     * Validar que un instructor no tenga conflictos con talleres y cursos
     * en la misma fecha y rango horario.
     *
     * @param string $instructorId UUID del instructor
     * @param string $fecha Fecha en formato Y-m-d
     * @param string $horaInicio Hora en formato H:i o H:i:s
     * @param string $horaFin Hora en formato H:i o H:i:s
     * @param string|null $excludeTallerId UUID del taller a excluir (para edición)
     * @return array ['valido' => bool, 'errores' => string[]]
     */
    public function validarTaller(
        string $instructorId,
        string $fecha,
        string $horaInicio,
        string $horaFin,
        ?string $excludeTallerId = null
    ): array {
        $errores = [];

        $horaInicioFormatted = $this->formatTime($horaInicio);
        $horaFinFormatted = $this->formatTime($horaFin);

        // Validar conflictos con otros talleres
        $tallerConflicto = $this->buscarConflictoEnTalleres(
            $instructorId,
            $fecha,
            $horaInicioFormatted,
            $horaFinFormatted,
            $excludeTallerId
        );

        if ($tallerConflicto) {
            $errores[] = "El instructor ya está asignado al taller \"{$tallerConflicto['nombre']}\" "
                . "el {$tallerConflicto['fecha']} de {$tallerConflicto['hora_inicio']} a {$tallerConflicto['hora_fin']}";
        }

        // Validar conflictos con clases de cursos
        $claseConflicto = $this->buscarConflictoEnClases(
            $instructorId,
            $fecha,
            $horaInicioFormatted,
            $horaFinFormatted
        );

        if ($claseConflicto) {
            $errores[] = "El instructor ya tiene una clase del curso \"{$claseConflicto['nombre']}\" "
                . "el {$claseConflicto['fecha']} de {$claseConflicto['hora_inicio']} a {$claseConflicto['hora_fin']}";
        }

        return [
            'valido' => empty($errores),
            'errores' => $errores,
        ];
    }

    /**
     * Validar conflictos para un curso abierto (docente asignado).
     * Verifica que las clases del curso no se solapen con talleres del instructor.
     *
     * @param string $instructorId UUID del instructor/docente
     * @param string $fechaInicio Fecha de inicio del curso (Y-m-d)
     * @param string $fechaFin Fecha de fin del curso (Y-m-d)
     * @param array $diasSemana Días de la semana [1=Lun..7=Dom]
     * @param string $horaInicio Hora de inicio de las clases
     * @param string $horaFin Hora de fin de las clases
     * @param string|null $excludeCursoId UUID del curso a excluir (para edición)
     * @return array ['valido' => bool, 'errores' => string[]]
     */
    public function validarCurso(
        string $instructorId,
        string $fechaInicio,
        string $fechaFin,
        array $diasSemana,
        string $horaInicio,
        string $horaFin,
        ?string $excludeCursoId = null
    ): array {
        $errores = [];

        $horaInicioFormatted = $this->formatTime($horaInicio);
        $horaFinFormatted = $this->formatTime($horaFin);

        // Buscar talleres del instructor en el rango de fechas
        $talleresConflicto = $this->buscarConflictosCursoVsTalleres(
            $instructorId,
            $fechaInicio,
            $fechaFin,
            $diasSemana,
            $horaInicioFormatted,
            $horaFinFormatted
        );

        foreach ($talleresConflicto as $conflicto) {
            $errores[] = "El instructor ya tiene asignado el taller \"{$conflicto['nombre']}\" "
                . "el {$conflicto['fecha']} de {$conflicto['hora_inicio']} a {$conflicto['hora_fin']}";
        }

        return [
            'valido' => empty($errores),
            'errores' => $errores,
        ];
    }

    /**
     * Validar conflictos para un taller multi-día con horarios por día.
     *
     * @param string $instructorId
     * @param array $fechas Array de fechas en formato Y-m-d
     * @param array|null $horarios Array de horarios [{dia_semana, hora_inicio, hora_fin}]
     * @param string|null $excludeTallerId
     * @return array
     */
    public function validarTallerRango(
        string $instructorId,
        array $fechas,
        ?array $horarios,
        ?string $excludeTallerId = null
    ): array {
        $errores = [];

        if (empty($horarios)) {
            return ['valido' => true, 'errores' => []];
        }

        // Construir mapa: fecha -> horarios del taller
        $horariosPorFecha = [];
        foreach ($fechas as $fecha) {
            $carbon = \Carbon\Carbon::parse($fecha);
            $diaSemana = (int) $carbon->format('N');
            foreach ($horarios as $h) {
                if (($h['dia_semana'] ?? null) == $diaSemana) {
                    $horariosPorFecha[$fecha][] = $h;
                }
            }
        }

        foreach ($horariosPorFecha as $fecha => $horariosDia) {
            foreach ($horariosDia as $h) {
                // Buscar conflictos con otros talleres en esa fecha
                $tallerConflicto = $this->buscarConflictoEnTalleres(
                    $instructorId,
                    $fecha,
                    $this->formatTime($h['hora_inicio']),
                    $this->formatTime($h['hora_fin']),
                    $excludeTallerId
                );
                if ($tallerConflicto) {
                    $errores[] = "El instructor ya está asignado al taller \"{$tallerConflicto['nombre']}\" "
                        . "el {$tallerConflicto['fecha']} de {$tallerConflicto['hora_inicio']} a {$tallerConflicto['hora_fin']}";
                }

                // Buscar conflictos con clases de cursos
                $claseConflicto = $this->buscarConflictoEnClases(
                    $instructorId,
                    $fecha,
                    $this->formatTime($h['hora_inicio']),
                    $this->formatTime($h['hora_fin'])
                );
                if ($claseConflicto) {
                    $errores[] = "El instructor ya tiene una clase del curso \"{$claseConflicto['nombre']}\" "
                        . "el {$claseConflicto['fecha']} de {$claseConflicto['hora_inicio']} a {$claseConflicto['hora_fin']}";
                }
            }
        }

        return [
            'valido' => empty($errores),
            'errores' => array_unique($errores),
        ];
    }

    // ========================================================================
    // MÉTODOS PRIVADOS
    // ========================================================================

    private function buscarConflictoEnTalleres(
        string $instructorId,
        string $fecha,
        string $horaInicio,
        string $horaFin,
        ?string $excludeTallerId = null
    ): ?array {
        $query = DB::connection('pgsql')
            ->table('academic.talleres')
            ->where('instructor_id', $instructorId)
            ->where('fecha', $fecha)
            ->whereIn('estado', ['pendiente', 'confirmado']);

        if ($excludeTallerId) {
            $query->where('id', '!=', $excludeTallerId);
        }

        $talleres = $query->get();

        foreach ($talleres as $taller) {
            if ($this->horariosSeSolapan($horaInicio, $horaFin, $taller->hora_inicio, $taller->hora_fin)) {
                return [
                    'nombre' => $taller->nombre,
                    'fecha' => Carbon::parse($taller->fecha)->format('d/m/Y'),
                    'hora_inicio' => substr($taller->hora_inicio, 0, 5),
                    'hora_fin' => substr($taller->hora_fin, 0, 5),
                ];
            }
        }

        return null;
    }

    private function buscarConflictoEnClases(
        string $instructorId,
        string $fecha,
        string $horaInicio,
        string $horaFin
    ): ?array {
        $clases = DB::connection('pgsql')
            ->table('academic.clases as c')
            ->join('academic.modulos as m', 'c.modulo_id', '=', 'm.id')
            ->join('academic.cursos_abiertos as ca', 'm.curso_abierto_id', '=', 'ca.id')
            ->join('academic.catalogo_cursos as cc', 'ca.catalogo_curso_id', '=', 'cc.id')
            ->where('c.instructor_id', $instructorId)
            ->where('c.fecha_clase', $fecha)
            ->select(
                'c.hora_inicio',
                'c.hora_fin',
                'c.fecha_clase',
                'cc.nombre as catalogo_nombre',
                'ca.nombre_instancia'
            )
            ->get();

        foreach ($clases as $clase) {
            if ($this->horariosSeSolapan($horaInicio, $horaFin, $clase->hora_inicio, $clase->hora_fin)) {
                return [
                    'nombre' => $clase->catalogo_nombre,
                    'fecha' => Carbon::parse($clase->fecha_clase)->format('d/m/Y'),
                    'hora_inicio' => substr($clase->hora_inicio, 0, 5),
                    'hora_fin' => substr($clase->hora_fin, 0, 5),
                ];
            }
        }

        return null;
    }

    private function buscarConflictosCursoVsTalleres(
        string $instructorId,
        string $fechaInicio,
        string $fechaFin,
        array $diasSemana,
        string $horaInicio,
        string $horaFin
    ): array {
        $conflictos = [];

        $talleres = DB::connection('pgsql')
            ->table('academic.talleres')
            ->where('instructor_id', $instructorId)
            ->where(function ($query) use ($fechaInicio, $fechaFin) {
                $query->whereBetween('fecha', [$fechaInicio, $fechaFin])
                    ->orWhere(function ($q) use ($fechaInicio, $fechaFin) {
                        $q->whereNotNull('fecha_fin')
                          ->where('fecha', '<=', $fechaFin)
                          ->where('fecha_fin', '>=', $fechaInicio);
                    });
            })
            ->whereIn('estado', ['pendiente', 'confirmado'])
            ->get();

        foreach ($talleres as $taller) {
            // Para talleres multi-día, verificar cada fecha del rango
            if (!empty($taller->fecha_fin)) {
                $inicio = Carbon::parse(max($taller->fecha, $fechaInicio));
                $fin = Carbon::parse(min($taller->fecha_fin, $fechaFin));
                $current = $inicio->copy();
                while ($current->lte($fin)) {
                    $diaSemanaTaller = (int) $current->format('N');
                    if (in_array($diaSemanaTaller, $diasSemana)) {
                        if ($this->horariosSeSolapan($horaInicio, $horaFin, $taller->hora_inicio, $taller->hora_fin)) {
                            $conflictos[] = [
                                'nombre' => $taller->nombre,
                                'fecha' => $current->format('d/m/Y'),
                                'hora_inicio' => substr($taller->hora_inicio, 0, 5),
                                'hora_fin' => substr($taller->hora_fin, 0, 5),
                            ];
                        }
                    }
                    $current->addDay();
                }
                continue;
            }

            $fechaTaller = Carbon::parse($taller->fecha);
            $diaSemanaTaller = (int) $fechaTaller->format('N');

            if (!in_array($diaSemanaTaller, $diasSemana)) {
                continue;
            }

            if ($this->horariosSeSolapan($horaInicio, $horaFin, $taller->hora_inicio, $taller->hora_fin)) {
                $conflictos[] = [
                    'nombre' => $taller->nombre,
                    'fecha' => $fechaTaller->format('d/m/Y'),
                    'hora_inicio' => substr($taller->hora_inicio, 0, 5),
                    'hora_fin' => substr($taller->hora_fin, 0, 5),
                ];
            }
        }

        return $conflictos;
    }

    /**
     * Verificar si dos rangos horarios se solapan.
     * Solapamiento: inicio1 < fin2 AND fin1 > inicio2
     */
    private function horariosSeSolapan(string $inicio1, string $fin1, string $inicio2, string $fin2): bool
    {
        $s1 = $this->timeToMinutes($inicio1);
        $e1 = $this->timeToMinutes($fin1);
        $s2 = $this->timeToMinutes($inicio2);
        $e2 = $this->timeToMinutes($fin2);

        return $s1 < $e2 && $e1 > $s2;
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return (intval($parts[0]) * 60) + intval($parts[1] ?? 0);
    }

    private function formatTime(string $time): string
    {
        if (strlen($time) === 5) {
            return $time . ':00';
        }
        return $time;
    }
}
