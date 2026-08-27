<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CursoAbierto;
use App\Models\Clase;
use App\Models\Matricula;
use App\Models\Asistencia;
use App\Models\Nota;
use App\Models\Modulo;
use App\Models\Persona;
use App\Models\ClienteExterno;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InstructorPortalController extends Controller
{
    private function isAdmin(): bool
    {
        return auth()->user()->hasRole('Administrador') || auth()->user()->hasRole('Secretaria');
    }

    /**
     * Listado de cursos asignados al instructor autenticado
     */
    public function misCursos(): JsonResponse
    {
        $personaId = auth()->user()->persona_id;

        $cursos = CursoAbierto::query()
            ->where('docente_id', $personaId)
            ->with(['catalogo', 'horario.diasSemana', 'ciudad'])
            ->get();

        return response()->json([
            'datos' => $cursos
        ]);
    }

    /**
     * Dashboard agregado del instructor: cursos + estudiantes + clases en una sola respuesta.
     * Replica la regla de visibilidad de misCursos(): siempre filtra por docente_id.
     */
    public function dashboard(): JsonResponse
    {
        $personaId = auth()->user()->persona_id;

        $cursos = CursoAbierto::query()
            ->where('docente_id', $personaId)
            ->with(['catalogo', 'horario.diasSemana', 'ciudad', 'modulos'])
            ->get();

        $cursoIds = $cursos->pluck('id')->all();

        $matriculas = empty($cursoIds)
            ? collect()
            : Matricula::whereIn('curso_abierto_id', $cursoIds)
                ->with(['estudiante', 'notas', 'solicitudInscripcion.participanteExterno'])
                ->get()
                ->groupBy('curso_abierto_id');

        $matriculaIds = $matriculas->flatten(1)->pluck('id')->all();

        $totalClasesPorCurso = empty($cursoIds)
            ? collect()
            : DB::table('academic.clases as c')
                ->join('academic.modulos as m', 'm.id', '=', 'c.modulo_id')
                ->whereIn('m.curso_abierto_id', $cursoIds)
                ->selectRaw('m.curso_abierto_id, COUNT(*) as total')
                ->groupBy('m.curso_abierto_id')
                ->pluck('total', 'curso_abierto_id');

        $asistidasPorMatricula = empty($matriculaIds)
            ? collect()
            : Asistencia::whereIn('matricula_id', $matriculaIds)
                ->where('asistio', true)
                ->selectRaw('matricula_id, COUNT(*) as c')
                ->groupBy('matricula_id')
                ->pluck('c', 'matricula_id');

        $moduloIds = $cursos->flatMap(fn($c) => $c->modulos->pluck('id'))->all();

        $clases = empty($moduloIds)
            ? collect()
            : Clase::whereIn('modulo_id', $moduloIds)->orderBy('fecha_clase', 'asc')->get();

        $claseIds = $clases->pluck('id')->all();
        $clasesConAsistencia = empty($claseIds)
            ? collect()
            : Asistencia::whereIn('clase_id', $claseIds)->distinct()->pluck('clase_id');

        $datos = $cursos->map(function ($curso) use ($matriculas, $totalClasesPorCurso, $asistidasPorMatricula, $clases, $clasesConAsistencia) {
            $totalClases = (int) ($totalClasesPorCurso->get($curso->id) ?? 0);

            $estudiantes = ($matriculas->get($curso->id) ?? collect())
                ->map(function ($m) use ($totalClases, $asistidasPorMatricula) {
                    $asistidas = (int) ($asistidasPorMatricula->get($m->id) ?? 0);

                    return [
                        'id' => $m->id,
                        'estudiante' => $m->estudiante,
                        'participante_externo' => $m->solicitudInscripcion?->participanteExterno,
                        'porcentaje_asistencia' => $totalClases > 0 ? round(($asistidas / $totalClases) * 100, 2) : 0,
                        'clases_asistidas' => $asistidas,
                        'total_clases' => $totalClases,
                        'notas' => $m->notas,
                        'estado' => $m->estado,
                    ];
                })->values();

            $moduloMap = $curso->modulos->keyBy('id');
            $clasesCurso = $clases
                ->filter(fn($cl) => $moduloMap->has($cl->modulo_id))
                ->map(fn($cl) => [
                    'id' => $cl->id,
                    'modulo_id' => $cl->modulo_id,
                    'modulo_nombre' => $moduloMap->get($cl->modulo_id)?->nombre_modulo ?? '',
                    'fecha_clase' => $cl->fecha_clase,
                    'hora_inicio' => $cl->hora_inicio,
                    'hora_fin' => $cl->hora_fin,
                    'asistencia_registrada' => $clasesConAsistencia->contains($cl->id),
                    'observaciones' => $cl->observaciones,
                ])->values();

            return [
                'id' => $curso->id,
                'catalogo' => $curso->catalogo,
                'nombre_instancia' => $curso->nombre_instancia,
                'estado' => $curso->estado,
                'fecha_inicio' => $curso->fecha_inicio,
                'fecha_fin' => $curso->fecha_fin,
                'modalidad' => $curso->modalidad,
                'horario' => $curso->horario,
                'ciudad' => $curso->ciudad,
                'modulos' => $curso->modulos,
                'estudiantes' => $estudiantes,
                'clases' => $clasesCurso,
            ];
        })->values();

        return response()->json([
            'datos' => $datos
        ]);
    }

    /**
     * Detalle de un curso específico para el instructor
     */
    public function detalleCurso($id): JsonResponse
    {
        $personaId = auth()->user()->persona_id;

        $query = CursoAbierto::query()
            ->where('id', $id);

        if (!$this->isAdmin()) {
            $query->where('docente_id', $personaId);
        }

        $curso = $query
            ->with(['catalogo', 'horario.diasSemana', 'modulos', 'matriculas.estudiante'])
            ->firstOrFail();

        $curso->modulos->map(function ($modulo) use ($curso) {
            if (is_null($modulo->ponderacion) || $modulo->ponderacion <= 0) {
                $total = $curso->modulos->count();
                $modulo->ponderacion = $total > 0 ? round(100 / $total, 2) : 0;
            }
            return $modulo;
        });

        return response()->json([
            'datos' => $curso
        ]);
    }

    /**
     * Obtener estudiantes de un curso con sus estadísticas de asistencia y notas
     */
    public function estudiantesCurso($id): JsonResponse
    {
        $personaId = auth()->user()->persona_id;

        // Validar que el curso exista (sin filtro de docente si es admin)
        $query = CursoAbierto::where('id', $id);

        if (!$this->isAdmin()) {
            $query->where('docente_id', $personaId);
        }

        $query->firstOrFail();

        $matriculas = Matricula::where('curso_abierto_id', $id)
            ->with(['estudiante', 'notas', 'solicitudInscripcion.participanteExterno'])
            ->get();

        $matriculaIds = $matriculas->pluck('id')->all();

        $totalClases = Clase::whereHas('modulo', function ($q) use ($id) {
            $q->where('curso_abierto_id', $id);
        })->count();

        $asistidasPorMatricula = Asistencia::whereIn('matricula_id', $matriculaIds)
            ->where('asistio', true)
            ->selectRaw('matricula_id, COUNT(*) AS asistidas')
            ->groupBy('matricula_id')
            ->pluck('asistidas', 'matricula_id');

        $datos = $matriculas->map(function ($matricula) use ($totalClases, $asistidasPorMatricula) {
            $asistidas = (int) $asistidasPorMatricula->get($matricula->id, 0);

            return [
                'id' => $matricula->id,
                'estudiante' => $matricula->estudiante,
                'participante_externo' => $matricula->solicitudInscripcion?->participanteExterno,
                'porcentaje_asistencia' => $totalClases > 0 ? round(($asistidas / $totalClases) * 100, 2) : 0,
                'clases_asistidas' => $asistidas,
                'total_clases' => $totalClases,
                'notas' => $matricula->notas,
                'estado' => $matricula->estado,
            ];
        });

        return response()->json([
            'datos' => $datos
        ]);
    }

    /**
     * Datos personales de un estudiante (solo si está en un curso del instructor)
     */
    public function detalleEstudiante($id): JsonResponse
    {
        $personaId = auth()->user()->persona_id;

        // Intentar como Persona (estudiante interno)
        $estudiante = Persona::with('perfilEstudiante')->find($id);
        $esExterno = false;

        if (!$estudiante) {
            // Intentar como ClienteExterno (participante externo)
            $cliente = ClienteExterno::with('ciudad')->find($id);
            if (!$cliente) {
                return response()->json(['mensaje' => 'Estudiante no encontrado.'], 404);
            }

            $esSuEstudiante = Matricula::whereHas('solicitudInscripcion', fn($q) => $q->where('participante_externo_id', $id))
                ->whereHas('cursoAbierto', fn($q) => $q->where('docente_id', $personaId))
                ->exists();

            if (!$esSuEstudiante) {
                return response()->json(['mensaje' => 'El estudiante no pertenece a ninguno de tus cursos.'], 403);
            }

            return response()->json([
                'datos' => [
                    'id' => $cliente->id,
                    'nombres' => $cliente->nombres,
                    'apellidos' => $cliente->apellidos ?? '',
                    'cedula' => $cliente->cedula,
                    'correo' => $cliente->correo,
                    'celular' => $cliente->celular,
                    'ciudad' => $cliente->ciudad,
                    'perfil_estudiante' => [
                        'ocupacion' => $cliente->ocupacion,
                        'direccion' => $cliente->direccion,
                        'estado_civil' => $cliente->estado_civil,
                        'edad' => $cliente->edad,
                    ],
                ]
            ]);
        }

        $esSuEstudiante = Matricula::where('estudiante_id', $id)
            ->whereHas('cursoAbierto', fn($q) => $q->where('docente_id', $personaId))
            ->exists();

        if (!$esSuEstudiante) {
            return response()->json(['mensaje' => 'El estudiante no pertenece a ninguno de tus cursos.'], 403);
        }

        return response()->json([
            'datos' => [
                'id' => $estudiante->id,
                'nombres' => $estudiante->nombres,
                'apellidos' => $estudiante->apellidos,
                'cedula' => $estudiante->cedula,
                'correo' => $estudiante->correo,
                'celular' => $estudiante->celular,
                'ciudad' => $estudiante->ciudad,
                'perfil_estudiante' => $estudiante->perfilEstudiante,
            ]
        ]);
    }

    /**
     * Listado de clases para un módulo específico
     */
    public function clasesModulo($moduloId): JsonResponse
    {
        $personaId = auth()->user()->persona_id;

        $query = Modulo::where('id', $moduloId);

        if (!$this->isAdmin()) {
            $query->whereHas('cursoAbierto', fn($q) => $q->where('docente_id', $personaId));
        }

        $query->firstOrFail();

        $clases = Clase::where('modulo_id', $moduloId)
            ->orderBy('fecha_clase', 'asc')
            ->get();

        // Podríamos agregar lógica para marcar si ya tienen asistencia
        $clasesConEstado = $clases->map(function ($clase) {
            $clase->asistencia_registrada = Asistencia::where('clase_id', $clase->id)->exists();
            return $clase;
        });

        return response()->json([
            'datos' => $clasesConEstado
        ]);
    }

    /**
     * Detalle de una clase específica
     */
    public function detalleClase($claseId): JsonResponse
    {
        $personaId = auth()->user()->persona_id;

        $query = Clase::where('id', $claseId);

        if (!$this->isAdmin()) {
            $query->whereHas('modulo.cursoAbierto', fn($q) => $q->where('docente_id', $personaId));
        }

        $clase = $query->firstOrFail();

        return response()->json([
            'datos' => $clase
        ]);
    }

    /**
     * Obtener asistencias detalladas de una clase (por estudiante)
     */
    public function asistenciaClase($claseId): JsonResponse
    {
        $personaId = auth()->user()->persona_id;

        $query = Clase::where('id', $claseId);

        if (!$this->isAdmin()) {
            $query->whereHas('modulo.cursoAbierto', fn($q) => $q->where('docente_id', $personaId));
        }

        $query->firstOrFail();

        $asistencias = Asistencia::where('clase_id', $claseId)
            ->with(['matricula.estudiante', 'matricula.solicitudInscripcion.participanteExterno'])
            ->get()
            ->map(function ($a) {
                $persona = $a->matricula->estudiante;
                $externo = $a->matricula->solicitudInscripcion?->participanteExterno;
                return [
                    'id' => $a->id,
                    'clase_id' => $a->clase_id,
                    'matricula_id' => $a->matricula_id,
                    'asistio' => $a->asistio,
                    'estado' => $a->estado,
                    'observaciones' => $a->observaciones,
                    'estudiante' => $persona ? [
                        'id' => $persona->id,
                        'nombres' => $persona->nombres,
                        'apellidos' => $persona->apellidos,
                        'cedula' => $persona->cedula,
                        'correo' => $persona->correo,
                        'ciudad' => $persona?->getAttribute('ciudad') ?? null,
                    ] : null,
                    'participante_externo' => $externo ? [
                        'id' => $externo->id,
                        'nombres' => $externo->nombres,
                        'apellidos' => $externo->apellidos ?? '',
                        'cedula' => $externo->cedula,
                        'correo' => $externo->correo,
                        'telefono' => $externo->celular,
                    ] : null,
                ];
            });

        return response()->json(['datos' => $asistencias]);
    }

    /**
     * Registrar asistencia para una clase
     */
    public function registrarAsistencia(Request $request, $claseId): JsonResponse
    {
        $personaId = auth()->user()->persona_id;

        $request->validate([
            'asistencias' => 'required|array',
            'asistencias.*.matricula_id' => 'required|uuid',
            'asistencias.*.asistio' => 'required|boolean',
            'asistencias.*.observaciones' => 'nullable|string',
            'asistencias.*.estado' => 'nullable|string|in:presente,ausente,tardanza,justificado',
            'clase_observaciones' => 'nullable|string|max:500',
        ]);

        $claseQuery = Clase::where('id', $claseId);

        if (!$this->isAdmin()) {
            $claseQuery->whereHas('modulo.cursoAbierto', fn($q) => $q->where('docente_id', $personaId));
        }

        $clase = $claseQuery->firstOrFail();

        DB::beginTransaction();
        try {
            foreach ($request->asistencias as $data) {
                Asistencia::updateOrCreate(
                    [
                        'clase_id' => $claseId,
                        'matricula_id' => $data['matricula_id'],
                    ],
                    [
                        'asistio' => $data['asistio'],
                        'estado' => $data['estado'] ?? ($data['asistio'] ? 'presente' : 'ausente'),
                        'observaciones' => $data['observaciones'] ?? null,
                    ]
                );
            }

            if ($request->filled('clase_observaciones')) {
                $clase->update(['observaciones' => $request->clase_observaciones]);
            }

            DB::commit();
            return response()->json(['mensaje' => 'Asistencia registrada correctamente.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['mensaje' => 'Error al registrar asistencia.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Registrar notas por módulo
     */
    public function registrarNotas(Request $request): JsonResponse
    {
        $personaId = auth()->user()->persona_id;

        $request->validate([
            'modulo_id' => 'required|uuid',
            'notas' => 'required|array',
            'notas.*.matricula_id' => 'required|uuid',
            'notas.*.calificacion' => 'required|numeric|min:0|max:10',
            'notas.*.observaciones' => 'nullable|string',
        ]);

        $moduloQuery = Modulo::where('id', $request->modulo_id);

        if (!$this->isAdmin()) {
            $moduloQuery->whereHas('cursoAbierto', fn($q) => $q->where('docente_id', $personaId));
        }

        $moduloQuery->firstOrFail();

        DB::beginTransaction();
        try {
            foreach ($request->notas as $data) {
                Nota::updateOrCreate(
                    [
                        'modulo_id' => $request->modulo_id,
                        'matricula_id' => $data['matricula_id'],
                    ],
                    [
                        'calificacion' => $data['calificacion'],
                        'observaciones' => $data['observaciones'] ?? null,
                    ]
                );
            }
            DB::commit();
            return response()->json(['mensaje' => 'Notas registradas correctamente.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['mensaje' => 'Error al registrar notas.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Datos para generar PDF de lista de asistencia de un curso
     */
    public function asistenciaPDF($id): JsonResponse
    {
        $personaId = auth()->user()->persona_id;

        $query = CursoAbierto::with([
            'catalogo',
            'horario.diasSemana',
            'modulos',
            'docente',
            'ciudad',
            'matriculas.estudiante.perfilEstudiante',
            'matriculas.solicitudInscripcion.estudiante.perfilEstudiante',
            'matriculas.solicitudInscripcion.participanteExterno',
        ]);

        if (!$this->isAdmin()) {
            $query->where('docente_id', $personaId);
        }

        $curso = $query->findOrFail($id);

        $diasSemana = $curso->horario?->diasSemana?->pluck('dia_semana')->toArray() ?? [];

        $modulos = $curso->modulos->sortBy('numero_orden')->values()->map(function ($modulo) use ($diasSemana) {
            $fechas = [];
            if ($modulo->fecha_inicio && $modulo->fecha_fin && $diasSemana) {
                $inicio = \Carbon\Carbon::parse($modulo->fecha_inicio);
                $fin = \Carbon\Carbon::parse($modulo->fecha_fin);
                $fecha = $inicio->copy();
                while ($fecha->lte($fin)) {
                    if (in_array((int)$fecha->format('N'), $diasSemana)) {
                        $fechas[$fecha->format('Y-m-d')] = true;
                    }
                    $fecha->addDay();
                }
            }
            $claseDates = Clase::where('modulo_id', $modulo->id)
                ->orderBy('fecha_clase')
                ->get()
                ->pluck('fecha_clase')
                ->map(fn($d) => $d->format('Y-m-d'));
            foreach ($claseDates as $d) {
                $fechas[$d] = true;
            }
            ksort($fechas);
            return [
                'nombre' => $modulo->nombre_modulo,
                'fechas' => array_keys($fechas),
            ];
        });

        $allDates = $modulos->flatMap(fn($m) => $m['fechas'])->unique()->sort()->values();

        $allClasesByDate = collect();
        if ($allDates->isNotEmpty()) {
            $allClasesByDate = Clase::whereIn('fecha_clase', $allDates)
                ->get()
                ->keyBy(fn($c) => $c->fecha_clase->format('Y-m-d'));
        }

        $participantes = $curso->matriculas->map(function ($matricula) use ($allDates, $allClasesByDate) {
            $persona = $matricula->estudiante;
            $sol = $matricula->solicitudInscripcion;
            $externo = $sol?->participanteExterno;

            $claseIds = $allClasesByDate->pluck('id');
            $asistencias = collect();
            if ($claseIds->isNotEmpty()) {
                $asistencias = Asistencia::where('matricula_id', $matricula->id)
                    ->whereIn('clase_id', $claseIds)
                    ->get()
                    ->keyBy('clase_id');
            }

            $asistenciasMap = [];
            $conteoC = 0;
            $conteoF = 0;

            foreach ($allDates as $fechaStr) {
                $clase = $allClasesByDate->get($fechaStr);
                $a = $clase ? $asistencias->get($clase->id) : null;
                if ($a && $a->asistio) {
                    $asistenciasMap[] = 'X';
                    $conteoC++;
                } elseif ($a && !$a->asistio) {
                    $asistenciasMap[] = 'F';
                    $conteoF++;
                } else {
                    $asistenciasMap[] = null;
                }
            }

            $ciudadStr = $persona?->getAttribute('ciudad') ?? '';

            return [
                'nombres' => $persona?->nombres ?? $externo?->nombres ?? '',
                'apellidos' => $persona?->apellidos ?? $externo?->apellidos ?? '',
                'cedula' => $persona?->cedula ?? $externo?->cedula ?? '',
                'telefono' => $persona?->celular ?? $externo?->celular ?? '',
                'ciudad' => $ciudadStr,
                'conteo_c' => $conteoC,
                'conteo_f' => $conteoF,
                'asistencias' => $asistenciasMap,
            ];
        });

        $horario = '';
        if ($curso->horario) {
            $dias = $curso->horario->diasSemana
                ->sortBy('dia_semana')
                ->map(fn($d) => [1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'][(int)$d->dia_semana] ?? '')
                ->implode(', ');
            $hIni = $curso->horario->hora_inicio ? substr($curso->horario->hora_inicio, 0, 5) : '';
            $hFin = $curso->horario->hora_fin ? substr($curso->horario->hora_fin, 0, 5) : '';
            $horario = $dias ? "{$dias} {$hIni}-{$hFin}" : '';
        }

        return response()->json([
            'info' => [
                'nombre' => $curso->nombre_instancia,
                'instructor' => $curso->docente ? trim("{$curso->docente->nombres} {$curso->docente->apellidos}") : '',
                'ciudad' => $curso->ciudad?->nombre ?? '',
                'horario' => $horario,
                'fecha_inicio' => $curso->fecha_inicio?->format('Y-m-d'),
                'fecha_fin' => $curso->fecha_fin?->format('Y-m-d'),
            ],
            'modulos' => $modulos,
            'participantes' => $participantes,
        ]);
    }

    /**
     * Listar sesiones de asistencia de un taller (para instructor)
     */
    public function asistenciasTaller($tallerId, Request $request): JsonResponse
    {
        return app(AsistenciaTallerController::class)->index($tallerId, $request);
    }

    /**
     * Listar estudiantes de una sesión de taller (para instructor)
     */
    public function estudiantesAsistenciaTaller($tallerId, $sesionId): JsonResponse
    {
        return app(AsistenciaTallerController::class)->listEstudiantes($tallerId, $sesionId);
    }

    /**
     * Actualizar asistencia de una sesión de taller (para instructor)
     */
    public function actualizarAsistenciaTaller(Request $request, $sesionId): JsonResponse
    {
        return app(AsistenciaTallerController::class)->update($request, $sesionId);
    }

    /**
     * Descargar PDF de asistencia de taller (para instructor)
     */
    public function asistenciaTallerPDF($tallerId): JsonResponse
    {
        return app(TallerController::class)->asistenciaPDF($tallerId);
    }
}
