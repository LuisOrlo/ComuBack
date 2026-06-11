<?php

namespace App\Services;

use App\Models\Horario;
use App\Models\Matricula;
use Carbon\Carbon;

/**
 * Service para validar conflictos de horarios
 * 
 * Detecta solapamientos entre horarios de estudiantes
 */
class ScheduleConflictService
{
    /**
     * Validar si existe conflicto de horario para un estudiante
     * 
     * Retorna true si hay conflicto, false si no
     */
    public function hasConflict(string $estudianteId, Horario $horarioNuevo): bool
    {
        // Obtener todas las matrículas activas del estudiante
        $matriculasActivas = Matricula::where('estudiante_id', $estudianteId)
            ->whereIn('estado', ['activo', 'completado'])
            ->with(['horario'])
            ->get();

        foreach ($matriculasActivas as $matricula) {
            if ($matricula->horario && $this->horariosSeSuperponen($matricula->horario, $horarioNuevo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtener conflictos específicos para un estudiante
     * 
     * Retorna array de matriculas que tienen conflicto
     */
    public function getConflicts(string $estudianteId, Horario $horarioNuevo): array
    {
        $conflictos = [];
        
        $matriculasActivas = Matricula::where('estudiante_id', $estudianteId)
            ->whereIn('estado', ['activo', 'completado'])
            ->with(['horario', 'cursoAbierto'])
            ->get();

        foreach ($matriculasActivas as $matricula) {
            if ($matricula->horario && $this->horariosSeSuperponen($matricula->horario, $horarioNuevo)) {
                $conflictos[] = [
                    'matricula_id' => $matricula->id,
                    'curso' => $matricula->cursoAbierto->nombre_instancia,
                    'horario_existente' => $this->formatearHorario($matricula->horario),
                    'horario_nuevo' => $this->formatearHorario($horarioNuevo),
                ];
            }
        }

        return $conflictos;
    }

    /**
     * Determinar si dos horarios se superponen
     * 
     * Compara:
     * - Horas (hora_inicio y hora_fin)
     * - Días de la semana
     */
    private function horariosSeSuperponen(Horario $horario1, Horario $horario2): bool
    {
        // Si no comparten días de la semana, no hay conflicto
        if (!$this->diasSeSuperponen($horario1, $horario2)) {
            return false;
        }

        // Comparar horas
        $inicio1 = Carbon::createFromTimeString($horario1->hora_inicio);
        $fin1 = Carbon::createFromTimeString($horario1->hora_fin);
        
        $inicio2 = Carbon::createFromTimeString($horario2->hora_inicio);
        $fin2 = Carbon::createFromTimeString($horario2->hora_fin);

        // Dos horarios se superponen si:
        // inicio1 <= fin2 AND fin1 >= inicio2
        return $inicio1 <= $fin2 && $fin1 >= $inicio2;
    }

    /**
     * Determinar si dos horarios comparten días de la semana
     */
    private function diasSeSuperponen(Horario $horario1, Horario $horario2): bool
    {
        $dias1 = $horario1->horarioDias()->pluck('dia_semana')->toArray();
        $dias2 = $horario2->horarioDias()->pluck('dia_semana')->toArray();

        // Verificar si hay intersección
        return count(array_intersect($dias1, $dias2)) > 0;
    }

    /**
     * Formatear horario para presentación
     */
    private function formatearHorario(Horario $horario): string
    {
        $dias = $horario->horarioDias()
            ->pluck('dia_semana')
            ->map(fn($dia) => $this->nombreDia($dia))
            ->join(', ');

        return "{$dias}: {$horario->hora_inicio} - {$horario->hora_fin}";
    }

    /**
     * Obtener nombre del día de la semana
     */
    private function nombreDia(int $dia): string
    {
        $dias = [
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado',
            7 => 'Domingo',
        ];

        return $dias[$dia] ?? "Día {$dia}";
    }
}
