<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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

        $path = storage_path("app/private/uploads/{$filename}");

        if (!file_exists($path) || !is_file($path)) {
            abort(404);
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($path);

        if (!in_array($mimeType, self::ALLOWED_MIMES, true)) {
            abort(415, 'Tipo de archivo no permitido');
        }

        return response()->file($path, [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'private, no-cache, no-store, must-revalidate',
        ]);
    }
}
