<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTallerRequest;
use App\Http\Requests\UpdateTallerRequest;
use App\Models\Taller;
use App\Models\AsistenciaTaller;
use App\Models\AsistenciaTallerEstudiante;
use App\Services\InstructorConflictValidator;
use Illuminate\Http\JsonResponse;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;
class TallerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Taller::query()->with(['ciudad', 'horarios', 'instructor:id,nombres,apellidos'])->withCount(['inscripciones as inscripciones_count' => function ($q) {
            $q->where('estado', 'activo');
        }]);

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('modalidad')) {
            $query->where('modalidad', $request->modalidad);
        }

        if ($request->filled('ciudad_id')) {
            $query->where('ciudad_id', $request->ciudad_id);
        }

        if ($request->filled('instructor_id')) {
            $query->where('instructor_id', $request->instructor_id);
        }

        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha', '>=', $request->fecha_desde);
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha', '<=', $request->fecha_hasta);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'ilike', "%{$search}%")
                  ->orWhere('descripcion', 'ilike', "%{$search}%");
            });
        }

        if ($request->filled('tab')) {
            if ($request->tab === 'proximos') {
                $query->where('fecha', '>', now()->subDays(7)->toDateString())
                      ->whereIn('estado', ['pendiente', 'confirmado', 'en_progreso']);
            } elseif ($request->tab === 'pasados') {
                $query->where(function ($q) {
                    $q->where('fecha', '<', now()->toDateString())
                      ->whereNull('fecha_fin')
                      ->orWhere('fecha_fin', '<', now()->toDateString());
                });
            } elseif ($request->tab === 'hoy') {
                $today = now()->toDateString();
                $query->where(function ($q) use ($today) {
                    $q->whereDate('fecha', '=', $today)
                      ->orWhere(function ($inner) use ($today) {
                          $inner->whereDate('fecha', '<=', $today)
                                ->whereDate('fecha_fin', '>=', $today);
                      });
                });
            }
        }

        $talleres = $query
            ->orderBy('fecha', $request->tab === 'pasados' ? 'desc' : 'asc')
            ->paginate($request->per_page ?? 50);

        return response()->json($talleres);
    }

    public function store(StoreTallerRequest $request, InstructorConflictValidator $validator): JsonResponse
    {
        $data = $request->validated();
        $horarios = $data['horarios'] ?? null;
        unset($data['horarios']);

        $fechas = $this->obtenerFechasRango($data['fecha'], $data['fecha_fin'] ?? null);

        if (!empty($data['fecha_fin'])) {
            $conflicto = $validator->validarTallerRango(
                $data['instructor_id'],
                $fechas,
                $horarios
            );
        } else {
            $conflicto = $validator->validarTaller(
                $data['instructor_id'],
                $data['fecha'],
                $data['hora_inicio'],
                $data['hora_fin']
            );
        }

        if (!$conflicto['valido']) {
            return response()->json([
                'mensaje' => 'Conflicto de horario detectado',
                'errores' => $conflicto['errores'],
            ], 409);
        }

        $taller = DB::transaction(function () use ($data, $horarios) {
            $taller = Taller::create($data);

            if (!empty($data['fecha_fin']) && !empty($horarios)) {
                foreach ($horarios as $h) {
                    $taller->horarios()->create([
                        'dia_semana' => $h['dia_semana'],
                        'hora_inicio' => $h['hora_inicio'],
                        'hora_fin' => $h['hora_fin'],
                        'aula' => $h['aula'] ?? null,
                        'capacidad' => $data['capacidad_maxima'] ?? 30,
                    ]);
                }
            }

            return $taller;
        });

        return response()->json(
            $taller->load(['instructor', 'ciudad', 'horarios']),
            201
        );
    }

    public function show(string $id): JsonResponse
    {
        $taller = Taller::with([
            'instructor',
            'ciudad',
            'inscripciones',
            'asistencias',
            'horarios',
        ])->findOrFail($id);

        return response()->json($taller);
    }

    public function update(UpdateTallerRequest $request, string $id, InstructorConflictValidator $validator): JsonResponse
    {
        $taller = Taller::findOrFail($id);
        $data = $request->validated();
        $horarios = $data['horarios'] ?? null;
        unset($data['horarios']);

        $needsValidation = $request->has('fecha') || $request->has('fecha_fin')
            || $request->has('hora_inicio') || $request->has('hora_fin')
            || $request->has('instructor_id') || !is_null($horarios);

        if ($needsValidation) {
            $instructorId = $data['instructor_id'] ?? $taller->instructor_id;
            $fechaInicio = $data['fecha'] ?? $taller->fecha;
            $fechaFin = array_key_exists('fecha_fin', $data) ? $data['fecha_fin'] : $taller->fecha_fin;
            $horaInicio = $data['hora_inicio'] ?? $taller->hora_inicio;
            $horaFin = $data['hora_fin'] ?? $taller->hora_fin;

            if (!empty($fechaFin)) {
                $fechas = $this->obtenerFechasRango(
                    $fechaInicio instanceof \Carbon\Carbon ? $fechaInicio->toDateString() : $fechaInicio,
                    $fechaFin instanceof \Carbon\Carbon ? $fechaFin->toDateString() : $fechaFin
                );
                $conflicto = $validator->validarTallerRango(
                    $instructorId,
                    $fechas,
                    $horarios ?? $taller->horarios->toArray()
                );
            } else {
                $fechaStr = $fechaInicio instanceof \Carbon\Carbon ? $fechaInicio->toDateString() : $fechaInicio;
                $conflicto = $validator->validarTaller(
                    $instructorId,
                    $fechaStr,
                    $horaInicio,
                    $horaFin,
                    $id
                );
            }

            if (!$conflicto['valido']) {
                return response()->json([
                    'mensaje' => 'Conflicto de horario detectado',
                    'errores' => $conflicto['errores'],
                ], 409);
            }
        }

        DB::transaction(function () use ($taller, $data, $horarios) {
            $taller->update($data);

            if (!is_null($horarios)) {
                $taller->horarios()->delete();
                foreach ($horarios as $h) {
                    $taller->horarios()->create([
                        'dia_semana' => $h['dia_semana'],
                        'hora_inicio' => $h['hora_inicio'],
                        'hora_fin' => $h['hora_fin'],
                        'aula' => $h['aula'] ?? null,
                        'capacidad' => $data['capacidad_maxima'] ?? $taller->capacidad_maxima ?? 30,
                    ]);
                }
            }
        });

        return response()->json($taller->fresh(['instructor', 'ciudad', 'horarios']));
    }

    private function obtenerFechasRango(string $fechaInicio, ?string $fechaFin): array
    {
        $fechas = [];
        $inicio = \Carbon\Carbon::parse($fechaInicio);
        $fin = $fechaFin ? \Carbon\Carbon::parse($fechaFin) : $inicio->copy();

        $current = $inicio->copy();
        while ($current->lte($fin)) {
            $fechas[] = $current->toDateString();
            $current->addDay();
        }

        return $fechas;
    }

    public function destroy(string $id): JsonResponse
    {
        $taller = Taller::findOrFail($id);
        $taller->delete();
        return response()->json(['mensaje' => 'Taller eliminado correctamente']);
    }

    public function asistenciaPDF(string $id): JsonResponse
    {
        $taller = Taller::with([
            'instructor',
            'ciudad',
            'inscripciones',
            'horarios',
        ])->findOrFail($id);

        // Sesiones de asistencia (fechas)
        $sesiones = AsistenciaTaller::where('taller_id', $id)
            ->orderBy('fecha_sesion')
            ->get();

        $fechas = $sesiones->pluck('fecha_sesion')->map(fn($d) => $d->format('Y-m-d'))->values();
        $sesionIds = $sesiones->pluck('id');

        // Construir un mapa: inscripcion_taller_id → { fecha_sesion → asistio }
        $asistenciaMap = [];
        if ($sesionIds->isNotEmpty()) {
            $registros = AsistenciaTallerEstudiante::whereIn('asistencia_taller_id', $sesionIds)->get();
            foreach ($registros as $r) {
                $key = $r->inscripcion_taller_id;
                $fecha = $sesiones->firstWhere('id', $r->asistencia_taller_id)?->fecha_sesion?->format('Y-m-d');
                if ($key && $fecha) {
                    $asistenciaMap[$key][$fecha] = $r->asistio;
                }
            }
        }

        // Participantes desde inscripciones
        $inscripciones = $taller->inscripciones()->where('estado', 'activo')->get();

        $participantes = $inscripciones->map(function ($ins) use ($fechas, $asistenciaMap) {
            $asistenciasArray = [];
            $conteoC = 0;
            $conteoF = 0;

            foreach ($fechas as $fecha) {
                $asistio = $asistenciaMap[$ins->id][$fecha] ?? null;
                if ($asistio === true) {
                    $asistenciasArray[] = 'X';
                    $conteoC++;
                } elseif ($asistio === false) {
                    $asistenciasArray[] = 'F';
                    $conteoF++;
                } else {
                    $asistenciasArray[] = null;
                }
            }

            return [
                'nombres' => $ins->nombres ?? '',
                'apellidos' => $ins->apellidos ?? '',
                'cedula' => $ins->cedula ?? '',
                'telefono' => $ins->telefono ?? '',
                'ciudad' => $ins->ciudad ?? '',
                'conteo_c' => $conteoC,
                'conteo_f' => $conteoF,
                'asistencias' => $asistenciasArray,
            ];
        });

        // Horario legible
        $horario = '';
        if ($taller->esMultiDia() && $taller->horarios->isNotEmpty()) {
            $dias = $taller->horarios
                ->sortBy('dia_semana')
                ->map(fn($h) => [1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'][(int)$h->dia_semana] ?? '')
                ->implode(', ');
            $hIni = $taller->hora_inicio ? substr($taller->hora_inicio, 0, 5) : '';
            $hFin = $taller->hora_fin ? substr($taller->hora_fin, 0, 5) : '';
            $horario = $dias ? "{$dias} {$hIni}-{$hFin}" : '';
        } elseif ($taller->hora_inicio) {
            $horario = substr($taller->hora_inicio, 0, 5) . '-' . substr($taller->hora_fin ?? '', 0, 5);
        }

        return response()->json([
            'info' => [
                'nombre' => $taller->nombre,
                'instructor' => $taller->instructor ? trim("{$taller->instructor->nombres} {$taller->instructor->apellidos}") : '',
                'ciudad' => $taller->ciudad?->nombre ?? '',
                'horario' => $horario,
                'fecha_inicio' => $taller->fecha?->format('Y-m-d'),
                'fecha_fin' => $taller->fecha_fin?->format('Y-m-d'),
            ],
            'modulos' => $fechas->isNotEmpty()
                ? [['nombre' => $taller->nombre, 'fechas' => $fechas]]
                : [],
            'participantes' => $participantes,
        ]);
    }

    public function estadisticas(string $id): JsonResponse
    {
        $taller = Taller::withCount(['inscripciones as inscripciones_count' => function ($q) {
            $q->where('estado', 'activo');
        }])->findOrFail($id);

        return response()->json([
            'id' => $taller->id,
            'nombre' => $taller->nombre,
            'total_inscritos' => $taller->inscripciones_count,
            'capacidad_disponible' => $taller->capacidadDisponible(),
            'tasa_ocupacion' => round($taller->tasaOcupacion(), 1),
            'ingreso_total' => $taller->inscripciones()->sum('monto_pagado'),
            'pagos_verificados' => $taller->inscripciones()->where('pago_verificado', true)->count(),
            'pagos_pendientes' => $taller->inscripciones()->where('pago_verificado', false)->count(),
            'estado' => $taller->estado,
            'permite_inscripcion' => $taller->permitirInscripcion(),
        ]);
    }

    public function cambiarEstadoMasivo(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => 'required|array|min:1|max:1000',
            'ids.*' => 'uuid|exists:pgsql.academic.talleres,id',
            'estado' => 'required|in:pendiente,confirmado,completado,cancelado',
        ]);

        $count = Taller::whereIn('id', $request->ids)->update(['estado' => $request->estado]);

        return response()->json([
            'mensaje' => "{$count} taller(es) actualizado(s)",
            'cantidad' => $count,
        ]);
    }
}
