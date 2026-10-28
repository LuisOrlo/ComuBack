<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CursoInteresado;
use App\Models\CursoAbierto;
use App\Models\Taller;
use App\Models\Matricula;
use App\Models\SolicitudInscripcion;
use App\Models\InscripcionTaller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InteresadosController extends Controller
{
    /**
     * Listar interesados para un curso abierto o curso personalizado
     */
    public function indexCurso(string $cursoId): JsonResponse
    {
        $curso = CursoAbierto::findOrFail($cursoId);
        $interesados = CursoInteresado::where('curso_abierto_id', $cursoId)
            ->orderBy('apellidos', 'asc')
            ->orderBy('nombres', 'asc')
            ->get();

        $data = $interesados->map(function ($lead) use ($cursoId) {
            return $this->formatearInteresadoCurso($lead, $cursoId);
        });

        $kpis = [
            'total_interesados' => $data->count(),
            'solo_interesados' => $data->where('estado_seguimiento', 'solo_interesado')->count(),
            'solicitudes_pendientes' => $data->where('estado_seguimiento', 'solicitud_pendiente')->count(),
            'matriculados' => $data->where('estado_seguimiento', 'matriculado')->count(),
            'total_anticipos' => (float) $data->sum('monto_pago_anticipo'),
            'total' => $data->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'interesados' => $data,
                'datos' => $data,
                'kpis' => $kpis,
                'totales' => $kpis,
            ],
            'interesados' => $data,
            'datos' => $data,
            'kpis' => $kpis,
            'totales' => $kpis,
        ]);
    }

    /**
     * Listar interesados para un taller
     */
    public function indexTaller(string $tallerId): JsonResponse
    {
        $taller = Taller::findOrFail($tallerId);
        $interesados = CursoInteresado::where('taller_id', $tallerId)
            ->orderBy('apellidos', 'asc')
            ->orderBy('nombres', 'asc')
            ->get();

        $data = $interesados->map(function ($lead) use ($tallerId) {
            return $this->formatearInteresadoTaller($lead, $tallerId);
        });

        $kpis = [
            'total_interesados' => $data->count(),
            'solo_interesados' => $data->where('estado_seguimiento', 'solo_interesado')->count(),
            'solicitudes_pendientes' => $data->where('estado_seguimiento', 'solicitud_pendiente')->count(),
            'matriculados' => $data->where('estado_seguimiento', 'matriculado')->count(),
            'total_anticipos' => (float) $data->sum('monto_pago_anticipo'),
            'total' => $data->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'interesados' => $data,
                'datos' => $data,
                'kpis' => $kpis,
                'totales' => $kpis,
            ],
            'interesados' => $data,
            'datos' => $data,
            'kpis' => $kpis,
            'totales' => $kpis,
        ]);
    }

    /**
     * Registrar interesado en un curso
     */
    public function storeCurso(string $cursoId, Request $request): JsonResponse
    {
        $curso = CursoAbierto::findOrFail($cursoId);

        $validated = $request->validate([
            'nombres' => 'required|string|max:100',
            'apellidos' => 'required|string|max:100',
            'telefono' => 'required|string|max:30',
            'cedula' => 'nullable|string|max:20',
            'correo' => 'nullable|email|max:150',
            'monto_pago_anticipo' => 'nullable|numeric|min:0',
            'comprobante_url' => 'nullable|string',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        $lead = CursoInteresado::create([
            'curso_abierto_id' => $cursoId,
            'taller_id' => null,
            'nombres' => trim($validated['nombres']),
            'apellidos' => trim($validated['apellidos']),
            'telefono' => trim($validated['telefono']),
            'cedula' => !empty($validated['cedula']) ? trim($validated['cedula']) : null,
            'correo' => !empty($validated['correo']) ? trim($validated['correo']) : null,
            'monto_pago_anticipo' => $validated['monto_pago_anticipo'] ?? 0.00,
            'comprobante_url' => $validated['comprobante_url'] ?? null,
            'observaciones' => $validated['observaciones'] ?? null,
            'creado_por_id' => auth()->id(),
        ]);

        $leadFormateado = $this->formatearInteresadoCurso($lead, $cursoId);

        return response()->json([
            'success' => true,
            'mensaje' => 'Interesado registrado correctamente',
            'data' => $leadFormateado,
            'datos' => $leadFormateado,
        ], 201);
    }

    /**
     * Registrar interesado en un taller
     */
    public function storeTaller(string $tallerId, Request $request): JsonResponse
    {
        $taller = Taller::findOrFail($tallerId);

        $validated = $request->validate([
            'nombres' => 'required|string|max:100',
            'apellidos' => 'required|string|max:100',
            'telefono' => 'required|string|max:30',
            'cedula' => 'nullable|string|max:20',
            'correo' => 'nullable|email|max:150',
            'monto_pago_anticipo' => 'nullable|numeric|min:0',
            'comprobante_url' => 'nullable|string',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        $lead = CursoInteresado::create([
            'curso_abierto_id' => null,
            'taller_id' => $tallerId,
            'nombres' => trim($validated['nombres']),
            'apellidos' => trim($validated['apellidos']),
            'telefono' => trim($validated['telefono']),
            'cedula' => !empty($validated['cedula']) ? trim($validated['cedula']) : null,
            'correo' => !empty($validated['correo']) ? trim($validated['correo']) : null,
            'monto_pago_anticipo' => $validated['monto_pago_anticipo'] ?? 0.00,
            'comprobante_url' => $validated['comprobante_url'] ?? null,
            'observaciones' => $validated['observaciones'] ?? null,
            'creado_por_id' => auth()->id(),
        ]);

        $leadFormateado = $this->formatearInteresadoTaller($lead, $tallerId);

        return response()->json([
            'success' => true,
            'mensaje' => 'Interesado registrado correctamente',
            'data' => $leadFormateado,
            'datos' => $leadFormateado,
        ], 201);
    }

    /**
     * Actualizar interesado
     */
    public function update(string $id, Request $request): JsonResponse
    {
        $lead = CursoInteresado::findOrFail($id);

        $validated = $request->validate([
            'nombres' => 'sometimes|required|string|max:100',
            'apellidos' => 'sometimes|required|string|max:100',
            'telefono' => 'sometimes|required|string|max:30',
            'cedula' => 'nullable|string|max:20',
            'correo' => 'nullable|email|max:150',
            'monto_pago_anticipo' => 'nullable|numeric|min:0',
            'comprobante_url' => 'nullable|string',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        $lead->update($validated);

        $formateado = $lead->curso_abierto_id
            ? $this->formatearInteresadoCurso($lead, $lead->curso_abierto_id)
            : $this->formatearInteresadoTaller($lead, $lead->taller_id);

        return response()->json([
            'success' => true,
            'mensaje' => 'Registro actualizado correctamente',
            'data' => $formateado,
            'datos' => $formateado,
        ]);
    }

    /**
     * Eliminar interesado
     */
    public function destroy(string $id): JsonResponse
    {
        $lead = CursoInteresado::findOrFail($id);
        $lead->delete();

        return response()->json([
            'success' => true,
            'mensaje' => 'Registro eliminado de la lista de interesados',
        ]);
    }

    /**
     * Subir foto del comprobante de anticipo
     */
    public function uploadComprobante(Request $request): JsonResponse
    {
        $file = $request->file('comprobante') ?? $request->file('archivo');
        if (!$file) {
            return response()->json(['message' => 'No se subió ningún archivo'], 422);
        }

        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('comprobantes', $filename);
        $url = Storage::disk()->url($path);

        return response()->json([
            'success' => true,
            'url' => $url,
            'path' => $path,
        ], 201);
    }

    /**
     * Helper: computa el estado dinámico en cursos
     */
    private function formatearInteresadoCurso(CursoInteresado $lead, string $cursoId): array
    {
        $estadoSeguimiento = 'solo_interesado';
        $solicitudId = null;
        $matriculaId = null;
        $esMismoCurso = true;
        $cursoRegistrado = null;

        if (!empty($lead->cedula)) {
            // 1. ¿Tiene matrícula confirmada en ESTE curso?
            $matricula = Matricula::where('curso_abierto_id', $cursoId)
                ->whereHas('estudiante', fn($q) => $q->where('cedula', $lead->cedula))
                ->first();

            if ($matricula) {
                $estadoSeguimiento = 'matriculado';
                $matriculaId = $matricula->id;
                $esMismoCurso = true;
            } else {
                // 2. ¿Tiene una solicitud de inscripción en ESTE curso?
                $solicitud = SolicitudInscripcion::where('curso_abierto_id', $cursoId)
                    ->where(function ($q) use ($lead) {
                        $q->whereHas('estudiante', fn($sq) => $sq->where('cedula', $lead->cedula))
                          ->orWhereHas('participanteExterno', fn($pq) => $pq->where('cedula', $lead->cedula));
                    })
                    ->first();

                if ($solicitud) {
                    if ($solicitud->estado === SolicitudInscripcion::ESTADO_MATRICULA_CREADA || $solicitud->estado === 'aprobado') {
                        $estadoSeguimiento = 'matriculado';
                    } elseif ($solicitud->estado === 'rechazado') {
                        $estadoSeguimiento = 'rechazado';
                        $solicitudId = $solicitud->id;
                    } else {
                        $estadoSeguimiento = 'solicitud_pendiente';
                        $solicitudId = $solicitud->id;
                    }
                    $esMismoCurso = true;
                } else {
                    // 3. ¿Tiene matrícula confirmada en OTRO curso?
                    $otraMatricula = Matricula::whereHas('estudiante', fn($q) => $q->where('cedula', $lead->cedula))
                        ->with('cursoAbierto.catalogo')
                        ->latest('fecha_inscripcion')
                        ->first();

                    if ($otraMatricula && $otraMatricula->cursoAbierto) {
                        $estadoSeguimiento = 'matriculado';
                        $matriculaId = $otraMatricula->id;
                        $esMismoCurso = false;
                        $cursoRegistrado = $otraMatricula->cursoAbierto->nombre_instancia
                            ?: ($otraMatricula->cursoAbierto->catalogo->nombre ?? 'Otro curso');
                    } else {
                        // 4. ¿Tiene solicitud de inscripción en OTRO curso?
                        $otraSolicitud = SolicitudInscripcion::where(function ($q) use ($lead) {
                                $q->whereHas('estudiante', fn($sq) => $sq->where('cedula', $lead->cedula))
                                  ->orWhereHas('participanteExterno', fn($pq) => $pq->where('cedula', $lead->cedula));
                            })
                            ->with('cursoAbierto.catalogo')
                            ->latest('created_at')
                            ->first();

                        if ($otraSolicitud && $otraSolicitud->cursoAbierto) {
                            if ($otraSolicitud->estado === SolicitudInscripcion::ESTADO_MATRICULA_CREADA || $otraSolicitud->estado === 'aprobado') {
                                $estadoSeguimiento = 'matriculado';
                            } elseif ($otraSolicitud->estado === 'rechazado') {
                                $estadoSeguimiento = 'rechazado';
                                $solicitudId = $otraSolicitud->id;
                            } else {
                                $estadoSeguimiento = 'solicitud_pendiente';
                                $solicitudId = $otraSolicitud->id;
                            }
                            $esMismoCurso = false;
                            $cursoRegistrado = $otraSolicitud->cursoAbierto->nombre_instancia
                                ?: ($otraSolicitud->cursoAbierto->catalogo->nombre ?? 'Otro curso');
                        }
                    }
                }
            }
        }

        return [
            'id' => $lead->id,
            'curso_abierto_id' => $lead->curso_abierto_id,
            'taller_id' => $lead->taller_id,
            'nombres' => $lead->nombres,
            'apellidos' => $lead->apellidos,
            'nombre_completo' => trim("{$lead->nombres} {$lead->apellidos}"),
            'telefono' => $lead->telefono,
            'cedula' => $lead->cedula,
            'correo' => $lead->correo,
            'monto_pago_anticipo' => (float) $lead->monto_pago_anticipo,
            'comprobante_url' => $lead->comprobante_url,
            'observaciones' => $lead->observaciones,
            'estado_seguimiento' => $estadoSeguimiento,
            'estado_embudo' => $estadoSeguimiento,
            'es_mismo_curso' => $esMismoCurso,
            'curso_registrado' => $cursoRegistrado,
            'solicitud_id' => $solicitudId,
            'matricula_id' => $matriculaId,
            'fecha_registro' => $lead->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Helper: computa el estado dinámico en talleres
     */
    private function formatearInteresadoTaller(CursoInteresado $lead, string $tallerId): array
    {
        $estadoSeguimiento = 'solo_interesado';
        $inscripcionId = null;
        $esMismoTaller = true;
        $tallerRegistrado = null;

        if (!empty($lead->cedula)) {
            $inscripcion = InscripcionTaller::where('taller_id', $tallerId)
                ->where('cedula', $lead->cedula)
                ->first();

            if ($inscripcion) {
                $inscripcionId = $inscripcion->id;
                if ($inscripcion->estado === 'retirado') {
                    $estadoSeguimiento = 'rechazado';
                } elseif ($inscripcion->pago_verificado) {
                    $estadoSeguimiento = 'matriculado';
                } else {
                    $estadoSeguimiento = 'solicitud_pendiente';
                }
                $esMismoTaller = true;
            } else {
                // ¿Tiene inscripción en OTRO taller?
                $otraInscripcion = InscripcionTaller::where('cedula', $lead->cedula)
                    ->with('taller')
                    ->latest('created_at')
                    ->first();

                if ($otraInscripcion && $otraInscripcion->taller) {
                    $inscripcionId = $otraInscripcion->id;
                    if ($otraInscripcion->estado === 'retirado') {
                        $estadoSeguimiento = 'rechazado';
                    } elseif ($otraInscripcion->pago_verificado) {
                        $estadoSeguimiento = 'matriculado';
                    } else {
                        $estadoSeguimiento = 'solicitud_pendiente';
                    }
                    $esMismoTaller = false;
                    $tallerRegistrado = $otraInscripcion->taller->nombre ?? 'Otro taller';
                }
            }
        }

        return [
            'id' => $lead->id,
            'curso_abierto_id' => $lead->curso_abierto_id,
            'taller_id' => $lead->taller_id,
            'nombres' => $lead->nombres,
            'apellidos' => $lead->apellidos,
            'nombre_completo' => trim("{$lead->nombres} {$lead->apellidos}"),
            'telefono' => $lead->telefono,
            'cedula' => $lead->cedula,
            'correo' => $lead->correo,
            'monto_pago_anticipo' => (float) $lead->monto_pago_anticipo,
            'comprobante_url' => $lead->comprobante_url,
            'observaciones' => $lead->observaciones,
            'estado_seguimiento' => $estadoSeguimiento,
            'estado_embudo' => $estadoSeguimiento,
            'es_mismo_curso' => $esMismoTaller,
            'curso_registrado' => $tallerRegistrado,
            'solicitud_id' => $inscripcionId,
            'matricula_id' => $inscripcionId,
            'fecha_registro' => $lead->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
