<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentoArchivo;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentoArchivoController extends Controller
{
    /**
     * Listado administrativo de documentos con KPIs y filtros
     * GET /api/documentos
     */
    public function index(Request $request)
    {
        $buscar = $request->query('buscar');
        $estado = $request->query('estado', 'todos');
        $perPage = (int) $request->query('per_page', 15);

        // KPIs globales para el panel administrativo
        $kpis = [
            'total' => DocumentoArchivo::count(),
            'publicos' => DocumentoArchivo::where('es_publico', true)->count(),
            'ocultos' => DocumentoArchivo::where('es_publico', false)->count(),
            'total_descargas' => (int) DocumentoArchivo::sum('descargas_count'),
        ];

        $query = DocumentoArchivo::query()
            ->select('id', 'nombre', 'descripcion', 'archivo_path', 'archivo_nombre_original', 'es_publico', 'descargas_count', 'created_at', 'updated_at')
            ->buscar($buscar)
            ->orderBy('created_at', 'desc');

        if ($estado === 'publicos') {
            $query->where('es_publico', true);
        } elseif ($estado === 'ocultos') {
            $query->where('es_publico', false);
        }

        $documentos = $perPage > 0 ? $query->paginate($perPage) : $query->get();

        return response()->json([
            'success' => true,
            'data' => [
                'documentos' => $documentos,
                'kpis' => $kpis,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Subir y registrar un nuevo documento PDF
     * POST /api/documentos
     */
    public function store(Request $request)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:pdf',
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'es_publico' => 'nullable',
        ], [
            'archivo.required' => 'El archivo PDF es obligatorio.',
            'archivo.file' => 'El archivo enviado no es válido.',
            'archivo.mimes' => 'Solo se permite subir archivos en formato PDF.',
            'nombre.required' => 'El nombre del documento es obligatorio.',
        ]);

        $file = $request->file('archivo');
        $originalName = $file->getClientOriginalName();
        $filename = Str::uuid() . '.pdf';
        $path = $file->storeAs('documentos', $filename, 'public');

        $esPublico = $request->has('es_publico')
            ? filter_var($request->input('es_publico'), FILTER_VALIDATE_BOOLEAN)
            : true;

        $documento = DocumentoArchivo::create([
            'nombre' => trim($request->input('nombre')),
            'descripcion' => $request->input('descripcion') ? trim($request->input('descripcion')) : null,
            'archivo_path' => $path,
            'archivo_nombre_original' => $originalName,
            'es_publico' => $esPublico,
        ]);

        return response()->json([
            'success' => true,
            'data' => $documento,
            'message' => 'Documento PDF registrado correctamente.',
        ], Response::HTTP_CREATED);
    }

    /**
     * Ver detalle de un documento
     * GET /api/documentos/{id}
     */
    public function show(string $id)
    {
        $documento = DocumentoArchivo::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $documento,
        ], Response::HTTP_OK);
    }

    /**
     * Actualizar información o reemplazar el PDF
     * POST /api/documentos/{id} (o PUT)
     */
    public function update(Request $request, string $id)
    {
        $documento = DocumentoArchivo::findOrFail($id);

        $request->validate([
            'archivo' => 'nullable|file|mimes:pdf',
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'es_publico' => 'nullable',
        ], [
            'archivo.file' => 'El archivo enviado no es válido.',
            'archivo.mimes' => 'Solo se permite subir archivos en formato PDF.',
            'nombre.required' => 'El nombre del documento es obligatorio.',
        ]);

        // Si se envió un nuevo archivo PDF, reemplazar el anterior
        if ($request->hasFile('archivo')) {
            $file = $request->file('archivo');

            // Limpieza física del archivo anterior para evitar archivos huérfanos
            if ($documento->archivo_path && Storage::disk('public')->exists($documento->archivo_path)) {
                Storage::disk('public')->delete($documento->archivo_path);
            }

            $filename = Str::uuid() . '.pdf';
            $documento->archivo_path = $file->storeAs('documentos', $filename, 'public');
            $documento->archivo_nombre_original = $file->getClientOriginalName();
        }

        $documento->nombre = trim($request->input('nombre'));
        $documento->descripcion = $request->input('descripcion') ? trim($request->input('descripcion')) : null;

        if ($request->has('es_publico')) {
            $documento->es_publico = filter_var($request->input('es_publico'), FILTER_VALIDATE_BOOLEAN);
        }

        $documento->save();

        return response()->json([
            'success' => true,
            'data' => $documento,
            'message' => 'Documento actualizado correctamente.',
        ], Response::HTTP_OK);
    }

    /**
     * Alternar visibilidad con 1 clic
     * PATCH /api/documentos/{id}/toggle-visibilidad
     */
    public function toggleVisibilidad(string $id)
    {
        $documento = DocumentoArchivo::findOrFail($id);
        $documento->es_publico = !$documento->es_publico;
        $documento->save();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $documento->id,
                'es_publico' => $documento->es_publico,
            ],
            'message' => $documento->es_publico
                ? 'El documento ahora es visible públicamente.'
                : 'El documento ha sido ocultado del portal público.',
        ], Response::HTTP_OK);
    }

    /**
     * Eliminar documento y su archivo físico
     * DELETE /api/documentos/{id}
     */
    public function destroy(string $id)
    {
        $documento = DocumentoArchivo::findOrFail($id);

        // Borrado del archivo físico en storage
        if ($documento->archivo_path && Storage::disk('public')->exists($documento->archivo_path)) {
            Storage::disk('public')->delete($documento->archivo_path);
        }

        $documento->delete();

        return response()->json([
            'success' => true,
            'message' => 'Documento eliminado exitosamente.',
        ], Response::HTTP_OK);
    }

    /**
     * Listado para la página de dominio público (solo documentos con es_publico = true)
     * GET /api/public/documentos
     */
    public function indexPublico(Request $request)
    {
        $buscar = $request->query('buscar');
        $perPage = (int) $request->query('per_page', 12);

        $query = DocumentoArchivo::query()
            ->publico()
            ->buscar($buscar)
            ->select('id', 'nombre', 'descripcion', 'archivo_path', 'archivo_nombre_original', 'descargas_count', 'created_at')
            ->orderBy('created_at', 'desc');

        $documentos = $perPage > 0 ? $query->paginate($perPage) : $query->get();

        return response()->json([
            'success' => true,
            'data' => $documentos,
        ], Response::HTTP_OK);
    }

    /**
     * Descarga directa forzada del PDF e incremento del contador de descargas
     * GET /api/public/documentos/{id}/descargar
     */
    public function descargar(string $id)
    {
        $documento = DocumentoArchivo::findOrFail($id);

        if (!$documento->archivo_path || !Storage::disk('public')->exists($documento->archivo_path)) {
            abort(404, 'Archivo físico no encontrado en el servidor.');
        }

        // Incremento atómico del contador de descargas
        $documento->increment('descargas_count');

        return Storage::disk('public')->download(
            $documento->archivo_path,
            $documento->archivo_nombre_original,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $documento->archivo_nombre_original . '"',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }
}
