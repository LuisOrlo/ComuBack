<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadController extends Controller
{
    /**
     * POST /api/academic/upload/comprobante
     * Subir imagen de comprobante y retornar URL
     */
    public function uploadComprobante(Request $request)
    {
        $request->validate([
            'archivo' => 'required|file|image|max:5120',
        ]);

        $file = $request->file('archivo');
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('comprobantes', $filename, 'public');

        $url = Storage::disk('public')->url($path);

        return response()->json([
            'data' => [
                'url' => $url,
                'filename' => $filename,
            ],
            'message' => 'Archivo subido correctamente',
        ], Response::HTTP_CREATED);
    }

    /**
     * POST /api/academic/upload/cedula
     * Subir imagen de cédula y retornar URL
     */
    public function uploadCedula(Request $request)
    {
        $request->validate([
            'archivo' => 'required|file|image|max:5120',
        ]);

        $file = $request->file('archivo');
        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('cedulas', $filename, 'public');

        $url = Storage::disk('public')->url($path);

        return response()->json([
            'data' => [
                'url' => $url,
                'filename' => $filename,
            ],
            'message' => 'Archivo subido correctamente',
        ], Response::HTTP_CREATED);
    }
}
