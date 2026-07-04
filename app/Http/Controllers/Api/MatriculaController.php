<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Matricula;
use App\Models\SolicitudInscripcion;
use App\Models\CursoAbierto;
use App\Services\RegistrationStateService;
use App\Http\Requests\StoreMatriculaRequest;
use App\Http\Requests\UpdateMatriculaRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class MatriculaController extends Controller
{
    public function index(Request $request)
    {
        $query = Matricula::query();

        if ($request->has('estudiante_id')) {
            $query->where('estudiante_id', $request->estudiante_id);
        }

        if ($request->has('curso_abierto_id')) {
            $query->where('curso_abierto_id', $request->curso_abierto_id);
        }

        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->has('activas') && $request->activas == 'true') {
            $query->activas();
        }

        $perPage = $request->get('per_page', 15);
        $matriculas = $query->with(['estudiante', 'cursoAbierto'])->paginate($perPage);

        return response()->json([
            'data' => $matriculas->items(),
            'meta' => [
                'total' => $matriculas->total(),
                'per_page' => $matriculas->perPage(),
                'current_page' => $matriculas->currentPage(),
                'last_page' => $matriculas->lastPage(),
            ]
        ]);
    }

    public function store(StoreMatriculaRequest $request)
    {
        $matricula = Matricula::create($request->validated());
        return response()->json(['data' => $matricula, 'message' => 'Creado exitosamente'], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $matricula = Matricula::with(['estudiante', 'cursoAbierto', 'horario', 'notas'])->findOrFail($id);
        return response()->json(['data' => $matricula]);
    }

    public function update(UpdateMatriculaRequest $request, $id)
    {
        $matricula = Matricula::with(['estudiante', 'cursoAbierto'])->findOrFail($id);
        $matricula->update($request->validated());
        return response()->json(['data' => $matricula, 'message' => 'Actualizado exitosamente']);
    }

    public function destroy($id)
    {
        Matricula::findOrFail($id)->delete();
        return response()->json(['message' => 'Eliminado exitosamente']);
    }

    public function notas($id)
    {
        $notas = Matricula::findOrFail($id)->notas()->with('modulo')->paginate(15);
        return response()->json(['data' => $notas->items(), 'meta' => ['total' => $notas->total()]]);
    }

    public function calificaciones($id)
    {
        $matricula = Matricula::with(['estudiante', 'cursoAbierto.modulos', 'notas.modulo'])->findOrFail($id);

        return response()->json([
            'data' => [
                'id' => $matricula->id,
                'estudiante' => $matricula->estudiante ? $matricula->estudiante->nombre : 'N/A',
                'curso' => $matricula->cursoAbierto ? $matricula->cursoAbierto->nombre_instancia : 'N/A',
                'promedio_simple' => $matricula->calcularPromedio(),
                'promedio_ponderado' => $matricula->calcularPromedioPonderado(),
                'total_notas_registradas' => $matricula->notas()->whereNotNull('calificacion')->count(),
                'total_modulos' => $matricula->cursoAbierto ? $matricula->cursoAbierto->modulos()->count() : 0,
                'todas_notas_registradas' => $matricula->tieneTotalNotasRegistradas(),
                'estado' => $matricula->obtenerDescripcionEstado(),
            ]
        ]);
    }

    public function cambiosHorario($id)
    {
        $cambios = Matricula::findOrFail($id)->cambiosHorario()->with(['cursoAbiertoAntiguo', 'cursoAbiertoNuevo'])->paginate(15);
        return response()->json(['data' => $cambios->items(), 'meta' => ['total' => $cambios->total()]]);
    }

    public function inscribirDesdePerfil(Request $request)
    {
        $request->validate([
            'estudiante_id' => 'required|uuid|exists:people.personas,id',
            'curso_abierto_id' => 'required|uuid|exists:academic.cursos_abiertos,id',
            'pagos' => 'required|array|min:1',
            'pagos.*.modulo_id' => 'required|uuid|exists:academic.modulos,id',
            'pagos.*.monto' => 'required|numeric|min:0.01',
            'pagos.*.monto_ajustado' => 'nullable|numeric|min:0',
            'pagos.*.motivo_ajuste' => 'nullable|string|max:255',
            'metodo_pago' => 'required|string|in:efectivo,transferencia,deposito,tarjeta,otro',
        ]);

        $curso = CursoAbierto::findOrFail($request->curso_abierto_id);

        $solicitud = DB::transaction(function () use ($request, $curso) {
            $solicitud = SolicitudInscripcion::create([
                'persona_id' => $request->estudiante_id,
                'curso_abierto_id' => $request->curso_abierto_id,
                'monto_solicitado' => collect($request->pagos)->sum('monto'),
                'tipo_pago' => count($request->pagos) > 1 || $request->pagos[0]['monto'] < ($curso->precio_base ?? 0) ? 'abono' : 'completo',
                'estado' => 'pendiente_validacion',
                'es_participante_externo' => false,
            ]);

            $stateService = app(RegistrationStateService::class);
            $resultado = $stateService->approve(
                $solicitud,
                auth()->user()->persona_id ?? null,
                null,
                $request->pagos,
                $request->metodo_pago
            );

            if (!$resultado['exito']) {
                throw new \Exception($resultado['mensaje']);
            }

            return $solicitud;
        });

        $matricula = Matricula::where('solicitud_inscripcion_id', $solicitud->id)->first();

        return response()->json([
            'mensaje' => 'Estudiante inscrito exitosamente',
            'data' => [
                'solicitud_id' => $solicitud->id,
                'matricula_id' => $matricula?->id,
            ],
        ], Response::HTTP_CREATED);
    }
}
