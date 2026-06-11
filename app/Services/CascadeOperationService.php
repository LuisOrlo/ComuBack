<?php

namespace App\Services;

use App\Models\CursoAbierto;
use App\Models\Matricula;
use App\Models\Nota;
use App\Models\Modulo;
use Illuminate\Database\Eloquent\Model;

/**
 * Service para manejar operaciones en cascada
 * 
 * Coordina eliminación y actualización de datos relacionados
 */
class CascadeOperationService
{
    /**
     * Eliminar un curso abierto en cascada
     * 
     * Elimina:
     * 1. Todas las notas de todas las matrículas
     * 2. Todas las matrículas
     * 3. Todos los módulos personalizados
     * 4. Todos los horarios
     * 5. El curso abierto
     */
    public function deleteCursoAbierto(CursoAbierto $curso, bool $force = false): array
    {
        $deletedItems = [
            'notas' => 0,
            'matriculas' => 0,
            'modulos' => 0,
            'horarios' => 0,
            'cambios_horario' => 0,
            'traslados_modulo' => 0,
        ];

        try {
            // 1. Eliminar notas
            $matriculaIds = $curso->matriculas()->pluck('id')->toArray();
            $notasCount = Nota::whereIn('matricula_id', $matriculaIds)
                ->delete();
            $deletedItems['notas'] = $notasCount;

            // 2. Eliminar cambios de horario relacionados
            $cambiosCount = $curso->cambiosHorarioOrigen()
                ->delete() + $curso->cambiosHorarioDestino()
                ->delete();
            $deletedItems['cambios_horario'] = $cambiosCount;

            // 3. Eliminar matrículas
            $matriculasCount = $curso->matriculas()->delete();
            $deletedItems['matriculas'] = $matriculasCount;

            // 4. Eliminar módulos personalizados
            $modulosCount = $curso->modulos()->delete();
            $deletedItems['modulos'] = $modulosCount;

            // 5. El horario es compartido (BelongsTo), no se elimina
            $deletedItems['horarios'] = $curso->horario ? 1 : 0;

            // 6. Eliminar el curso
            $curso->delete();

            return [
                'success' => true,
                'message' => 'Curso eliminado en cascada exitosamente',
                'deleted_items' => $deletedItems,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Error al eliminar en cascada: {$e->getMessage()}",
                'deleted_items' => $deletedItems,
            ];
        }
    }

    /**
     * Eliminar una matrícula en cascada
     * 
     * Elimina:
     * 1. Todas las notas de la matrícula
     * 2. Cambios de horario asociados
     * 3. Traslados de módulo asociados
     * 4. La matrícula
     */
    public function deleteMatricula(Matricula $matricula): array
    {
        $deletedItems = [
            'notas' => 0,
            'cambios_horario' => 0,
            'traslados_modulo' => 0,
        ];

        try {
            // 1. Eliminar notas
            $notasCount = $matricula->notas()->delete();
            $deletedItems['notas'] = $notasCount;

            // 2. Eliminar cambios de horario
            $cambiosCount = $matricula->cambiosHorario()->delete();
            $deletedItems['cambios_horario'] = $cambiosCount;

            // 3. Eliminar traslados de módulo
            $trasladosCount = $matricula->trasladosModulo()->delete();
            $deletedItems['traslados_modulo'] = $trasladosCount;

            // 4. Eliminar matrícula
            $matricula->delete();

            return [
                'success' => true,
                'message' => 'Matrícula eliminada en cascada exitosamente',
                'deleted_items' => $deletedItems,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Error al eliminar en cascada: {$e->getMessage()}",
                'deleted_items' => $deletedItems,
            ];
        }
    }

    /**
     * Eliminar un módulo en cascada
     * 
     * Elimina:
     * 1. Todas las notas del módulo
     * 2. Traslados de módulo donde este es el origen o destino
     * 3. El módulo
     */
    public function deleteModulo(Modulo $modulo): array
    {
        $deletedItems = [
            'notas' => 0,
            'traslados_modulo' => 0,
        ];

        try {
            // 1. Eliminar notas
            $notasCount = $modulo->notas()->delete();
            $deletedItems['notas'] = $notasCount;

            // 2. Eliminar traslados donde es origen o destino
            $trasladosCount = $modulo->trasladosModuloOrigen()
                ->delete() + $modulo->trasladosModuloDestino()
                ->delete();
            $deletedItems['traslados_modulo'] = $trasladosCount;

            // 3. Eliminar módulo
            $modulo->delete();

            return [
                'success' => true,
                'message' => 'Módulo eliminado en cascada exitosamente',
                'deleted_items' => $deletedItems,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Error al eliminar en cascada: {$e->getMessage()}",
                'deleted_items' => $deletedItems,
            ];
        }
    }

    /**
     * Restaurar un curso (soft delete recovery)
     */
    public function restoreCursoAbierto(string $cursoId): array
    {
        try {
            $curso = CursoAbierto::withTrashed()->find($cursoId);

            if (!$curso) {
                return [
                    'success' => false,
                    'message' => 'Curso no encontrado',
                ];
            }

            if (!$curso->trashed()) {
                return [
                    'success' => false,
                    'message' => 'El curso no está eliminado',
                ];
            }

            $curso->restore();

            return [
                'success' => true,
                'message' => 'Curso restaurado exitosamente',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Error al restaurar: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Obtener análisis de dependencias antes de eliminar
     */
    public function analyzeDependencies(CursoAbierto $curso): array
    {
        return [
            'curso_id' => $curso->id,
            'nombre' => $curso->nombre_instancia,
            'dependencias' => [
                'matriculas' => [
                    'cantidad' => $curso->matriculas()->count(),
                    'estados' => $curso->matriculas()
                        ->groupBy('estado')
                        ->selectRaw('estado, COUNT(*) as cantidad')
                        ->get()
                        ->keyBy('estado'),
                ],
                'horarios' => [
                    'cantidad' => $curso->horario ? 1 : 0,
                    'activos' => ($curso->horario && $curso->horario->es_activo) ? 1 : 0,
                ],
                'modulos' => [
                    'cantidad' => $curso->modulos()->count(),
                ],
                'notas' => [
                    'cantidad' => Nota::whereIn(
                        'matricula_id',
                        $curso->matriculas()->pluck('id')
                    )->count(),
                ],
            ],
            'advertencia' => $curso->matriculas()->count() > 0
                ? "Este curso tiene {$curso->matriculas()->count()} matrículas activas"
                : null,
        ];
    }
}
