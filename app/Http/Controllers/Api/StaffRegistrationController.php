<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ValidateRegistrationRequest;
use App\Http\Requests\RejectRegistrationRequest;
use App\Models\SolicitudInscripcion;
use App\Models\Persona;
use App\Services\RegistrationStateService;
use App\Services\StorageCleanupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StaffRegistrationController extends Controller
{
    private RegistrationStateService $stateService;

    public function __construct(RegistrationStateService $stateService)
    {
        $this->stateService = $stateService;
        // Middleware de autenticación aplicado en rutas
    }

    /**
     * GET /api/staff/solicitudes-inscripcion
     * Listar solicitudes con filtros
     */
    public function index(Request $request)
    {
        $query = SolicitudInscripcion::with([
            'estudiante:id,nombres,apellidos,correo',
            'participanteExterno:id,nombres,apellidos,correo,celular,cedula,ocupacion,direccion,ciudad,estado_civil,fecha_nacimiento,edad',
            'cursoAbierto:id,catalogo_curso_id,precio_base,capacidad_maxima,estudiantes_inscritos',
            'cursoAbierto.catalogo:id,nombre,categoria',
        ]);

        // Filtro por estado
        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        // Filtro por curso
        if ($request->has('curso_abierto_id')) {
            $query->where('curso_abierto_id', $request->curso_abierto_id);
        }

        // Búsqueda por nombre/email del solicitante
        if ($request->has('search')) {
            $query->search($request->search);
        }

        // Filtro por fecha
        if ($request->has('fecha_desde')) {
            $query->whereDate('created_at', '>=', $request->fecha_desde);
        }

        if ($request->has('fecha_hasta')) {
            $query->whereDate('created_at', '<=', $request->fecha_hasta);
        }

        // Ordenar por fecha descendente
        $query->orderByDesc('created_at');

        $perPage = $request->get('per_page', 15);
        $solicitudes = $query->paginate($perPage);

        $baseQuery = SolicitudInscripcion::query();
        $fechaMin = (clone $baseQuery)->min('created_at');
        $fechaMax = (clone $baseQuery)->max('created_at');

        return response()->json([
            'data' => $solicitudes->items(),
            'meta' => [
                'total' => $solicitudes->total(),
                'per_page' => $solicitudes->perPage(),
                'current_page' => $solicitudes->currentPage(),
                'last_page' => $solicitudes->lastPage(),
                'fecha_min' => $fechaMin,
                'fecha_max' => $fechaMax,
            ],
        ]);
    }

    /**
     * GET /api/staff/solicitudes-inscripcion/{id}
     * Ver detalles de una solicitud
     */
    public function show(string $id)
    {
        $solicitud = SolicitudInscripcion::with([
            'estudiante:id,nombres,apellidos,cedula,correo,celular',
            'participanteExterno:id,nombres,apellidos,correo,celular,cedula,ocupacion,direccion,ciudad,estado_civil,fecha_nacimiento,edad',
            'cursoAbierto:id,catalogo_curso_id,precio_base,capacidad_maxima,estudiantes_inscritos,fecha_inicio,fecha_fin',
            'cursoAbierto.catalogo:id,nombre,descripcion,categoria',
            'validador:id,nombres,apellidos,correo',
        ])->find($id);

        if (!$solicitud) {
            return response()->json([
                'mensaje' => 'Solicitud no encontrada',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => $this->formatearSolicitudDetallada($solicitud),
        ]);
    }

    /**
     * POST /api/staff/solicitudes-inscripcion/{id}/validar
     * Aprobar una solicitud (transición a aprobado + crear matrícula)
     */
    public function approve(string $id, ValidateRegistrationRequest $request)
    {
        $solicitud = SolicitudInscripcion::with('cursoAbierto.modulos', 'participanteExterno')->find($id);

        if (!$solicitud) {
            return response()->json([
                'mensaje' => 'Solicitud no encontrada',
            ], Response::HTTP_NOT_FOUND);
        }

        // Obtener el validador (sin restricción de rol por ahora)
        $validador = auth()->user();
        $validadorPersonaId = auth()->user()->persona_id ?? null;

        // Usar el servicio para aprobar
        $resultado = $this->stateService->approve(
            $solicitud,
            $validadorPersonaId,
            $request->observaciones_validacion,
            $request->pagos ?? [],
            $request->metodo_pago ?? 'efectivo',
            $request->has('precio_inscripcion') ? (float) $request->precio_inscripcion : null,
            (float) ($request->inscripcion_cubierta ?? 0)
        );

        if (!$resultado['exito']) {
            return response()->json([
                'mensaje' => $resultado['mensaje'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'mensaje' => $resultado['mensaje'],
            'data' => [
                'solicitud_id' => $solicitud->id,
                'matricula_id' => $resultado['matricula_id'],
                'cuenta_cobrar_id' => $resultado['cuenta_cobrar_id'] ?? null,
                'lineas_pago_ids' => $resultado['lineas_pago_ids'] ?? [],
                'estado' => $solicitud->refresh()->estado,
            ],
        ]);
    }

    /**
     * POST /api/staff/solicitudes-inscripcion/{id}/rechazar
     * Rechazar una solicitud
     */
    public function reject(string $id, RejectRegistrationRequest $request)
    {
        $solicitud = SolicitudInscripcion::find($id);

        if (!$solicitud) {
            return response()->json([
                'mensaje' => 'Solicitud no encontrada',
            ], Response::HTTP_NOT_FOUND);
        }

        $validador = auth()->user();
        $validadorPersonaId = ($validador instanceof Persona) ? $validador->id : null;

        $resultado = $this->stateService->reject(
            $solicitud,
            $validadorPersonaId,
            $request->motivo_rechazo
        );

        if (!$resultado['exito']) {
            return response()->json([
                'mensaje' => $resultado['mensaje'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'mensaje' => $resultado['mensaje'],
            'data' => [
                'solicitud_id' => $solicitud->id,
                'estado' => $solicitud->refresh()->estado,
                'motivo' => $solicitud->motivo_rechazo,
            ],
        ]);
    }

    /**
     * POST /api/staff/solicitudes-inscripcion/{id}/cancelar
     * Cancelar una solicitud (por staff)
     */
    public function cancel(string $id, Request $request)
    {
        $solicitud = SolicitudInscripcion::find($id);

        if (!$solicitud) {
            return response()->json([
                'mensaje' => 'Solicitud no encontrada',
            ], Response::HTTP_NOT_FOUND);
        }

        $resultado = $this->stateService->cancel($solicitud, $request->motivo ?? null);

        if (!$resultado['exito']) {
            return response()->json([
                'mensaje' => $resultado['mensaje'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'mensaje' => $resultado['mensaje'],
            'data' => [
                'solicitud_id' => $solicitud->id,
                'estado' => $solicitud->refresh()->estado,
            ],
        ]);
    }

    /**
     * Formatear solicitud detallada
     */
    private function formatearSolicitudDetallada(SolicitudInscripcion $solicitud): array
    {
        $solicitud->loadMissing([
            'estudiante.perfilEstudiante',
            'participanteExterno',
            'cursoAbierto.catalogo',
            'validador',
        ]);

        $lineasPago = null;
        if ($solicitud->estado === SolicitudInscripcion::ESTADO_MATRICULA_CREADA) {
            $matricula = \App\Models\Matricula::with('lineasPago.modulo')
                ->where('solicitud_inscripcion_id', $solicitud->id)
                ->first();
            if ($matricula?->lineasPago) {
                $totalAbonado = 0;
                $totalEsperado = 0;
                $lineas = $matricula->lineasPago->sortBy('orden')->map(function ($lp) use (&$totalAbonado, &$totalEsperado) {
                    $totalAbonado += (float) $lp->monto_abonado;
                    $totalEsperado += (float) $lp->monto_ajustado;
                    return [
                        'modulo_nombre' => $lp->tipo === 'inscripcion' ? 'Inscripción' : ($lp->modulo?->nombre_modulo ?? 'Módulo'),
                        'monto_ajustado' => (float) $lp->monto_ajustado,
                        'monto_abonado' => (float) $lp->monto_abonado,
                        'saldo' => max(0, (float) $lp->monto_ajustado - (float) $lp->monto_abonado),
                        'estado' => $lp->estado,
                    ];
                })->values()->toArray();
                $lineasPago = [
                    'modulos' => $lineas,
                    'total_abonado' => $totalAbonado,
                    'total_esperado' => $totalEsperado,
                    'modulos_count' => count($lineas),
                    'modulos_pagados' => $matricula->lineasPago->filter(fn($lp) => $lp->estaPagada())->count(),
                ];
            }
        }

        return [
            'id' => $solicitud->id,
            'solicitante' => [
                'tipo' => $solicitud->esEstudiante() ? 'estudiante' : 'externo',
                'datos' => $solicitud->esEstudiante()
                    ? array_merge(
                        $solicitud->estudiante?->only([
                            'id', 'tipo', 'cedula', 'nombres', 'apellidos',
                            'correo', 'celular', 'ciudad_id',
                        ]) ?? [],
                        $solicitud->estudiante?->perfilEstudiante?->only([
                            'fecha_nacimiento', 'ocupacion', 'direccion',
                            'ciudad', 'estado_civil', 'edad',
                        ]) ?? [],
                    )
                    : $solicitud->participanteExterno?->toArray() ?? [],
            ],
            'curso' => $solicitud->cursoAbierto ? [
                'id' => $solicitud->cursoAbierto->id,
                'nombre' => $solicitud->cursoAbierto->catalogo?->nombre,
                'descripcion' => $solicitud->cursoAbierto->catalogo?->descripcion,
                'precio_base' => $solicitud->cursoAbierto->precio_base,
                'capacidad' => [
                    'maxima' => $solicitud->cursoAbierto->capacidad_maxima,
                    'inscritos' => $solicitud->cursoAbierto->estudiantes_inscritos,
                    'disponible' => $solicitud->cursoAbierto->capacidad_maxima - $solicitud->cursoAbierto->estudiantes_inscritos,
                ],
                'fechas' => [
                    'inicio' => $solicitud->cursoAbierto->fecha_inicio,
                    'fin_estimada' => $solicitud->cursoAbierto->fecha_fin,
                ],
            ] : null,
            'pago' => [
                'monto_solicitado' => $solicitud->monto_solicitado,
                'tipo_pago' => $solicitud->tipo_pago,
                'comprobante' => [
                    'tipo' => $solicitud->tipo_comprobante,
                    'url' => $solicitud->archivo_comprobante_url,
                    'cedula_url' => $solicitud->archivo_cedula_url,
                    'fecha_pago_declarada' => $solicitud->fecha_pago_declarada,
                    'comprobante_purgado' => $solicitud->archivo_comprobante_url
                        ? \App\Models\ArchivoEliminado::archivoFueEliminado(
                            \App\Models\SolicitudInscripcion::class, $solicitud->id, 'archivo_comprobante_url'
                        ) : false,
                    'cedula_purgado' => $solicitud->archivo_cedula_url
                        ? \App\Models\ArchivoEliminado::archivoFueEliminado(
                            \App\Models\SolicitudInscripcion::class, $solicitud->id, 'archivo_cedula_url'
                        ) : false,
                ],
            ],
            'lineas_pago' => $lineasPago,
            'estado' => [
                'valor' => $solicitud->estado,
                'descripcion' => $solicitud->obtenerDescripcionEstado(),
            ],
            'validacion' => [
                'validado_por' => $solicitud->validador ? [
                    'id' => $solicitud->validador->id,
                    'nombre' => $solicitud->validador->nombres . ' ' . $solicitud->validador->apellidos,
                ] : null,
                'fecha_validacion' => $solicitud->fecha_validacion,
                'observaciones' => $solicitud->observaciones_validacion,
                'motivo_rechazo' => $solicitud->motivo_rechazo,
            ],
            'fechas' => [
                'registro' => $solicitud->created_at,
                'actualizado' => $solicitud->updated_at,
            ],
        ];
    }

    /**
     * POST /api/academic/solicitudes-inscripcion/{id}/cedula
     */
    public function uploadCedula(string $id, Request $request)
    {
        $request->validate(['archivo' => 'required|file|image|max:2048']);
        $solicitud = SolicitudInscripcion::findOrFail($id);
        $service = app(StorageCleanupService::class);

        if ($solicitud->archivo_cedula_url) {
            $service->deleteFilePhysically($solicitud, 'archivo_cedula_url');
        }

        $file = $request->file('archivo');
        $filename = \Illuminate\Support\Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('comprobantes', $filename);
        $url = Storage::disk()->url($path);
        $solicitud->update(['archivo_cedula_url' => $url]);
        $service->reviveFileField($solicitud, 'archivo_cedula_url');
        return response()->json(['data' => ['cedula_url' => $url], 'message' => 'Cédula subida']);
    }

    /**
     * PATCH /api/staff/solicitudes-inscripcion/{id}/actualizar-estudiante
     * Actualizar datos del estudiante/participante externo
     * 
     * @param string $id ID de la solicitud
     * @param Request $request Datos a actualizar (nombres, apellidos, correo, celular, cedula)
     * @return JsonResponse
     */
    public function updateEstudiante(string $id, Request $request)
    {
        $solicitud = SolicitudInscripcion::findOrFail($id);
        $solicitud->loadMissing(['estudiante.perfilEstudiante', 'participanteExterno']);

        // Validar datos
        $validated = $request->validate([
            'nombres' => 'nullable|string|max:255',
            'apellidos' => 'nullable|string|max:255',
            'correo' => 'nullable|email|max:255',
            'celular' => 'nullable|string|max:20',
            'tipo_id' => 'nullable|in:cedula,dni',
            'cedula' => [
                'nullable',
                'string',
                'max:20',
                function ($attribute, $value, $fail) use ($request) {
                    $tipoId = $request->input('tipo_id');
                    if ($tipoId === 'cedula' && !empty($value)) {
                        if (!preg_match('/^\d{10}$/', $value)) {
                            $fail('La cédula debe tener exactamente 10 dígitos numéricos.');
                        }
                    } elseif ($tipoId === 'dni' && !empty($value)) {
                        if (strlen($value) < 5) {
                            $fail('El DNI debe tener al menos 5 caracteres.');
                        }
                    }
                },
            ],
            'ocupacion' => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:1000',
            'ciudad' => 'nullable|string|max:100',
            'estado_civil' => 'nullable|string|max:20',
            'fecha_nacimiento' => 'nullable|date',
            'edad' => 'nullable|integer|min:0|max:150',
        ]);

        try {
            // Actualizar datos según si es estudiante o participante externo
            if ($solicitud->esEstudiante() && $solicitud->estudiante) {
                // Actualizar Persona
                $dataUpdate = array_filter([
                    'nombres' => $validated['nombres'] ?? null,
                    'apellidos' => $validated['apellidos'] ?? null,
                    'correo' => $validated['correo'] ?? null,
                    'celular' => $validated['celular'] ?? null,
                ], fn($v) => $v !== null);

                if (!empty($dataUpdate)) {
                    $solicitud->estudiante->update($dataUpdate);
                }

                // Actualizar perfil_estudiante si aplica
                $perfilUpdate = array_filter([
                    'ocupacion' => $validated['ocupacion'] ?? null,
                    'direccion' => $validated['direccion'] ?? null,
                    'ciudad' => $validated['ciudad'] ?? null,
                    'estado_civil' => $validated['estado_civil'] ?? null,
                    'fecha_nacimiento' => $validated['fecha_nacimiento'] ?? null,
                    'edad' => $validated['edad'] ?? null,
                ], fn($v) => $v !== null);

                if (!empty($perfilUpdate) && $solicitud->estudiante->perfilEstudiante) {
                    $solicitud->estudiante->perfilEstudiante->update($perfilUpdate);
                }
            } elseif ($solicitud->participanteExterno) {
                // Actualizar ClienteExterno
                $dataUpdate = array_filter([
                    'nombres' => $validated['nombres'] ?? null,
                    'apellidos' => $validated['apellidos'] ?? null,
                    'correo' => $validated['correo'] ?? null,
                    'celular' => $validated['celular'] ?? null,
                    'cedula' => $validated['cedula'] ?? null,
                    'ocupacion' => $validated['ocupacion'] ?? null,
                    'direccion' => $validated['direccion'] ?? null,
                    'ciudad' => $validated['ciudad'] ?? null,
                    'estado_civil' => $validated['estado_civil'] ?? null,
                    'fecha_nacimiento' => $validated['fecha_nacimiento'] ?? null,
                    'edad' => $validated['edad'] ?? null,
                ], fn($v) => $v !== null);

                if (!empty($dataUpdate)) {
                    $solicitud->participanteExterno->update($dataUpdate);
                }
            }

            return response()->json([
                'mensaje' => 'Datos del estudiante actualizados correctamente',
                'data' => $this->formatearSolicitudDetallada($solicitud->refresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'mensaje' => "Error al actualizar datos: {$e->getMessage()}",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * PATCH /api/academic/solicitudes-inscripcion/{id}/actualizar-pago
     * Actualizar datos de pago (monto, tipo)
     */
    public function updatePago(string $id, Request $request)
    {
        $solicitud = SolicitudInscripcion::findOrFail($id);

        $validated = $request->validate([
            'monto_solicitado' => 'nullable|numeric|min:0.01',
            'tipo_pago' => 'nullable|string|in:completo,abono',
            'tipo_comprobante' => 'nullable|string|max:50',
            'fecha_pago_declarada' => 'nullable|date',
        ]);

        try {
            $dataUpdate = [];

            if (isset($validated['monto_solicitado'])) {
                $monto = $validated['monto_solicitado'];
                $dataUpdate['monto_solicitado'] = $monto;

                // Auto-detectar tipo de pago según el monto vs el precio base del curso
                $solicitud->load('cursoAbierto');
                $precioBase = $solicitud->cursoAbierto?->precio_base;
                if ($precioBase) {
                    $dataUpdate['tipo_pago'] = $monto >= $precioBase ? 'completo' : 'abono';
                }
            }

            // Explicit tipo_pago override (solo valores permitidos por constraint)
            if (isset($validated['tipo_pago'])) {
                $dataUpdate['tipo_pago'] = $validated['tipo_pago'];
            }

            if (isset($validated['tipo_comprobante'])) {
                $dataUpdate['tipo_comprobante'] = $validated['tipo_comprobante'];
            }

            if (isset($validated['fecha_pago_declarada'])) {
                $dataUpdate['fecha_pago_declarada'] = $validated['fecha_pago_declarada'];
            }

            if (!empty($dataUpdate)) {
                $solicitud->update($dataUpdate);
            }

            return response()->json([
                'mensaje' => 'Datos de pago actualizados correctamente',
                'data' => $this->formatearSolicitudDetallada($solicitud->refresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'mensaje' => "Error al actualizar pago: {$e->getMessage()}",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * PATCH /api/academic/solicitudes-inscripcion/{id}/actualizar-curso
     * Actualizar curso abierto asignado a la solicitud
     */
    public function updateCurso(string $id, Request $request)
    {
        $solicitud = SolicitudInscripcion::findOrFail($id);

        $validated = $request->validate([
            'curso_abierto_id' => 'required|uuid|exists:cursos_abiertos,id',
        ]);

        try {
            $solicitud->update(['curso_abierto_id' => $validated['curso_abierto_id']]);

            return response()->json([
                'mensaje' => 'Curso actualizado correctamente',
                'data' => $this->formatearSolicitudDetallada($solicitud->refresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'mensaje' => "Error al actualizar curso: {$e->getMessage()}",
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }

    /**
     * POST /api/academic/solicitudes-inscripcion/{id}/comprobante
     * Subir nuevo comprobante de pago
     */
    public function uploadComprobante(string $id, Request $request)
    {
        $request->validate(['archivo' => 'required|file|image|max:5120']);
        $solicitud = SolicitudInscripcion::findOrFail($id);
        $service = app(StorageCleanupService::class);

        if ($solicitud->archivo_comprobante_url) {
            $service->deleteFilePhysically($solicitud, 'archivo_comprobante_url');
        }

        $file = $request->file('archivo');
        $filename = \Illuminate\Support\Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('comprobantes', $filename);
        $url = Storage::disk()->url($path);
        $solicitud->update(['archivo_comprobante_url' => $url]);
        $service->reviveFileField($solicitud, 'archivo_comprobante_url');
        return response()->json([
            'data' => ['comprobante_url' => $url],
            'mensaje' => 'Comprobante subido correctamente',
        ]);
    }

    /**
     * DELETE /api/academic/solicitudes-inscripcion/{id}
     * Eliminar una solicitud rechazada (soft delete + limpieza de archivos)
     */
    public function destroy(string $id): JsonResponse
    {
        $solicitud = SolicitudInscripcion::findOrFail($id);
        $eliminadoPor = auth()->id() ?? auth()->user()?->persona_id ?? null;

        DB::transaction(function () use ($solicitud) {
            \App\Models\CuentaPorCobrar::where('solicitud_inscripcion_id', $solicitud->id)->delete();
            $solicitud->delete();
        });

        app(StorageCleanupService::class)->deleteRecordFiles($solicitud, $eliminadoPor);

        return response()->json(['mensaje' => 'Solicitud eliminada correctamente']);
    }

    public function deleteArchivo(Request $request, string $id): \Illuminate\Http\JsonResponse
    {
        $request->validate([
            'campo' => 'required|string|in:archivo_comprobante_url,archivo_cedula_url',
        ]);

        $solicitud = SolicitudInscripcion::findOrFail($id);
        $eliminadoPor = auth()->id() ?? auth()->user()?->persona_id ?? null;
        $service = app(StorageCleanupService::class);

        $resultado = $service->deleteFile($solicitud, $request->campo, $eliminadoPor);

        if (!$resultado['eliminado']) {
            return response()->json(['mensaje' => $resultado['mensaje']], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'mensaje' => 'Archivo eliminado del almacenamiento. El registro se conserva como constancia histórica.',
        ]);
    }
}
