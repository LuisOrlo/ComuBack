<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ValidateRegistrationRequest;
use App\Http\Requests\RejectRegistrationRequest;
use App\Models\SolicitudInscripcion;
use App\Models\Persona;
use App\Services\RegistrationStateService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StaffRegistrationController extends Controller
{
    private RegistrationStateService $stateService;

    public function __construct(RegistrationStateService $stateService)
    {
        $this->stateService = $stateService;
        // Middleware de autenticación aplicado en rutas
    }

    /**
     * GET /api/v1/staff/solicitudes-inscripcion
     * Listar solicitudes con filtros
     */
    public function index(Request $request)
    {
        $query = SolicitudInscripcion::with([
            'estudiante:id,nombres,apellidos,correo',
            'participanteExterno:id,nombres,apellidos,correo',
            'cursoAbierto:id,catalogo_curso_id,precio_base,capacidad_maxima,estudiantes_inscritos',
            'cursoAbierto.catalogo:id,nombre',
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

        return response()->json([
            'data' => $solicitudes->items(),
            'meta' => [
                'total' => $solicitudes->total(),
                'per_page' => $solicitudes->perPage(),
                'current_page' => $solicitudes->currentPage(),
                'last_page' => $solicitudes->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/staff/solicitudes-inscripcion/{id}
     * Ver detalles de una solicitud
     */
    public function show(string $id)
    {
        $solicitud = SolicitudInscripcion::with([
            'estudiante:id,nombres,apellidos,cedula,correo,celular',
            'participanteExterno:id,nombres,apellidos,correo,celular,cedula',
            'cursoAbierto:id,catalogo_curso_id,precio_base,capacidad_maxima,estudiantes_inscritos,fecha_inicio,fecha_fin',
            'cursoAbierto.catalogo:id,nombre,descripcion',
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
     * POST /api/v1/staff/solicitudes-inscripcion/{id}/validar
     * Aprobar una solicitud (transición a aprobado + crear matrícula)
     */
    public function approve(string $id, ValidateRegistrationRequest $request)
    {
        $solicitud = SolicitudInscripcion::find($id);

        if (!$solicitud) {
            return response()->json([
                'mensaje' => 'Solicitud no encontrada',
            ], Response::HTTP_NOT_FOUND);
        }

        // Obtener el validador (sin restricción de rol por ahora)
        $validador = auth()->user();
        $validadorPersonaId = ($validador instanceof Persona) ? $validador->id : null;

        // Usar el servicio para aprobar
        $resultado = $this->stateService->approve(
            $solicitud,
            $validadorPersonaId,
            $request->observaciones_validacion
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
                'cuenta_cobrar_id' => $resultado['cuenta_cobrar_id'],
                'estado' => $solicitud->refresh()->estado,
            ],
        ]);
    }

    /**
     * POST /api/v1/staff/solicitudes-inscripcion/{id}/rechazar
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
     * POST /api/v1/staff/solicitudes-inscripcion/{id}/cancelar
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
        return [
            'id' => $solicitud->id,
            'solicitante' => [
                'tipo' => $solicitud->esEstudiante() ? 'estudiante' : 'externo',
                'datos' => $solicitud->esEstudiante()
                    ? $solicitud->estudiante?->only(['id', 'nombres', 'apellidos', 'cedula', 'correo', 'celular'])
                    : $solicitud->participanteExterno?->only(['id', 'nombres', 'apellidos', 'correo', 'celular', 'cedula']),
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
                ],
            ],
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
     * POST /api/v1/academic/solicitudes-inscripcion/{id}/cedula
     */
    public function uploadCedula(string $id, Request $request)
    {
        $request->validate(['archivo' => 'required|file|image|max:5120']);
        $solicitud = SolicitudInscripcion::findOrFail($id);
        $file = $request->file('archivo');
        $filename = \Illuminate\Support\Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('comprobantes', $filename, 'public');
        $solicitud->update(['archivo_cedula_url' => '/storage/' . $path]);
        return response()->json(['data' => ['cedula_url' => '/storage/' . $path], 'message' => 'Cédula subida']);
    }

    /**
     * PATCH /api/v1/staff/solicitudes-inscripcion/{id}/actualizar-estudiante
     * Actualizar datos del estudiante/participante externo
     * 
     * @param string $id ID de la solicitud
     * @param Request $request Datos a actualizar (nombres, apellidos, correo, celular, cedula)
     * @return JsonResponse
     */
    public function updateEstudiante(string $id, Request $request)
    {
        $solicitud = SolicitudInscripcion::findOrFail($id);

        // Validar datos
        $validated = $request->validate([
            'nombres' => 'nullable|string|max:255',
            'apellidos' => 'nullable|string|max:255',
            'correo' => 'nullable|email|max:255',
            'celular' => 'nullable|string|max:20',
            'cedula' => 'nullable|string|max:20',
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
            } elseif ($solicitud->participanteExterno) {
                // Actualizar ClienteExterno
                $dataUpdate = array_filter([
                    'nombres' => $validated['nombres'] ?? null,
                    'apellidos' => $validated['apellidos'] ?? null,
                    'correo' => $validated['correo'] ?? null,
                    'celular' => $validated['celular'] ?? null,
                    'cedula' => $validated['cedula'] ?? null,
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
     * PATCH /api/v1/academic/solicitudes-inscripcion/{id}/actualizar-pago
     * Actualizar datos de pago (monto, tipo)
     */
    public function updatePago(string $id, Request $request)
    {
        $solicitud = SolicitudInscripcion::findOrFail($id);

        $validated = $request->validate([
            'monto_solicitado' => 'nullable|numeric|min:0.01',
            'tipo_pago' => 'nullable|string|max:50',
            'fecha_pago_declarada' => 'nullable|date',
        ]);

        try {
            $dataUpdate = array_filter([
                'monto_solicitado' => $validated['monto_solicitado'] ?? null,
                'tipo_pago' => $validated['tipo_pago'] ?? null,
                'fecha_pago_declarada' => $validated['fecha_pago_declarada'] ?? null,
            ], fn($v) => $v !== null);

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
     * PATCH /api/v1/academic/solicitudes-inscripcion/{id}/actualizar-curso
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
     * POST /api/v1/academic/solicitudes-inscripcion/{id}/comprobante
     * Subir nuevo comprobante de pago
     */
    public function uploadComprobante(string $id, Request $request)
    {
        $request->validate(['archivo' => 'required|file|image|max:5120']);
        $solicitud = SolicitudInscripcion::findOrFail($id);
        $file = $request->file('archivo');
        $filename = \Illuminate\Support\Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('comprobantes', $filename, 'public');
        $solicitud->update(['archivo_comprobante_url' => '/storage/' . $path]);
        return response()->json([
            'data' => ['comprobante_url' => '/storage/' . $path],
            'mensaje' => 'Comprobante subido correctamente',
        ]);
    }
}
