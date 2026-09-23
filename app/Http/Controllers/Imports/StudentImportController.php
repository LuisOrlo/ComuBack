<?php

namespace App\Http\Controllers\Imports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\StudentImportExecuteRequest;
use App\Http\Requests\Imports\StudentImportPreviewRequest;
use App\Services\Imports\StudentImportService;
use Illuminate\Http\JsonResponse;

class StudentImportController extends Controller
{
    public function __construct(private readonly StudentImportService $service) {}

    public function preview(StudentImportPreviewRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->preview(
                $request->file('archivo'),
                $request->input('preview_id'),
                $request->input('mapping', []),
                $request->input('enrollment', []),
                $request->input('finance', []),
            ),
        ]);
    }

    public function execute(StudentImportExecuteRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->execute(
                $request->string('preview_id')->toString(),
                $request->input('confirmed_rows', []),
                $request->input('enrollment', []),
            ),
        ]);
    }
}
