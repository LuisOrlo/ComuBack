<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CursoAbierto;
use App\Models\Horario;
use App\Models\HorarioDia;
use App\Models\Clase;
use App\Models\Modulo;
use App\Http\Requests\StoreCursoAbiertoRequest;
use App\Http\Requests\UpdateCursoAbiertoRequest;
use App\Services\InstructorConflictValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CursoAbiertoController extends Controller
{
    public function index(Request $request)
    {
        $query = CursoAbierto::with([
            'catalogo:id,nombre,categoria',
            'ciudad:id,nombre',
            'horario.diasSemana',
            'docente:id,nombres,apellidos',
        ])
            ->withCount(['matriculas as estudiantes_inscritos']);

        if ($request->has('catalogo_curso_id')) {
            $query->where('catalogo_curso_id', $request->catalogo_curso_id);
        }

        if ($request->has('semestre')) {
            $query->where('semestre', $request->semestre);
        }

        if ($request->has('docente_id')) {
            $query->where('docente_id', $request->docente_id);
        }

        if ($request->has('vigentes') && $request->vigentes == 'true') {
            $query->vigentes();
        }

        if ($request->has('dias_desde_inicio') && is_numeric($request->dias_desde_inicio)) {
            $dias = max(0, (int) $request->dias_desde_inicio);
            $query->where('fecha_inicio', '>', now()->subDays($dias));
        } elseif ($request->has('no_iniciados') && $request->no_iniciados == 'true') {
            $query->where('fecha_inicio', '>', now());
        }

        if ($request->has('modalidad')) {
            $query->where('modalidad', $request->modalidad);
        }

        if ($request->has('categoria')) {
            $query->whereHas('catalogo', fn($q) => $q->where('categoria', $request->categoria));
        }

        if ($request->has('ciudad_id')) {
            $query->where('ciudad_id', $request->ciudad_id);
        }

        if ($request->has('activos') && $request->activos == 'true') {
            $query->activos();
        }

        $search = $request->get('buscar') ?? $request->get('search');
        if ($search) {
            $query->buscar($search);
        }

        $perPage = $request->get('per_page', 15);
        $cursos = $query->paginate($perPage);

        return response()->json([
            'data' => $cursos->items(),
            'meta' => [
                'total' => $cursos->total(),
                'per_page' => $cursos->perPage(),
                'current_page' => $cursos->currentPage(),
                'last_page' => $cursos->lastPage(),
            ]
        ]);
    }

    public function store(StoreCursoAbiertoRequest $request, InstructorConflictValidator $conflictValidator)
    {
        $data = $request->validated();

        // Validar conflicto de instructor si hay datos de programación completos
        if (!empty($data['docente_id']) && !empty($data['fecha_inicio']) && !empty($data['fecha_fin'])
            && !empty($data['dias_semana']) && !empty($data['hora_inicio']) && !empty($data['hora_fin'])) {

            $conflicto = $conflictValidator->validarCurso(
                $data['docente_id'],
                $data['fecha_inicio'],
                $data['fecha_fin'],
                $data['dias_semana'],
                $data['hora_inicio'],
                $data['hora_fin']
            );

            if (!$conflicto['valido']) {
                return response()->json([
                    'mensaje' => 'Conflicto de horario detectado',
                    'errores' => $conflicto['errores'],
                ], 409);
            }
        }

        // Si se enviaron horas, crear un Horario y vincularlo
        if (!empty($data['hora_inicio']) && !empty($data['hora_fin'])) {
            $horarioId = (string) \Illuminate\Support\Str::uuid();
            $dias = !empty($data['dias_semana']) ? $data['dias_semana'] : [1, 2, 3, 4, 5];
            
            // Insertar horario
            Horario::create([
                'id' => $horarioId,
                'nombre_referencial' => 'Horario de ' . ($data['nombre_instancia'] ?? 'Curso'),
                'hora_inicio' => $data['hora_inicio'],
                'hora_fin' => $data['hora_fin'],
                'es_activo' => true,
            ]);
            
            // Crear registros de días en horarios_dias
            foreach ($dias as $dia) {
                HorarioDia::create([
                    'horario_id' => $horarioId,
                    'dia_semana' => $dia,
                ]);
            }
            
            $data['horario_id'] = $horarioId;
            unset($data['hora_inicio'], $data['hora_fin']);
        } else {
            unset($data['hora_inicio'], $data['hora_fin']);
        }
        unset($data['dias_semana']);

        // Precio base obligatorio en DB, default 0 si no se envía
        if (empty($data['precio_base'])) {
            $data['precio_base'] = 0;
        }

        $curso = CursoAbierto::create($data);

        $catalogo = \App\Models\CatalogoCurso::find($data['catalogo_curso_id']);

        // Los talleres no tienen módulos
        if ($catalogo && $catalogo->categoria === 'taller') {
            return response()->json(['data' => $curso, 'message' => 'Creado exitosamente'], Response::HTTP_CREATED);
        }

        // Crear módulos si se enviaron en el request, sino usar default del catálogo
        $precioBase = $data['precio_base'] ?? 0;
        $modulosRecibidos = $request->input('modulos', []);
        if (!empty($modulosRecibidos)) {
            foreach ($modulosRecibidos as $i => $mod) {
                \App\Models\Modulo::create([
                    'curso_abierto_id' => $curso->id,
                    'nombre_modulo' => $mod['nombre'] ?? ('Módulo ' . ($i + 1)),
                    'numero_orden' => $i + 1,
                    'fecha_inicio' => $mod['fecha_inicio'] ?? null,
                    'fecha_fin' => $mod['fecha_fin'] ?? null,
                    'precio_base' => $mod['precio_base'] ?? $precioBase,
                ]);
            }
        } else {
            $numModulos = $catalogo ? ($catalogo->modulos_default ?: 2) : 2;
            for ($i = 1; $i <= $numModulos; $i++) {
                \App\Models\Modulo::create([
                    'curso_abierto_id' => $curso->id,
                    'nombre_modulo' => 'Módulo ' . $i,
                    'numero_orden' => $i,
                    'precio_base' => $precioBase,
                ]);
            }
        }

        // Generar clases automaticamente si hay modulos con fechas y dias
        $diasSemana = $request->input('dias_semana', []);
        $horaInicio = $request->input('hora_inicio');
        $horaFin = $request->input('hora_fin');
        if (!empty($diasSemana) && $horaInicio && $horaFin) {
            $curso->refresh()->load('modulos');
            $this->generarClasesParaCurso($curso, $diasSemana, $horaInicio, $horaFin);
        }

        return response()->json(['data' => $curso, 'message' => 'Creado exitosamente'], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $curso = CursoAbierto::with(['catalogo', 'docente', 'ciudad', 'horario.diasSemana', 'matriculas', 'modulos'])->findOrFail($id);
        return response()->json(['data' => $curso]);
    }

    public function update(UpdateCursoAbiertoRequest $request, $id, InstructorConflictValidator $conflictValidator)
    {
        $curso = CursoAbierto::findOrFail($id);
        $data = $request->validated();

        // Validar conflicto de instructor si se están cambiando datos de programación
        $docenteId = $data['docente_id'] ?? $curso->docente_id;
        $fechaInicio = $data['fecha_inicio'] ?? ($curso->fecha_inicio ? $curso->fecha_inicio->toDateString() : null);
        $fechaFin = $data['fecha_fin'] ?? ($curso->fecha_fin ? $curso->fecha_fin->toDateString() : null);
        $diasSemana = $request->input('dias_semana', []);
        $horaInicio = $data['hora_inicio'] ?? ($request->input('hora_inicio'));
        $horaFin = $data['hora_fin'] ?? ($request->input('hora_fin'));

        $hasScheduleChange = $request->has('docente_id') || $request->has('fecha_inicio')
            || $request->has('fecha_fin') || $request->has('dias_semana')
            || $request->has('hora_inicio') || $request->has('hora_fin');

        if ($hasScheduleChange && $docenteId && $fechaInicio && $fechaFin
            && !empty($diasSemana) && $horaInicio && $horaFin) {

            $conflicto = $conflictValidator->validarCurso(
                $docenteId,
                $fechaInicio,
                $fechaFin,
                $diasSemana,
                $horaInicio,
                $horaFin
            );

            if (!$conflicto['valido']) {
                return response()->json([
                    'mensaje' => 'Conflicto de horario detectado',
                    'errores' => $conflicto['errores'],
                ], 409);
            }
        }

        // Si se enviaron horas, crear/actualizar Horario
        if (!empty($data['hora_inicio']) && !empty($data['hora_fin'])) {
            $dias = !empty($data['dias_semana']) ? $data['dias_semana'] : [1, 2, 3, 4, 5];
            
            if ($curso->horario_id) {
                // Actualizar horario existente
                $horario = Horario::findOrFail($curso->horario_id);
                $horario->update([
                    'hora_inicio' => $data['hora_inicio'],
                    'hora_fin' => $data['hora_fin'],
                ]);
                
                // Eliminar días existentes y crear nuevos
                HorarioDia::where('horario_id', $curso->horario_id)->delete();
                foreach ($dias as $dia) {
                    HorarioDia::create([
                        'horario_id' => $curso->horario_id,
                        'dia_semana' => $dia,
                    ]);
                }
            } else {
                // Crear nuevo horario
                $horarioId = (string) \Illuminate\Support\Str::uuid();
                Horario::create([
                    'id' => $horarioId,
                    'nombre_referencial' => 'Horario de ' . ($data['nombre_instancia'] ?? 'Curso'),
                    'hora_inicio' => $data['hora_inicio'],
                    'hora_fin' => $data['hora_fin'],
                    'es_activo' => true,
                ]);
                
                foreach ($dias as $dia) {
                    HorarioDia::create([
                        'horario_id' => $horarioId,
                        'dia_semana' => $dia,
                    ]);
                }
                
                $data['horario_id'] = $horarioId;
            }
            unset($data['hora_inicio'], $data['hora_fin']);
        }
        unset($data['dias_semana']);

        $curso->update($data);

        // Sincronizar módulos si se enviaron en el request
        $modulosRecibidos = $request->input('modulos');
        if ($modulosRecibidos !== null) {
            $catalogo = \App\Models\CatalogoCurso::find($curso->catalogo_curso_id);

            if (!$catalogo || $catalogo->categoria !== 'taller') {
                $existingIds = $curso->modulos()->pluck('id')->toArray();
                $newIds = [];

                foreach ($modulosRecibidos as $i => $mod) {
                    $modId = $mod['id'] ?? null;
                    $cursoPrecio = $data['precio_base'] ?? $curso->precio_base;
                    $modData = [
                        'nombre_modulo' => $mod['nombre'] ?? ('Módulo ' . ($i + 1)),
                        'numero_orden' => $i + 1,
                        'fecha_inicio' => $mod['fecha_inicio'] ?? null,
                        'fecha_fin' => $mod['fecha_fin'] ?? null,
                        'precio_base' => $mod['precio_base'] ?? $cursoPrecio,
                    ];

                    if ($modId && in_array($modId, $existingIds)) {
                        Modulo::where('id', $modId)
                              ->where('curso_abierto_id', $curso->id)
                              ->update($modData);
                        $newIds[] = $modId;
                    } else {
                        $nuevoMod = Modulo::create(array_merge($modData, [
                            'curso_abierto_id' => $curso->id,
                        ]));
                        $newIds[] = $nuevoMod->id;
                    }
                }

                $toDelete = array_diff($existingIds, $newIds);
                if (!empty($toDelete)) {
                    Clase::whereIn('modulo_id', $toDelete)->delete();
                    Modulo::whereIn('id', $toDelete)->delete();
                }

                $curso->refresh();
            }
        }

        // Regenerar clases si hay modulos con fechas y dias definidos
        $diasSemana = $request->input('dias_semana', []);
        $horaInicio = $request->input('hora_inicio');
        $horaFin = $request->input('hora_fin');
        if (!empty($diasSemana) && $horaInicio && $horaFin) {
            $this->generarClasesParaCurso($curso, $diasSemana, $horaInicio, $horaFin);
        }

        return response()->json(['data' => $curso, 'message' => 'Actualizado exitosamente']);
    }

    private function generarClasesParaCurso(CursoAbierto $curso, array $diasSemana, string $horaInicio, string $horaFin): void
    {
        $modulos = $curso->modulos()->orderBy('numero_orden')->get();

        foreach ($modulos as $modulo) {
            // Eliminar clases existentes de este modulo para regenerarlas
            Clase::where('modulo_id', $modulo->id)->delete();

            if (!$modulo->fecha_inicio || !$modulo->fecha_fin) {
                continue;
            }

            $inicio = \Carbon\Carbon::parse($modulo->fecha_inicio);
            $fin = \Carbon\Carbon::parse($modulo->fecha_fin);

            // Generar clase para cada dia de la semana seleccionado dentro del rango del modulo
            $fecha = $inicio->copy();
            while ($fecha->lte($fin)) {
                $diaSemana = (int) $fecha->format('N'); // 1=Lun, 7=Dom
                if (in_array($diaSemana, $diasSemana)) {
                    Clase::create([
                        'modulo_id' => $modulo->id,
                        'instructor_id' => $curso->docente_id,
                        'fecha_clase' => $fecha->format('Y-m-d'),
                        'hora_inicio' => $horaInicio,
                        'hora_fin' => $horaFin,
                    ]);
                }
                $fecha->addDay();
            }
        }
    }

    public function destroy($id)
    {
        CursoAbierto::findOrFail($id)->delete();
        return response()->json(['message' => 'Eliminado exitosamente']);
    }

    public function horarios($id)
    {
        $curso = CursoAbierto::findOrFail($id);
        $horario = $curso->horario;
        $data = $horario ? [$horario] : [];
        return response()->json(['data' => $data, 'meta' => ['total' => count($data)]]);
    }

    public function matriculas($id)
    {
        $curso = CursoAbierto::findOrFail($id);
        $matriculas = $curso->matriculas()->with(['estudiante', 'solicitudInscripcion.estudiante', 'solicitudInscripcion.participanteExterno'])->paginate(15);
        return response()->json(['data' => $matriculas->items(), 'meta' => ['total' => $matriculas->total()]]);
    }

    public function modulos($id)
    {
        $curso = CursoAbierto::findOrFail($id);
        $modulos = $curso->modulos()->paginate(15);
        return response()->json(['data' => $modulos->items(), 'meta' => ['total' => $modulos->total()]]);
    }

    public function exportar($id, Request $request)
    {
        $curso = CursoAbierto::findOrFail($id);
        $matriculas = $curso->matriculas()
            ->with(['estudiante', 'solicitudInscripcion.estudiante', 'solicitudInscripcion.participanteExterno'])
            ->get();

        $rows = $matriculas->map(function ($m) {
            $nombres = $m->estudiante->nombres ?? $m->solicitudInscripcion?->estudiante?->nombres ?? $m->solicitudInscripcion?->participanteExterno?->nombres ?? '—';
            $apellidos = $m->estudiante->apellidos ?? $m->solicitudInscripcion?->estudiante?->apellidos ?? $m->solicitudInscripcion?->participanteExterno?->apellidos ?? '';
            $cedula = $m->estudiante->cedula ?? $m->solicitudInscripcion?->estudiante?->cedula ?? $m->solicitudInscripcion?->participanteExterno?->cedula ?? '—';
            $correo = $m->estudiante->correo ?? $m->solicitudInscripcion?->estudiante?->correo ?? $m->solicitudInscripcion?->participanteExterno?->correo ?? '—';
            $fecha = $m->fecha_inscripcion ? \Carbon\Carbon::parse($m->fecha_inscripcion)->format('d/m/Y') : '—';
            return compact('nombres', 'apellidos', 'cedula', 'correo', 'fecha');
        });

        $formato = $request->get('formato', 'csv');

        if ($formato === 'pdf') {
            $html = view('exports.participantes-curso', [
                'curso' => $curso,
                'rows' => $rows,
            ])->render();

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
            return $pdf->download("participantes_{$curso->id}.pdf");
        }

        // CSV
        $csvHeader = "Nombres,Apellidos,Cédula,Correo,Fecha Inscripción\n";
        $csvBody = $rows->map(fn($r) => implode(',', [
            '"' . str_replace('"', '""', $r['nombres']) . '"',
            '"' . str_replace('"', '""', $r['apellidos']) . '"',
            $r['cedula'],
            $r['correo'],
            $r['fecha'],
        ]))->implode("\n");

        $csv = $csvHeader . $csvBody;

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"participantes_{$curso->id}.csv\"",
        ]);
    }

    public function estadisticas($id)
    {
        $curso = CursoAbierto::findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $curso->id,
                'nombre' => $curso->nombre_instancia,
                'matriculas_totales' => $curso->obtenerCountMatriculas(),
                'espacios_disponibles' => $curso->obtenerEspaciosDisponibles(),
                'porcentaje_ocupacion' => $curso->getPorcentajeOcupacion(),
                'esta_lleno' => $curso->estaLleno(),
                'esta_vigente' => $curso->estaVigente(),
            ]
        ]);
    }
}
