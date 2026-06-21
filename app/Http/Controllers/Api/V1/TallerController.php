<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTallerRequest;
use App\Http\Requests\UpdateTallerRequest;
use App\Models\Taller;
use App\Services\InstructorConflictValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TallerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Taller::query()->with(['instructor'])->withCount(['inscripciones as inscripciones_count' => function ($q) {
            $q->where('estado', 'activo');
        }]);

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('modalidad')) {
            $query->where('modalidad', $request->modalidad);
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
                $query->where('fecha', '>=', now()->toDateString())
                      ->whereIn('estado', ['pendiente', 'confirmado']);
            } elseif ($request->tab === 'pasados') {
                $query->where('fecha', '<', now()->toDateString());
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

        $taller = Taller::create($data);

        if (!empty($data['fecha_fin']) && !empty($horarios)) {
            foreach ($horarios as $h) {
                $taller->horarios()->create([
                    'dia_semana' => $h['dia_semana'],
                    'hora_inicio' => $h['hora_inicio'],
                    'hora_fin' => $h['hora_fin'],
                    'aula' => $h['aula'] ?? null,
                ]);
            }
        }

        return response()->json(
            $taller->load(['instructor', 'horarios']),
            201
        );
    }

    public function show(string $id): JsonResponse
    {
        $taller = Taller::with([
            'instructor',
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

        $taller->update($data);

        if (!is_null($horarios)) {
            $taller->horarios()->delete();
            foreach ($horarios as $h) {
                $taller->horarios()->create([
                    'dia_semana' => $h['dia_semana'],
                    'hora_inicio' => $h['hora_inicio'],
                    'hora_fin' => $h['hora_fin'],
                    'aula' => $h['aula'] ?? null,
                ]);
            }
        }

        return response()->json($taller->load(['instructor', 'horarios']));
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
            'ids.*' => 'uuid|exists:academic.talleres,id',
            'estado' => 'required|in:pendiente,confirmado,completado,cancelado',
        ]);

        $count = Taller::whereIn('id', $request->ids)->update(['estado' => $request->estado]);

        return response()->json([
            'mensaje' => "{$count} taller(es) actualizado(s)",
            'cantidad' => $count,
        ]);
    }
}
