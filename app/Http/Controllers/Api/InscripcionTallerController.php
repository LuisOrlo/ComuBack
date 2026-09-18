<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InscripcionTaller;
use App\Models\Taller;
use App\Models\Persona;
use App\Models\ClienteExterno;
use App\Models\CuentaPorCobrar;
use App\Models\TransaccionIngreso;
use App\Models\ArchivoEliminado;
use App\Services\StorageCleanupService;
use App\Services\StudentScheduleConflictService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Http\Exceptions\HttpResponseException;

class InscripcionTallerController extends Controller
{
    public function __construct(private StudentScheduleConflictService $scheduleConflictService)
    {
    }

    public function index(string $tallerId, Request $request): JsonResponse
    {
        Taller::findOrFail($tallerId);

        $query = InscripcionTaller::where('taller_id', $tallerId);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('nombres', 'ilike', "%{$s}%")
                  ->orWhere('apellidos', 'ilike', "%{$s}%")
                  ->orWhere('cedula', 'ilike', "%{$s}%")
                  ->orWhere('correo', 'ilike', "%{$s}%");
            });
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        $inscripciones = $query->orderBy('fecha_inscripcion', 'desc')
            ->paginate(min(100, max(10, (int) ($request->per_page ?? 20))));

        return response()->json($inscripciones);
    }

    public function listarPendientes(Request $request): JsonResponse
    {
        $query = InscripcionTaller::select([
            'id',
            'taller_id',
            'nombres',
            'apellidos',
            'correo',
            'estado',
            'pago_verificado',
            'fecha_inscripcion',
            'observaciones',
            'created_at',
        ])->with([
            'taller:id,nombre,modalidad,ciudad_id',
            'taller.ciudad:id,nombre',
        ]);

        if ($request->filled('pago_verificado')) {
            $query->where('pago_verificado', $request->pago_verificado === 'true');
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('nombres', 'ilike', "%{$s}%")
                  ->orWhere('apellidos', 'ilike', "%{$s}%")
                  ->orWhere('correo', 'ilike', "%{$s}%");
            });
        }

        if ($request->filled('taller_id')) {
            $query->where('taller_id', $request->taller_id);
        }

        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha_inscripcion', '>=', $request->fecha_desde);
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha_inscripcion', '<=', $request->fecha_hasta);
        }

        $inscripciones = $query->orderBy('fecha_inscripcion', 'desc')
            ->paginate(min(100, max(10, (int) ($request->per_page ?? 20))));

        $statsQuery = InscripcionTaller::query();
        if ($request->filled('taller_id')) {
            $statsQuery->where('taller_id', $request->taller_id);
        }
        $statsRaw = (clone $statsQuery)->selectRaw("
            count(*) as total,
            count(*) filter (where estado = 'activo' and (pago_verificado = false or pago_verificado is null)) as pendientes,
            count(*) filter (where pago_verificado = true) as aprobados,
            count(*) filter (where estado = 'retirado') as rechazados
        ")->first();

        $stats = [
            'todos' => (int) ($statsRaw->total ?? 0),
            'pendientes' => (int) ($statsRaw->pendientes ?? 0),
            'aprobados' => (int) ($statsRaw->aprobados ?? 0),
            'rechazados' => (int) ($statsRaw->rechazados ?? 0),
        ];

        $res = $inscripciones->toArray();
        $res['stats'] = $stats;

        return response()->json($res);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'taller_id' => 'required|uuid|exists:talleres,id',
            'nombres' => 'required|string|max:100',
            'apellidos' => 'required|string|max:100',
            'cedula' => 'required|string|max:20',
            'correo' => 'required|email|max:150',
            'telefono' => 'nullable|string|max:20',
            'ciudad' => 'nullable|string|max:100',
            'ocupacion' => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:500',
            'estado_civil' => 'nullable|string|max:20',
            'edad' => 'nullable|integer|min:0|max:150',
            'nivel_educativo' => 'nullable|string|in:educacion inicial,general basica,bachillerato,tecnico/tecnologico,superior,otro',
            'tipo_pago' => 'required|in:completo,abono',
            'monto_pagado' => 'required|numeric|min:0',
            'monto_declarado' => 'nullable|numeric|min:0',
            'referencia_declarada' => 'nullable|string|max:100',
            'metodo_pago' => 'nullable|string|max:50',
            'fecha_pago' => 'nullable|date',
            'comprobante' => 'nullable|file|image|max:5120',
            'archivo_cedula' => 'nullable|file|image|max:2048',
        ]);

        $taller = Taller::findOrFail($validated['taller_id']);

        if (!$taller->permitirInscripcion()) {
            return response()->json([
                'mensaje' => 'El taller no está disponible para inscripciones',
            ], 422);
        }

        if ($taller->capacidadDisponible() <= 0) {
            return response()->json([
                'mensaje' => 'El taller está lleno',
            ], 422);
        }

        if ($validated['tipo_pago'] === 'completo' && (float)$validated['monto_pagado'] != (float)$taller->precio && (float)$validated['monto_pagado'] != 0) {
            return response()->json([
                'mensaje' => "Para pago completo, el monto debe ser {$taller->precio}",
            ], 422);
        }

        if ($validated['tipo_pago'] === 'abono' && (float)$validated['monto_pagado'] < 0) {
            return response()->json([
                'mensaje' => "Para abono, el monto no puede ser negativo",
            ], 422);
        }

        if ($validated['tipo_pago'] === 'abono' && (float)$validated['monto_pagado'] > 0 && (float)$validated['monto_pagado'] >= (float)$taller->precio) {
            return response()->json([
                'mensaje' => "Para abono, el monto debe ser menor a {$taller->precio}",
            ], 422);
        }

        // 1. Determinar si es estudiante o participante externo sin vincular indebidamente al funcionario
        $authUser = $request->user();
        $personaId = null;
        if ($authUser && $authUser->persona_id && $authUser->persona && ($authUser->persona->correo === $validated['correo'] || $authUser->persona->cedula === $validated['cedula'])) {
            $personaId = $authUser->persona_id;
        }
        $participanteExternoId = null;

        if (empty($personaId)) {
            // Es participante externo - crear o actualizar sus datos personales
            $datosExterno = [
                'nombres' => $validated['nombres'],
                'apellidos' => $validated['apellidos'],
                'cedula' => $validated['cedula'] ?? null,
                'correo' => $validated['correo'] ?? null,
                'celular' => $validated['telefono'] ?? null,
                'ocupacion' => $validated['ocupacion'] ?? null,
                'direccion' => $validated['direccion'] ?? null,
                'ciudad' => $validated['ciudad'] ?? null,
                'estado_civil' => $validated['estado_civil'] ?? null,
                'edad' => $validated['edad'] ?? null,
                'nivel_educativo' => $validated['nivel_educativo'] ?? null,
            ];

            $participanteExterno = ClienteExterno::where('correo', $validated['correo'])->first();

            if (!$participanteExterno) {
                $participanteExterno = ClienteExterno::create($datosExterno);
            }
            $participanteExternoId = $participanteExterno->id;
        }

        // 2. Procesar subida de archivos de manera segura
        $comprobanteUrl = null;
        $cedulaUrl = null;

        if ($request->hasFile('comprobante')) {
            $file = $request->file('comprobante');
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('comprobantes-talleres', $filename);
            $comprobanteUrl = Storage::disk()->url($path);
        }

        if ($request->hasFile('archivo_cedula')) {
            $file = $request->file('archivo_cedula');
            $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('cedulas-talleres', $filename);
            $cedulaUrl = Storage::disk()->url($path);
        }

        try {
            $inscripcion = DB::transaction(function () use ($validated, $personaId, $participanteExternoId, $comprobanteUrl, $cedulaUrl) {
                $taller = Taller::where('id', $validated['taller_id'])->lockForUpdate()->firstOrFail();
                if (!$taller->permitirInscripcion() || $taller->capacidadDisponible() <= 0) {
                    abort(422, 'El taller ya no dispone de cupos disponibles');
                }

                return InscripcionTaller::create([
                    'taller_id' => $validated['taller_id'],
                    'persona_id' => $personaId,
                    'participante_externo_id' => $participanteExternoId,
                    'nombres' => $validated['nombres'],
                    'apellidos' => $validated['apellidos'],
                    'cedula' => $validated['cedula'],
                    'correo' => $validated['correo'],
                    'telefono' => $validated['telefono'] ?? null,
                    'ciudad' => $validated['ciudad'] ?? null,
                    'ocupacion' => $validated['ocupacion'] ?? null,
                    'direccion' => $validated['direccion'] ?? null,
                    'estado_civil' => $validated['estado_civil'] ?? null,
                    'edad' => $validated['edad'] ?? null,
                    'nivel_educativo' => $validated['nivel_educativo'] ?? null,
                    'datos_declarados' => [
                        'nombres' => $validated['nombres'],
                        'apellidos' => $validated['apellidos'],
                        'cedula' => $validated['cedula'],
                        'correo' => $validated['correo'],
                        'telefono' => $validated['telefono'] ?? null,
                        'ocupacion' => $validated['ocupacion'] ?? null,
                        'direccion' => $validated['direccion'] ?? null,
                        'ciudad' => $validated['ciudad'] ?? null,
                        'estado_civil' => $validated['estado_civil'] ?? null,
                        'edad' => $validated['edad'] ?? null,
                        'nivel_educativo' => $validated['nivel_educativo'] ?? null,
                        'monto_declarado' => $validated['monto_declarado'] ?? $validated['monto_pagado'] ?? null,
                        'referencia_declarada' => $validated['referencia_declarada'] ?? null,
                        'metodo_pago_declarado' => $validated['metodo_pago'] ?? null,
                        'fecha_pago_declarada' => $validated['fecha_pago'] ?? now()->timezone('America/Guayaquil')->toDateString(),
                    ],
                    'fecha_inscripcion' => now()->timezone('America/Guayaquil')->toDateString(),
                    'estado' => 'activo',
                    'tipo_pago' => $validated['tipo_pago'],
                    'monto_pagado' => $validated['monto_pagado'],
                    'metodo_pago' => $validated['metodo_pago'] ?? null,
                    'fecha_pago' => $validated['fecha_pago'] ?? now()->timezone('America/Guayaquil')->toDateString(),
                    'comprobante_url' => $comprobanteUrl,
                    'cedula_url' => $cedulaUrl,
                ]);
            });

            return response()->json($inscripcion, 201);
        } catch (\Exception $e) {
            // Si la base de datos falla, limpiamos los archivos huérfanos que acabamos de crear
            if ($comprobanteUrl) {
                Storage::disk()->delete(ltrim((string) parse_url($comprobanteUrl, PHP_URL_PATH), '/'));
            }
            if ($cedulaUrl) {
                Storage::disk()->delete(ltrim((string) parse_url($cedulaUrl, PHP_URL_PATH), '/'));
            }
            throw $e;
        }
    }

    private function autorizarModificacionArchivos(Request $request, InscripcionTaller $inscripcion): void
    {
        if ($inscripcion->pago_verificado) {
            abort(422, 'No se puede modificar archivos de una inscripción con pago ya verificado');
        }

        $user = auth('sanctum')->user() ?? $request->user();
        if ($user) {
            if ($user->hasAnyRole(['Administrador', 'Secretaria'])) {
                return;
            }
            if ($inscripcion->persona_id && $user->persona_id === $inscripcion->persona_id) {
                return;
            }
        }

        if ($request->hasValidSignature()) {
            return;
        }

        abort(403, 'No autorizado para subir o modificar archivos de esta inscripción');
    }

    public function uploadComprobante(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'archivo' => 'required|file|image|max:5120',
        ]);

        $inscripcion = InscripcionTaller::findOrFail($id);
        $this->autorizarModificacionArchivos($request, $inscripcion);

        $service = app(StorageCleanupService::class);
        $eliminadoPor = auth('sanctum')->id() ?? auth('sanctum')->user()?->persona_id ?? null;

        if ($inscripcion->comprobante_url) {
            $service->deleteFile($inscripcion, 'comprobante_url', $eliminadoPor, ArchivoEliminado::ACCION_BORRADO_ARCHIVO);
        }

        $file = $request->file('archivo');
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('comprobantes-talleres', $filename);

        $inscripcion->update(['comprobante_url' => Storage::disk()->url($path)]);
        $service->reviveFileField($inscripcion, 'comprobante_url');

        return response()->json([
            'mensaje' => 'Comprobante subido correctamente',
            'comprobante_url' => $inscripcion->comprobante_url,
        ]);
    }

    public function verificarPago(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'monto_pagado' => 'nullable|numeric|min:0',
            'precio_ajustado' => 'nullable|numeric|min:0',
            'motivo_ajuste' => 'nullable|string|max:255',
            'metodo_pago' => 'nullable|string|max:50',
            'fecha_pago' => 'nullable|date',
            'tipo_pago' => 'nullable|string|in:completo,abono',
        ]);

        $inscripcion = InscripcionTaller::with(['taller', 'cuentaPorCobrar'])->findOrFail($id);

        // Idempotencia: si ya fue verificado, devolver estado actual sin duplicar transacciones ni dinero
        if ($inscripcion->pago_verificado) {
            return response()->json([
                'mensaje' => 'El pago de esta inscripción ya fue verificado previamente',
                'pago_verificado' => true,
                'data' => $inscripcion->fresh(['cuentaPorCobrar.transacciones']),
            ], 200);
        }

        DB::transaction(function () use ($inscripcion, $validated, $request) {
            $precioBase = (float) ($inscripcion->taller->precio ?? 0);
            $precioPactado = isset($validated['precio_ajustado']) ? (float) $validated['precio_ajustado'] : $precioBase;

            // Determinar monto verificado/abonado
            $montoAbonado = isset($validated['monto_pagado'])
                ? (float) $validated['monto_pagado']
                : (float) ($inscripcion->monto_pagado ?? 0);

            $updateData = [
                'pago_verificado' => true,
                'monto_pagado' => $montoAbonado, // Conservar el abono real, NO sobrescribir con el precio total
                'metodo_pago' => $validated['metodo_pago'] ?? $inscripcion->metodo_pago,
                'fecha_pago' => $validated['fecha_pago'] ?? now()->timezone('America/Guayaquil')->toDateString(),
                'tipo_pago' => $validated['tipo_pago'] ?? ($montoAbonado >= $precioPactado ? 'completo' : 'abono'),
            ];

            if (!empty($validated['motivo_ajuste'])) {
                $updateData['motivo_ajuste'] = $validated['motivo_ajuste'];
            }

            $inscripcion->update($updateData);

            $estadoCuenta = match (true) {
                $montoAbonado >= $precioPactado => CuentaPorCobrar::ESTADO_PAGADO,
                $montoAbonado > 0 => CuentaPorCobrar::ESTADO_ABONADO,
                default => CuentaPorCobrar::ESTADO_PENDIENTE,
            };

            $cuenta = CuentaPorCobrar::updateOrCreate(
                ['inscripcion_taller_id' => $inscripcion->id],
                [
                    'monto_total' => $precioPactado,
                    'monto_abonado' => $montoAbonado,
                    'estado' => $estadoCuenta,
                    'es_legacy' => false,
                ]
            );

            // Crear asiento contable si existe dinero recibido
            if ($montoAbonado > 0) {
                $personaId = auth()->user()->persona_id ?? null;
                if ($personaId && !Persona::where('id', $personaId)->exists()) {
                    $personaId = null;
                }

                TransaccionIngreso::create([
                    'cuenta_cobrar_id' => $cuenta->id,
                    'monto' => $montoAbonado,
                    'metodo_pago' => $inscripcion->metodo_pago ?? 'efectivo',
                    'fecha_pago' => $validated['fecha_pago'] ?? $inscripcion->fecha_pago ?? now()->timezone('America/Guayaquil')->toDateString(),
                    'comprobante_url' => $inscripcion->comprobante_url,
                    'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
                    'registrado_por' => $personaId,
                    'verificado_por' => $personaId,
                    'fecha_verificacion' => now(),
                ]);
            }
        });

        return response()->json([
            'mensaje' => 'Inscripción aprobada correctamente',
            'pago_verificado' => true,
        ]);
    }

    public function uploadCedula(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'archivo' => 'required|file|image|max:2048',
        ]);

        $inscripcion = InscripcionTaller::findOrFail($id);
        $this->autorizarModificacionArchivos($request, $inscripcion);

        $service = app(StorageCleanupService::class);
        $eliminadoPor = auth('sanctum')->id() ?? auth('sanctum')->user()?->persona_id ?? null;

        if ($inscripcion->cedula_url) {
            $service->deleteFile($inscripcion, 'cedula_url', $eliminadoPor, ArchivoEliminado::ACCION_BORRADO_ARCHIVO);
        }

        $file = $request->file('archivo');
        $filename = \Illuminate\Support\Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('cedulas-talleres', $filename);

        $inscripcion->update(['cedula_url' => Storage::disk()->url($path)]);
        $service->reviveFileField($inscripcion, 'cedula_url');

        return response()->json([
            'mensaje' => 'Cédula subida correctamente',
            'cedula_url' => $inscripcion->cedula_url,
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'nombres' => 'sometimes|string|max:100',
            'apellidos' => 'sometimes|string|max:100',
            'cedula' => 'sometimes|string|max:20',
            'correo' => 'sometimes|email|max:150',
            'telefono' => 'nullable|string|max:20',
            'ocupacion' => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:500',
            'estado_civil' => 'nullable|string|max:20',
            'edad' => 'nullable|integer|min:0|max:150',
            'nivel_educativo' => 'nullable|string|in:educacion inicial,general basica,bachillerato,tecnico/tecnologico,superior,otro',
            'tipo_pago' => 'sometimes|in:completo,abono',
            'monto_pagado' => 'sometimes|numeric|min:0',
            'metodo_pago' => 'nullable|string|max:50',
            'ciudad' => 'nullable|string|max:100',
            'fecha_pago' => 'nullable|date',
            'taller_id' => 'nullable|uuid|exists:pgsql.academic.talleres,id',
            'precio_ajustado' => 'nullable|numeric|min:0',
            'motivo_ajuste' => 'nullable|string|max:255',
        ]);

        $inscripcion = InscripcionTaller::findOrFail($id);
        $inscripcion->update($validated);

        return response()->json([
            'mensaje' => 'Inscripción actualizada',
            'data' => $inscripcion,
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $inscripcion = InscripcionTaller::with('taller')->findOrFail($id);
        return response()->json($inscripcion);
    }

    public function updateEstado(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'estado' => 'required|in:activo,completado,retirado',
        ]);

        $inscripcion = InscripcionTaller::findOrFail($id);
        $inscripcion->update(['estado' => $request->estado]);

        return response()->json($inscripcion);
    }

    public function destroy(string $id): JsonResponse
    {
        $inscripcion = InscripcionTaller::findOrFail($id);
        $eliminadoPor = auth()->id() ?? auth()->user()?->persona_id ?? null;

        DB::transaction(function () use ($inscripcion) {
            CuentaPorCobrar::where('inscripcion_taller_id', $inscripcion->id)->delete();
            $inscripcion->delete();
        });

        app(StorageCleanupService::class)->deleteRecordFiles($inscripcion, $eliminadoPor);

        return response()->json(['mensaje' => 'Inscripción eliminada']);
    }

    public function deleteArchivo(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'campo' => 'required|string|in:comprobante_url,cedula_url',
        ]);

        $inscripcion = InscripcionTaller::findOrFail($id);
        $eliminadoPor = auth()->id() ?? auth()->user()?->persona_id ?? null;
        $service = app(StorageCleanupService::class);

        $resultado = $service->deleteFile($inscripcion, $request->campo, $eliminadoPor);

        if (!$resultado['eliminado']) {
            return response()->json(['mensaje' => $resultado['mensaje']], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'mensaje' => 'Archivo eliminado del almacenamiento. El registro se conserva como constancia histórica.',
        ]);
    }

    public function adjacent(Request $request, string $id): JsonResponse
    {
        $current = InscripcionTaller::findOrFail($id);

        $query = InscripcionTaller::query();

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('pago_verificado')) {
            $query->where('pago_verificado', $request->pago_verificado === 'true');
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('nombres', 'ilike', "%{$s}%")
                  ->orWhere('apellidos', 'ilike', "%{$s}%")
                  ->orWhere('cedula', 'ilike', "%{$s}%");
            });
        }

        if ($request->filled('taller_id')) {
            $query->where('taller_id', $request->taller_id);
        }

        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha_inscripcion', '>=', $request->fecha_desde);
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha_inscripcion', '<=', $request->fecha_hasta);
        }

        $ids = $query->orderByDesc('fecha_inscripcion')->pluck('id');
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

    public function exportar(string $tallerId, Request $request)
    {
        $taller = Taller::findOrFail($tallerId);

        $inscripciones = InscripcionTaller::where('taller_id', $tallerId)
            ->where('estado', 'activo')
            ->get();

        $formato = $request->get('formato', 'csv');

        if ($formato === 'pdf') {
            $rows = $inscripciones->map(function ($ins) {
                return [
                    'nombres' => $ins->nombres,
                    'apellidos' => $ins->apellidos,
                    'cedula' => $ins->cedula,
                    'correo' => $ins->correo,
                    'telefono' => $ins->telefono ?? '—',
                    'fecha' => $ins->fecha_inscripcion ? \Carbon\Carbon::parse($ins->fecha_inscripcion)->format('d/m/Y') : '—',
                    'pago' => $ins->pago_verificado ? 'Verificado' : 'Pendiente',
                ];
            });

            $html = view('exports.participantes-taller', [
                'taller' => $taller,
                'rows' => $rows,
            ])->render();

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
            return $pdf->download("participantes_{$taller->id}.pdf");
        }

        $csv = "Nombres,Apellidos,Cédula,Correo,Teléfono,Fecha Inscripción\n";
        foreach ($inscripciones as $ins) {
            $csv .= implode(',', [
                '"' . str_replace('"', '""', $ins->nombres) . '"',
                '"' . str_replace('"', '""', $ins->apellidos) . '"',
                $ins->cedula,
                $ins->correo,
                $ins->telefono ?? '',
                $ins->fecha_inscripcion ? \Carbon\Carbon::parse($ins->fecha_inscripcion)->format('d/m/Y') : '',
            ]) . "\n";
        }

        return response()->json([
            'csv' => $csv,
            'filename' => "participantes_{$taller->nombre}.csv",
            'total' => $inscripciones->count(),
        ]);
    }

    public function inscribirDesdePerfil(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'estudiante_id' => 'required|uuid|exists:pgsql.people.personas,id',
            'taller_id' => 'required|uuid|exists:pgsql.academic.talleres,id',
            'monto_pagado' => 'required|numeric|min:0',
            'metodo_pago' => 'nullable|string|max:50',
            'comprobante_url' => 'nullable|string|max:500',
            'cedula_url' => 'nullable|string|max:500',
        ]);

        $persona = Persona::findOrFail($validated['estudiante_id']);
        $taller = Taller::findOrFail($validated['taller_id']);
        $precioTaller = (float) ($taller->precio ?? 0);
        $montoPagado = (float) $validated['monto_pagado'];

        if (!$taller->permitirInscripcion() || $taller->capacidadDisponible() <= 0) {
            return response()->json(['mensaje' => 'El taller ya no dispone de cupos para inscripción.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (InscripcionTaller::where('taller_id', $taller->id)
            ->where('persona_id', $persona->id)
            ->whereIn('estado', ['activo', 'completado'])
            ->exists()) {
            return response()->json(['mensaje' => 'El estudiante ya está inscrito en este taller.'], Response::HTTP_CONFLICT);
        }

        if ($precioTaller > 0 && $montoPagado <= 0) {
            return response()->json([
                'mensaje' => 'Registra un monto mayor a $0 para un taller de pago.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $inscripcion = DB::transaction(function () use ($validated, $persona, $montoPagado) {
            $taller = Taller::where('id', $validated['taller_id'])->lockForUpdate()->firstOrFail();
            $precioTaller = (float) ($taller->precio ?? 0);

            if (!$taller->permitirInscripcion() || $taller->capacidadDisponible() <= 0) {
                abort(422, 'El taller ya no dispone de cupos para inscripción.');
            }

            if (InscripcionTaller::where('taller_id', $taller->id)
                ->where('persona_id', $persona->id)
                ->whereIn('estado', ['activo', 'completado'])
                ->lockForUpdate()
                ->exists()) {
                abort(409, 'El estudiante ya está inscrito en este taller.');
            }

            $conflictos = $this->scheduleConflictService->conflictsForWorkshop($persona->id, $taller);
            if ($conflictos) {
                throw new HttpResponseException(response()->json([
                    'mensaje' => 'El estudiante tiene un conflicto de horario.',
                    'conflictos' => $conflictos,
                ], Response::HTTP_CONFLICT));
            }

            $inscripcion = InscripcionTaller::create([
                'taller_id' => $validated['taller_id'],
                'persona_id' => $persona->id,
                'nombres' => $persona->nombres,
                'apellidos' => $persona->apellidos,
                'cedula' => $persona->cedula,
                'correo' => $persona->correo,
                'celular' => $persona->celular,
                'precio_pagado' => $montoPagado,
                'monto_pagado' => $montoPagado,
                'tipo_pago' => $montoPagado >= $precioTaller ? 'completo' : 'abono',
                'metodo_pago' => $validated['metodo_pago'] ?? 'efectivo',
                'fecha_pago' => now()->timezone('America/Guayaquil')->toDateString(),
                'estado' => 'activo',
            'pago_verificado' => $precioTaller <= 0 || $montoPagado > 0,
                'comprobante_url' => $validated['comprobante_url'] ?? null,
                'cedula_url' => $validated['cedula_url'] ?? null,
            ]);

            $estadoCuenta = match (true) {
                $precioTaller <= 0 || $montoPagado >= $precioTaller => CuentaPorCobrar::ESTADO_PAGADO,
                $montoPagado > 0 => CuentaPorCobrar::ESTADO_ABONADO,
                default => CuentaPorCobrar::ESTADO_PENDIENTE,
            };

            $cuenta = CuentaPorCobrar::create([
                'inscripcion_taller_id' => $inscripcion->id,
                'monto_total' => $precioTaller,
                'monto_abonado' => $montoPagado,
                'estado' => $estadoCuenta,
                'es_legacy' => false,
            ]);

            if ($montoPagado > 0) {
                $personaId = auth()->user()->persona_id ?? null;
                if ($personaId && !Persona::where('id', $personaId)->exists()) {
                    $personaId = null;
                }

                TransaccionIngreso::create([
                    'cuenta_cobrar_id' => $cuenta->id,
                    'monto' => $montoPagado,
                    'metodo_pago' => $validated['metodo_pago'] ?? 'efectivo',
                    'fecha_pago' => now()->timezone('America/Guayaquil')->toDateString(),
                    'registrado_por' => $personaId,
                    'verificado_por' => $personaId,
                    'fecha_verificacion' => now(),
                    'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
                    'observaciones' => 'Inscripción directa a taller desde perfil',
                ]);
            }

            Cache::forget('finance.resumen');

            return $inscripcion;
        });

        return response()->json([
            'mensaje' => 'Estudiante inscrito exitosamente al taller',
            'data' => $inscripcion,
        ], 201);
    }
}
