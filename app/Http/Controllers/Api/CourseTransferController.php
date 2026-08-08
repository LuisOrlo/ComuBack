<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransferirMatriculaRequest;
use App\Models\Matricula;
use App\Services\CourseTransferService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CourseTransferController extends Controller
{
    public function __construct(
        private readonly CourseTransferService $courseTransferService
    ) {}

    public function alternativos(string $matriculaId): JsonResponse
    {
        $matricula = Matricula::findOrFail($matriculaId);

        if ($matricula->estado !== Matricula::ESTADO_ACTIVO) {
            return response()->json([
                'mensaje' => 'La matrícula debe estar activa para buscar alternativas.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $alternativos = $this->courseTransferService->getAlternativos($matricula->curso_abierto_id);

        return response()->json([
            'datos' => $alternativos,
        ]);
    }

    public function transferir(TransferirMatriculaRequest $request, string $matriculaId): JsonResponse
    {
        try {
            $resultado = $this->courseTransferService->transferir(
                $matriculaId,
                $request->input('curso_abierto_nuevo_id'),
                $request->input('motivo'),
                $request->input('lineas', []),
            );

            return response()->json($resultado, Response::HTTP_OK);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
