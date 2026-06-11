<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Nota;
use App\Models\Matricula;
use App\Models\Modulo;
use App\Http\Requests\BulkRegisterNotasRequest;
use App\Http\Resources\NotaResource;
use App\Traits\Auditable;
use Illuminate\Http\JsonResponse;

/**
 * BulkOperationsController
 * 
 * Maneja operaciones masivas:
 * - Registro bulk de notas
 * - Actualización bulk de estados
 * - Importación de datos
 */
class BulkOperationsController extends Controller
{
    use Auditable;

    /**
     * POST /api/v1/academic/bulk/notas/register
     * 
     * Registrar múltiples notas de una sola vez
     * 
     * Payload:
     * {
     *     "notas": [
     *         {
     *             "matricula_id": "uuid",
     *             "modulo_id": "uuid",
     *             "calificacion": 4.5
     *         },
     *         ...
     *     ]
     * }
     */
    public function bulkRegisterNotas(BulkRegisterNotasRequest $request): JsonResponse
    {
        $notasData = $request->validated('notas');
        $this->audit('bulk_registro_notas', ['cantidad' => count($notasData)]);
        $createdNotas = [];
        $errors = [];

        foreach ($notasData as $index => $notaData) {
            try {
                // Validar que matrícula y módulo existan
                $matricula = Matricula::find($notaData['matricula_id']);
                $modulo = Modulo::find($notaData['modulo_id']);

                if (!$matricula) {
                    $errors[] = [
                        'index' => $index,
                        'error' => "Matrícula {$notaData['matricula_id']} no encontrada",
                    ];
                    continue;
                }

                if (!$modulo) {
                    $errors[] = [
                        'index' => $index,
                        'error' => "Módulo {$notaData['modulo_id']} no encontrado",
                    ];
                    continue;
                }

                // Validar que no exista nota duplicada
                $notaExistente = Nota::where('matricula_id', $notaData['matricula_id'])
                    ->where('modulo_id', $notaData['modulo_id'])
                    ->first();

                if ($notaExistente) {
                    $errors[] = [
                        'index' => $index,
                        'error' => 'Ya existe nota para esta matrícula y módulo',
                        'nota_id' => $notaExistente->id,
                    ];
                    continue;
                }

                // Crear nota
                $nota = Nota::create($notaData);
                $createdNotas[] = new NotaResource($nota);

            } catch (\Exception $e) {
                $errors[] = [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $response = [
            'data' => $createdNotas,
            'summary' => [
                'total_procesadas' => count($notasData),
                'exitosas' => count($createdNotas),
                'errores' => count($errors),
            ],
            'message' => count($errors) === 0 
                ? 'Todas las notas fueron registradas exitosamente'
                : "Se registraron {$createdNotas} notas, con {$errors} errores",
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        $statusCode = count($errors) === 0 ? 201 : 207; // 207 Multi-Status

        return response()->json($response, $statusCode);
    }

    /**
     * POST /api/v1/academic/bulk/notas/update
     * 
     * Actualizar múltiples notas de una sola vez
     * 
     * Payload:
     * {
     *     "notas": [
     *         {
     *             "id": "uuid",
     *             "calificacion": 3.8,
     *             "observaciones": "..."
     *         },
     *         ...
     *     ]
     * }
     */
    public function bulkUpdateNotas(BulkUpdateNotasRequest $request): JsonResponse
    {
        $notasData = $request->validated('notas');
        $this->audit('bulk_actualizacion_notas', ['cantidad' => count($notasData)]);
        $updatedNotas = [];
        $errors = [];

        foreach ($notasData as $index => $notaData) {
            try {
                $nota = Nota::find($notaData['id']);

                if (!$nota) {
                    $errors[] = [
                        'index' => $index,
                        'error' => "Nota {$notaData['id']} no encontrada",
                    ];
                    continue;
                }

                // Actualizar
                $nota->update($notaData);
                $updatedNotas[] = new NotaResource($nota);

            } catch (\Exception $e) {
                $errors[] = [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $response = [
            'data' => $updatedNotas,
            'summary' => [
                'total_procesadas' => count($notasData),
                'exitosas' => count($updatedNotas),
                'errores' => count($errors),
            ],
            'message' => 'Notas actualizadas',
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        $statusCode = count($errors) === 0 ? 200 : 207;

        return response()->json($response, $statusCode);
    }

    /**
     * POST /api/v1/academic/bulk/matriculas/cambiar-estado
     * 
     * Cambiar estado de múltiples matrículas
     * 
     * Payload:
     * {
     *     "matriculas": [
     *         {
     *             "id": "uuid",
     *             "nuevo_estado": "completado"
     *         },
     *         ...
     *     ]
     * }
     */
    public function bulkChangeMatriculasStatus(BulkChangeStatusRequest $request): JsonResponse
    {
        $matriculasData = $request->validated('matriculas');
        $this->audit('bulk_cambio_estado_matriculas', ['cantidad' => count($matriculasData)]);
        $updated = [];
        $errors = [];

        foreach ($matriculasData as $index => $matriculaData) {
            try {
                $matricula = Matricula::find($matriculaData['id']);

                if (!$matricula) {
                    $errors[] = [
                        'index' => $index,
                        'error' => "Matrícula {$matriculaData['id']} no encontrada",
                    ];
                    continue;
                }

                $estadoActual = $matricula->estado;
                $estadoNuevo = $matriculaData['nuevo_estado'];

                // Validar transición de estado
                if (!$this->isValidStateTransition($estadoActual, $estadoNuevo)) {
                    $errors[] = [
                        'index' => $index,
                        'error' => "Transición inválida: {$estadoActual} → {$estadoNuevo}",
                    ];
                    continue;
                }

                $matricula->update([
                    'estado' => $estadoNuevo,
                    'fecha_fin' => $estadoNuevo !== 'activo' ? now() : $matricula->fecha_fin,
                ]);

                $updated[] = [
                    'id' => $matricula->id,
                    'estado_anterior' => $estadoActual,
                    'estado_nuevo' => $estadoNuevo,
                ];

            } catch (\Exception $e) {
                $errors[] = [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $response = [
            'data' => $updated,
            'summary' => [
                'total_procesadas' => count($matriculasData),
                'exitosas' => count($updated),
                'errores' => count($errors),
            ],
            'message' => 'Estados actualizados',
        ];

        if (!empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, count($errors) === 0 ? 200 : 207);
    }

    /**
     * Validar transición válida de estado
     */
    private function isValidStateTransition(string $actual, string $nuevo): bool
    {
        $transiciones_validas = [
            'activo' => ['completado', 'retirado', 'reprobado'],
            'completado' => [],
            'retirado' => ['activo'],
            'reprobado' => ['activo'],
        ];

        return in_array($nuevo, $transiciones_validas[$actual] ?? []);
    }

    /**
     * POST /api/v1/academic/bulk/notas/delete
     * 
     * Eliminar múltiples notas
     * 
     * Payload:
     * {
     *     "nota_ids": ["uuid", "uuid", ...]
     * }
     */
    public function bulkDeleteNotas(BulkDeleteRequest $request): JsonResponse
    {
        $notaIds = $request->validated('ids');
        $this->audit('bulk_eliminacion_notas', ['cantidad' => count($notaIds)]);
        
        $deletedCount = Nota::whereIn('id', $notaIds)->delete();

        return response()->json([
            'message' => "{$deletedCount} notas eliminadas",
            'deleted_count' => $deletedCount,
        ]);
    }
}
