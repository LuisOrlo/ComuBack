<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCertificadoRequest;
use App\Models\ArchivoEliminado;
use App\Models\Certificado;
use App\Models\Persona;
use App\Services\StorageCleanupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Str;

class CertificadoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Certificado::with(['estudiante', 'catalogoCurso', 'cursoAbierto']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('codigo_certificado', 'ilike', "%{$search}%")
                  ->orWhere('cedula_impresa', 'ilike', "%{$search}%")
                  ->orWhereHas('estudiante', function ($qq) use ($search) {
                      $qq->where('nombres', 'ilike', "%{$search}%")
                         ->orWhere('apellidos', 'ilike', "%{$search}%");
                  });
            });
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('curso_abierto_id')) {
            $query->where('curso_abierto_id', $request->curso_abierto_id);
        }

        $certificados = $query->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json($certificados);
    }

    public function store(StoreCertificadoRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (!empty($data['matricula_id'])) {
            $resolved = $this->resolveEstudianteFromMatricula($data['matricula_id']);
            if (!$resolved) {
                return response()->json([
                    'message' => 'No se pudo identificar al estudiante desde la matrícula',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $data['estudiante_id'] = $resolved['persona_id'];
            if (empty($data['curso_abierto_id'])) {
                $data['curso_abierto_id'] = $resolved['curso_abierto_id'];
            }
        } else {
            $persona = Persona::find($data['estudiante_id']);
            if (!$persona) {
                $externo = DB::table('people.clientes_externos')->where('id', $data['estudiante_id'])->first();
                if (!$externo) {
                    return response()->json([
                        'message' => 'Estudiante no encontrado',
                    ], Response::HTTP_NOT_FOUND);
                }
                $persona = Persona::firstOrCreate(
                    ['cedula' => $externo->cedula],
                    [
                        'nombres' => $externo->nombres,
                        'apellidos' => $externo->apellidos,
                        'tipo' => 'estudiante',
                    ]
                );
                $data['estudiante_id'] = $persona->id;
            }
        }

        if (empty($data['catalogo_id']) && !empty($data['curso_abierto_id'])) {
            $curso = DB::table('academic.cursos_abiertos')
                ->where('id', $data['curso_abierto_id'])
                ->first();
            $data['catalogo_id'] = $curso?->catalogo_curso_id;
        }

        $existing = Certificado::where('estudiante_id', $data['estudiante_id'])
            ->where('curso_abierto_id', $data['curso_abierto_id'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'El estudiante ya tiene un certificado para este curso',
                'data' => $existing,
            ], Response::HTTP_CONFLICT);
        }

        $persona = Persona::find($data['estudiante_id']);
        $data['cedula_impresa'] = $persona?->cedula ?? $data['cedula_impresa'] ?? '';
        $data['fecha_emision'] = $data['fecha_emision'] ?? now()->toDateString();
        $data['estado'] = Certificado::ESTADO_GENERADO;
        $data['emitido_por'] = auth()->id() ?? auth()->user()?->persona_id ?? null;
        $data['fecha_emitido'] = now();

        if ($request->hasFile('pdf')) {
            $file = $request->file('pdf');
            $filename = Str::uuid() . '.pdf';
            $path = $file->storeAs('certificados', $filename, 's3');
            $data['archivo_pdf_url'] = Storage::disk('s3')->url($path);
        }

        $certificado = Certificado::create($data);
        $certificado->load(['estudiante', 'catalogoCurso', 'cursoAbierto']);

        return response()->json([
            'data' => $certificado,
            'message' => 'Certificado creado correctamente',
        ], Response::HTTP_CREATED);
    }

    private function resolveEstudianteFromMatricula(string $matriculaId): ?array
    {
        $matricula = DB::table('academic.matriculas')
            ->where('id', $matriculaId)
            ->whereNull('deleted_at')
            ->first();

        if (!$matricula || !$matricula->solicitud_inscripcion_id) {
            return null;
        }

        $solicitud = DB::table('academic.solicitudes_inscripcion')
            ->where('id', $matricula->solicitud_inscripcion_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$solicitud) {
            return null;
        }

        if ($solicitud->persona_id) {
            return [
                'persona_id' => $solicitud->persona_id,
                'curso_abierto_id' => $matricula->curso_abierto_id,
            ];
        }

        if (!$solicitud->participante_externo_id) {
            return null;
        }

        $externo = DB::table('people.clientes_externos')
            ->where('id', $solicitud->participante_externo_id)
            ->first();

        if (!$externo) {
            return null;
        }

        $persona = Persona::firstOrCreate(
            ['cedula' => $externo->cedula],
            [
                'nombres' => $externo->nombres,
                'apellidos' => $externo->apellidos,
                'correo' => $externo->correo ?? null,
                'celular' => $externo->celular ?? null,
                'tipo' => 'estudiante',
            ]
        );

        DB::table('academic.solicitudes_inscripcion')
            ->where('id', $solicitud->id)
            ->update([
                'persona_id' => $persona->id,
                'participante_externo_id' => null,
                'es_participante_externo' => false,
            ]);

        DB::table('academic.matriculas')
            ->where('id', $matricula->id)
            ->update(['estudiante_id' => $persona->id]);

        return [
            'persona_id' => $persona->id,
            'curso_abierto_id' => $matricula->curso_abierto_id,
        ];
    }

    public function show(string $id): JsonResponse
    {
        $certificado = Certificado::with([
            'estudiante',
            'catalogoCurso',
            'cursoAbierto',
            'modulo',
        ])->findOrFail($id);

        $certificado->archivo_purgado = ArchivoEliminado::archivoFueEliminado(
            Certificado::class,
            $certificado->id,
            'archivo_pdf_url'
        );

        return response()->json(['data' => $certificado]);
    }

    public function uploadPdf(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:512',
        ]);

        $certificado = Certificado::findOrFail($id);
        $service = app(StorageCleanupService::class);

        if ($certificado->estaBorrado()) {
            return response()->json([
                'message' => 'No se puede subir PDF a un certificado borrado',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($certificado->archivo_pdf_url) {
            $service->deleteFilePhysically($certificado, 'archivo_pdf_url');
        }

        $service->reviveFileField($certificado, 'archivo_pdf_url');

        $file = $request->file('pdf');
        $filename = Str::uuid() . '.pdf';
        $path = $file->storeAs('certificados', $filename, 's3');

        $certificado->update([
            'archivo_pdf_url' => Storage::disk('s3')->url($path),
            'estado' => Certificado::ESTADO_GENERADO,
        ]);

        return response()->json([
            'data' => $certificado,
            'message' => 'PDF subido correctamente',
        ]);
    }

    public function removePdf(string $id): JsonResponse
    {
        $certificado = Certificado::findOrFail($id);

        $eliminadoPor = auth()->id() ?? auth()->user()?->persona_id ?? null;
        $service = app(StorageCleanupService::class);

        $resultado = $service->deleteFile($certificado, 'archivo_pdf_url', $eliminadoPor);

        if (!$resultado['eliminado']) {
            return response()->json([
                'message' => $resultado['mensaje'],
                'data' => $certificado->load(['estudiante', 'catalogoCurso', 'cursoAbierto']),
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'data' => $certificado->load(['estudiante', 'catalogoCurso', 'cursoAbierto']),
            'message' => 'PDF eliminado del almacenamiento. El registro del certificado se conserva como constancia histórica.',
        ]);
    }

    public function marcarEntregado(Request $request, string $id): JsonResponse
    {
        $certificado = Certificado::findOrFail($id);
        $certificado->update([
            'entregado_fisicamente' => true,
            'fecha_entrega' => $request->get('fecha_entrega', $certificado->fecha_entrega ?? now()->toDateString()),
            'estado' => Certificado::ESTADO_ENTREGADO,
            'metodo_entrega' => $request->get('metodo_entrega'),
        ]);

        return response()->json([
            'data' => $certificado,
            'message' => 'Certificado marcado como entregado físicamente',
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $certificado = Certificado::findOrFail($id);
        $eliminadoPor = auth()->id() ?? auth()->user()?->persona_id ?? null;

        $certificado->update([
            'fecha_borrado' => now(),
            'borrado_por' => $eliminadoPor,
        ]);
        $certificado->delete();

        app(StorageCleanupService::class)->deleteRecordFiles($certificado, $eliminadoPor);

        return response()->json(['message' => 'Certificado eliminado correctamente']);
    }

    public function panelEstudiantes(Request $request): JsonResponse
    {
        $query = DB::table('academic.matriculas')
            ->join('academic.cursos_abiertos', 'academic.matriculas.curso_abierto_id', '=', 'academic.cursos_abiertos.id')
            ->join('academic.catalogo_cursos', 'academic.cursos_abiertos.catalogo_curso_id', '=', 'academic.catalogo_cursos.id')
            ->leftJoin('academic.solicitudes_inscripcion', function ($join) {
                $join->on('academic.matriculas.solicitud_inscripcion_id', '=', 'academic.solicitudes_inscripcion.id')
                     ->whereNull('academic.solicitudes_inscripcion.deleted_at');
            })
            ->leftJoin('people.personas', 'academic.solicitudes_inscripcion.persona_id', '=', 'people.personas.id')
            ->leftJoin('people.clientes_externos', 'academic.solicitudes_inscripcion.participante_externo_id', '=', 'people.clientes_externos.id')
            ->leftJoin('academic.certificados', function ($join) {
                $join->on('academic.certificados.estudiante_id', '=', 'people.personas.id')
                     ->on('academic.certificados.curso_abierto_id', '=', 'academic.matriculas.curso_abierto_id')
                     ->whereNull('academic.certificados.deleted_at');
            })
            ->whereNull('academic.matriculas.deleted_at');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('people.personas.nombres', 'ilike', "%{$search}%")
                  ->orWhere('people.personas.apellidos', 'ilike', "%{$search}%")
                  ->orWhere('people.personas.cedula', 'ilike', "%{$search}%")
                  ->orWhere('people.clientes_externos.nombres', 'ilike', "%{$search}%")
                  ->orWhere('people.clientes_externos.apellidos', 'ilike', "%{$search}%")
                  ->orWhere('people.clientes_externos.cedula', 'ilike', "%{$search}%")
                  ->orWhere('academic.catalogo_cursos.nombre', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('estado_matricula')) {
            $query->where('academic.matriculas.estado', $request->estado_matricula);
        }

        if ($request->filled('curso_abierto_id')) {
            $query->where('academic.matriculas.curso_abierto_id', $request->curso_abierto_id);
        }

        if ($request->filled('estado_certificado')) {
            if ($request->estado_certificado === 'pendiente') {
                $query->whereNull('academic.certificados.id');
            } else {
                $query->where('academic.certificados.estado', $request->estado_certificado);
            }
        }

        if ($request->filled('pago_completo')) {
            $query->where('academic.matriculas.tipo_pago', 'completo');
        }

        if ($request->filled('tiene_certificado')) {
            if ($request->tiene_certificado === 'si') {
                $query->whereNotNull('academic.certificados.id');
            } elseif ($request->tiene_certificado === 'no') {
                $query->whereNull('academic.certificados.id');
            }
        }

        $rows = $query->select([
                'academic.matriculas.id as matricula_id',
                'academic.matriculas.estado as estado_matricula',
                'academic.matriculas.fecha_inscripcion',
                DB::raw('COALESCE(people.personas.id, people.clientes_externos.id) as persona_id'),
                DB::raw('COALESCE(people.personas.nombres, people.clientes_externos.nombres) as nombres'),
                DB::raw('COALESCE(people.personas.apellidos, people.clientes_externos.apellidos) as apellidos'),
                DB::raw('COALESCE(people.personas.cedula, people.clientes_externos.cedula) as cedula'),
                'academic.cursos_abiertos.id as curso_abierto_id',
                'academic.catalogo_cursos.nombre as catalogo_nombre',
                'academic.cursos_abiertos.nombre_instancia',
                'academic.cursos_abiertos.modalidad',
                'academic.certificados.id as certificado_id',
                'academic.certificados.codigo_certificado',
                'academic.certificados.estado as estado_certificado',
                'academic.certificados.archivo_pdf_url',
                DB::raw("COALESCE((
                    SELECT CASE WHEN accion IN ('borrado_archivo', 'borrado_registro') THEN true ELSE false END
                    FROM archivos_eliminados
                    WHERE model_type = 'App\\Models\\Certificado'
                      AND model_id = academic.certificados.id
                      AND field_name = 'archivo_pdf_url'
                    ORDER BY created_at DESC
                    LIMIT 1
                ), false) as archivo_purgado"),
            ])
            ->orderByRaw('COALESCE(people.personas.apellidos, people.clientes_externos.apellidos)')
            ->orderByRaw('COALESCE(people.personas.nombres, people.clientes_externos.nombres)')
            ->paginate($request->per_page ?? 15);

        return response()->json($rows);
    }

    public function buscarEstudiantes(Request $request): JsonResponse
    {
        $request->validate([
            'buscar' => 'required|string|min:2',
        ]);

        $search = $request->buscar;

        $internos = Persona::where(function ($q) use ($search) {
                $q->where('nombres', 'ilike', "%{$search}%")
                  ->orWhere('apellidos', 'ilike', "%{$search}%")
                  ->orWhere('cedula', 'ilike', "%{$search}%");
            })
            ->limit(10)
            ->get(['id', 'nombres', 'apellidos', 'cedula', 'tipo']);

        $externos = DB::table('people.clientes_externos')
            ->where(function ($q) use ($search) {
                $q->where('nombres', 'ilike', "%{$search}%")
                  ->orWhere('apellidos', 'ilike', "%{$search}%")
                  ->orWhere('cedula', 'ilike', "%{$search}%");
            })
            ->limit(10)
            ->get(['id', 'nombres', 'apellidos', 'cedula', DB::raw("'externo' as tipo")]);

        return response()->json([
            'data' => array_merge($internos->toArray(), $externos->toArray()),
        ]);
    }

    public function bulkStore(Request $request): JsonResponse
    {
        $request->validate([
            'certificados' => 'required|array|min:1|max:50',
            'certificados.*.estudiante_id' => 'required|uuid',
            'certificados.*.curso_abierto_id' => 'required|uuid',
            'certificados.*.catalogo_id' => 'nullable|uuid',
            'certificados.*.pdf' => 'required|file|mimes:pdf|max:512',
        ]);

        $resultados = [];
        $errores = [];

        DB::transaction(function () use ($request, &$resultados, &$errores) {
        foreach ($request->file('certificados') as $index => $files) {
            try {
                $data = $request->input("certificados.{$index}");
                $persona = Persona::find($data['estudiante_id']);

                if (!$persona) {
                    $errores[] = [
                        'index' => $index,
                        'estudiante_id' => $data['estudiante_id'],
                        'error' => 'Estudiante no encontrado',
                    ];
                    continue;
                }

                $file = $files['pdf'] ?? null;
                if (!$file) {
                    $errores[] = [
                        'index' => $index,
                        'estudiante_id' => $data['estudiante_id'],
                        'error' => 'PDF no proporcionado',
                    ];
                    continue;
                }

                $filename = Str::uuid() . '.pdf';
                $path = $file->storeAs('certificados', $filename, 's3');

                $certificado = Certificado::create([
                    'estudiante_id' => $persona->id,
                    'catalogo_id' => $data['catalogo_id'] ?? null,
                    'curso_abierto_id' => $data['curso_abierto_id'],
                    'cedula_impresa' => $persona->cedula,
                    'fecha_emision' => $data['fecha_emision'] ?? now()->toDateString(),
                    'estado' => Certificado::ESTADO_GENERADO,
                    'archivo_pdf_url' => Storage::disk('s3')->url($path),
                ]);

                $resultados[] = [
                    'index' => $index,
                    'certificado_id' => $certificado->id,
                    'codigo_certificado' => $certificado->codigo_certificado,
                    'estudiante' => $persona->nombres . ' ' . $persona->apellidos,
                ];
            } catch (\Exception $e) {
                $errores[] = [
                    'index' => $index,
                    'error' => $e->getMessage(),
                ];
            }
        }
        });

        return response()->json([
            'message' => 'Procesamiento completado',
            'procesados' => count($resultados),
            'errores' => count($errores),
            'resultados' => $resultados,
            'errores_detalle' => $errores,
        ], count($errores) > 0 && count($resultados) === 0
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK);
    }

    public function verificarPorCedula(Request $request): JsonResponse
    {
        $request->validate([
            'cedula' => 'required|string|max:20',
        ]);

        $certificados = Certificado::with(['estudiante', 'catalogoCurso', 'cursoAbierto'])
            ->porCedula($request->cedula)
            ->orderBy('fecha_emision', 'desc')
            ->select('academic.certificados.*')
            ->addSelect(DB::raw("COALESCE((
                SELECT CASE WHEN accion IN ('borrado_archivo', 'borrado_registro') THEN true ELSE false END
                FROM archivos_eliminados
                WHERE model_type = 'App\\Models\\Certificado'
                  AND model_id = academic.certificados.id
                  AND field_name = 'archivo_pdf_url'
                ORDER BY created_at DESC
                LIMIT 1
            ), false) as archivo_purgado"))
            ->get();

        return response()->json(['data' => $certificados]);
    }

    public function verificarPorCodigo(string $codigo): JsonResponse
    {
        $certificado = Certificado::with(['estudiante', 'catalogoCurso', 'cursoAbierto'])
            ->porCodigo($codigo)
            ->disponibles()
            ->first();

        if (!$certificado) {
            return response()->json([
                'message' => 'Certificado no encontrado o no disponible',
            ], Response::HTTP_NOT_FOUND);
        }

        $certificado->incrementarVerificaciones();

        return response()->json(['data' => $certificado]);
    }

    public function descargarPdf(string $id): Response|JsonResponse|StreamedResponse
    {
        $certificado = Certificado::with(['estudiante', 'catalogoCurso'])->findOrFail($id);

        if ($certificado->estaBorrado()) {
            return response()->json([
                'message' => 'El certificado fue eliminado',
            ], Response::HTTP_NOT_FOUND);
        }

        if (!$certificado->tienePdf()) {
            return response()->json([
                'message' => 'El PDF del certificado no está disponible',
            ], Response::HTTP_NOT_FOUND);
        }

        $archivoUrl = $certificado->archivo_pdf_url;
        $path = str_starts_with($archivoUrl, 'http')
            ? ltrim(parse_url($archivoUrl, \PHP_URL_PATH) ?? '', '/')
            : str_replace('/storage/', '', $archivoUrl);

        if (ArchivoEliminado::archivoFueEliminado(Certificado::class, $certificado->id, 'archivo_pdf_url')) {
            return response()->json([
                'message' => 'El archivo PDF fue eliminado del almacenamiento. El registro se conserva como constancia histórica.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (!Storage::disk('s3')->exists($path)) {
            return response()->json([
                'message' => 'El archivo PDF no se encuentra en el servidor',
            ], Response::HTTP_NOT_FOUND);
        }

        $certificado->marcarEntregado();

        $nombres = strtoupper(str_replace(' ', '_', trim(($certificado->estudiante->nombres ?? '') . '_' . ($certificado->estudiante->apellidos ?? ''))));
        $catalogo = strtoupper(str_replace(' ', '_', $certificado->catalogoCurso->nombre ?? 'CERTIFICADO'));
        $filename = preg_replace('/[^A-Z0-9_]/', '', "{$nombres}_{$catalogo}") . '.pdf';

        return Storage::disk('s3')->download($path, $filename);
    }

    public function historial(string $id): JsonResponse
    {
        $certificado = Certificado::with(['estudiante'])->findOrFail($id);

        $personaIds = [];
        if ($certificado->emitido_por) {
            $personaIds[] = $certificado->emitido_por;
        }
        if ($certificado->borrado_por) {
            $personaIds[] = $certificado->borrado_por;
        }

        $archivosEliminados = ArchivoEliminado::where('model_type', Certificado::class)
            ->where('model_id', $certificado->id)
            ->orderBy('created_at', 'desc')
            ->get();

        foreach ($archivosEliminados as $ae) {
            if ($ae->eliminado_por) {
                $personaIds[] = $ae->eliminado_por;
            }
        }

        $personas = [];
        if (!empty($personaIds)) {
            $personas = Persona::whereIn('id', array_unique($personaIds))
                ->get()
                ->keyBy('id');
        }

        $eventos = [];

        if ($certificado->fecha_emitido || $certificado->created_at) {
            $emitidoPor = null;
            if ($certificado->emitido_por && isset($personas[$certificado->emitido_por])) {
                $p = $personas[$certificado->emitido_por];
                $emitidoPor = trim(($p->nombres ?? '') . ' ' . ($p->apellidos ?? ''));
            }
            $fechaEmitido = $certificado->fecha_emitido ?? $certificado->created_at;
            $eventos[] = [
                'accion' => 'Emitido',
                'fecha' => $fechaEmitido instanceof \Carbon\Carbon ? $fechaEmitido->format('Y-m-d H:i') : $fechaEmitido,
                'usuario' => $emitidoPor ?? 'Sistema',
                'detalle' => 'Certificado generado',
            ];
        }

        if ($certificado->fecha_entrega) {
            $eventos[] = [
                'accion' => 'Entregado',
                'fecha' => $certificado->fecha_entrega instanceof \Carbon\Carbon
                    ? $certificado->fecha_entrega->format('Y-m-d')
                    : $certificado->fecha_entrega,
                'usuario' => null,
                'detalle' => ($certificado->metodo_entrega ? ucfirst($certificado->metodo_entrega) : 'Entregado'),
            ];
        }

        if ($certificado->fecha_borrado) {
            $borradoPor = null;
            if ($certificado->borrado_por && isset($personas[$certificado->borrado_por])) {
                $p = $personas[$certificado->borrado_por];
                $borradoPor = trim(($p->nombres ?? '') . ' ' . ($p->apellidos ?? ''));
            }
            $fechaBorrado = $certificado->fecha_borrado;
            $eventos[] = [
                'accion' => 'Borrado',
                'fecha' => $fechaBorrado instanceof \Carbon\Carbon ? $fechaBorrado->format('Y-m-d H:i') : $fechaBorrado,
                'usuario' => $borradoPor ?? 'Sistema',
                'detalle' => 'Registro conservado como histórico',
            ];
        }

        foreach ($archivosEliminados as $ae) {
            $nombre = null;
            if ($ae->eliminado_por && isset($personas[$ae->eliminado_por])) {
                $p = $personas[$ae->eliminado_por];
                $nombre = trim(($p->nombres ?? '') . ' ' . ($p->apellidos ?? ''));
            }

            if ($ae->accion === ArchivoEliminado::ACCION_RESTAURADO) {
                $eventos[] = [
                    'accion' => 'Archivo restaurado',
                    'fecha' => $ae->created_at->format('Y-m-d H:i'),
                    'usuario' => $nombre ?? 'Sistema',
                    'detalle' => 'PDF re-subido al almacenamiento',
                ];
            } elseif ($ae->accion === ArchivoEliminado::ACCION_BORRADO_REGISTRO) {
                $eventos[] = [
                    'accion' => 'Archivo eliminado (por borrado de registro)',
                    'fecha' => $ae->created_at->format('Y-m-d H:i'),
                    'usuario' => $nombre ?? 'Sistema',
                    'detalle' => 'PDF eliminado del almacenamiento — ' . $ae->file_path,
                ];
            } else {
                $eventos[] = [
                    'accion' => 'Archivo eliminado',
                    'fecha' => $ae->created_at->format('Y-m-d H:i'),
                    'usuario' => $nombre ?? 'Sistema',
                    'detalle' => 'PDF eliminado del almacenamiento — ' . $ae->file_path,
                ];
            }
        }

        return response()->json(['data' => $eventos]);
    }

    public function deleteArchivo(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'campo' => 'required|string|in:archivo_pdf_url',
        ]);

        $certificado = Certificado::findOrFail($id);
        $eliminadoPor = auth()->id() ?? auth()->user()?->persona_id ?? null;
        $service = app(StorageCleanupService::class);

        $resultado = $service->deleteFile($certificado, $request->campo, $eliminadoPor);

        if (!$resultado['eliminado']) {
            return response()->json(['message' => $resultado['mensaje']], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'message' => 'Archivo eliminado del almacenamiento. El registro se conserva como constancia histórica.',
        ]);
    }
}
