<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InscripcionTaller;
use App\Models\Taller;
use App\Models\Persona;
use App\Models\CuentaPorCobrar;
use App\Models\TransaccionIngreso;
use App\Models\ArchivoEliminado;
use App\Services\StorageCleanupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InscripcionTallerController extends Controller
{
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
            ->paginate($request->per_page ?? 50);

        return response()->json($inscripciones);
    }

    public function listarPendientes(Request $request): JsonResponse
    {
        $query = InscripcionTaller::with('taller');

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
                  ->orWhere('cedula', 'ilike', "%{$s}%");
            });
        }

        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha_inscripcion', '>=', $request->fecha_desde);
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha_inscripcion', '<=', $request->fecha_hasta);
        }

        $inscripciones = $query->orderBy('fecha_inscripcion', 'desc')
            ->paginate($request->per_page ?? 50);

        return response()->json($inscripciones);
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
            'fecha_nacimiento' => 'nullable|date',
            'edad' => 'nullable|integer|min:0|max:150',
            'tipo_pago' => 'required|in:completo,abono',
            'monto_pagado' => 'required|numeric|min:0',
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

        $inscripcion = DB::transaction(function () use ($request, $validated) {
            $data = [
                'taller_id' => $validated['taller_id'],
                'nombres' => $validated['nombres'],
                'apellidos' => $validated['apellidos'],
                'cedula' => $validated['cedula'],
                'correo' => $validated['correo'],
                'telefono' => $validated['telefono'] ?? null,
                'ciudad' => $validated['ciudad'] ?? null,
                'ocupacion' => $validated['ocupacion'] ?? null,
                'direccion' => $validated['direccion'] ?? null,
                'estado_civil' => $validated['estado_civil'] ?? null,
                'fecha_nacimiento' => $validated['fecha_nacimiento'] ?? null,
                'edad' => $validated['edad'] ?? null,
                'fecha_inscripcion' => now()->toDateString(),
                'estado' => 'activo',
                'tipo_pago' => $validated['tipo_pago'],
                'monto_pagado' => $validated['monto_pagado'],
                'metodo_pago' => $validated['metodo_pago'] ?? null,
                'fecha_pago' => $validated['fecha_pago'] ?? now()->toDateString(),
            ];

            if ($request->hasFile('comprobante')) {
                $file = $request->file('comprobante');
                $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('comprobantes-talleres', $filename, 's3');
                $data['comprobante_url'] = Storage::disk('s3')->url($path);
            }

            if ($request->hasFile('archivo_cedula')) {
                $file = $request->file('archivo_cedula');
                $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('cedulas-talleres', $filename, 's3');
                $data['cedula_url'] = Storage::disk('s3')->url($path);
            }

            return InscripcionTaller::create($data);
        });

        return response()->json($inscripcion, 201);
    }

    public function uploadComprobante(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'archivo' => 'required|file|image|max:5120',
        ]);

        $inscripcion = InscripcionTaller::findOrFail($id);
        $service = app(StorageCleanupService::class);

        if ($inscripcion->comprobante_url) {
            $service->deleteFilePhysically($inscripcion, 'comprobante_url');
        }

        $file = $request->file('archivo');
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('comprobantes-talleres', $filename, 's3');

        $inscripcion->update(['comprobante_url' => Storage::disk('s3')->url($path)]);
        $service->reviveFileField($inscripcion, 'comprobante_url');

        return response()->json([
            'mensaje' => 'Comprobante subido correctamente',
            'comprobante_url' => $inscripcion->comprobante_url,
        ]);
    }

    public function verificarPago(Request $request, string $id): JsonResponse
    {
        \Log::info('verificarPago ejecutado', ['id' => $id, 'data' => $request->all()]);
        $inscripcion = InscripcionTaller::with('taller')->findOrFail($id);

        DB::transaction(function () use ($inscripcion, $request) {
            $updateData = [
                'pago_verificado' => true,
                'metodo_pago' => $request->metodo_pago ?? $inscripcion->metodo_pago,
                'fecha_pago' => $request->fecha_pago ?? now()->toDateString(),
            ];

            if ($request->has('monto_pagado')) {
                $updateData['monto_pagado'] = $request->monto_pagado;
            }
            if ($request->has('tipo_pago')) {
                $updateData['tipo_pago'] = $request->tipo_pago;
            }

            $inscripcion->update($updateData);

            $precioTotal = $request->filled('precio_ajustado')
                ? $request->precio_ajustado
                : ($inscripcion->taller->precio ?? 0);

            // Guardar el precio ajustado en la inscripción para que persista
            if ($request->filled('precio_ajustado')) {
                $inscripcion->update(['monto_pagado' => $precioTotal]);
            }

            if ($request->filled('motivo_ajuste')) {
                $inscripcion->update(['motivo_ajuste' => $request->motivo_ajuste]);
            }

            $montoAbonado = $request->monto_pagado ?? $inscripcion->monto_pagado ?? 0;
            $estado = $montoAbonado >= $precioTotal
                ? CuentaPorCobrar::ESTADO_PAGADO
                : ($montoAbonado > 0 ? CuentaPorCobrar::ESTADO_ABONADO : CuentaPorCobrar::ESTADO_PENDIENTE);

            $cuenta = CuentaPorCobrar::updateOrCreate(
                ['inscripcion_taller_id' => $inscripcion->id],
                [
                    'monto_total' => $precioTotal,
                    'monto_abonado' => $montoAbonado,
                    'estado' => $estado,
                ]
            );

            if ($request->monto_pagado > 0) {
                $personaId = auth()->user()->persona_id ?? null;
                if ($personaId && !Persona::where('id', $personaId)->exists()) {
                    $personaId = null;
                }
                TransaccionIngreso::create([
                    'cuenta_cobrar_id' => $cuenta->id,
                    'monto' => $request->monto_pagado,
                    'metodo_pago' => $inscripcion->metodo_pago,
                    'fecha_pago' => $inscripcion->fecha_pago ?? now()->toDateString(),
                    'comprobante_url' => $inscripcion->comprobante_url,
                    'estado_verificacion' => 'aprobado',
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
        $service = app(StorageCleanupService::class);

        if ($inscripcion->cedula_url) {
            $service->deleteFilePhysically($inscripcion, 'cedula_url');
        }

        $file = $request->file('archivo');
        $filename = \Illuminate\Support\Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('cedulas-talleres', $filename, 's3');

        $inscripcion->update(['cedula_url' => Storage::disk('s3')->url($path)]);
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
            'fecha_nacimiento' => 'nullable|date',
            'edad' => 'nullable|integer|min:0|max:150',
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
        ]);

        $persona = Persona::findOrFail($validated['estudiante_id']);
        $taller = Taller::findOrFail($validated['taller_id']);

        $inscripcion = DB::transaction(function () use ($validated, $persona, $taller) {
            $inscripcion = InscripcionTaller::create([
                'taller_id' => $validated['taller_id'],
                'persona_id' => $persona->id,
                'nombres' => $persona->nombres,
                'apellidos' => $persona->apellidos,
                'cedula' => $persona->cedula,
                'correo' => $persona->correo,
                'celular' => $persona->celular,
                'precio_pagado' => $validated['monto_pagado'],
                'monto_pagado' => $validated['monto_pagado'],
                'tipo_pago' => $validated['monto_pagado'] >= ($taller->precio ?? 0) ? 'completo' : 'abono',
                'metodo_pago' => $validated['metodo_pago'] ?? 'efectivo',
                'fecha_pago' => now()->toDateString(),
                'estado' => 'activo',
                'pago_verificado' => true,
            ]);

            return $inscripcion;
        });

        return response()->json([
            'mensaje' => 'Estudiante inscrito exitosamente al taller',
            'data' => $inscripcion,
        ], 201);
    }
}
