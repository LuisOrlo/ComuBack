<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AgendaRequest;
use App\Services\AgendaPdfService;
use App\Services\AgendaService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Carbon\Carbon;

class AgendaController extends Controller
{
    protected AgendaService $agendaService;

    public function __construct(AgendaService $agendaService)
    {
        $this->agendaService = $agendaService;
    }

    /**
     * GET /api/academic/agenda
     *
     * Lista paginada de eventos unificados de la agenda.
     */
    public function index(AgendaRequest $request): JsonResponse
    {
        $fechaInicio = $request->validated('fecha_inicio');
        $fechaFin = $request->validated('fecha_fin');
        $tipos = $request->validated('tipos');

        $events = $this->agendaService->getEvents($fechaInicio, $fechaFin, $tipos);

        $perPage = $request->validated('per_page', 100);
        $page = $request->input('page', 1);
        $total = $events->count();
        $paginated = $events->forPage($page, $perPage)->values();

        return response()->json([
            'data' => $paginated,
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => (int) $page,
                'last_page' => (int) ceil($total / $perPage),
            ],
            'tipos_disponibles' => [
                ['tipo' => 'CLASE_CURSO', 'label' => 'Cursos', 'color' => '#6366f1'],
                ['tipo' => 'TALLER', 'label' => 'Talleres', 'color' => '#f59e0b'],
                ['tipo' => 'ALQUILER_AULA', 'label' => 'Alquiler de Aulas', 'color' => '#10b981'],
                ['tipo' => 'PODCAST', 'label' => 'Podcast', 'color' => '#ec4899'],
                ['tipo' => 'STREAMING', 'label' => 'Streaming', 'color' => '#06b6d4'],
                ['tipo' => 'ASESORIA', 'label' => 'Asesorías', 'color' => '#8b5cf6'],
                ['tipo' => 'RADIO', 'label' => 'Radio', 'color' => '#ef4444'],
            ],
        ]);
    }

    /**
     * GET /api/academic/agenda/{tipo_evento}/{referencia_id}
     *
     * Detalle completo de un evento.
     */
    public function show(string $tipoEvento, string $referenciaId): JsonResponse
    {
        $tipoEvento = strtoupper($tipoEvento);
        $event = $this->agendaService->getEventDetail($tipoEvento, $referenciaId);

        if (!$event) {
            return response()->json([
                'mensaje' => 'Evento no encontrado',
            ], 404);
        }

        return response()->json([
            'data' => $event,
        ]);
    }

    /**
     * GET /api/academic/agenda/exportar/pdf
     *
     * Genera y descarga el PDF de la agenda (mensual o semanal) renderizado en servidor.
     */
    public function exportarPdf(Request $request, AgendaPdfService $pdfService): Response
    {
        $validated = $request->validate([
            'vista'        => 'required|in:mes,semana,dia,lista',
            'fecha_inicio' => 'required|date',
            'fecha_fin'    => 'required|date|after_or_equal:fecha_inicio',
            'tipos'        => 'nullable|array',
            'tipos.*'      => 'string',
            'titulo'       => 'nullable|string|max:120',
        ]);

        $fechaInicio = Carbon::parse($validated['fecha_inicio']);
        $fechaFin    = Carbon::parse($validated['fecha_fin']);
        $tipos       = $validated['tipos'] ?? [];
        $titulo      = $validated['titulo'] ?? 'AGENDA GENERAL';

        $pdf = match ($validated['vista']) {
            'mes' => $pdfService->generateMonthPdf($fechaInicio, $fechaFin, $tipos, $titulo),
            'dia' => $pdfService->generateDayPdf($fechaInicio, $fechaFin, $tipos, $titulo),
            'lista' => $pdfService->generateListPdf($fechaInicio, $fechaFin, $tipos, $titulo),
            default => $pdfService->generateWeekPdf($fechaInicio, $fechaFin, $tipos, $titulo),
        };

        $nombreArchivo = 'AGENDA_' . strtoupper($validated['vista']) . '_' . $fechaInicio->format('Y_m_d') . '.pdf';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $nombreArchivo . '"',
        ]);
    }
}
