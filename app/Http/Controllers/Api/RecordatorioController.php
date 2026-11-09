<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RecordatorioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecordatorioController extends Controller
{
    protected RecordatorioService $recordatorioService;

    public function __construct(RecordatorioService $recordatorioService)
    {
        $this->recordatorioService = $recordatorioService;
    }

    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $recordatorios = $this->recordatorioService->getRecordatorios($user);

        return response()->json($recordatorios);
    }
}
