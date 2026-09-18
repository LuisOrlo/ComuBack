<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileAccessController extends Controller
{
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    public function serve(Request $request, string $filename): StreamedResponse
    {
        $filename = basename($filename);

        if ($filename === '' || $filename === '.') {
            abort(404);
        }

        // Los cargadores almacenan por tipo; se busca solo dentro de carpetas
        // permitidas y nunca se acepta una ruta arbitraria del usuario.
        $path = collect(['comprobantes', 'cedulas', 'alquileres', 'talleres'])
            ->map(fn ($dir) => "{$dir}/{$filename}")
            ->first(fn ($candidate) => Storage::disk()->exists($candidate));

        if (! $path) {
            abort(404);
        }

        $mimeType = Storage::disk()->mimeType($path);

        if (!in_array($mimeType, self::ALLOWED_MIMES, true)) {
            abort(415, 'Tipo de archivo no permitido');
        }

        // Control de autorización a nivel de objeto (OWASP BOLA):
        // Personal administrativo (Admin, Secretaria, Staff) tiene acceso de auditoría/revisión.
        // Estudiantes o usuarios regulares solo pueden descargar documentos de su propio expediente.
        $user = $request->user() ?? auth()->user();
        if (!$user) {
            abort(401, 'No autenticado');
        }

        if (!$user->hasAnyRole(['Administrador', 'Secretaria', 'Staff'])) {
            $personaId = $user->persona_id;
            if (!$personaId) {
                abort(403, 'No tiene autorización para consultar este documento');
            }

            $tieneAcceso = \App\Models\Persona::where('id', $personaId)
                    ->where('cedula_photo_url', 'like', "%{$filename}%")
                    ->exists()
                || \App\Models\SolicitudInscripcion::where('persona_id', $personaId)
                    ->where(function ($q) use ($filename) {
                        $q->where('archivo_comprobante_url', 'like', "%{$filename}%")
                          ->orWhere('archivo_cedula_url', 'like', "%{$filename}%");
                    })->exists()
                || \App\Models\InscripcionTaller::where('persona_id', $personaId)
                    ->where(function ($q) use ($filename) {
                        $q->where('comprobante_url', 'like', "%{$filename}%")
                          ->orWhere('cedula_url', 'like', "%{$filename}%");
                    })->exists()
                || \App\Models\Matricula::where('estudiante_id', $personaId)
                    ->where('voucher_url', 'like', "%{$filename}%")
                    ->exists();

            if (!$tieneAcceso) {
                abort(403, 'No tiene autorización para consultar este documento');
            }
        }

        return Storage::disk()->download($path, $filename, [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
