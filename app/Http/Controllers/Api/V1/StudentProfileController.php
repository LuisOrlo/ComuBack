<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SolicitudInscripcion;
use App\Models\Matricula;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StudentProfileController extends Controller
{
    /**
     * GET /api/v1/mi-perfil/solicitudes
     * Ver mis solicitudes de inscripción
     */
    public function mySolicitudes(Request $request)
    {
        $user = auth()->user();

        $query = SolicitudInscripcion::where('persona_id', $user->id)
            ->with([
                'cursoAbierto:id,catalogo_id,precio_base,capacidad_maxima,estudiantes_inscritos',
                'cursoAbierto.catalogo:id,nombre',
            ]);

        // Filtrar por estado si se especifica
        if ($request->has('estado')) {
            $query->where('estado', $request->estado);
        }

        $query->orderByDesc('created_at');

        $perPage = $request->get('per_page', 15);
        $solicitudes = $query->paginate($perPage);

        return response()->json([
            'data' => $solicitudes->items(),
            'meta' => [
                'total' => $solicitudes->total(),
                'per_page' => $solicitudes->perPage(),
                'current_page' => $solicitudes->currentPage(),
                'last_page' => $solicitudes->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/mi-perfil/cursos-completados
     * Ver cursos completados
     */
    public function completedCourses(Request $request)
    {
        $user = auth()->user();

        $query = Matricula::where('estudiante_id', $user->id)
            ->where('estado', Matricula::ESTADO_COMPLETADO)
            ->with([
                'cursoAbierto:id,catalogo_id,precio_base',
                'cursoAbierto.catalogo:id,nombre,descripcion',
            ]);

        $query->orderByDesc('updated_at');

        $perPage = $request->get('per_page', 15);
        $matriculas = $query->paginate($perPage);

        return response()->json([
            'data' => $matriculas->items(),
            'meta' => [
                'total' => $matriculas->total(),
                'per_page' => $matriculas->perPage(),
                'current_page' => $matriculas->currentPage(),
                'last_page' => $matriculas->lastPage(),
            ],
        ]);
    }
}
