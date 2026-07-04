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
     * Detalle de un curso específico para el instructor
     */
    public function detalleCurso($id): JsonResponse
    {
        $personaId = auth()->user()->persona_id;

        $curso = CursoAbierto::query()
            ->where('id', $id)
            ->where('docente_id', $personaId)
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

        // Validar que el curso pertenezca al instructor
        CursoAbierto::where('id', $id)
            ->where('docente_id', $personaId)
            ->firstOrFail();

        $matriculas = Matricula::where('curso_abierto_id', $id)
            ->with(['estudiante', 'notas', 'solicitudInscripcion.participanteExterno'])
            ->get()
            ->map(function ($matricula) {
                $asistenciaStats = $this->calcularAsistenciaMatricula($matricula->id);
                return [
                    'id' => $matricula->id,
                    'estudiante' => $matricula->estudiante,
                    'participante_externo' => $matricula->solicitudInscripcion?->participanteExterno,
                    'porcentaje_asistencia' => $asistenciaStats['porcentaje'],
                    'clases_asistidas' => $asistenciaStats['asistidas'],
                    'total_clases' => $asistenciaStats['total'],
                    'notas' => $matricula->notas,
                    'estado' => $matricula->estado,
                ];
            });

        return response()->json([
            'datos' => $matriculas
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
                        'fecha_nacimiento' => $cliente->fecha_nacimiento,
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

        Modulo::where('id', $moduloId)
            ->whereHas('cursoAbierto', fn($q) => $q->where('docente_id', $personaId))
            ->firstOrFail();

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

        $clase = Clase::where('id', $claseId)
            ->whereHas('modulo.cursoAbierto', fn($q) => $q->where('docente_id', $personaId))
            ->firstOrFail();

        return response()->json([
            'datos' => $clase
        ]);
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
        ]);

        Clase::where('id', $claseId)
            ->whereHas('modulo.cursoAbierto', fn($q) => $q->where('docente_id', $personaId))
            ->firstOrFail();

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

        Modulo::where('id', $request->modulo_id)
            ->whereHas('cursoAbierto', fn($q) => $q->where('docente_id', $personaId))
            ->firstOrFail();

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

    private function calcularAsistenciaMatricula($matriculaId)
    {
        $totalClases = Clase::whereHas('modulo', function($q) use ($matriculaId) {
            $q->whereHas('cursoAbierto', function($sq) use ($matriculaId) {
                $sq->whereHas('matriculas', function($ssq) use ($matriculaId) {
                    $ssq->where('id', $matriculaId);
                });
            });
        })->count();

        if ($totalClases === 0) return ['porcentaje' => 0, 'asistidas' => 0, 'total' => 0];

        $asistidas = Asistencia::where('matricula_id', $matriculaId)
            ->where('asistio', true)
            ->count();

        return [
            'total' => $totalClases,
            'asistidas' => $asistidas,
            'porcentaje' => round(($asistidas / $totalClases) * 100, 2)
        ];
    }
}
