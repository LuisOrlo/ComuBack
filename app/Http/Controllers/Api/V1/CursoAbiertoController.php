<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CursoAbierto;
use App\Models\Horario;
use App\Models\HorarioDia;
use App\Models\Clase;
use App\Http\Requests\StoreCursoAbiertoRequest;
use App\Http\Requests\UpdateCursoAbiertoRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CursoAbiertoController extends Controller
{
    public function index(Request $request)
    {
        $query = CursoAbierto::with(['catalogo', 'docente', 'ciudad', 'modulos', 'matriculas'])
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

        if ($request->has('activos') && $request->activos == 'true') {
            $query->activos();
        }

        if ($request->has('buscar')) {
            $query->buscar($request->buscar);
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

    public function store(StoreCursoAbiertoRequest $request)
    {
        $data = $request->validated();

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
        $modulosRecibidos = $request->input('modulos', []);
        if (!empty($modulosRecibidos)) {
            foreach ($modulosRecibidos as $i => $mod) {
                \App\Models\Modulo::create([
                    'curso_abierto_id' => $curso->id,
                    'nombre_modulo' => $mod['nombre'] ?? ('Módulo ' . ($i + 1)),
                    'numero_orden' => $i + 1,
                    'fecha_inicio' => $mod['fecha_inicio'] ?? null,
                    'fecha_fin' => $mod['fecha_fin'] ?? null,
                ]);
            }
        } else {
            $numModulos = $catalogo ? ($catalogo->modulos_default ?: 2) : 2;
            for ($i = 1; $i <= $numModulos; $i++) {
                \App\Models\Modulo::create([
                    'curso_abierto_id' => $curso->id,
                    'nombre_modulo' => 'Módulo ' . $i,
                    'numero_orden' => $i,
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

    public function update(UpdateCursoAbiertoRequest $request, $id)
    {
        $curso = CursoAbierto::findOrFail($id);
        $data = $request->validated();

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
