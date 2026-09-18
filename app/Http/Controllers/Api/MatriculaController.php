<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CuentaPorCobrar;
use App\Models\CursoAbierto;
use App\Models\LineaPagoModulo;
use App\Models\Matricula;
use App\Models\Persona;
use App\Models\SolicitudInscripcion;
use App\Services\RegistrationStateService;
use App\Services\StorageCleanupService;
use App\Http\Requests\StoreMatriculaRequest;
use App\Http\Requests\UpdateMatriculaRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MatriculaController extends Controller
{
    public function index(Request $request)
    {
        $query = Matricula::query();

        if ($request->has('estudiante_id')) {
            $query->where('estudiante_id', $request->estudiante_id);
        }

        if ($request->has('curso_abierto_id')) {
            $query->where('curso_abierto_id', $request->curso_abierto_id);
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->has('activas') && $request->activas == 'true') {
            $query->activas();
        }

        $perPage = $request->get('per_page', 15);
        $matriculas = $query->with(['estudiante', 'cursoAbierto'])->paginate($perPage);

        return response()->json([
            'data' => $matriculas->items(),
            'meta' => [
                'total' => $matriculas->total(),
                'per_page' => $matriculas->perPage(),
                'current_page' => $matriculas->currentPage(),
                'last_page' => $matriculas->lastPage(),
            ]
        ]);
    }

    public function store(StoreMatriculaRequest $request)
    {
        return DB::transaction(function () use ($request) {
            $validated = $request->validated();
            $curso = CursoAbierto::where('id', $request->curso_abierto_id)
                ->lockForUpdate()
                ->firstOrFail();

            $conflicto = Matricula::conflictoNuevaInscripcion(
                (string) $request->estudiante_id,
                (string) $request->curso_abierto_id
            );
            if ($conflicto) {
                abort(Response::HTTP_CONFLICT, $conflicto['mensaje']);
            }

            if (in_array($validated['estado'], [Matricula::ESTADO_ACTIVO, Matricula::ESTADO_COMPLETADO], true)
                && $curso->capacidad_maxima > 0
                && $curso->obtenerEspaciosDisponibles() <= 0) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'El curso ya no dispone de cupos disponibles.');
            }

            $matricula = Matricula::create($validated);

            // Cargar el curso abierto y sus módulos para estructurar las cuentas y líneas financieras
            $curso = CursoAbierto::with('modulos')->findOrFail($matricula->curso_abierto_id);
            $modulos = $curso->modulos()->orderBy('numero_orden')->get();

            $lineas = [];
            foreach ($modulos as $i => $modulo) {
                $precioBase = (float) ($modulo->precio_base ?? 0);
                $lineas[] = LineaPagoModulo::create([
                    'matricula_id' => $matricula->id,
                    'modulo_id' => $modulo->id,
                    'monto_original' => $precioBase,
                    'monto_ajustado' => $precioBase,
                    'monto_abonado' => 0,
                    'estado' => LineaPagoModulo::ESTADO_PENDIENTE,
                    'orden' => $i,
                ]);
            }

            $montoTotal = collect($lineas)->sum('monto_ajustado');
            if ($montoTotal <= 0 && ($curso->precio_base ?? 0) > 0) {
                $montoTotal = (float) $curso->precio_base;
            }

            $cuenta = CuentaPorCobrar::create([
                'matricula_id' => $matricula->id,
                'monto_total' => $montoTotal,
                'monto_abonado' => 0,
                'estado' => $montoTotal > 0 ? CuentaPorCobrar::ESTADO_PENDIENTE : CuentaPorCobrar::ESTADO_PAGADO,
                'es_legacy' => false,
            ]);

            $curso->increment('estudiantes_inscritos');

            Cache::forget('finance.resumen');

            Log::channel('audit')->info('matricula.creada', [
                'matricula_id' => $matricula->id,
                'cuenta_cobrar_id' => $cuenta->id,
                'usuario_id' => auth()->id(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'data' => $matricula->fresh(['cursoAbierto', 'estudiante']),
                'cuenta_cobrar_id' => $cuenta->id,
                'message' => 'Creado exitosamente',
            ], Response::HTTP_CREATED);
        });
    }

    public function show($id)
    {
        $matricula = Matricula::with(['estudiante', 'cursoAbierto', 'horario', 'notas'])->findOrFail($id);
        return response()->json(['data' => $matricula]);
    }

    public function update(UpdateMatriculaRequest $request, $id)
    {
        $matricula = Matricula::with(['estudiante', 'cursoAbierto'])->findOrFail($id);
        $matricula->update($request->validated());
        Log::channel('audit')->info('matricula.actualizada', ['matricula_id' => $matricula->id, 'cambios' => $matricula->getChanges(), 'usuario_id' => auth()->id(), 'ip' => $request->ip()]);
        return response()->json(['data' => $matricula, 'message' => 'Actualizado exitosamente']);
    }

    public function destroy($id)
    {
        $matricula = Matricula::findOrFail($id);
        $eliminadoPor = auth()->id() ?? auth()->user()?->persona_id ?? null;
        $matricula->delete();
        Log::channel('audit')->info('matricula.eliminada', ['matricula_id' => $matricula->id, 'usuario_id' => auth()->id(), 'ip' => $request->ip()]);

        app(StorageCleanupService::class)->deleteRecordFiles($matricula, $eliminadoPor);

        return response()->json(['message' => 'Eliminado exitosamente']);
    }

    public function deleteArchivo(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'campo' => 'required|string|in:voucher_url',
        ]);

        $matricula = Matricula::findOrFail($id);
        $eliminadoPor = auth()->id() ?? auth()->user()?->persona_id ?? null;
        $service = app(StorageCleanupService::class);

        $resultado = $service->deleteFile($matricula, $request->campo, $eliminadoPor);

        if (!$resultado['eliminado']) {
            return response()->json(['message' => $resultado['mensaje']], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'message' => 'Archivo eliminado del almacenamiento. El registro se conserva como constancia histórica.',
        ]);
    }

    public function notas($id)
    {
        $notas = Matricula::findOrFail($id)->notas()->with('modulo')->paginate(15);
        return response()->json(['data' => $notas->items(), 'meta' => ['total' => $notas->total()]]);
    }

    public function calificaciones($id)
    {
        $matricula = Matricula::with(['estudiante', 'cursoAbierto.modulos', 'notas.modulo'])->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $matricula->id,
                'estudiante' => $matricula->estudiante ? $matricula->estudiante->nombre : 'N/A',
                'curso' => $matricula->cursoAbierto ? $matricula->cursoAbierto->nombre_instancia : 'N/A',
                'promedio_simple' => $matricula->calcularPromedio(),
                'promedio_ponderado' => $matricula->calcularPromedioPonderado(),
                'total_notas_registradas' => $matricula->notas()->whereNotNull('calificacion')->count(),
                'total_modulos' => $matricula->cursoAbierto ? $matricula->cursoAbierto->modulos()->count() : 0,
                'todas_notas_registradas' => $matricula->tieneTotalNotasRegistradas(),
                'estado' => $matricula->obtenerDescripcionEstado(),
            ]
        ]);
    }

    public function cambiosHorario($id)
    {
        $cambios = Matricula::findOrFail($id)->cambiosHorario()->with(['cursoAbiertoAntiguo', 'cursoAbiertoNuevo'])->paginate(15);
        return response()->json(['data' => $cambios->items(), 'meta' => ['total' => $cambios->total()]]);
    }

    public function inscribirDesdePerfil(Request $request)
    {
        $request->validate([
            'estudiante_id' => 'required|uuid|exists:pgsql.people.personas,id',
            'curso_abierto_id' => 'required|uuid|exists:pgsql.academic.cursos_abiertos,id',
            'pagos' => 'nullable|array',
            'pago_inicial' => 'nullable|numeric|min:0',
            'metodo_pago' => 'required|string|in:efectivo,transferencia,deposito,tarjeta,otro',
            'archivo_comprobante_url' => 'nullable|string|max:500',
            'archivo_cedula_url' => 'nullable|string|max:500',
        ]);

        $curso = CursoAbierto::findOrFail($request->curso_abierto_id);

        if ($curso->es_personalizado) {
            $pagoInicial = (float) $request->input('pago_inicial', 0);
            $precio = (float) ($curso->precio_base ?? 0);

            if ($precio <= 0 || $pagoInicial <= 0 || $pagoInicial > $precio) {
                return response()->json([
                    'mensaje' => 'El pago inicial debe ser mayor que cero y no superar el precio del curso personalizado.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $solicitud = DB::transaction(function () use ($request, $curso, $pagoInicial, $precio) {
                // Serializa primero por estudiante antes de crear la solicitud
                // (su FK a Persona adquiere un key-share lock en PostgreSQL).
                // Esto evita que dos altas simultáneas intenten actualizar la
                // misma Persona después de retener ese lock de referencia.
                Persona::whereKey($request->estudiante_id)->lockForUpdate()->firstOrFail();

                $solicitud = SolicitudInscripcion::create([
                    'persona_id' => $request->estudiante_id,
                    'curso_abierto_id' => $request->curso_abierto_id,
                    'monto_solicitado' => $pagoInicial,
                    'tipo_pago' => $pagoInicial >= $precio ? 'completo' : 'abono',
                    'estado' => 'pendiente_validacion',
                    'es_participante_externo' => false,
                    'archivo_comprobante_url' => $request->archivo_comprobante_url,
                    'archivo_cedula_url' => $request->archivo_cedula_url,
                    'datos_declarados' => [
                        'origen' => 'administrativo',
                        'pago_inicial' => $pagoInicial,
                        'precio_total' => $precio,
                        'metodo_pago' => $request->metodo_pago,
                        'usuario_id' => auth()->id(),
                        'persona_validadora_id' => auth()->user()->persona_id ?? null,
                    ],
                    'fecha_pago_declarada' => now()->toDateString(),
                ]);

                $resultado = app(RegistrationStateService::class)->approve(
                    $solicitud,
                    auth()->user()->persona_id ?? null,
                    null,
                    [],
                    $request->metodo_pago,
                    $precio,
                    $pagoInicial
                );

                if (!$resultado['exito']) {
                    $mensaje = $resultado['mensaje'];
                    if (($resultado['codigo'] ?? null) === 'conflicto_horario') {
                        throw new HttpResponseException(response()->json([
                            'mensaje' => $mensaje,
                            'conflictos' => $resultado['conflictos'] ?? [],
                        ], Response::HTTP_CONFLICT));
                    }
                    $status = ($resultado['codigo'] ?? null) === 'duplicado'
                        ? Response::HTTP_CONFLICT
                        : Response::HTTP_UNPROCESSABLE_ENTITY;
                    abort($status, $mensaje);
                }

                return $solicitud;
            });

            $matricula = Matricula::where('solicitud_inscripcion_id', $solicitud->id)->first();

            return response()->json([
                'mensaje' => 'Estudiante inscrito exitosamente en el curso personalizado.',
                'data' => [
                    'solicitud_id' => $solicitud->id,
                    'matricula_id' => $matricula?->id,
                ],
            ], Response::HTTP_CREATED);
        }

        $request->validate([
            'pagos' => 'required|array|min:1',
            'pagos.*.modulo_id' => 'required|uuid|exists:pgsql.academic.modulos,id',
            'pagos.*.monto' => 'required|numeric|min:0.01',
            'pagos.*.monto_ajustado' => 'nullable|numeric|min:0',
            'pagos.*.motivo_ajuste' => 'nullable|string|max:255',
        ]);

        $solicitud = DB::transaction(function () use ($request, $curso) {
            // El estudiante debe bloquearse antes de insertar la solicitud y
            // antes de bloquear la oferta para mantener un orden consistente.
            Persona::whereKey($request->estudiante_id)->lockForUpdate()->firstOrFail();

            $solicitud = SolicitudInscripcion::create([
                'persona_id' => $request->estudiante_id,
                'curso_abierto_id' => $request->curso_abierto_id,
                'monto_solicitado' => collect($request->pagos)->sum('monto'),
                'tipo_pago' => count($request->pagos) > 1 || $request->pagos[0]['monto'] < ($curso->precio_base ?? 0) ? 'abono' : 'completo',
                'estado' => 'pendiente_validacion',
                'es_participante_externo' => false,
                'archivo_comprobante_url' => $request->archivo_comprobante_url,
                'archivo_cedula_url' => $request->archivo_cedula_url,
            ]);

            $stateService = app(RegistrationStateService::class);
            $resultado = $stateService->approve(
                $solicitud,
                auth()->user()->persona_id ?? null,
                null,
                $request->pagos,
                $request->metodo_pago
            );

            if (!$resultado['exito']) {
                if (($resultado['codigo'] ?? null) === 'conflicto_horario') {
                    throw new HttpResponseException(response()->json([
                        'mensaje' => $resultado['mensaje'],
                        'conflictos' => $resultado['conflictos'] ?? [],
                    ], Response::HTTP_CONFLICT));
                }
                if (($resultado['codigo'] ?? null) === 'duplicado') {
                    abort(Response::HTTP_CONFLICT, $resultado['mensaje']);
                }

                throw new \Exception($resultado['mensaje']);
            }

            return $solicitud;
        });

        $matricula = Matricula::where('solicitud_inscripcion_id', $solicitud->id)->first();

        return response()->json([
            'mensaje' => 'Estudiante inscrito exitosamente',
            'data' => [
                'solicitud_id' => $solicitud->id,
                'matricula_id' => $matricula?->id,
            ],
        ], Response::HTTP_CREATED);
    }
}
