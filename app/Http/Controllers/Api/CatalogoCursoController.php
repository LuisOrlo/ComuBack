<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogoCurso;
use App\Models\CursoAbierto;
use App\Http\Requests\StoreCatalogoCursoRequest;
use App\Http\Requests\UpdateCatalogoCursoRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class CatalogoCursoController extends Controller
{
    public function index(Request $request)
    {
        $query = CatalogoCurso::query();

        if ($request->has('programa_id')) {
            $query->where('programa_id', $request->programa_id);
        }

        if ($request->has('categoria')) {
            $query->where('categoria', $request->categoria);
        }

        if ($request->has('activos') && $request->activos == 'true') {
            $query->activos();
        }

        if ($request->has('buscar')) {
            $query->buscar($request->buscar);
        }

        $perPage = $request->get('per_page', 15);
        $catalogos = $query->paginate($perPage);

        return response()->json([
            'data' => $catalogos->items(),
            'meta' => [
                'total' => $catalogos->total(),
                'per_page' => $catalogos->perPage(),
                'current_page' => $catalogos->currentPage(),
                'last_page' => $catalogos->lastPage(),
            ]
        ]);
    }

    public function store(StoreCatalogoCursoRequest $request)
    {
        $catalogo = CatalogoCurso::create($request->validated());
        return response()->json(['data' => $catalogo, 'message' => 'Creado exitosamente'], Response::HTTP_CREATED);
    }

    public function show($id)
    {
        $catalogo = CatalogoCurso::findOrFail($id);
        return response()->json(['data' => $catalogo]);
    }

    public function update(UpdateCatalogoCursoRequest $request, $id)
    {
        $catalogo = CatalogoCurso::findOrFail($id);
        $catalogo->update($request->validated());
        return response()->json(['data' => $catalogo, 'message' => 'Actualizado exitosamente']);
    }

    public function destroy($id)
    {
        CatalogoCurso::findOrFail($id)->delete();
        return response()->json(['message' => 'Eliminado exitosamente']);
    }

    /**
     * POST /api/academic/catalogos-cursos/upload-imagen
     * Subir imagen para un catálogo
     */
    public function uploadImagen(Request $request)
    {
        $request->validate([
            'imagen' => 'required|image|mimes:jpeg,png,jpg,webp|max:2048',
        ]);

        $path = $request->file('imagen')->store('catalogos');
        $url = Storage::disk()->url($path);

        return response()->json([
            'data' => ['url' => $url, 'path' => $path],
            'message' => 'Imagen subida exitosamente'
        ]);
    }

    /**
     * GET /api/catalogo-cursos/disponibles
     * Listar cursos abiertos disponibles (sin llenos, no en pasado)
     * Public endpoint para el formulario de registro
     */
    public function disponibles(Request $request)
    {
        $query = CursoAbierto::with('catalogo:id,nombre,descripcion,categoria,color')
            ->where('estado', '!=', 'cancelado');

        // Filtrar por cursos que aún no se han iniciado o están en progreso
        $query->where(function ($q) {
            $q->whereNull('fecha_inicio')
              ->orWhere('fecha_inicio', '>', Carbon::now());
        });

        // Filtrar por capacidad disponible
        $query->whereRaw('capacidad_maxima > estudiantes_inscritos');

        // Filtro por modalidad
        if ($request->has('modalidad')) {
            $query->where('modalidad', $request->modalidad);
        }

        // Filtro por ciudad
        if ($request->has('ciudad_id')) {
            $query->where('ciudad_id', $request->ciudad_id);
        }

        // Filtro por categoría del catálogo
        if ($request->has('categoria')) {
            $query->whereHas('catalogo', function ($q) use ($request) {
                $q->where('categoria', $request->categoria);
            });
        }

        // Búsqueda en nombre del catálogo
        if ($request->has('search')) {
            $query->whereHas('catalogo', function ($q) use ($request) {
                $q->where('nombre', 'ilike', '%' . $request->search . '%')
                  ->orWhere('descripcion', 'ilike', '%' . $request->search . '%');
            });
        }

        // Ordenar por fecha de inicio
        $query->orderBy('fecha_inicio', 'asc');

        $perPage = $request->get('per_page', 15);
        $cursos = $query->paginate($perPage);

        // Formatear respuesta con capacidad disponible
        $cursos->setCollection($cursos->getCollection()->map(function ($curso) {
            return [
                'id' => $curso->id,
                'catalogo' => $curso->catalogo,
                'modalidad' => $curso->modalidad,
                'precio_base' => $curso->precio_base,
                'capacidad' => [
                    'maxima' => $curso->capacidad_maxima,
                    'inscritos' => $curso->estudiantes_inscritos,
                    'disponible' => $curso->capacidad_maxima - $curso->estudiantes_inscritos,
                ],
                'fechas' => [
                    'inicio' => $curso->fecha_inicio,
                    'fin_estimada' => $curso->fecha_fin_estimada,
                ],
                'estado' => $curso->estado,
            ];
        }));

        return response()->json([
            'data' => $cursos->items(),
            'meta' => [
                'total' => $cursos->total(),
                'per_page' => $cursos->perPage(),
                'current_page' => $cursos->currentPage(),
                'last_page' => $cursos->lastPage(),
            ],
        ]);
    }
}
