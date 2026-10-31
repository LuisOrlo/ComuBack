<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ValidateRegistrationRequest;
use App\Http\Requests\RejectRegistrationRequest;
use App\Models\SolicitudInscripcion;
use App\Models\CursoAbierto;
use App\Models\Ciudad;
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
        $query = SolicitudInscripcion::select([
            'id',
            'persona_id',
            'participante_externo_id',
            'es_participante_externo',
            'curso_abierto_id',
            'estado',
            'validado_por',
            'motivo_rechazo',
            'fecha_validacion',
            'created_at',
        ])->with([
            'estudiante:id,nombres,apellidos,correo',
            'participanteExterno:id,nombres,apellidos,correo,ciudad',
            'cursoAbierto:id,catalogo_curso_id,es_personalizado,nombre_instancia,precio_base,precio_matricula,modalidad,ciudad_id,fecha_inicio',
            'cursoAbierto.catalogo:id,nombre,categoria,color',
            'cursoAbierto.ciudad:id,nombre',
            'validador:id,nombres,apellidos',
        ]);

        // Filtro por estado
        if ($request->filled('estado') && $request->estado !== 'todos') {
            if (in_array($request->estado, ['matricula_creada', 'aprobado', 'aprobados'])) {
                $query->whereIn('estado', [SolicitudInscripcion::ESTADO_MATRICULA_CREADA, SolicitudInscripcion::ESTADO_APROBADO]);
            } else {
                $query->where('estado', $request->estado);
            }
        }

        // Filtro por curso
        if ($request->has('curso_abierto_id')) {
            $query->where('curso_abierto_id', $request->curso_abierto_id);
        }

        // Filtro por ciudad
        if ($request->filled('ciudad_id')) {
            $query->whereHas('cursoAbierto', fn ($c) => $c->where('ciudad_id', $request->ciudad_id));
        }

        if ($request->filled('categoria')) {
            if ($request->categoria === 'personalizado') {
                $query->whereHas('cursoAbierto', fn ($curso) => $curso->where('es_personalizado', true));
            } else {
                $query->whereHas('cursoAbierto', fn ($curso) => $curso->where('es_personalizado', false))
                    ->whereHas('cursoAbierto.catalogo', function ($catalogo) use ($request) {
                        if ($request->categoria === 'regular') {
                            $catalogo->where(fn ($q) => $q->where('categoria', 'regular')->orWhereNull('categoria'));
                        } else {
                            $catalogo->where('categoria', $request->categoria);
                        }
                    });
            }
        }

        // Búsqueda por nombre/cédula/email del solicitante
        if ($request->has('search')) {
            $query->search($request->search);
        }

        // Filtro por fecha (en aprobados filtra por fecha de validación/aprobación)
        $esAprobados = in_array($request->estado, ['matricula_creada', 'aprobado', 'aprobados']);
        $columnaFecha = $esAprobados ? 'fecha_validacion' : 'created_at';

        if ($request->has('fecha_desde')) {
            $query->whereDate($columnaFecha, '>=', $request->fecha_desde);
        }

        if ($request->has('fecha_hasta')) {
            $query->whereDate($columnaFecha, '<=', $request->fecha_hasta);
        }

        // Ordenar por fecha descendente (en aprobados, los más recientemente validados primero)
        if ($esAprobados) {
            $query->orderByRaw('COALESCE(fecha_validacion, created_at) DESC');
        } else {
            $query->orderByDesc('created_at');
        }

        $perPage = min(100, max(10, (int) $request->get('per_page', 20)));
        $solicitudes = $query->paginate($perPage);

        $countsQuery = SolicitudInscripcion::query();
        if ($request->filled('ciudad_id')) {
            $countsQuery->whereHas('cursoAbierto', fn ($c) => $c->where('ciudad_id', $request->ciudad_id));
        }
        if ($request->filled('categoria')) {
            if ($request->categoria === 'personalizado') {
                $countsQuery->whereHas('cursoAbierto', fn ($curso) => $curso->where('es_personalizado', true));
            } else {
                $countsQuery->whereHas('cursoAbierto', fn ($curso) => $curso->where('es_personalizado', false))
                    ->whereHas('cursoAbierto.catalogo', function ($c) use ($request) {
                        if ($request->categoria === 'regular') {
                            $c->where(fn ($q) => $q->where('categoria', 'regular')->orWhereNull('categoria'));
                        } else {
                            $c->where('categoria', $request->categoria);
                        }
                    });
            }
        }
        $estadosCount = $countsQuery
            ->selectRaw("estado, count(*) as total")
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $stats = [
            'todos' => (int) $estadosCount->sum(),
            'pendientes' => (int) ($estadosCount['pendiente_validacion'] ?? 0),
            'aprobados' => (int) ($estadosCount['matricula_creada'] ?? 0),
            'rechazados' => (int) ($estadosCount['rechazado'] ?? 0),
        ];

        $baseQuery = SolicitudInscripcion::query();
        $fechaMin = (clone $baseQuery)->min('created_at');
        $fechaMax = (clone $baseQuery)->max('created_at');

        return response()->json([
            'data' => $solicitudes->items(),
            'stats' => $stats,
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
            'cursoAbierto:id,catalogo_curso_id,es_personalizado,nombre_instancia,observaciones,precio_base,precio_matricula,capacidad_maxima,estudiantes_inscritos,fecha_inicio,fecha_fin,modalidad,docente_id,ciudad_id,horario_id',
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
            (float) ($request->inscripcion_cubierta ?? 0),
            $request->motivo_ajuste,
            false,
            $request->grupo_pago_id,
            $request->metodos_pago
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

        if ($request->filled('status')) {
            $status = $request->status;
            if ($status === 'pendientes') {
                $query->where('estado', 'pendiente_validacion');
            } elseif ($status === 'aprobados') {
                $query->whereIn('estado', ['matricula_creada', 'aprobado']);
            } elseif ($status === 'rechazados') {
                $query->where('estado', 'rechazado');
            }
            // Si status === 'todos', no se filtra por estado
        } elseif ($request->filled('estado')) {
            if ($request->estado !== 'todos') {
                $query->where('estado', $request->estado);
            }
        } else {
            $query->where('estado', 'pendiente_validacion');
        }

        if ($request->filled('categoria')) {
            if ($request->categoria === 'personalizado') {
                $query->whereHas('cursoAbierto', fn ($curso) => $curso->where('es_personalizado', true));
            } elseif ($request->categoria === 'regular') {
                $query->whereHas('cursoAbierto', fn ($curso) => $curso->where('es_personalizado', false))
                    ->whereHas('cursoAbierto.catalogo', function ($catalogo) {
                        $catalogo->where(fn ($q) => $q->where('categoria', 'regular')->orWhereNull('categoria'));
                    });
            }
        }

        if ($request->filled('ciudad_id')) {
            $query->whereHas('cursoAbierto', fn ($c) => $c->where('ciudad_id', $request->ciudad_id));
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
        $esMixto = false;
        $desgloseMetodos = [];
        if ($solicitud->estado === SolicitudInscripcion::ESTADO_MATRICULA_CREADA) {
            $matricula = \App\Models\Matricula::with([
                'lineasPago.modulo:id,nombre_modulo,numero_orden',
                'lineasPago.transacciones:id,linea_pago_modulo_id,monto,metodo_pago,referencia_pago,comprobante_url,fecha_pago,estado_verificacion,grupo_pago_id'
            ])
                ->where('solicitud_inscripcion_id', $solicitud->id)
                ->first();
            if ($matricula?->lineasPago) {
                $totalAbonado = 0;
                $totalEsperado = 0;
                $modulosList = [];
                $inscripcionData = null;

                $allTxs = $matricula->lineasPago->flatMap->transacciones;
                $metodosUnicos = $allTxs->pluck('metodo_pago')->filter()->unique();
                $esMixto = $metodosUnicos->count() > 1;
                $desgloseMetodos = $allTxs->groupBy('metodo_pago')->map(fn($g, $m) => [
                    'metodo' => (string) $m,
                    'monto' => round((float) $g->sum('monto'), 2),
                    'comprobantes' => $g->pluck('comprobante_url')->filter()->unique()->values()->all(),
                ])->values()->all();

                foreach ($matricula->lineasPago->sortBy('orden') as $lp) {
                    $totalAbonado += (float) $lp->monto_abonado;
                    $totalEsperado += (float) $lp->monto_ajustado;

                    $txsLinea = $lp->transacciones->map(fn($t) => [
                        'id' => $t->id,
                        'monto' => (float) $t->monto,
                        'metodo_pago' => (string) $t->metodo_pago,
                        'referencia_pago' => $t->referencia_pago,
                        'comprobante_url' => $t->comprobante_url,
                        'fecha_pago' => $t->fecha_pago?->toISOString(),
                    ])->values()->all();

                    $linea = [
                        'id' => $lp->id,
                        'modulo_id' => $lp->modulo_id,
                        'tipo' => $lp->tipo,
                        'modulo_nombre' => $lp->tipo === 'inscripcion' ? 'Inscripción' : ($lp->modulo?->nombre_modulo ?? 'Módulo'),
                        'monto_original' => (float) ($lp->monto_original ?? $lp->monto_ajustado),
                        'monto_ajustado' => (float) $lp->monto_ajustado,
                        'motivo_ajuste' => $lp->motivo_ajuste,
                        'monto_abonado' => (float) $lp->monto_abonado,
                        'saldo' => max(0, (float) $lp->monto_ajustado - (float) $lp->monto_abonado),
                        'estado' => $lp->estado,
                        'metodos' => $txsLinea,
                        'es_mixto' => count($txsLinea) > 1,
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
                'datos_declarados' => $solicitud->datos_declarados,
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
                'es_personalizado' => (bool) $solicitud->cursoAbierto->es_personalizado,
                'nombre' => $solicitud->cursoAbierto->nombre_instancia ?: $solicitud->cursoAbierto->catalogo?->nombre,
                'nombre_catalogo' => $solicitud->cursoAbierto->catalogo?->nombre,
                'descripcion' => $solicitud->cursoAbierto->observaciones ?: $solicitud->cursoAbierto->catalogo?->descripcion,
                'color' => $solicitud->cursoAbierto->catalogo?->color,
                'modalidad' => $solicitud->cursoAbierto->modalidad,
                'precio_base' => $solicitud->cursoAbierto->precio_base,
                'precio_matricula' => $solicitud->cursoAbierto->precio_matricula,
                'capacidad' => [
                    'maxima' => $solicitud->cursoAbierto->capacidad_maxima,
                    'inscritos' => $solicitud->cursoAbierto->es_personalizado
                        ? $solicitud->cursoAbierto->obtenerCountMatriculas()
                        : $solicitud->cursoAbierto->estudiantes_inscritos,
                    'disponible' => $solicitud->cursoAbierto->capacidad_maxima - ($solicitud->cursoAbierto->es_personalizado
                        ? $solicitud->cursoAbierto->obtenerCountMatriculas()
                        : $solicitud->cursoAbierto->estudiantes_inscritos),
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
                'es_mixto' => $esMixto,
                'desglose_metodos' => $desgloseMetodos,
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
        $request->validate([
            'archivo' => [
                'required',
                'file',
                'mimes:jpeg,png,jpg,webp,pdf',
                function ($attribute, $value, $fail) {
                    if ($value instanceof \Illuminate\Http\UploadedFile) {
                        $isPdf = strtolower($value->getClientOriginalExtension()) === 'pdf' || $value->getMimeType() === 'application/pdf';
                        if ($isPdf && $value->getSize() > 5 * 1024 * 1024) {
                            $fail('El documento PDF de la cédula no debe superar los 5 MB.');
                        }
                    }
                },
            ],
        ]);
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
            'monto_solicitado' => 'nullable|numeric|min:0',
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
            'permitir_sobrecupo' => 'nullable|boolean',
            'lineas' => 'required|array|min:1',
            'lineas.*.modulo_id' => 'nullable|uuid|exists:modulos,id',
            'lineas.*.tipo' => 'required|string|in:modulo,inscripcion',
            'lineas.*.monto_abonado' => 'required|numeric|min:0',
            'lineas.*.monto_ajustado' => 'required|numeric|min:0',
        ]);

        $nuevoCurso = \App\Models\CursoAbierto::findOrFail($validated['curso_abierto_id']);
        $permitirSobrecupo = $request->boolean('permitir_sobrecupo', true);
        $cupo = ($nuevoCurso->capacidad_maxima ?? 0) - $nuevoCurso->matriculas()->count();
        if (! $permitirSobrecupo && $cupo <= 0 && $nuevoCurso->id !== $solicitud->curso_abierto_id) {
            return response()->json([
                'mensaje' => 'El curso seleccionado no tiene cupo disponible',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(function () use ($matricula, $solicitud, $validated) {
            // 1. Obtener todas las transacciones reales existentes ordenadas por fecha
            $transacciones = \App\Models\TransaccionIngreso::whereHas('lineaPagoModulo', function ($q) use ($matricula) {
                $q->where('matricula_id', $matricula->id);
            })->orderBy('fecha_pago')->orderBy('created_at')->get();

            // 2. Desvincular transacciones de las líneas viejas y eliminar líneas viejas
            \App\Models\TransaccionIngreso::whereIn('id', $transacciones->pluck('id'))
                ->update(['linea_pago_modulo_id' => null]);
            $matricula->lineasPago()->delete();

            // 3. Crear nuevas líneas de pago con orden explícito
            $nuevasLineas = [];
            foreach ($validated['lineas'] as $orden => $linea) {
                $montoAbonado = (float) $linea['monto_abonado'];
                $montoAjustado = (float) $linea['monto_ajustado'];
                $lp = $matricula->lineasPago()->create([
                    'modulo_id' => $linea['modulo_id'],
                    'tipo' => $linea['tipo'],
                    'monto_original' => $montoAjustado,
                    'monto_ajustado' => $montoAjustado,
                    'monto_abonado' => $montoAbonado,
                    'orden' => $orden,
                    'estado' => match(true) {
                        $montoAbonado >= $montoAjustado && $montoAjustado > 0 => 'pagado',
                        $montoAbonado > 0 => 'abonado',
                        default => 'pendiente',
                    },
                ]);
                $nuevasLineas[] = $lp;
            }

            // 4. Re-asignar transacciones reales a las nuevas líneas preservando pagos mixtos,
            // métodos de pago, comprobantes_url, referencias y grupos de pago intactos.
            $colaTx = [];
            foreach ($transacciones as $tx) {
                $colaTx[] = [
                    'modelo' => $tx,
                    'monto_restante' => (float) $tx->monto,
                ];
            }

            $txIndex = 0;
            foreach ($nuevasLineas as $linea) {
                $necesita = (float) $linea->monto_abonado;
                while ($necesita > 0.0001 && $txIndex < count($colaTx)) {
                    $actual = &$colaTx[$txIndex];
                    $disponible = $actual['monto_restante'];

                    if ($disponible <= 0.0001) {
                        $txIndex++;
                        continue;
                    }

                    if ($disponible <= $necesita + 0.0001) {
                        // Transacción cabe completa en esta línea
                        if (! $actual['modelo']->linea_pago_modulo_id) {
                            $actual['modelo']->update(['linea_pago_modulo_id' => $linea->id]);
                        } else {
                            \App\Models\TransaccionIngreso::create([
                                'linea_pago_modulo_id' => $linea->id,
                                'monto' => $disponible,
                                'metodo_pago' => $actual['modelo']->metodo_pago,
                                'referencia_pago' => $actual['modelo']->referencia_pago,
                                'comprobante_url' => $actual['modelo']->comprobante_url,
                                'fecha_pago' => $actual['modelo']->fecha_pago,
                                'estado_verificacion' => $actual['modelo']->estado_verificacion,
                                'grupo_pago_id' => $actual['modelo']->grupo_pago_id,
                            ]);
                        }
                        $necesita -= $disponible;
                        $actual['monto_restante'] = 0;
                        $txIndex++;
                    } else {
                        // La transacción es mayor que el saldo que requiere la línea:
                        // Asignar porción a esta línea y dejar el resto para la siguiente
                        $montoAsignar = $necesita;
                        if (! $actual['modelo']->linea_pago_modulo_id) {
                            $actual['modelo']->update([
                                'linea_pago_modulo_id' => $linea->id,
                                'monto' => $montoAsignar,
                            ]);
                        } else {
                            \App\Models\TransaccionIngreso::create([
                                'linea_pago_modulo_id' => $linea->id,
                                'monto' => $montoAsignar,
                                'metodo_pago' => $actual['modelo']->metodo_pago,
                                'referencia_pago' => $actual['modelo']->referencia_pago,
                                'comprobante_url' => $actual['modelo']->comprobante_url,
                                'fecha_pago' => $actual['modelo']->fecha_pago,
                                'estado_verificacion' => $actual['modelo']->estado_verificacion,
                                'grupo_pago_id' => $actual['modelo']->grupo_pago_id,
                            ]);
                        }
                        $actual['monto_restante'] -= $montoAsignar;
                        $necesita = 0;
                    }
                }
            }

            // Si quedaron transacciones con remanente (ej. si el nuevo curso es de menor valor),
            // vincularlas a la última línea de pago para que ningún pago quede huérfano.
            if ($txIndex < count($colaTx) && count($nuevasLineas) > 0) {
                $ultimaLinea = end($nuevasLineas);
                while ($txIndex < count($colaTx)) {
                    $actual = &$colaTx[$txIndex];
                    if ($actual['monto_restante'] > 0.0001) {
                        if (! $actual['modelo']->linea_pago_modulo_id) {
                            $actual['modelo']->update([
                                'linea_pago_modulo_id' => $ultimaLinea->id,
                                'monto' => $actual['monto_restante'],
                            ]);
                        } else {
                            \App\Models\TransaccionIngreso::create([
                                'linea_pago_modulo_id' => $ultimaLinea->id,
                                'monto' => $actual['monto_restante'],
                                'metodo_pago' => $actual['modelo']->metodo_pago,
                                'referencia_pago' => $actual['modelo']->referencia_pago,
                                'comprobante_url' => $actual['modelo']->comprobante_url,
                                'fecha_pago' => $actual['modelo']->fecha_pago,
                                'estado_verificacion' => $actual['modelo']->estado_verificacion,
                                'grupo_pago_id' => $actual['modelo']->grupo_pago_id,
                            ]);
                        }
                    }
                    $txIndex++;
                }
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
            'lineas.*.monto_ajustado' => 'nullable|numeric|min:0',
            'lineas.*.motivo_ajuste' => 'nullable|string|max:255',
            'lineas.*.metodos' => 'nullable|array',
            'lineas.*.metodos.*.metodo_pago' => 'nullable|string',
            'lineas.*.metodos.*.monto' => 'nullable|numeric|min:0',
            'lineas.*.metodos.*.referencia_pago' => 'nullable|string',
            'lineas.*.metodos.*.comprobante_url' => 'nullable|string',
        ]);

        $metodosValidos = ['transferencia', 'efectivo', 'tarjeta', 'deposito', 'otro'];
        $sanitizarMetodo = function (?string $metodo) use ($metodosValidos): string {
            $m = strtolower(trim($metodo ?? ''));
            return in_array($m, $metodosValidos, true) ? $m : 'transferencia';
        };

        DB::transaction(function () use ($validated, $matricula, $solicitud, $sanitizarMetodo) {
            $matriculaId = $matricula->id;
            $validadorId = auth()->user()->persona_id ?? auth()->id();

            // Reutilizar el grupo_pago_id existente de la matrícula si existe
            $primerGrupoPagoId = \App\Models\TransaccionIngreso::whereHas('lineaPagoModulo', fn($q) => $q->where('matricula_id', $matriculaId))
                ->whereNotNull('grupo_pago_id')
                ->value('grupo_pago_id');
            $grupoPagoId = $primerGrupoPagoId ?: (string) \Illuminate\Support\Str::uuid();

            foreach ($validated['lineas'] as $indice => $linea) {
                $lineaPago = $matricula->lineasPago()->where('id', $linea['id'])->lockForUpdate()->first();
                if (! $lineaPago) {
                    throw new \Exception("Línea de pago {$linea['id']} no encontrada en esta matrícula");
                }

                $montoAjustado = isset($linea['monto_ajustado']) && $linea['monto_ajustado'] !== null
                    ? round((float) $linea['monto_ajustado'], 2)
                    : round((float) $lineaPago->monto_ajustado, 2);

                $montoAbonado = round((float) $linea['monto_abonado'], 2);

                // Si se especificaron métodos explícitos, asegurar que el monto abonado coincida con su suma
                if (isset($linea['metodos']) && is_array($linea['metodos'])) {
                    $montoAbonado = round((float) collect($linea['metodos'])->sum(fn($m) => (float) ($m['monto'] ?? 0)), 2);
                }

                if ($montoAbonado > $montoAjustado) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        "lineas.{$indice}.monto_abonado" => "El monto abonado (\${$montoAbonado}) no puede exceder el precio (\${$montoAjustado}).",
                    ]);
                }

                $motivoAjuste = array_key_exists('motivo_ajuste', $linea)
                    ? $linea['motivo_ajuste']
                    : $lineaPago->motivo_ajuste;

                // Manejo de transacciones de ingreso (sin disparar observers para evitar incrementos dobles no deseados)
                \App\Models\TransaccionIngreso::withoutEvents(function () use (
                    $linea, $lineaPago, $grupoPagoId, $sanitizarMetodo, $solicitud, $validadorId, $montoAbonado
                ) {
                    if (isset($linea['metodos']) && is_array($linea['metodos'])) {
                        // Si se enviaron métodos explícitos (ej. pago mixto o reasignación)
                        \App\Models\TransaccionIngreso::where('linea_pago_modulo_id', $lineaPago->id)->delete();

                        foreach ($linea['metodos'] as $m) {
                            $montoM = round((float) ($m['monto'] ?? 0), 2);
                            if ($montoM <= 0) continue;

                            \App\Models\TransaccionIngreso::create([
                                'linea_pago_modulo_id' => $lineaPago->id,
                                'grupo_pago_id' => $grupoPagoId,
                                'monto' => $montoM,
                                'metodo_pago' => $sanitizarMetodo($m['metodo_pago'] ?? null),
                                'referencia_pago' => $m['referencia_pago'] ?? null,
                                'comprobante_url' => !empty($m['comprobante_url']) ? $m['comprobante_url'] : $solicitud->archivo_comprobante_url,
                                'fecha_pago' => now(),
                                'registrado_por' => $validadorId,
                                'verificado_por' => $validadorId,
                                'fecha_verificacion' => now(),
                                'estado_verificacion' => \App\Models\TransaccionIngreso::VERIFICACION_APROBADO,
                            ]);
                        }
                    } else {
                        // Si no se enviaron métodos explícitos pero cambió monto_abonado
                        $txsExistentes = \App\Models\TransaccionIngreso::where('linea_pago_modulo_id', $lineaPago->id)->get();
                        if ($montoAbonado <= 0) {
                            \App\Models\TransaccionIngreso::where('linea_pago_modulo_id', $lineaPago->id)->delete();
                        } elseif ($txsExistentes->count() === 1) {
                            $txsExistentes->first()->update([
                                'monto' => $montoAbonado,
                                'metodo_pago' => $sanitizarMetodo($txsExistentes->first()->metodo_pago),
                            ]);
                        } elseif ($txsExistentes->count() > 1) {
                            $primerMetodo = $sanitizarMetodo($txsExistentes->first()->metodo_pago);
                            $primerUrl = $txsExistentes->first()->comprobante_url ?? $solicitud->archivo_comprobante_url;
                            \App\Models\TransaccionIngreso::where('linea_pago_modulo_id', $lineaPago->id)->delete();
                            \App\Models\TransaccionIngreso::create([
                                'linea_pago_modulo_id' => $lineaPago->id,
                                'grupo_pago_id' => $grupoPagoId,
                                'monto' => $montoAbonado,
                                'metodo_pago' => $primerMetodo,
                                'referencia_pago' => $solicitud->tipo_pago,
                                'comprobante_url' => $primerUrl,
                                'fecha_pago' => now(),
                                'registrado_por' => $validadorId,
                                'verificado_por' => $validadorId,
                                'fecha_verificacion' => now(),
                                'estado_verificacion' => \App\Models\TransaccionIngreso::VERIFICACION_APROBADO,
                            ]);
                        } elseif ($txsExistentes->isEmpty() && $montoAbonado > 0) {
                            \App\Models\TransaccionIngreso::create([
                                'linea_pago_modulo_id' => $lineaPago->id,
                                'grupo_pago_id' => $grupoPagoId,
                                'monto' => $montoAbonado,
                                'metodo_pago' => $sanitizarMetodo($solicitud->tipo_comprobante),
                                'referencia_pago' => $solicitud->tipo_pago,
                                'comprobante_url' => $solicitud->archivo_comprobante_url,
                                'fecha_pago' => now(),
                                'registrado_por' => $validadorId,
                                'verificado_por' => $validadorId,
                                'fecha_verificacion' => now(),
                                'estado_verificacion' => \App\Models\TransaccionIngreso::VERIFICACION_APROBADO,
                            ]);
                        }
                    }
                });

                // Recalcular monto abonado real a partir de las transacciones aprobadas existentes
                $montoReal = round((float) \App\Models\TransaccionIngreso::where('linea_pago_modulo_id', $lineaPago->id)
                    ->where('estado_verificacion', \App\Models\TransaccionIngreso::VERIFICACION_APROBADO)
                    ->sum('monto'), 2);

                $estado = match(true) {
                    $montoAjustado <= 0 => 'pagado',
                    $montoReal >= $montoAjustado => 'pagado',
                    $montoReal > 0 => 'abonado',
                    default => 'pendiente',
                };

                DB::table('finance.lineas_pago_modulo')
                    ->where('id', $lineaPago->id)
                    ->update([
                        'monto_ajustado' => $montoAjustado,
                        'motivo_ajuste' => $motivoAjuste,
                        'monto_abonado' => $montoReal,
                        'estado' => $estado,
                        'updated_at' => now(),
                    ]);
            }

            $totalAbonado = (float) DB::table('finance.lineas_pago_modulo')
                ->where('matricula_id', $matriculaId)
                ->sum('monto_abonado');

            $totalAjustado = (float) DB::table('finance.lineas_pago_modulo')
                ->where('matricula_id', $matriculaId)
                ->sum('monto_ajustado');

            $cuentaEstado = match(true) {
                $totalAjustado <= 0 => 'pagado',
                $totalAbonado >= $totalAjustado => 'pagado',
                $totalAbonado > 0 => 'abonado',
                default => 'pendiente',
            };

            DB::update("
                UPDATE finance.cuentas_por_cobrar SET
                    monto_total = ?,
                    monto_abonado = ?,
                    estado = ?::t_estado_pago,
                    updated_at = NOW()
                WHERE matricula_id = ?
            ", [$totalAjustado, $totalAbonado, $cuentaEstado, $matriculaId]);

            $solicitud->update([
                'monto_solicitado' => $totalAbonado,
                'tipo_pago' => $totalAbonado >= $totalAjustado ? 'completo' : 'abono',
            ]);

            \Illuminate\Support\Facades\Cache::forget('finance.resumen');
            \Illuminate\Support\Facades\Cache::increment('estadisticas.version');
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

    /**
     * GET /api/academic/solicitudes-inscripcion/cursos-con-solicitudes
     * Devuelve solo los cursos abiertos que tienen estudiantes/solicitudes de inscripción según contexto
     */
    public function cursosConSolicitudes(Request $request): JsonResponse
    {
        $query = CursoAbierto::query()
            ->whereHas('solicitudesInscripcion', function ($s) use ($request) {
                if ($request->filled('estado') && $request->estado !== 'todos') {
                    if (in_array($request->estado, ['matricula_creada', 'aprobado', 'aprobados'])) {
                        $s->whereIn('estado', [SolicitudInscripcion::ESTADO_MATRICULA_CREADA, SolicitudInscripcion::ESTADO_APROBADO]);
                    } else {
                        $s->where('estado', $request->estado);
                    }
                }
                $esAprobados = in_array($request->estado, ['matricula_creada', 'aprobado', 'aprobados']);
                $columnaFecha = $esAprobados ? 'fecha_validacion' : 'created_at';

                if ($request->filled('fecha_desde')) {
                    $s->whereDate($columnaFecha, '>=', $request->fecha_desde);
                }
                if ($request->filled('fecha_hasta')) {
                    $s->whereDate($columnaFecha, '<=', $request->fecha_hasta);
                }
                // Si estamos en aprobados (matricula_creada) y no hay fecha_desde, acotar a los últimos 90 días
                if ($esAprobados && !$request->filled('fecha_desde')) {
                    $s->whereRaw('COALESCE(fecha_validacion, created_at) >= ?', [now()->subDays(90)]);
                }
            })
            ->select([
                'id',
                'catalogo_curso_id',
                'nombre_instancia',
                'ciudad_id',
                'modalidad',
                'es_personalizado',
            ])
            ->with([
                'catalogo:id,nombre,categoria',
                'ciudad:id,nombre',
            ]);

        if ($request->filled('ciudad_id')) {
            $query->where('ciudad_id', $request->ciudad_id);
        }

        if ($request->filled('categoria')) {
            if ($request->categoria === 'personalizado') {
                $query->where('es_personalizado', true);
            } else {
                $query->where('es_personalizado', false)
                    ->whereHas('catalogo', function ($c) use ($request) {
                        if ($request->categoria === 'regular') {
                            $c->where(fn ($q) => $q->where('categoria', 'regular')->orWhereNull('categoria'));
                        } else {
                            $c->where('categoria', $request->categoria);
                        }
                    });
            }
        }

        $cursos = $query->orderBy('nombre_instancia')->get();

        $data = $cursos->map(function ($curso) {
            $nombreBase = $curso->nombre_instancia ?: ($curso->catalogo->nombre ?? 'Curso sin nombre');
            $ciudadNombre = $curso->ciudad->nombre ?? null;
            return [
                'id' => $curso->id,
                'nombre' => $ciudadNombre ? "{$nombreBase} ({$ciudadNombre})" : $nombreBase,
                'nombre_simple' => $nombreBase,
                'catalogo_id' => $curso->catalogo_curso_id,
                'ciudad_id' => $curso->ciudad_id,
                'ciudad_nombre' => $ciudadNombre,
                'es_personalizado' => $curso->es_personalizado,
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/academic/solicitudes-inscripcion/ciudades-con-solicitudes
     * Devuelve solo las ciudades que tienen cursos con solicitudes según contexto
     */
    public function ciudadesConSolicitudes(Request $request): JsonResponse
    {
        $ciudades = Ciudad::query()
            ->whereHas('cursosAbiertos', function ($c) use ($request) {
                if ($request->filled('categoria')) {
                    if ($request->categoria === 'personalizado') {
                        $c->where('es_personalizado', true);
                    } else {
                        $c->where('es_personalizado', false)
                            ->whereHas('catalogo', function ($cat) use ($request) {
                                if ($request->categoria === 'regular') {
                                    $cat->where(fn ($q) => $q->where('categoria', 'regular')->orWhereNull('categoria'));
                                } else {
                                    $cat->where('categoria', $request->categoria);
                                }
                            });
                    }
                }

                $c->whereHas('solicitudesInscripcion', function ($s) use ($request) {
                    if ($request->filled('estado') && $request->estado !== 'todos') {
                        if (in_array($request->estado, ['matricula_creada', 'aprobado', 'aprobados'])) {
                            $s->whereIn('estado', [SolicitudInscripcion::ESTADO_MATRICULA_CREADA, SolicitudInscripcion::ESTADO_APROBADO]);
                        } else {
                            $s->where('estado', $request->estado);
                        }
                    }
                    $esAprobados = in_array($request->estado, ['matricula_creada', 'aprobado', 'aprobados']);
                    $columnaFecha = $esAprobados ? 'fecha_validacion' : 'created_at';

                    if ($request->filled('fecha_desde')) {
                        $s->whereDate($columnaFecha, '>=', $request->fecha_desde);
                    }
                    if ($request->filled('fecha_hasta')) {
                        $s->whereDate($columnaFecha, '<=', $request->fecha_hasta);
                    }
                    if ($esAprobados && !$request->filled('fecha_desde')) {
                        $s->whereRaw('COALESCE(fecha_validacion, created_at) >= ?', [now()->subDays(90)]);
                    }
                });
            })
            ->select(['id', 'nombre'])
            ->orderBy('nombre')
            ->get();

        return response()->json(['data' => $ciudades]);
    }
}
