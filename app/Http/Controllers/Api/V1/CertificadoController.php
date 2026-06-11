<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCertificadoRequest;
use App\Models\Certificado;
use App\Models\Persona;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
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

        if ($request->hasFile('pdf')) {
            $file = $request->file('pdf');
            $filename = Str::uuid() . '.pdf';
            $path = $file->storeAs('certificados', $filename, 'public');
            $data['archivo_pdf_url'] = '/storage/' . $path;
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

        return response()->json(['data' => $certificado]);
    }

    public function uploadPdf(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'pdf' => 'required|file|mimes:pdf|max:512',
        ]);

        $certificado = Certificado::findOrFail($id);

        if ($certificado->estaBorrado()) {
            return response()->json([
                'message' => 'No se puede subir PDF a un certificado borrado',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($certificado->archivo_pdf_url) {
            $oldPath = str_replace('/storage/', '', $certificado->archivo_pdf_url);
            Storage::disk('public')->delete($oldPath);
        }

        $file = $request->file('pdf');
        $filename = Str::uuid() . '.pdf';
        $path = $file->storeAs('certificados', $filename, 'public');

        $certificado->update([
            'archivo_pdf_url' => '/storage/' . $path,
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

        if ($certificado->archivo_pdf_url) {
            $oldPath = str_replace('/storage/', '', $certificado->archivo_pdf_url);
            Storage::disk('public')->delete($oldPath);
        }

        $certificado->update([
            'archivo_pdf_url' => null,
            'estado' => Certificado::ESTADO_BORRADO,
        ]);

        return response()->json([
            'data' => $certificado,
            'message' => 'PDF eliminado, el registro del certificado se conserva',
        ]);
    }

    public function marcarEntregado(string $id): JsonResponse
    {
        $certificado = Certificado::findOrFail($id);
        $certificado->update([
            'entregado_fisicamente' => true,
            'fecha_entrega' => $certificado->fecha_entrega ?? now()->toDateString(),
            'estado' => Certificado::ESTADO_ENTREGADO,
        ]);

        return response()->json([
            'data' => $certificado,
            'message' => 'Certificado marcado como entregado físicamente',
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $certificado = Certificado::findOrFail($id);
        $certificado->delete();

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
                'academic.catalogo_cursos.nombre as curso_nombre',
                'academic.certificados.id as certificado_id',
                'academic.certificados.codigo_certificado',
                'academic.certificados.estado as estado_certificado',
                'academic.certificados.archivo_pdf_url',
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
                $path = $file->storeAs('certificados', $filename, 'public');

                $certificado = Certificado::create([
                    'estudiante_id' => $persona->id,
                    'catalogo_id' => $data['catalogo_id'] ?? null,
                    'curso_abierto_id' => $data['curso_abierto_id'],
                    'cedula_impresa' => $persona->cedula,
                    'fecha_emision' => $data['fecha_emision'] ?? now()->toDateString(),
                    'estado' => Certificado::ESTADO_GENERADO,
                    'archivo_pdf_url' => '/storage/' . $path,
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
            ->disponibles()
            ->orderBy('fecha_emision', 'desc')
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

    public function descargarPdf(string $id): Response|JsonResponse
    {
        $certificado = Certificado::findOrFail($id);

        if (!$certificado->tienePdf() || $certificado->estaBorrado()) {
            return response()->json([
                'message' => 'El PDF del certificado no está disponible',
            ], Response::HTTP_NOT_FOUND);
        }

        $path = str_replace('/storage/', '', $certificado->archivo_pdf_url);

        if (!Storage::disk('public')->exists($path)) {
            return response()->json([
                'message' => 'El archivo PDF no se encuentra en el servidor',
            ], Response::HTTP_NOT_FOUND);
        }

        $certificado->marcarEntregado();

        return Storage::disk('public')->download($path, "certificado_{$certificado->codigo_certificado}.pdf");
    }
}
