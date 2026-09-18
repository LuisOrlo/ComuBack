<?php

namespace App\Services;

use App\Models\CursoAbierto;
use App\Models\InscripcionTaller;
use App\Models\Persona;
use App\Models\Taller;
use Carbon\Carbon;

/**
 * Compara la agenda real de las actividades de un estudiante.
 *
 * Cursos normales y personalizados se normalizan desde CursoAbierto. Los
 * talleres se normalizan desde sus HorarioTaller. La comparación temporal es
 * común para ambos tipos.
 */
class StudentScheduleConflictService
{
    private const ACTIVE_COURSE_STATES = ['activo', 'completado'];
    private const ACTIVE_WORKSHOP_STATES = ['activo', 'completado'];

    public function conflictsForCourse(?string $personaId, CursoAbierto $newCourse): array
    {
        if (!$personaId) {
            return [];
        }

        $newActivity = $this->normalizeCourse($newCourse);

        return $newActivity
            ? $this->findConflicts($newActivity, $this->existingActivities($personaId))
            : [];
    }

    public function conflictsForWorkshop(?string $personaId, Taller $newWorkshop): array
    {
        if (!$personaId) {
            return [];
        }

        $newActivity = $this->normalizeWorkshop($newWorkshop);

        return $newActivity
            ? $this->findConflicts($newActivity, $this->existingActivities($personaId))
            : [];
    }

    private function existingActivities(string $personaId): array
    {
        // Serializa inscripciones administrativas de ofertas distintas para el
        // mismo estudiante. El llamador debe estar dentro de una transacción.
        Persona::whereKey($personaId)->lockForUpdate()->first();

        $activities = [];

        // No withTrashed(): una matrícula eliminada no representa asistencia.
        $matriculas = \App\Models\Matricula::query()
            ->where('estudiante_id', $personaId)
            ->whereIn('estado', self::ACTIVE_COURSE_STATES)
            ->with(['cursoAbierto.horario.diasSemana', 'cursoAbierto.catalogo'])
            ->get();

        foreach ($matriculas as $matricula) {
            $activity = $this->normalizeCourse($matricula->cursoAbierto);
            if ($activity) {
                $activities[] = $activity;
            }
        }

        // InscripcionTaller no utiliza soft deletes actualmente. La relación
        // se filtra por estados de participación vigente.
        $inscripciones = InscripcionTaller::query()
            ->where('persona_id', $personaId)
            ->whereIn('estado', self::ACTIVE_WORKSHOP_STATES)
            ->with(['taller.horarios'])
            ->get();

        foreach ($inscripciones as $inscripcion) {
            $activity = $this->normalizeWorkshop($inscripcion->taller);
            if ($activity) {
                $activities[] = $activity;
            }
        }

        return $activities;
    }

    private function normalizeCourse(?CursoAbierto $course): ?array
    {
        if (!$course || !$course->fecha_inicio || !$course->fecha_fin) {
            return null;
        }

        $schedule = $course->relationLoaded('horario')
            ? $course->horario
            : $course->horario()->with('diasSemana')->first();

        if (!$schedule) {
            return null;
        }

        $days = $schedule->relationLoaded('diasSemana')
            ? $schedule->diasSemana
            : $schedule->diasSemana()->get();

        $slots = [];
        foreach ($days as $day) {
            $slot = $this->normalizeSlot($day->dia_semana, $schedule->hora_inicio, $schedule->hora_fin);
            if ($slot) {
                $slots[] = $slot;
            }
        }

        if (!$slots) {
            return null;
        }

        return [
            'tipo' => $course->es_personalizado ? 'curso_personalizado' : 'curso',
            'actividad_id' => (string) $course->id,
            'nombre' => $course->nombre_instancia
                ?: ($course->catalogo?->nombre ?? 'Curso'),
            'fecha_inicio' => Carbon::parse($course->fecha_inicio)->toDateString(),
            'fecha_fin' => Carbon::parse($course->fecha_fin)->toDateString(),
            'horarios' => $slots,
        ];
    }

    private function normalizeWorkshop(?Taller $workshop): ?array
    {
        if (!$workshop || !$workshop->fecha) {
            return null;
        }

        $schedules = $workshop->relationLoaded('horarios')
            ? $workshop->horarios
            : $workshop->horarios()->get();

        $slots = [];
        foreach ($schedules as $schedule) {
            $slot = $this->normalizeSlot($schedule->dia_semana, $schedule->hora_inicio, $schedule->hora_fin);
            if ($slot) {
                $slots[] = $slot;
            }
        }

        if (!$slots) {
            return null;
        }

        return [
            'tipo' => 'taller',
            'actividad_id' => (string) $workshop->id,
            'nombre' => $workshop->nombre ?: 'Taller',
            'fecha_inicio' => Carbon::parse($workshop->fecha)->toDateString(),
            'fecha_fin' => Carbon::parse($workshop->fecha_fin ?: $workshop->fecha)->toDateString(),
            'horarios' => $slots,
        ];
    }

    private function normalizeSlot($day, $start, $end): ?array
    {
        $day = (int) $day;
        $startMinutes = $this->timeToMinutes($start);
        $endMinutes = $this->timeToMinutes($end);

        if ($day < 1 || $day > 7 || $startMinutes === null || $endMinutes === null || $startMinutes >= $endMinutes) {
            return null;
        }

        return [
            'dia_semana' => $day,
            'hora_inicio' => substr((string) $start, 0, 5),
            'hora_fin' => substr((string) $end, 0, 5),
            '_inicio' => $startMinutes,
            '_fin' => $endMinutes,
        ];
    }

    private function findConflicts(array $newActivity, array $existingActivities): array
    {
        $conflicts = [];

        foreach ($existingActivities as $existing) {
            if (!$this->datesOverlap($existing, $newActivity)) {
                continue;
            }

            foreach ($existing['horarios'] as $existingSlot) {
                foreach ($newActivity['horarios'] as $newSlot) {
                    if ($existingSlot['dia_semana'] !== $newSlot['dia_semana']) {
                        continue;
                    }

                    // Intervalos semiabiertos: 18:00-20:00 y 20:00-22:00 no chocan.
                    if ($existingSlot['_inicio'] < $newSlot['_fin']
                        && $existingSlot['_fin'] > $newSlot['_inicio']) {
                        $conflict = [
                            'tipo' => $existing['tipo'],
                            'actividad_id' => $existing['actividad_id'],
                            'nombre' => $existing['nombre'],
                            'fecha_inicio' => $existing['fecha_inicio'],
                            'fecha_fin' => $existing['fecha_fin'],
                            'dia_semana' => $existingSlot['dia_semana'],
                            'dia' => $this->dayName($existingSlot['dia_semana']),
                            'hora_inicio' => $existingSlot['hora_inicio'],
                            'hora_fin' => $existingSlot['hora_fin'],
                        ];
                        $key = implode('|', [$conflict['tipo'], $conflict['actividad_id'], $conflict['dia_semana'], $conflict['hora_inicio'], $conflict['hora_fin']]);
                        $conflicts[$key] = $conflict;
                    }
                }
            }
        }

        return array_values($conflicts);
    }

    private function datesOverlap(array $first, array $second): bool
    {
        return $first['fecha_inicio'] <= $second['fecha_fin']
            && $first['fecha_fin'] >= $second['fecha_inicio'];
    }

    private function timeToMinutes($time): ?int
    {
        if ($time === null || $time === '') {
            return null;
        }

        $parts = explode(':', (string) $time);
        if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return null;
        }

        return ((int) $parts[0] * 60) + (int) $parts[1];
    }

    private function dayName(int $day): string
    {
        return [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'][$day] ?? 'Día';
    }
}
