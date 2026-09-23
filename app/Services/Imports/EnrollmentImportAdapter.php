<?php

namespace App\Services\Imports;

use App\Models\CursoAbierto;
use App\Models\Matricula;

final class EnrollmentImportAdapter
{
    public function supportsCourse(CursoAbierto $curso): bool
    {
        return self::supportsPersonalizationFlag((bool) $curso->es_personalizado);
    }

    public static function supportsPersonalizationFlag(bool $isPersonalized): bool
    {
        return !$isPersonalized;
    }

    public function unsupportedCourseError(): array
    {
        return [
            'code' => 'CUSTOM_COURSE_NOT_SUPPORTED',
            'message' => 'Los cursos personalizados utilizan un flujo académico y financiero especial y todavía no pueden matricularse mediante importación.',
        ];
    }

    /**
     * Read-only academic validation used by preview and by the execute guard.
     */
    public function preview(string $personaId, string $cursoAbiertoId): array
    {
        $curso = CursoAbierto::with(['catalogo', 'ciudad'])->find($cursoAbiertoId);

        if (!$curso) {
            return [
                'status' => 'COURSE_NOT_FOUND',
                'curso_abierto_id' => $cursoAbiertoId,
                'errors' => [['code' => 'COURSE_NOT_FOUND', 'message' => 'El curso seleccionado no existe o fue eliminado.']],
                'warnings' => [],
            ];
        }

        if (!$this->supportsCourse($curso)) {
            return [
                'status' => 'CUSTOM_COURSE_NOT_SUPPORTED',
                'curso_abierto_id' => (string) $curso->id,
                'curso_nombre' => $curso->catalogo?->nombre ?? $curso->nombre_instancia,
                'nombre_instancia' => $curso->nombre_instancia,
                'fecha_inicio' => $curso->fecha_inicio?->toDateString(),
                'fecha_fin' => $curso->fecha_fin?->toDateString(),
                'historico' => $curso->fecha_inicio?->lt(now()->subDays(7)) ?? false,
                'es_personalizado' => true,
                'proposed_action' => 'BLOCK',
                'errors' => [$this->unsupportedCourseError()],
                'warnings' => [],
            ];
        }

        $conflict = Matricula::conflictoNuevaInscripcion($personaId, $cursoAbiertoId);
        $status = $conflict ? match ($conflict['tipo']) {
            'soft_deleted' => 'SOFT_DELETED_ENROLLMENT',
            'activo' => 'ALREADY_ENROLLED',
            'completado' => 'ALREADY_COMPLETED',
            default => 'DUPLICATE_ENROLLMENT',
        } : 'ENROLLMENT_AVAILABLE';

        $existing = $conflict
            ? Matricula::withTrashed()->where('estudiante_id', $personaId)->where('curso_abierto_id', $cursoAbiertoId)->latest('fecha_inscripcion')->first()
            : null;

        $available = $curso->capacidad_maxima > 0 ? $curso->obtenerEspaciosDisponibles() : null;
        if (!$conflict && $available !== null && $available <= 0) {
            $status = 'COURSE_FULL';
        }

        return [
            'status' => $status,
            'curso_abierto_id' => (string) $curso->id,
            'curso_nombre' => $curso->catalogo?->nombre ?? $curso->nombre_instancia,
            'nombre_instancia' => $curso->nombre_instancia,
            'fecha_inicio' => $curso->fecha_inicio?->toDateString(),
            'fecha_fin' => $curso->fecha_fin?->toDateString(),
            'modalidad' => $curso->modalidad,
            'ciudad' => $curso->ciudad?->nombre,
            'estado' => $curso->estado,
            'historico' => $curso->fecha_inicio?->lt(now()->subDays(7)) ?? false,
            'capacidad_maxima' => $curso->capacidad_maxima,
            'estudiantes_inscritos' => $curso->obtenerCountMatriculas(),
            'espacios_disponibles' => $available,
            'existing_matricula_id' => $existing?->id,
            'proposed_action' => $status === 'ENROLLMENT_AVAILABLE' ? 'CREATE_ENROLLMENT' : 'BLOCK',
            'errors' => $status === 'ENROLLMENT_AVAILABLE' ? [] : [[
                'code' => $status,
                'message' => $status === 'COURSE_FULL'
                    ? 'El curso no dispone de cupos disponibles.'
                    : ($conflict['mensaje'] ?? 'La matrícula no puede crearse.'),
            ]],
            'warnings' => [],
        ];
    }

    /**
     * Creates only the academic matricula. The caller owns the transaction.
     */
    public function execute(string $personaId, string $cursoAbiertoId): array
    {
        $curso = CursoAbierto::whereKey($cursoAbiertoId)->lockForUpdate()->first();
        if (!$curso) {
            throw new \RuntimeException('El curso seleccionado no existe o fue eliminado.');
        }

        if (!$this->supportsCourse($curso)) {
            throw new \RuntimeException($this->unsupportedCourseError()['message']);
        }

        $conflict = Matricula::conflictoNuevaInscripcion($personaId, $cursoAbiertoId);
        if ($conflict) {
            throw new \RuntimeException($conflict['mensaje']);
        }

        if ($curso->capacidad_maxima > 0 && $curso->obtenerEspaciosDisponibles() <= 0) {
            throw new \RuntimeException('El curso no dispone de cupos disponibles.');
        }

        $matricula = Matricula::create([
            'estudiante_id' => $personaId,
            'curso_abierto_id' => $cursoAbiertoId,
            'estado' => Matricula::ESTADO_ACTIVO,
            'precio_total_legacy' => 0,
            'tipo_pago' => 'completo',
        ]);

        $curso->increment('estudiantes_inscritos');

        return [
            'matricula_id' => (string) $matricula->id,
            'enrollment_action' => 'CREATED',
            'enrollment_status' => 'ENROLLED',
        ];
    }
}
