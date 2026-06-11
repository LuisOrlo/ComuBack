<?php

namespace App\Services;

use App\Models\CursoAbierto;
use App\Models\Matricula;

/**
 * Service para validar capacidad de cursos
 * 
 * Maneja validaciones de:
 * - Capacidad máxima
 * - Espacios disponibles
 * - Ocupación
 */
class CapacityValidationService
{
    /**
     * Validar si un curso tiene espacios disponibles
     */
    public function hasCapacity(CursoAbierto $curso, int $estudiantesAdicionales = 1): bool
    {
        return $curso->obtenerEspaciosDisponibles() >= $estudiantesAdicionales;
    }

    /**
     * Obtener espacios disponibles
     */
    public function getAvailableCapacity(CursoAbierto $curso): int
    {
        return $curso->obtenerEspaciosDisponibles();
    }

    /**
     * Obtener información de capacidad
     */
    public function getCapacityInfo(CursoAbierto $curso): array
    {
        $inscritos = $curso->obtenerCountMatriculas();
        $capacidad = $curso->capacidad_maxima;
        $disponibles = $curso->obtenerEspaciosDisponibles();
        $porcentaje = $curso->getPorcentajeOcupacion();

        return [
            'capacidad_maxima' => $capacidad,
            'inscritos' => $inscritos,
            'disponibles' => $disponibles,
            'porcentaje_ocupacion' => $porcentaje,
            'esta_lleno' => $curso->estaLleno(),
            'hay_espacios' => $curso->hayEspacios(),
        ];
    }

    /**
     * Validar si se puede agregar un estudiante
     */
    public function canAddStudent(CursoAbierto $curso): bool
    {
        return $this->hasCapacity($curso, 1);
    }

    /**
     * Validar si se pueden agregar múltiples estudiantes
     */
    public function canAddStudents(CursoAbierto $curso, int $cantidad): bool
    {
        return $this->hasCapacity($curso, $cantidad);
    }

    /**
     * Obtener porcentaje de ocupación
     */
    public function getOccupancyPercentage(CursoAbierto $curso): float
    {
        return $curso->getPorcentajeOcupacion();
    }

    /**
     * Validar si el curso está a punto de llenar (90%+)
     */
    public function isAlmostFull(CursoAbierto $curso, float $threshold = 90): bool
    {
        return $this->getOccupancyPercentage($curso) >= $threshold;
    }

    /**
     * Obtener estudiantes inscritos
     */
    public function getEnrolledCount(CursoAbierto $curso): int
    {
        return $curso->obtenerCountMatriculas();
    }

    /**
     * Validar que la capacidad sea válida
     */
    public function isValidCapacity(int $capacidad): bool
    {
        return $capacidad > 0 && $capacidad <= 9999;
    }
}
