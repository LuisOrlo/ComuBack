<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ValidateRegistrationRequest;
use App\Http\Requests\RejectRegistrationRequest;
use App\Models\SolicitudInscripcion;
use App\Models\Persona;
use App\Models\Horario;
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
            'participanteExterno:id,nombres,apellidos,correo,celular,cedula,ocupacion,direccion,ciudad,estado_civil,edad,nivel_educativo',
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
            'participanteExterno:id,nombres,apellidos,correo,celular,cedula,ocupacion,direccion,ciudad,estado_civil,edad,nivel_educativo',
            'cursoAbierto:id,catalogo_curso_id,nombre_instancia,precio_base,capacidad_maxima,estudiantes_inscritos,fecha_inicio,fecha_fin,modalidad,docente_id,ciudad_id,horario_id',
            'cursoAbierto.catalogo:id,nombre,descripcion,categoria,color',
            'cursoAbierto.docente:id,nombres,apellidos',
            'cursoAbierto.ciudad:id,nombre',
            'cursoAbierto.horario:id,nombre_referencial,dia_semana,hora_inicio,hora_fin,es_activo',
            'cursoAbierto.horario.diasSemana',
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
     * GET /api/academic/solicitudes-inscripcion/{id}/adjacent
     * Obtener IDs de la solicitud anterior y siguiente para navegación rápida
     */
    public function adjacent(Request $request, string $id): JsonResponse
    {
        $current = SolicitudInscripcion::findOrFail($id);

        $query = SolicitudInscripcion::query();

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        } else {
            $query->where('estado', 'pendiente_validacion');
        }

        if ($request->has('search')) {
            $query->search($request->search);
        }

        if ($request->has('curso_abierto_id')) {
            $query->where('curso_abierto_id', $request->curso_abierto_id);
        }

        if ($request->has('fecha_desde')) {
            $query->whereDate('created_at', '>=', $request->fecha_desde);
        }

        if ($request->has('fecha_hasta')) {
            $query->whereDate('created_at', '<=', $request->fecha_hasta);
        }

        $ids = $query->orderByDesc('created_at')->pluck('id');
        $total = $ids->count();
        $position = $ids->search($id);

        if ($position === false) {
            return response()->json([
                'prev_id'      => null,
                'next_id'      => $total > 0 ? $ids->first() : null,
                'first_id'     => $total > 0 ? $ids->first() : null,
                'position'     => 0,
                'total'        => $total,
                'stale'        => true,
                'stale_estado' => $current->estado,
            ]);
        }

        return response()->json([
            'prev_id'   => $position > 0 ? $ids[$position - 1] : null,
            'next_id'   => $position < $total - 1 ? $ids[$position + 1] : null,
            'first_id'  => null,
            'position'  => $position + 1,
            'total'     => $total,
            'stale'     => false,
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
            'cursoAbierto.docente',
            'cursoAbierto.ciudad',
            'cursoAbierto.horario.diasSemana',
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
                $modulosList = [];
                $inscripcionData = null;

                foreach ($matricula->lineasPago->sortBy('orden') as $lp) {
                    $totalAbonado += (float) $lp->monto_abonado;
                    $totalEsperado += (float) $lp->monto_ajustado;
                    $linea = [
                        'id' => $lp->id,
                        'tipo' => $lp->tipo,
                        'modulo_nombre' => $lp->tipo === 'inscripcion' ? 'Inscripción' : ($lp->modulo?->nombre_modulo ?? 'Módulo'),
                        'monto_ajustado' => (float) $lp->monto_ajustado,
                        'monto_abonado' => (float) $lp->monto_abonado,
                        'saldo' => max(0, (float) $lp->monto_ajustado - (float) $lp->monto_abonado),
                        'estado' => $lp->estado,
                    ];

                    if ($lp->tipo === 'inscripcion') {
                        $inscripcionData = $linea;
                    } else {
                        $modulosList[] = $linea;
                    }
                }

                $lineasPago = [
                    'modulos' => $modulosList,
                    'inscripcion' => $inscripcionData,
                    'total_abonado' => $totalAbonado,
                    'total_esperado' => $totalEsperado,
                    'modulos_count' => count($modulosList),
                    'modulos_pagados' => $matricula->lineasPago->filter(fn($lp) => $lp->tipo !== 'inscripcion' && $lp->estaPagada())->count(),
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
                            'ocupacion', 'direccion',
                            'ciudad', 'estado_civil', 'edad',
                            'nivel_educativo',
                        ]) ?? [],
                    )
                    : $solicitud->participanteExterno?->toArray() ?? [],
            ],
            'curso' => $solicitud->cursoAbierto ? [
                'id' => $solicitud->cursoAbierto->id,
                'nombre' => $solicitud->cursoAbierto->nombre_instancia ?: $solicitud->cursoAbierto->catalogo?->nombre,
                'nombre_catalogo' => $solicitud->cursoAbierto->catalogo?->nombre,
                'descripcion' => $solicitud->cursoAbierto->catalogo?->descripcion,
                'color' => $solicitud->cursoAbierto->catalogo?->color,
                'modalidad' => $solicitud->cursoAbierto->modalidad,
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
                'docente' => $solicitud->cursoAbierto->docente ? [
                    'id' => $solicitud->cursoAbierto->docente->id,
                    'nombre' => trim(($solicitud->cursoAbierto->docente->nombres ?? '') . ' ' . ($solicitud->cursoAbierto->docente->apellidos ?? '')),
                ] : null,
                'ciudad' => $solicitud->cursoAbierto->ciudad?->nombre,
                'horario' => $solicitud->cursoAbierto->horario ? [
                    'descripcion' => $this->descripcionHorario($solicitud->cursoAbierto->horario),
                    'dias_semana' => $solicitud->cursoAbierto->horario->obtenerDiasSemana(),
                    'hora_inicio' => $solicitud->cursoAbierto->horario->hora_inicio,
                    'hora_fin' => $solicitud->cursoAbierto->horario->hora_fin,
                ] : null,
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

    private function descripcionHorario(?Horario $horario): ?string
    {
        if (!$horario) return null;
        $dias = implode('-', $horario->obtenerDiasNombres());
        $inicio = substr((string) $horario->hora_inicio, 0, 5);
        $fin = substr((string) $horario->hora_fin, 0, 5);
        return "{$dias} {$inicio}-{$fin}";
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
            'edad' => 'nullable|integer|min:0|max:150',
            'nivel_educativo' => 'nullable|string|in:educacion inicial,general basica,bachillerato,tecnico/tecnologico,superior,otro',
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
                    'edad' => $validated['edad'] ?? null,
                    'nivel_educativo' => $validated['nivel_educativo'] ?? null,
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
                    'edad' => $validated['edad'] ?? null,
                    'nivel_educativo' => $validated['nivel_educativo'] ?? null,
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
     * POST /api/academic/solicitudes-inscripcion/{id}/reconciliar-curso
     * Cambio de curso con reasignación de lineas_pago para matrículas aprobadas
     */
    public function reconciliarCurso(string $id, Request $request)
    {
        $solicitud = SolicitudInscripcion::findOrFail($id);

        if ($solicitud->estado !== SolicitudInscripcion::ESTADO_MATRICULA_CREADA) {
            return response()->json([
                'mensaje' => 'La reconciliación de pagos solo aplica a matrículas aprobadas',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $matricula = \App\Models\Matricula::where('solicitud_inscripcion_id', $id)->first();
        if (! $matricula) {
            return response()->json([
                'mensaje' => 'No se encontró la matrícula asociada',
            ], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'curso_abierto_id' => 'required|uuid|exists:cursos_abiertos,id',
            'lineas' => 'required|array|min:1',
            'lineas.*.modulo_id' => 'nullable|uuid|exists:modulos,id',
            'lineas.*.tipo' => 'required|string|in:modulo,inscripcion',
            'lineas.*.monto_abonado' => 'required|numeric|min:0',
            'lineas.*.monto_ajustado' => 'required|numeric|min:0',
        ]);

        $nuevoCurso = \App\Models\CursoAbierto::findOrFail($validated['curso_abierto_id']);
        $cupo = ($nuevoCurso->capacidad_maxima ?? 0) - $nuevoCurso->matriculas()->count();
        if ($cupo <= 0 && $nuevoCurso->id !== $solicitud->curso_abierto_id) {
            return response()->json([
                'mensaje' => 'El curso seleccionado no tiene cupo disponible',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(function () use ($matricula, $solicitud, $validated) {
            // 1. Guardar IDs de transacciones viejas (ordenadas por fecha)
            $viejosTxIds = \App\Models\TransaccionIngreso::whereHas('lineaPagoModulo', function ($q) use ($matricula) {
                $q->where('matricula_id', $matricula->id);
            })->orderBy('fecha_pago')->pluck('id');

            // 2. Desvincular y eliminar líneas de pago viejas
            \App\Models\TransaccionIngreso::whereIn('id', $viejosTxIds)
                ->update(['linea_pago_modulo_id' => null]);
            $matricula->lineasPago()->delete();

            // 3. Crear nuevas líneas de pago (con orden explícito)
            $nuevasLineas = [];
            foreach ($validated['lineas'] as $orden => $linea) {
                $lp = $matricula->lineasPago()->create([
                    'modulo_id' => $linea['modulo_id'],
                    'tipo' => $linea['tipo'],
                    'monto_original' => $linea['monto_ajustado'],
                    'monto_ajustado' => $linea['monto_ajustado'],
                    'monto_abonado' => $linea['monto_abonado'],
                    'orden' => $orden,
                    'estado' => match(true) {
                        $linea['monto_abonado'] >= $linea['monto_ajustado'] => 'pagado',
                        $linea['monto_abonado'] > 0 => 'abonado',
                        default => 'pendiente',
                    },
                ]);
                $nuevasLineas[] = $lp;
            }

            // 4. Re-asignar transacciones viejas a nuevas líneas (en orden)
            foreach ($viejosTxIds as $i => $txId) {
                if (! isset($nuevasLineas[$i])) break;
                $linea = $nuevasLineas[$i];
                \App\Models\TransaccionIngreso::where('id', $txId)->update([
                    'linea_pago_modulo_id' => $linea->id,
                    'monto' => $linea->monto_abonado,
                ]);
            }

            // 5. Actualizar curso en solicitud y matrícula
            $solicitud->update(['curso_abierto_id' => $validated['curso_abierto_id']]);
            $matricula->update(['curso_abierto_id' => $validated['curso_abierto_id']]);

            // 6. Sincronizar cuenta por cobrar (monto_total + monto_abonado)
            $totalAbonado = $matricula->lineasPago()->sum('monto_abonado');
            $totalAjustado = $matricula->lineasPago()->sum('monto_ajustado');
            $matricula->cuentaPorCobrar()?->update([
                'monto_total' => $totalAjustado,
                'monto_abonado' => $totalAbonado,
                'estado' => match(true) {
                    $totalAbonado >= $totalAjustado => 'pagado',
                    $totalAbonado > 0 => 'abonado',
                    default => 'pendiente',
                },
            ]);
        });

        return response()->json([
            'mensaje' => 'Curso actualizado y pagos reconciliados correctamente',
            'data' => $this->formatearSolicitudDetallada($solicitud->refresh()),
        ]);
    }

    /**
     * PATCH /api/academic/solicitudes-inscripcion/{id}/actualizar-lineas-pago
     * Actualizar montos abonados de las líneas de pago de una matrícula aprobada
     */
    public function updateLineasPago(string $id, Request $request)
    {
        $solicitud = SolicitudInscripcion::findOrFail($id);

        if ($solicitud->estado !== SolicitudInscripcion::ESTADO_MATRICULA_CREADA) {
            return response()->json([
                'mensaje' => 'Solo se pueden editar las líneas de pago de matrículas aprobadas',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $matricula = \App\Models\Matricula::where('solicitud_inscripcion_id', $id)->first();
        if (! $matricula) {
            return response()->json([
                'mensaje' => 'No se encontró la matrícula asociada a esta solicitud',
            ], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validate([
            'lineas' => 'required|array|min:1',
            'lineas.*.id' => 'required|string|uuid',
            'lineas.*.monto_abonado' => 'required|numeric|min:0',
            'lineas.*.motivo_ajuste' => 'nullable|string|max:255',
        ]);

        DB::transaction(function () use ($validated, $matricula) {
            $matriculaId = $matricula->id;

            foreach ($validated['lineas'] as $linea) {
                $lineaPago = $matricula->lineasPago()->where('id', $linea['id'])->first();
                if (! $lineaPago) {
                    throw new \Exception("Línea de pago {$linea['id']} no encontrada en esta matrícula");
                }
                if ($linea['monto_abonado'] > $lineaPago->monto_ajustado) {
                    throw new \Exception("El monto abonado no puede exceder el monto ajustado ({$lineaPago->monto_ajustado})");
                }

                $estado = match(true) {
                    $linea['monto_abonado'] >= $lineaPago->monto_ajustado => 'pagado',
                    $linea['monto_abonado'] > 0 => 'abonado',
                    default => 'pendiente',
                };

                DB::update("
                    UPDATE finance.lineas_pago_modulo SET
                        monto_abonado = ?,
                        estado = ?::t_estado_pago,
                        updated_at = NOW()
                    WHERE id = ?
                ", [$linea['monto_abonado'], $estado, $linea['id']]);

                DB::update("
                    UPDATE finance.transacciones_ingreso SET
                        monto = ?
                    WHERE linea_pago_modulo_id = ?
                ", [$linea['monto_abonado'], $linea['id']]);
            }

            $totalAbonado = DB::table('finance.lineas_pago_modulo')
                ->where('matricula_id', $matriculaId)
                ->sum('monto_abonado');

            $cuentaEstado = match(true) {
                $totalAbonado >= ($matricula->cuentaPorCobrar?->monto_total ?? 0) => 'pagado',
                $totalAbonado > 0 => 'abonado',
                default => 'pendiente',
            };

            DB::update("
                UPDATE finance.cuentas_por_cobrar SET
                    monto_abonado = ?,
                    estado = ?::t_estado_pago,
                    updated_at = NOW()
                WHERE matricula_id = ?
            ", [$totalAbonado, $cuentaEstado, $matriculaId]);
        });

        return response()->json([
            'mensaje' => 'Líneas de pago actualizadas correctamente',
            'data' => $this->formatearSolicitudDetallada($solicitud->refresh()),
        ]);
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
