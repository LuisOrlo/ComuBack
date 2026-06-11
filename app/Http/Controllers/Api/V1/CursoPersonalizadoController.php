<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePersonalizadoCursoRequest;
use App\Http\Requests\UpdatePersonalizadoCursoRequest;
use App\Http\Requests\StoreParticipanteExternoCursoRequest;
use App\Http\Requests\UpdateParticipanteExternoCursoRequest;
use App\Models\CursoPersonalizado;
use App\Models\ParticipanteExterno;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CursoPersonalizadoController extends Controller
{
    /**
     * Listar cursos personalizados
     */
    public function index(Request $request): JsonResponse
    {
        $query = CursoPersonalizado::query();

        // Filtros
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('instructor_id')) {
            $query->where('instructor_id', $request->instructor_id);
        }

        if ($request->filled('fecha_inicio')) {
            $query->whereDate('fecha_inicio', '>=', $request->fecha_inicio);
        }

        if ($request->filled('fecha_fin')) {
            $query->whereDate('fecha_fin', '<=', $request->fecha_fin);
        }

        if ($request->filled('acepta_externos')) {
            $query->where('acepta_externos', $request->boolean('acepta_externos'));
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'ilike', "%{$search}%")
                  ->orWhere('descripcion', 'ilike', "%{$search}%");
            });
        }

        $cursos = $query
            ->with(['catalogo', 'instructor', 'matriculas', 'modulos'])
            ->orderBy('fecha_inicio', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json($cursos);
    }

    /**
     * Crear curso personalizado
     */
    public function store(StorePersonalizadoCursoRequest $request): JsonResponse
    {
        $curso = CursoPersonalizado::create(array_merge(
            $request->validated(),
            ['estado' => 'abierto']
        ));

        return response()->json($curso->load(['catalogo', 'instructor']), 201);
    }

    /**
     * Ver detalle de curso personalizado
     */
    public function show(string $id): JsonResponse
    {
        $curso = CursoPersonalizado::with([
            'catalogo',
            'instructor',
            'matriculas',
            'participantesExternos',
            'modulos',
            'horarios'
        ])->findOrFail($id);

        return response()->json($curso);
    }

    /**
     * Actualizar curso personalizado
     */
    public function update(UpdatePersonalizadoCursoRequest $request, string $id): JsonResponse
    {
        $curso = CursoPersonalizado::findOrFail($id);
        $curso->update($request->validated());

        return response()->json($curso->load(['catalogo', 'instructor']), 200);
    }

    /**
     * Eliminar curso personalizado
     */
    public function destroy(string $id): JsonResponse
    {
        $curso = CursoPersonalizado::findOrFail($id);
        $curso->delete();

        return response()->json(['message' => 'Curso personalizado eliminado'], 200);
    }

    /**
     * Obtener estadísticas del curso
     */
    public function estadisticas(string $id): JsonResponse
    {
        $curso = CursoPersonalizado::findOrFail($id);

        return response()->json($curso->estadisticas());
    }

    /**
     * Listar estudiantes inscritos
     */
    public function estudiantes(string $id, Request $request): JsonResponse
    {
        $curso = CursoPersonalizado::findOrFail($id);

        $estudiantes = $curso->matriculas()
            ->with('estudiante')
            ->orderBy('fecha_matricula', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json($estudiantes);
    }

    /**
     * Listar participantes externos
     */
    public function participantesExternos(string $id, Request $request): JsonResponse
    {
        $curso = CursoPersonalizado::findOrFail($id);

        $participantes = $curso->participantesExternos()
            ->orderByPivot('fecha_inscripcion', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json($participantes);
    }

    /**
     * Inscribir participante externo
     */
    public function inscribirExterno(StoreParticipanteExternoCursoRequest $request): JsonResponse
    {
        $curso = CursoPersonalizado::findOrFail($request->curso_personalizado_id);

        // Validar que la fecha de inicio no haya pasado
        if ($curso->fecha_inicio <= now()->toDateString()) {
            return response()->json([
                'message' => 'No se puede inscribir después de la fecha de inicio del curso',
            ], 422);
        }

        // Validar que no exista inscripción anterior
        $existe = $curso->participantesExternos()
            ->where('participante_externo_id', $request->participante_externo_id)
            ->exists();

        if ($existe) {
            return response()->json([
                'message' => 'El participante ya está inscrito en este curso',
            ], 422);
        }

        // Validar capacidad
        if ($curso->capacidadDisponibleParticipantes() <= 0) {
            return response()->json([
                'message' => 'El curso está lleno',
                'capacidad' => $curso->capacidad,
                'inscritos' => $curso->totalParticipantes(),
            ], 422);
        }

        // Validar que acepte externos
        if (!$curso->acepta_externos) {
            return response()->json([
                'message' => 'Este curso no acepta participantes externos',
            ], 422);
        }

        $curso->participantesExternos()->attach(
            $request->participante_externo_id,
            [
                'fecha_inscripcion' => now()->toDateString(),
                'estado' => 'inscrito',
            ]
        );

        $participante = ParticipanteExterno::find($request->participante_externo_id);

        return response()->json([
            'message' => 'Participante inscrito correctamente',
            'participante' => $participante,
            'curso_id' => $curso->id,
        ], 201);
    }

    /**
     * Actualizar estado de participante externo
     */
    public function actualizarEstadoExterno(UpdateParticipanteExternoCursoRequest $request, string $id): JsonResponse
    {
        $curso = CursoPersonalizado::findOrFail($id);

        $request->validate([
            'participante_externo_id' => 'required|uuid|exists:academic.participantes_externos,id',
            'estado' => 'required|in:inscrito,completado,retirado',
        ]);

        // Verificar que el participante está inscrito
        $existe = $curso->participantesExternos()
            ->where('participante_externo_id', $request->participante_externo_id)
            ->exists();

        if (!$existe) {
            return response()->json([
                'message' => 'El participante no está inscrito en este curso',
            ], 404);
        }

        $curso->participantesExternos()->updateExistingPivot(
            $request->participante_externo_id,
            ['estado' => $request->estado]
        );

        return response()->json([
            'message' => 'Estado actualizado correctamente',
        ], 200);
    }

    /**
     * Desinscribir participante externo
     */
    public function desinscribirExterno(string $id, string $participante_id): JsonResponse
    {
        $curso = CursoPersonalizado::findOrFail($id);

        $curso->participantesExternos()->detach($participante_id);

        return response()->json(['message' => 'Participante desinscrito'], 200);
    }

    /**
     * Obtener módulos del curso
     */
    public function modulos(string $id): JsonResponse
    {
        $curso = CursoPersonalizado::findOrFail($id);

        $modulos = $curso->modulos()->with('notas')->get();

        return response()->json($modulos);
    }

    /**
     * Obtener horarios del curso
     */
    public function horarios(string $id): JsonResponse
    {
        $curso = CursoPersonalizado::findOrFail($id);

        $horario = $curso->horario;
        $horarios = $horario ? collect([$horario]) : collect([]);

        return response()->json($horarios);
    }
}
