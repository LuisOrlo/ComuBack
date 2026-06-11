<?php

namespace App\Services;

use App\Models\Taller;
use App\Models\HorarioTaller;
use App\Models\CursoAbierto;
use App\Models\Horario;
use Carbon\Carbon;

class WorkshopScheduleConflictService
{
    /**
     * Validate that workshop schedule doesn't conflict with existing schedules
     */
    public function validarSinCruces(
        string $dia_semana,
        string $hora_inicio,
        string $hora_fin,
        ?string $taller_id = null
    ): array {
        $errores = [];

        // Validar que hora_fin > hora_inicio
        if ($this->compararHoras($hora_inicio, $hora_fin) >= 0) {
            $errores[] = 'La hora de fin debe ser posterior a la hora de inicio';
            return ['valido' => false, 'errores' => $errores];
        }

        // Buscar conflictos con otros talleres
        $conflictosCalleres = $this->buscarConflictosEnTalleres($dia_semana, $hora_inicio, $hora_fin, $taller_id);
        if ($conflictosCalleres) {
            $errores[] = "Existe conflicto de horario con taller: {$conflictosCalleres['nombre']}";
        }

        // Buscar conflictos con cursos abiertos
        $conflictosAulas = $this->buscarConflictosEnCursos($dia_semana, $hora_inicio, $hora_fin);
        if ($conflictosAulas) {
            $errores[] = "Existe conflicto de horario con curso: {$conflictosAulas['nombre']}";
        }

        return [
            'valido' => empty($errores),
            'errores' => $errores,
            'conflictos' => [
                'talleres' => $conflictosCalleres,
                'cursos' => $conflictosAulas,
            ],
        ];
    }

    /**
     * Buscar conflictos en horarios de otros talleres
     */
    private function buscarConflictosEnTalleres(
        string $dia_semana,
        string $hora_inicio,
        string $hora_fin,
        ?string $taller_id = null
    ): ?array {
        $query = HorarioTaller::where('dia_semana', $dia_semana)
            ->whereHas('taller', function ($q) {
                $q->whereIn('estado', ['planificado', 'activo']);
            });

        if ($taller_id) {
            $query->whereNot('taller_id', $taller_id);
        }

        $conflictos = $query->get();

        foreach ($conflictos as $horario) {
            if ($this->horariosSeCoruzan($hora_inicio, $hora_fin, $horario->hora_inicio, $horario->hora_fin)) {
                return [
                    'id' => $horario->taller_id,
                    'nombre' => $horario->taller->nombre,
                    'dia' => $horario->nombreDia(),
                    'hora_inicio' => $horario->hora_inicio,
                    'hora_fin' => $horario->hora_fin,
                ];
            }
        }

        return null;
    }

    /**
     * Buscar conflictos en horarios de cursos abiertos
     */
    private function buscarConflictosEnCursos(
        string $dia_semana,
        string $hora_inicio,
        string $hora_fin
    ): ?array {
        $query = Horario::where('dia_semana', $dia_semana)
            ->whereHas('curso', function ($q) {
                $q->whereIn('estado', ['abierto', 'en_curso']);
            });

        $conflictos = $query->get();

        foreach ($conflictos as $horario) {
            if ($this->horariosSeCoruzan($hora_inicio, $hora_fin, $horario->hora_inicio, $horario->hora_fin)) {
                return [
                    'id' => $horario->curso_id,
                    'nombre' => $horario->curso->nombre,
                    'dia' => $horario->nombreDia(),
                    'hora_inicio' => $horario->hora_inicio,
                    'hora_fin' => $horario->hora_fin,
                ];
            }
        }

        return null;
    }

    /**
     * Verificar si dos horarios se solapan
     */
    private function horariosSeCoruzan(
        string $inicio1,
        string $fin1,
        string $inicio2,
        string $fin2
    ): bool {
        $inicio1 = $this->convertirAMinutos($inicio1);
        $fin1 = $this->convertirAMinutos($fin1);
        $inicio2 = $this->convertirAMinutos($inicio2);
        $fin2 = $this->convertirAMinutos($fin2);

        return !($fin1 <= $inicio2 || $fin2 <= $inicio1);
    }

    /**
     * Convertir hora HH:MM:SS a minutos desde medianoche
     */
    private function convertirAMinutos(string $hora): int
    {
        $partes = explode(':', $hora);
        return ($partes[0] * 60) + $partes[1];
    }

    /**
     * Comparar dos horas: -1 si hora1 < hora2, 0 si iguales, 1 si hora1 > hora2
     */
    private function compararHoras(string $hora1, string $hora2): int
    {
        $min1 = $this->convertirAMinutos($hora1);
        $min2 = $this->convertirAMinutos($hora2);

        if ($min1 < $min2) return -1;
        if ($min1 > $min2) return 1;
        return 0;
    }
}
