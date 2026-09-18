<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePersonalizadoCursoRequest;
use App\Http\Requests\UpdatePersonalizadoCursoRequest;
use App\Http\Requests\StoreParticipanteExternoCursoRequest;
use App\Http\Requests\UpdateParticipanteExternoCursoRequest;
use App\Models\CursoPersonalizado;
use App\Models\Horario;
use App\Models\HorarioDia;
use App\Models\ParticipanteExterno;
use App\Models\Matricula;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use App\Services\InstructorConflictValidator;

class CursoPersonalizadoController extends Controller
{
    /**
     * Listar cursos personalizados
     */
    public function index(Request $request): JsonResponse
    {
        $query = CursoPersonalizado::query();

        if ($request->boolean('publico')) {
            $query->where('es_activo', true)
                ->whereDate('fecha_fin', '>=', now()->toDateString());
        }

        // Filtros
        if ($request->filled('docente_id')) {
            $query->where('docente_id', $request->docente_id);
        }

        if ($request->filled('modalidad')) {
            $query->where('modalidad', $request->modalidad);
        }

        if ($request->filled('fecha_inicio')) {
            $query->whereDate('fecha_inicio', '>=', $request->fecha_inicio);
        }

        if ($request->filled('fecha_fin')) {
            $query->whereDate('fecha_fin', '<=', $request->fecha_fin);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre_instancia', 'ilike', "%{$search}%")
                  ->orWhere('observaciones', 'ilike', "%{$search}%")
                  ->orWhereHas('docente', function ($docente) use ($search) {
                      $docente->where('nombres', 'ilike', "%{$search}%")
                          ->orWhere('apellidos', 'ilike', "%{$search}%");
                  });
            });
        }

        $cursos = $query
            ->with(['docente', 'ciudad', 'horario.diasSemana'])
            ->withCount(['matriculas as matriculas_ocupadas' => function ($matriculas) {
                $matriculas->whereIn('estado', [Matricula::ESTADO_ACTIVO, Matricula::ESTADO_COMPLETADO])
                    ->whereNull('deleted_at');
            }])
            ->orderBy('fecha_inicio', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => collect($cursos->items())->map(fn ($curso) => $this->toApi($curso))->values(),
            'meta' => [
                'total' => $cursos->total(),
                'per_page' => $cursos->perPage(),
                'current_page' => $cursos->currentPage(),
                'last_page' => $cursos->lastPage(),
            ],
        ]);
    }

    /**
     * Crear curso personalizado
     */
    public function store(StorePersonalizadoCursoRequest $request, InstructorConflictValidator $conflictValidator): JsonResponse
    {
        $data = $this->normalizarDiasSesionUnica($request->validated());
        $this->validarConflictoDocente($data, $conflictValidator);

        $curso = DB::transaction(function () use ($data) {
            $cursoData = $this->mapearDatosCreacion($data);
            $curso = CursoPersonalizado::create($cursoData);
            $this->sincronizarHorario($curso, $data, true);
            return $curso->fresh(['catalogo', 'docente', 'ciudad', 'horario.diasSemana']);
        });

        return response()->json(['data' => $this->toApi($curso)], 201);
    }

    /**
     * Ver detalle de curso personalizado
     */
    public function show(string $id): JsonResponse
    {
        $curso = CursoPersonalizado::with([
            'docente',
            'ciudad',
            'matriculas.estudiante',
            'matriculas.cuentaPorCobrar',
            'matriculas.lineasPago',
            'horario.diasSemana',
        ])->findOrFail($id);

        // La pertenencia se determina exclusivamente por Matrícula.
        // La relación ya excluye registros soft-deleted.
        $matriculas = $curso->matriculas;

        return response()->json([
            'data' => $this->toApi($curso),
            'estudiantes' => $matriculas->map(fn (Matricula $matricula) => $this->mapMatricula($matricula, (float) $curso->precio_base))->values(),
            'finanzas' => $this->resumenFinanciero($matriculas, (float) $curso->precio_base),
        ]);
    }

    /**
     * Actualizar curso personalizado
     */
    public function update(UpdatePersonalizadoCursoRequest $request, string $id, InstructorConflictValidator $conflictValidator): JsonResponse
    {
        $curso = CursoPersonalizado::findOrFail($id);
        $data = $request->validated();
        $this->validarConflictoDocente($data, $conflictValidator, $curso);

        $curso = DB::transaction(function () use ($data, $curso) {
            $curso->update($this->mapearDatosActualizacion($data, $curso));
            $this->sincronizarHorario($curso, $data, false);
            return $curso->fresh(['catalogo', 'docente', 'ciudad', 'horario.diasSemana']);
        });

        return response()->json(['data' => $this->toApi($curso)], 200);
    }

    private function mapearDatosCreacion(array $data): array
    {
        return [
            'catalogo_curso_id' => null,
            'es_personalizado' => true,
            'nombre_instancia' => $data['nombre'],
            'observaciones' => $data['descripcion'] ?? null,
            'docente_id' => $data['docente_id'] ?? null,
            'modalidad' => $data['modalidad'],
            'ciudad_id' => $data['modalidad'] === 'virtual' ? null : ($data['ciudad_id'] ?? null),
            'fecha_inicio' => $data['fecha_inicio'],
            'fecha_fin' => $data['fecha_fin'],
            'precio_base' => $data['precio_total'],
            'capacidad_maxima' => $data['capacidad'],
            'es_activo' => true,
        ];
    }

    /**
     * Una sesión no necesita que el usuario seleccione un día aparte: se
     * obtiene directamente de la fecha elegida. Se conserva dias_semana en
     * el horario porque es el formato común usado por agenda y conflictos.
     */
    private function normalizarDiasSesionUnica(array $data): array
    {
        if (empty($data['dias_semana'])
            && !empty($data['fecha_inicio'])
            && ($data['fecha_fin'] ?? null) === $data['fecha_inicio']) {
            $data['dias_semana'] = [Carbon::parse($data['fecha_inicio'])->dayOfWeekIso];
        }

        return $data;
    }

    private function mapearDatosActualizacion(array $data, CursoPersonalizado $curso): array
    {
        $mapped = [];

        if (array_key_exists('nombre', $data)) $mapped['nombre_instancia'] = $data['nombre'];
        if (array_key_exists('descripcion', $data)) $mapped['observaciones'] = $data['descripcion'];
        if (array_key_exists('docente_id', $data)) $mapped['docente_id'] = $data['docente_id'];
        if (array_key_exists('modalidad', $data)) {
            $mapped['modalidad'] = $data['modalidad'];
            if ($data['modalidad'] === 'virtual') $mapped['ciudad_id'] = null;
        }
        if (array_key_exists('ciudad_id', $data)
            && ($data['modalidad'] ?? $curso->modalidad) !== 'virtual') {
            $mapped['ciudad_id'] = $data['ciudad_id'];
        }
        if (array_key_exists('fecha_inicio', $data)) $mapped['fecha_inicio'] = $data['fecha_inicio'];
        if (array_key_exists('fecha_fin', $data)) $mapped['fecha_fin'] = $data['fecha_fin'];
        if (array_key_exists('precio_total', $data)) $mapped['precio_base'] = $data['precio_total'];
        if (array_key_exists('capacidad', $data)) $mapped['capacidad_maxima'] = $data['capacidad'];

        return $mapped;
    }

    private function sincronizarHorario(CursoPersonalizado $curso, array $data, bool $creating): void
    {
        $hasTimeData = array_key_exists('hora_inicio', $data) || array_key_exists('hora_fin', $data);
        if (!$hasTimeData) return;

        $horaInicio = $data['hora_inicio'] ?? $curso->horario?->hora_inicio;
        $horaFin = $data['hora_fin'] ?? $curso->horario?->hora_fin;
        if (!$horaInicio || !$horaFin) return;

        if ($curso->horario_id) {
            $horario = Horario::findOrFail($curso->horario_id);
            $horario->update([
                'hora_inicio' => $horaInicio,
                'hora_fin' => $horaFin,
                'nombre_referencial' => 'Horario de ' . $curso->nombre_instancia,
            ]);
            $horarioId = $horario->id;
        } else {
            $horarioId = (string) Str::uuid();
            Horario::create([
                'id' => $horarioId,
                'nombre_referencial' => 'Horario de ' . $curso->nombre_instancia,
                'hora_inicio' => $horaInicio,
                'hora_fin' => $horaFin,
                'es_activo' => true,
            ]);
            $curso->update(['horario_id' => $horarioId]);
        }

        if (array_key_exists('dias_semana', $data)) {
            HorarioDia::where('horario_id', $horarioId)->delete();
            foreach (($data['dias_semana'] ?? []) as $dia) {
                HorarioDia::create(['horario_id' => $horarioId, 'dia_semana' => $dia]);
            }
        }
    }

    private function validarConflictoDocente(array $data, InstructorConflictValidator $validator, ?CursoPersonalizado $curso = null): void
    {
        $docenteId = $data['docente_id'] ?? $curso?->docente_id;
        $fechaInicio = $data['fecha_inicio'] ?? $curso?->fecha_inicio?->toDateString();
        $fechaFin = $data['fecha_fin'] ?? $curso?->fecha_fin?->toDateString();
        $dias = $data['dias_semana'] ?? ($curso?->horario?->obtenerDiasSemana() ?? []);
        $horaInicio = $data['hora_inicio'] ?? $curso?->horario?->hora_inicio;
        $horaFin = $data['hora_fin'] ?? $curso?->horario?->hora_fin;

        // Sin docente o sin días definidos no existe información suficiente
        // para validar un conflicto de instructor.
        if (!$docenteId || !$fechaInicio || !$fechaFin || empty($dias) || !$horaInicio || !$horaFin) return;

        $resultado = $validator->validarCurso($docenteId, $fechaInicio, $fechaFin, $dias, $horaInicio, $horaFin, $curso?->id);
        if (!$resultado['valido']) {
            abort(409, 'Conflicto de horario detectado');
        }
    }

    private function toApi(CursoPersonalizado $curso): array
    {
        $ocupados = $curso->getAttribute('matriculas_ocupadas');
        if ($ocupados === null && $curso->relationLoaded('matriculas')) {
            $ocupados = $curso->matriculas->filter(fn (Matricula $m) =>
                in_array($m->estado, [Matricula::ESTADO_ACTIVO, Matricula::ESTADO_COMPLETADO], true)
            )->count();
        }
        $ocupados = (int) ($ocupados ?? $curso->obtenerCountMatriculas());
        $inicio = $curso->fecha_inicio?->toDateString();
        $fin = $curso->fecha_fin?->toDateString();
        $hoy = now()->toDateString();
        $estadoVisual = $fin && $fin < $hoy
            ? 'finalizado'
            : ($inicio && $inicio > $hoy ? 'proximo' : ($ocupados >= (int) $curso->capacidad_maxima ? 'lleno' : 'en_curso'));

        return [
            'id' => $curso->id,
            'es_personalizado' => (bool) $curso->es_personalizado,
            'nombre' => $curso->nombre_instancia,
            'descripcion' => $curso->observaciones,
            'docente_id' => $curso->docente_id,
            'docente' => $curso->docente ? [
                'id' => $curso->docente->id,
                'nombres' => $curso->docente->nombres,
                'apellidos' => $curso->docente->apellidos,
            ] : null,
            'modalidad' => $curso->modalidad,
            'ciudad_id' => $curso->ciudad_id,
            'ciudad' => $curso->ciudad?->nombre,
            'fecha_inicio' => $curso->fecha_inicio,
            'fecha_fin' => $curso->fecha_fin,
            'hora_inicio' => $curso->horario?->hora_inicio,
            'hora_fin' => $curso->horario?->hora_fin,
            'dias_semana' => $curso->horario?->obtenerDiasSemana() ?? [],
            'precio_total' => $curso->precio_base,
            'capacidad' => $curso->capacidad_maxima,
            'matriculados' => $ocupados,
            'cupos_disponibles' => max(0, (int) $curso->capacidad_maxima - $ocupados),
            'estado_visual' => $estadoVisual,
            'es_activo' => $curso->es_activo,
            'catalogo_curso_id' => $curso->catalogo_curso_id,
        ];
    }

    private function mapMatricula(Matricula $matricula, float $precioCurso): array
    {
        $cuenta = $matricula->cuentaPorCobrar;
        $total = $precioCurso;
        $abonado = $cuenta
            ? (float) $cuenta->monto_abonado
            : (float) ($matricula->relationLoaded('lineasPago')
                ? $matricula->lineasPago->sum('monto_abonado')
                : $matricula->lineasPago()->sum('monto_abonado'));
        $saldo = $cuenta
            ? max(0, (float) $cuenta->obtenerSaldoPendiente())
            : max(0, $total - $abonado);
        $estadoFinanciero = $cuenta?->estado
            ?? $matricula->lineasPago->first()?->estado;

        return [
            'matricula_id' => $matricula->id,
            'estudiante' => $matricula->estudiante ? [
                'id' => $matricula->estudiante->id,
                'nombre' => trim(($matricula->estudiante->nombres ?? '') . ' ' . ($matricula->estudiante->apellidos ?? '')),
                'identificacion' => $matricula->estudiante->cedula,
                'correo' => $matricula->estudiante->correo,
            ] : null,
            'estado_matricula' => $matricula->estado,
            'precio' => $total,
            'monto_pagado' => $abonado,
            'saldo_pendiente' => $saldo,
            'estado_financiero' => $estadoFinanciero,
            'cuenta_id' => $cuenta?->id,
        ];
    }

    private function resumenFinanciero($matriculas, float $precioCurso): array
    {
        $filas = $matriculas->map(fn (Matricula $matricula) => $this->mapMatricula($matricula, $precioCurso));
        return [
            'precio_por_estudiante' => $precioCurso,
            'total_esperado' => (float) $filas->sum('precio'),
            'total_abonado' => (float) $filas->sum('monto_pagado'),
            'saldo_pendiente' => (float) $filas->sum('saldo_pendiente'),
            'cuentas_pagadas' => $filas->where('estado_financiero', 'pagado')->count(),
            'cuentas_abonadas' => $filas->where('estado_financiero', 'abonado')->count(),
            'cuentas_pendientes' => $filas->where('estado_financiero', 'pendiente')->count(),
        ];
    }

    /**
     * Eliminar curso personalizado
     */
    public function destroy(string $id): JsonResponse
    {
        $curso = CursoPersonalizado::findOrFail($id);

        if ($curso->matriculas()->withTrashed()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar un curso personalizado que tiene matrículas asociadas.',
            ], 422);
        }

        $curso->delete();

        return response()->json(['message' => 'Curso personalizado eliminado'], 200);
    }

    /**
     * Obtener estadísticas del curso
     */
    public function estadisticas(string $id): JsonResponse
    {
        $curso = CursoPersonalizado::findOrFail($id);
        $matriculas = $curso->matriculas()
            ->with('cuentaPorCobrar', 'lineasPago', 'estudiante')
            ->get();

        return response()->json([
            'data' => array_merge($this->toApi($curso), ['finanzas' => $this->resumenFinanciero($matriculas, (float) $curso->precio_base)]),
        ]);
    }

    /**
     * Listar estudiantes inscritos
     */
    public function estudiantes(string $id, Request $request): JsonResponse
    {
        $curso = CursoPersonalizado::findOrFail($id);

        $estudiantes = $curso->matriculas()
            ->with('estudiante', 'cuentaPorCobrar', 'lineasPago')
            ->orderBy('fecha_matricula', 'desc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => $estudiantes->getCollection()->map(fn (Matricula $matricula) => $this->mapMatricula($matricula, (float) $curso->precio_base))->values(),
            'meta' => [
                'total' => $estudiantes->total(),
                'per_page' => $estudiantes->perPage(),
                'current_page' => $estudiantes->currentPage(),
                'last_page' => $estudiantes->lastPage(),
            ],
        ]);
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
        if ($curso->fecha_inicio <= now()->timezone('America/Guayaquil')->toDateString()) {
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
                'fecha_inscripcion' => now()->timezone('America/Guayaquil')->toDateString(),
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
            'participante_externo_id' => 'required|uuid|exists:pgsql.academic.participantes_externos,id',
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
