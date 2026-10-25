<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SolicitudInscripcion;
use App\Models\InscripcionTaller;
use App\Models\Matricula;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function index(): JsonResponse
    {
        $user = auth()->user();
        $isDocenteOnly = $user && $user->hasRole('Docente') && !$user->hasAnyRole(['Administrador', 'Super Admin', 'Secretaria', 'Coordinador']);

        // 1. Solicitudes de inscripción pendientes (para el contador y notificaciones de aprobación)
        $solicitudesQuery = SolicitudInscripcion::where('estado', 'pendiente_validacion')
            ->where('created_at', '>=', Carbon::now()->subDays(30));

        // 2. Inscripciones a talleres recientes
        $inscripcionesQuery = InscripcionTaller::where('fecha_inscripcion', '>=', Carbon::now()->subDays(30));

        // 3. Matrículas confirmadas recientes
        $matriculasQuery = Matricula::where('fecha_inscripcion', '>=', Carbon::now()->subDays(30));

        if ($isDocenteOnly) {
            $solicitudesQuery->whereHas('cursoAbierto', function ($q) use ($user) {
                $q->where('docente_id', $user->persona_id);
            });
            $inscripcionesQuery->whereHas('taller', function ($q) use ($user) {
                $q->where('instructor_id', $user->persona_id);
            });
            $matriculasQuery->whereHas('cursoAbierto', function ($q) use ($user) {
                $q->where('docente_id', $user->persona_id);
            });
        }

        $solicitudes = $solicitudesQuery->with([
            'estudiante:id,nombres,apellidos',
            'participanteExterno:id,nombres,apellidos',
            'cursoAbierto:id,catalogo_curso_id,precio_base',
            'cursoAbierto.catalogo:id,nombre,color',
        ])
        ->orderByDesc('created_at')
        ->take(20)
        ->get()
        ->map(function ($s) {
            $nombre = $s->estudiante
                ? trim($s->estudiante->nombres . ' ' . $s->estudiante->apellidos)
                : ($s->participanteExterno
                    ? trim($s->participanteExterno->nombres . ' ' . ($s->participanteExterno->apellidos ?? ''))
                    : 'Desconocido');

            return [
                'id' => $s->id,
                'tipo' => 'curso',
                'subtipo' => 'solicitud',
                'titulo' => 'Nueva solicitud de inscripción',
                'estudiante' => $nombre,
                'curso' => $s->cursoAbierto?->catalogo?->nombre ?? 'Sin curso',
                'color' => $s->cursoAbierto?->catalogo?->color,
                'monto' => (float) $s->monto_solicitado,
                'metodo_pago' => $s->tipo_comprobante,
                'fecha_creacion' => $s->created_at,
                'hora' => Carbon::parse($s->created_at)->timezone('America/Guayaquil')->format('H:i'),
            ];
        });

        $matriculas = $matriculasQuery->with([
            'estudiante:id,nombres,apellidos',
            'cursoAbierto:id,catalogo_curso_id,precio_base,docente_id',
            'cursoAbierto.catalogo:id,nombre,color',
        ])
        ->orderByDesc('fecha_inscripcion')
        ->take(20)
        ->get()
        ->map(function ($m) {
            return [
                'id' => $m->id,
                'tipo' => 'curso',
                'subtipo' => 'matricula',
                'titulo' => 'Nueva matrícula',
                'estudiante' => $m->estudiante ? trim($m->estudiante->nombres . ' ' . $m->estudiante->apellidos) : 'Estudiante',
                'curso' => $m->cursoAbierto?->catalogo?->nombre ?? 'Sin curso',
                'color' => $m->cursoAbierto?->catalogo?->color,
                'monto' => (float) ($m->precio_total_legacy ?? 0),
                'metodo_pago' => $m->tipo_pago,
                'fecha_creacion' => $m->fecha_inscripcion,
                'hora' => Carbon::parse($m->fecha_inscripcion)->timezone('America/Guayaquil')->format('H:i'),
            ];
        });

        $inscripciones = $inscripcionesQuery->with('taller:id,nombre')
        ->orderByDesc('fecha_inscripcion')
        ->take(20)
        ->get()
        ->map(function ($i) {
            return [
                'id' => $i->id,
                'tipo' => 'taller',
                'subtipo' => 'taller',
                'titulo' => 'Inscripción a taller',
                'estudiante' => trim($i->nombres . ' ' . $i->apellidos),
                'curso' => $i->taller?->nombre ?? 'Sin taller',
                'color' => null,
                'monto' => (float) ($i->monto_pagado ?? 0),
                'metodo_pago' => $i->metodo_pago,
                'fecha_creacion' => $i->fecha_inscripcion,
                'hora' => Carbon::parse($i->fecha_inscripcion)->timezone('America/Guayaquil')->format('H:i'),
            ];
        });

        $merged = $solicitudes->concat($matriculas)->concat($inscripciones)
            ->sortByDesc('fecha_creacion')
            ->take(25)
            ->values();

        // Pendientes son las que requieren validación/aprobación inmediata
        $countQuerySol = SolicitudInscripcion::where('estado', 'pendiente_validacion');
        $countQueryIns = InscripcionTaller::where('estado', 'activo')->where('pago_verificado', false);
        if ($isDocenteOnly) {
            $countQuerySol->whereHas('cursoAbierto', fn($q) => $q->where('docente_id', $user->persona_id));
            $countQueryIns->whereHas('taller', fn($q) => $q->where('instructor_id', $user->persona_id));
        }
        $count = $countQuerySol->count() + $countQueryIns->count();

        $grouped = $merged->groupBy(function ($item) {
            return Carbon::parse($item['fecha_creacion'])->timezone(config('app.timezone', 'America/Guayaquil'))->format('Y-m-d');
        })->map(function ($items, $date) {
            return [
                'fecha' => $date,
                'items' => $items->map(function ($item) {
                    return [
                        'id' => $item['id'],
                        'tipo' => $item['tipo'],
                        'subtipo' => $item['subtipo'] ?? $item['tipo'],
                        'titulo' => $item['titulo'] ?? ($item['tipo'] === 'curso' ? 'Nueva matrícula' : 'Inscripción a taller'),
                        'estudiante' => $item['estudiante'],
                        'curso' => $item['curso'],
                        'color' => $item['color'],
                        'monto' => $item['monto'],
                        'metodo_pago' => $item['metodo_pago'],
                        'hora' => $item['hora'],
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        return response()->json([
            'pendientes' => $count,
            'recientes' => $grouped,
        ]);
    }
}
