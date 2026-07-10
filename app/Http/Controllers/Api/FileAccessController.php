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

        $path = "uploads/{$filename}";

        if (!Storage::disk('s3')->exists($path)) {
            abort(404);
        }

        $mimeType = Storage::disk('s3')->mimeType($path);

        if (!in_array($mimeType, self::ALLOWED_MIMES, true)) {
            abort(415, 'Tipo de archivo no permitido');
        }

        return Storage::disk('s3')->download($path, $filename, [
            'Content-Type' => $mimeType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
