<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SolicitudInscripcion;
use App\Models\InscripcionTaller;
use Carbon\Carbon;

class NotificationController extends Controller
{
    public function index()
    {
        return response()->json((function () {
        $solicitudes = SolicitudInscripcion::where('estado', 'pendiente_validacion')
            ->where('created_at', '>=', Carbon::now()->subDays(14))
            ->with([
                'estudiante:id,nombres,apellidos',
                'participanteExterno:id,nombres,apellidos',
                'cursoAbierto:id,catalogo_curso_id,precio_base',
                'cursoAbierto.catalogo:id,nombre,color',
            ])
            ->orderByDesc('created_at')
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
                    'estudiante' => $nombre,
                    'curso' => $s->cursoAbierto?->catalogo?->nombre ?? 'Sin curso',
                    'color' => $s->cursoAbierto?->catalogo?->color,
                    'monto' => (float) $s->monto_solicitado,
                    'metodo_pago' => $s->tipo_comprobante,
                    'fecha_creacion' => $s->created_at,
                    'hora' => Carbon::parse($s->created_at)->timezone('America/Guayaquil')->format('H:i'),
                ];
            });

        $inscripciones = InscripcionTaller::where('estado', 'activo')
            ->where('pago_verificado', false)
            ->where('fecha_inscripcion', '>=', Carbon::now()->subDays(14))
            ->with('taller:id,nombre')
            ->orderByDesc('fecha_inscripcion')
            ->get()
            ->map(function ($i) {
                return [
                    'id' => $i->id,
                    'tipo' => 'taller',
                    'estudiante' => trim($i->nombres . ' ' . $i->apellidos),
                    'curso' => $i->taller?->nombre ?? 'Sin taller',
                    'color' => null,
                    'monto' => (float) ($i->monto_pagado ?? 0),
                    'metodo_pago' => $i->metodo_pago,
                    'fecha_creacion' => $i->fecha_inscripcion,
                    'hora' => Carbon::parse($i->fecha_inscripcion)->timezone('America/Guayaquil')->format('H:i'),
                ];
            });

        $merged = $solicitudes->concat($inscripciones)
            ->sortByDesc('fecha_creacion')
            ->take(20)
            ->values();

        $count = SolicitudInscripcion::where('estado', 'pendiente_validacion')->count()
            + InscripcionTaller::where('estado', 'activo')->where('pago_verificado', false)->count();

        $grouped = $merged->groupBy(function ($item) {
            return Carbon::parse($item['fecha_creacion'])->timezone(config('app.timezone'))->format('Y-m-d');
        })->map(function ($items, $date) {
            return [
                'fecha' => $date,
                'items' => $items->map(function ($item) {
                    return [
                        'id' => $item['id'],
                        'tipo' => $item['tipo'],
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

                return [
                    'pendientes' => $count,
                    'recientes' => $grouped,
                ];
            }));
    }
}
