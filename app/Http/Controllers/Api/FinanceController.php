<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CuentaPorCobrar;
use App\Models\InscripcionTaller;
use App\Models\Matricula;
use App\Models\Services\ReservaAula;
use App\Models\Services\ReservaPodcast;
use App\Models\Services\ReservaRadio;
use App\Models\Services\AlquilerEquipo;
use App\Models\Services\TrabajoEdicion;
use App\Models\TransaccionEgreso;
use App\Models\TransaccionIngreso;
use App\Models\Finance\LineaPagoModulo;
use App\Services\StorageCleanupService;
use App\Http\Requests\StorePagoInicialRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;

class FinanceController extends Controller
{
    public function getCuentas(Request $request): JsonResponse
    {
        $recientes = $request->boolean('recientes');
        $perPage = (int) $request->get('per_page', 15);
        $origen = $request->get('origen');
        $estado = $request->get('estado');
        $search = $request->get('search');
        $modalidad = $request->get('modalidad');

        // --- Cuentas por cobrar (carga selectiva según origen) ---
        $query = CuentaPorCobrar::query();

        if (!$origen || $origen === 'curso') {
            $query->with([
                'matricula.estudiante',
                'matricula.cursoAbierto.catalogo',
                'matricula.lineasPago.modulo',
                'solicitudInscripcion.estudiante',
                'solicitudInscripcion.participanteExterno',
                'solicitudInscripcion.cursoAbierto.catalogo',
            ]);
        }
        if (!$origen || $origen === 'servicio') {
            $query->with([
                'reservaPodcast.persona',
                'reservaPodcast.clienteExterno',
                'reservaPodcast.paquete',
                'reservaAula.persona',
                'reservaAula.clienteExterno',
                'reservaAula.aula',
                'alquilerEquipo.persona',
                'alquilerEquipo.clienteExterno',
                'alquilerEquipo.equipo',
                'reservaRadio.persona',
                'reservaRadio.clienteExterno',
            ]);
        }
        if (!$origen || $origen === 'taller') {
            $query->with(['inscripcionTaller.taller']);
        }

        if ($estado) {
            $query->where('estado', $estado);
        }

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->whereHas('matricula.estudiante', fn($sq) => $sq->where('nombres', 'ilike', "%{$search}%")->orWhere('apellidos', 'ilike', "%{$search}%"))
                  ->orWhereHas('solicitudInscripcion.estudiante', fn($sq) => $sq->where('nombres', 'ilike', "%{$search}%")->orWhere('apellidos', 'ilike', "%{$search}%"))
                  ->orWhereHas('solicitudInscripcion.participanteExterno', fn($sq) => $sq->where('nombres', 'ilike', "%{$search}%")->orWhere('apellidos', 'ilike', "%{$search}%"));
            });
        }

        if ($origen) {
            if ($origen === 'curso') {
                $query->where(fn($q) => $q->whereNotNull('matricula_id')->orWhereNotNull('solicitud_inscripcion_id'));
            } elseif ($origen === 'taller') {
                $query->whereNotNull('inscripcion_taller_id');
            } elseif ($origen === 'servicio') {
                $query->where(fn($q) => $q
                    ->whereNotNull('reserva_aula_id')
                    ->orWhereNotNull('reserva_podcast_id')
                    ->orWhereNotNull('alquiler_equipo_id')
                    ->orWhereNotNull('servicio_streaming_id')
                    ->orWhereNotNull('servicio_produccion_id')
                    ->orWhereNotNull('edicion_video_id')
                    ->orWhereNotNull('clase_extra_id')
                    ->orWhereNotNull('asesoria_id')
                    ->orWhereNotNull('reserva_radio_id')
                );
            }
        }

        if ($recientes) {
            $query->where(function ($q) {
                $q->whereIn('estado', ['pendiente', 'abonado'])
                  ->orWhere(function ($sq) {
                      $sq->where('estado', 'pagado')
                         ->where('updated_at', '>=', now()->subDays(7));
                  });
            });
        }

        $cuentasQuery = $query->orderBy('created_at', 'desc');
        if (!$search) {
            $cuentasQuery->limit(2000);
        }
        $cuentas = $cuentasQuery->get();

        // --- Matrículas con lineas_pago_modulo sin cuenta por cobrar ---
        $cursosSinCuenta = collect();
        $debeCursosSinCuenta = ! $origen || $origen === 'curso';

        if ($debeCursosSinCuenta) {
            $idsConCuenta = CuentaPorCobrar::whereNotNull('matricula_id')->pluck('matricula_id')->toArray();

            $lineasAgrupadas = DB::table('finance.lineas_pago_modulo as lpm')
                ->join('academic.matriculas as m', 'm.id', '=', 'lpm.matricula_id')
                ->join('academic.cursos_abiertos as ca', 'ca.id', '=', 'm.curso_abierto_id')
                ->join('people.personas as p', 'p.id', '=', 'm.estudiante_id')
                ->whereNull('m.deleted_at')
                ->whereNull('ca.deleted_at')
                ->when($modalidad && in_array($modalidad, ['presencial', 'virtual']), fn($q) => $q->where('ca.modalidad', $modalidad))
                ->when(! empty($idsConCuenta), fn($q) => $q->whereNotIn('m.id', $idsConCuenta))
                ->when($search, fn($q) => $q->where(function($q) use ($search) {
                    $q->where('p.nombres', 'ilike', "%{$search}%")
                      ->orWhere('p.apellidos', 'ilike', "%{$search}%");
                }))
                ->select(
                    'm.id as matricula_id',
                    DB::raw('SUM(lpm.monto_ajustado) as monto_total'),
                    DB::raw('SUM(lpm.monto_abonado) as monto_abonado'),
                    DB::raw("MAX(
                        CASE WHEN lpm.estado = 'pagado' THEN 1
                             WHEN lpm.estado = 'abonado' THEN 2
                             ELSE 3 END
                    ) as peor_estado_num"),
                    DB::raw('MAX(lpm.updated_at) as ultima_actualizacion'),
                    DB::raw('MIN(lpm.created_at) as primera_creacion')
                )
                ->groupBy('m.id')
                ->get();

        if ($modalidad && in_array($modalidad, ['presencial', 'virtual'])) {
            $query->where(function($q) use ($modalidad) {
                $q->whereHas('matricula.cursoAbierto', fn($sq) => $sq->where('modalidad', $modalidad))
                  ->orWhereHas('solicitudInscripcion.cursoAbierto', fn($sq) => $sq->where('modalidad', $modalidad))
                  ->orWhereHas('inscripcionTaller.taller', fn($sq) => $sq->where('modalidad', $modalidad));
            });
        }

        if ($recientes) {
                $lineasAgrupadas = $lineasAgrupadas->filter(function ($l) {
                    // pendiente/abonado siempre reciente; pagado solo si actualizado hace <= 7 días
                    if ($l->peor_estado_num >= 2) return true; // pendiente (3) o abonado (2)
                    return $l->ultima_actualizacion && \Carbon\Carbon::parse($l->ultima_actualizacion)->gte(now()->subDays(7));
                });
            }

            if ($estado === 'pendiente') {
                $lineasAgrupadas = $lineasAgrupadas->where('peor_estado_num', 3);
            } elseif ($estado === 'abonado') {
                $lineasAgrupadas = $lineasAgrupadas->where('peor_estado_num', 2);
            } elseif ($estado === 'pagado') {
                $lineasAgrupadas = $lineasAgrupadas->where('peor_estado_num', 1);
            }

            $matriculaIds = $lineasAgrupadas->pluck('matricula_id')->toArray();
            if (! empty($matriculaIds)) {
                $matriculas = Matricula::with([
                        'estudiante',
                        'solicitudInscripcion.estudiante',
                        'solicitudInscripcion.participanteExterno',
                        'cursoAbierto.catalogo',
                        'cursoAbierto.docente',
                        'cursoAbierto.ciudad',
                        'lineasPago.modulo',
                    ])
                    ->whereIn('id', $matriculaIds)
                    ->get()
                    ->keyBy('id');

                foreach ($lineasAgrupadas as $l) {
                    $matricula = $matriculas->get($l->matricula_id);
                    $estadoCalc = match ((int) $l->peor_estado_num) {
                        1 => 'pagado',
                        2 => 'abonado',
                        default => 'pendiente',
                    };

                    $lineasDetalle = $matricula?->lineasPago
                        ? $matricula->lineasPago->sortBy('orden')->map(fn($lp) => [
                            'id' => $lp->id,
                            'modulo_id' => $lp->modulo_id,
                            'nombre_modulo' => $lp->modulo?->nombre_modulo ?? ('M\u00f3dulo ' . ($lp->orden + 1)),
                            'numero_orden' => $lp->modulo?->numero_orden ?? $lp->orden,
                            'monto_ajustado' => (float) $lp->monto_ajustado,
                            'monto_abonado' => (float) $lp->monto_abonado,
                            'saldo_pendiente' => (float) $lp->saldo_pendiente,
                            'estado' => $lp->estado,
                        ])->values()->toArray()
                        : [];

                    $instructorNombre = $matricula?->cursoAbierto?->docente
                        ? trim(($matricula->cursoAbierto->docente->nombres ?? '') . ' ' . ($matricula->cursoAbierto->docente->apellidos ?? ''))
                        : null;

                    $cursoAbierto = $matricula?->cursoAbierto;
                    if ($cursoAbierto) {
                        if ($cursoAbierto->nombre_instancia) {
                            $cursoNombre = $cursoAbierto->nombre_instancia;
                        } else {
                            $partes = [$cursoAbierto->catalogo?->nombre];
                            if ($cursoAbierto->ciudad?->nombre) {
                                $partes[] = $cursoAbierto->ciudad->nombre;
                            }
                            if ($cursoAbierto->fecha_inicio) {
                                $meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
                                $partes[] = $meses[$cursoAbierto->fecha_inicio->month - 1] . ' ' . $cursoAbierto->fecha_inicio->year;
                            }
                            if ($cursoAbierto->modalidad) {
                                $partes[] = ucfirst($cursoAbierto->modalidad);
                            }
                            $cursoNombre = implode(' — ', $partes);
                        }
                    } else {
                        $cursoNombre = 'Curso';
                    }

                    $cursosSinCuenta->push((object) [
                        'id' => 'lpm-' . $l->matricula_id,
                        'matricula_id' => $l->matricula_id,
                        'solicitud_inscripcion_id' => null,
                        'inscripcion_taller_id' => null,
                        'monto_total' => (float) $l->monto_total,
                        'monto_abonado' => (float) $l->monto_abonado,
                        'saldo_pendiente' => max(0, (float) $l->monto_total - (float) $l->monto_abonado),
                        'estado' => $estadoCalc,
                        'created_at' => $l->primera_creacion,
                        'modalidad' => $matricula?->cursoAbierto?->modalidad ?? null,
                        'matricula' => $matricula,
                        'solicitud_inscripcion' => null,
                        'inscripcion_taller' => null,
                        '_origen' => 'lineas_pago',
                        'curso_nombre' => $cursoNombre,
                        'curso_abierto_id' => $cursoAbierto?->id,
                        'lineas_pago' => $lineasDetalle,
                        'persona_nombre' => $matricula?->estudiante
                            ? trim(($matricula->estudiante->nombres ?? '') . ' ' . ($matricula->estudiante->apellidos ?? ''))
                            : ($matricula?->solicitudInscripcion?->estudiante
                                ? trim(($matricula->solicitudInscripcion->estudiante->nombres ?? '') . ' ' . ($matricula->solicitudInscripcion->estudiante->apellidos ?? ''))
                                : ($matricula?->solicitudInscripcion?->participanteExterno?->nombres
                                    ? trim(($matricula->solicitudInscripcion->participanteExterno->nombres ?? '') . ' ' . ($matricula->solicitudInscripcion->participanteExterno->apellidos ?? ''))
                                    : '')),
                        'instructor_nombre' => $instructorNombre,
                    ]);
                }
            }
        }

        // --- Mezclar, ordenar y paginar ---
        $todas = $cuentas->map(function ($c) {
            $lineasPagoData = [];
            if ($c->matricula && $c->matricula->lineasPago->isNotEmpty()) {
                $lineasPagoData = $c->matricula->lineasPago->sortBy('orden')->map(fn($lp) => [
                    'id' => $lp->id,
                    'modulo_id' => $lp->modulo_id,
                    'nombre_modulo' => $lp->modulo?->nombre_modulo ?? ('M\u00f3dulo ' . ($lp->orden + 1)),
                    'numero_orden' => $lp->modulo?->numero_orden ?? $lp->orden,
                'monto_ajustado' => (float) $lp->monto_ajustado,
                'monto_original' => (float) $lp->monto_original,
                'motivo_ajuste' => $lp->motivo_ajuste,
                'monto_abonado' => (float) $lp->monto_abonado,
                    'saldo_pendiente' => (float) $lp->saldo_pendiente,
                    'estado' => $lp->estado,
                ])->values()->toArray();
            }
            return (object) [...$c->toArray(), '_origen' => 'cuenta_cobrar', 'lineas_pago' => $lineasPagoData];
        })
            ->concat($cursosSinCuenta)
            ->sortByDesc(function ($c) {
                return $c->created_at ?? '1970-01-01';
            })
            ->values();

        $offset = max(0, ($request->get('page', 1) - 1)) * $perPage;
        $pagina = $todas->slice($offset, $perPage)->values();

        return response()->json([
            'data' => $pagina,
            'current_page' => (int) $request->get('page', 1),
            'per_page' => $perPage,
            'total' => $todas->count(),
            'last_page' => max(1, (int) ceil($todas->count() / $perPage)),
        ]);
    }

    public function getCuentaDetalle($id): JsonResponse
    {
        $cuenta = CuentaPorCobrar::with([
            'matricula.estudiante',
            'matricula.cursoAbierto.catalogo',
            'solicitudInscripcion.estudiante',
            'solicitudInscripcion.participanteExterno',
            'solicitudInscripcion.cursoAbierto.catalogo',
            'inscripcionTaller.taller',
        ])->findOrFail($id);

        $transacciones = TransaccionIngreso::where('cuenta_cobrar_id', $id)
            ->orderBy('fecha_pago', 'desc')
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'monto' => (float) $t->monto,
                'metodo_pago' => $t->metodo_pago,
                'comprobante_url' => $t->comprobante_url,
                'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                'estado_verificacion' => $t->estado_verificacion,
                'observaciones' => $t->observaciones,
            ])
            ->values();

        return response()->json([
            'datos' => $cuenta,
            'transacciones' => $transacciones
        ]);
    }

    public function getResumen(): JsonResponse
    {
        $stats = Cache::remember('finance.resumen', now()->addMinutes(5), function () {
            $base = CuentaPorCobrar::whereIn('estado', ['pendiente', 'abonado']);

            $saldoDeudor = fn($q) => $q->get()->sum(fn($c) => $c->monto_total - $c->monto_abonado);

            // Talleres sin cuenta por cobrar
            $talleresIdsConCuenta = CuentaPorCobrar::whereNotNull('inscripcion_taller_id')->pluck('inscripcion_taller_id')->toArray();
            $talleresSinCuenta = InscripcionTaller::whereNotIn('id', $talleresIdsConCuenta)
                ->where('estado', 'activo')
                ->get();

            $totalTalleresSinCuenta = $talleresSinCuenta->sum(fn($t) => (float) ($t->monto_pagado ?? $t->precio_pagado ?? 0));
            $countTalleresSinCuenta = $talleresSinCuenta->count();

            $talleresItems = [];
            foreach ($talleresSinCuenta as $t) {
                $talleresItems[] = [
                    'id' => $t->id,
                    'inscripcion_taller_id' => $t->id,
                    'taller_id' => $t->taller_id,
                    'monto_total' => (float) ($t->monto_pagado ?? $t->precio_pagado ?? 0),
                    'monto_abonado' => 0,
                    'saldo_pendiente' => (float) ($t->monto_pagado ?? $t->precio_pagado ?? 0),
                    'estado' => 'pendiente',
                    'modalidad' => $t->taller->modalidad ?? null,
                    'inscripcion_taller' => [
                        'taller' => $t->taller ? ['id' => $t->taller->id, 'nombre' => $t->taller->nombre] : null,
                    ],
                    'persona_nombre' => trim(($t->nombres ?? '') . ' ' . ($t->apellidos ?? '')),
                ];
            }

            // Servicios sin cuenta por cobrar (todos los tipos: presencial y virtual)
            $serviciosItems = [];

            // Aulas
            $aulasIds = CuentaPorCobrar::whereNotNull('reserva_aula_id')->pluck('reserva_aula_id')->toArray();
            $aulasSinCuenta = ReservaAula::whereNotIn('id', $aulasIds)
                ->whereIn('estado', ['reservado', 'confirmado', 'en_progreso'])
                ->get();
            foreach ($aulasSinCuenta as $r) {
                $serviciosItems[] = [
                    'id' => $r->id,
                    'tipo' => 'aula',
                    'monto_total' => (float) ($r->precio_total ?? 0),
                    'monto_abonado' => 0,
                    'saldo_pendiente' => (float) ($r->precio_total ?? 0),
                    'estado' => 'pendiente',
                    'nombre_servicio' => $r->aula?->nombre ?? 'Aula',
                    'persona_nombre' => $r->persona ? trim(($r->persona->nombres ?? '') . ' ' . ($r->persona->apellidos ?? '')) : ($r->clienteExterno?->nombres ?? ''),
                ];
            }

            // Podcast
            $podcastIds = CuentaPorCobrar::whereNotNull('reserva_podcast_id')->pluck('reserva_podcast_id')->toArray();
            $podcastSinCuenta = ReservaPodcast::whereNotIn('id', $podcastIds)
                ->whereIn('estado', ['reservado', 'confirmado', 'en_progreso'])
                ->get();
            foreach ($podcastSinCuenta as $r) {
                $nombreServicio = $r->titulo ?: ($r->paquete?->nombre ?? 'Podcast');
                $serviciosItems[] = [
                    'id' => $r->id,
                    'tipo' => 'podcast',
                    'titulo' => $r->titulo,
                    'monto_total' => (float) ($r->precio_total ?? 0),
                    'monto_abonado' => 0,
                    'saldo_pendiente' => (float) ($r->precio_total ?? 0),
                    'estado' => 'pendiente',
                    'nombre_servicio' => $nombreServicio,
                    'persona_nombre' => $r->persona ? trim(($r->persona->nombres ?? '') . ' ' . ($r->persona->apellidos ?? '')) : ($r->clienteExterno?->nombres ?? ''),
                ];
            }

            // Equipos
            $equiposIds = CuentaPorCobrar::whereNotNull('alquiler_equipo_id')->pluck('alquiler_equipo_id')->toArray();
            $equiposSinCuenta = AlquilerEquipo::whereNotIn('id', $equiposIds)
                ->whereIn('estado', ['pendiente', 'activo', 'entregado'])
                ->get();
            foreach ($equiposSinCuenta as $r) {
                $serviciosItems[] = [
                    'id' => $r->id,
                    'tipo' => 'equipo',
                    'monto_total' => (float) ($r->precio_total ?? 0),
                    'monto_abonado' => 0,
                    'saldo_pendiente' => (float) ($r->precio_total ?? 0),
                    'estado' => 'pendiente',
                    'nombre_servicio' => $r->equipo?->nombre ?? 'Equipo',
                    'persona_nombre' => $r->persona ? trim(($r->persona->nombres ?? '') . ' ' . ($r->persona->apellidos ?? '')) : ($r->clienteExterno?->nombres ?? ''),
                ];
            }

            // Streaming (virtual)
            $streamingIds = CuentaPorCobrar::whereNotNull('servicio_streaming_id')->pluck('servicio_streaming_id')->toArray();
            $streamingSinCuenta = DB::table('services.servicios_streaming')
                ->whereNotIn('id', $streamingIds)
                ->whereIn('estado', ['reservado', 'confirmado', 'en_progreso'])
                ->get();
            foreach ($streamingSinCuenta as $r) {
                $nombrePersona = '';
                if ($r->persona_id) {
                    $p = \App\Models\Persona::find($r->persona_id);
                    $nombrePersona = $p ? trim(($p->nombres ?? '') . ' ' . ($p->apellidos ?? '')) : '';
                } elseif ($r->cliente_externo_id) {
                    $c = \App\Models\ClienteExterno::find($r->cliente_externo_id);
                    $nombrePersona = $c ? trim(($c->nombres ?? '') . ' ' . ($c->apellidos ?? '')) : '';
                }
                $serviciosItems[] = [
                    'id' => $r->id,
                    'tipo' => 'streaming',
                    'monto_total' => (float) ($r->precio_total ?? 0),
                    'monto_abonado' => 0,
                    'saldo_pendiente' => (float) ($r->precio_total ?? 0),
                    'estado' => 'pendiente',
                    'nombre_servicio' => 'Streaming',
                    'persona_nombre' => $nombrePersona,
                ];
            }

            // Producci\u00f3n (virtual)
            $produccionIds = CuentaPorCobrar::whereNotNull('servicio_produccion_id')->pluck('servicio_produccion_id')->toArray();
            $produccionSinCuenta = DB::table('services.servicios_produccion')
                ->whereNotIn('id', $produccionIds)
                ->whereIn('estado', ['reservado', 'confirmado', 'en_progreso'])
                ->get();
            foreach ($produccionSinCuenta as $r) {
                $nombrePersona = '';
                if ($r->persona_id) {
                    $p = \App\Models\Persona::find($r->persona_id);
                    $nombrePersona = $p ? trim(($p->nombres ?? '') . ' ' . ($p->apellidos ?? '')) : '';
                } elseif ($r->cliente_externo_id) {
                    $c = \App\Models\ClienteExterno::find($r->cliente_externo_id);
                    $nombrePersona = $c ? trim(($c->nombres ?? '') . ' ' . ($c->apellidos ?? '')) : '';
                }
                $serviciosItems[] = [
                    'id' => $r->id,
                    'tipo' => 'produccion',
                    'monto_total' => (float) ($r->precio_total ?? 0),
                    'monto_abonado' => 0,
                    'saldo_pendiente' => (float) ($r->precio_total ?? 0),
                    'estado' => 'pendiente',
                    'nombre_servicio' => 'Producci\u00f3n',
                    'persona_nombre' => $nombrePersona,
                ];
            }

            // Edici\u00f3n de video (virtual)
            $edicionIds = CuentaPorCobrar::whereNotNull('edicion_video_id')->pluck('edicion_video_id')->toArray();
            $edicionSinCuenta = TrabajoEdicion::whereNotIn('id', $edicionIds)
                ->whereIn('estado', ['pendiente', 'en_progreso'])
                ->get();
            foreach ($edicionSinCuenta as $r) {
                $serviciosItems[] = [
                    'id' => $r->id,
                    'tipo' => 'edicion',
                    'monto_total' => (float) ($r->precio_cobrado ?? 0),
                    'monto_abonado' => 0,
                    'saldo_pendiente' => (float) ($r->precio_cobrado ?? 0),
                    'estado' => 'pendiente',
                    'nombre_servicio' => 'Edici\u00f3n: ' . ($r->titulo ?? 'Sin t\u00edtulo'),
                    'persona_nombre' => '',
                ];
            }

            // Radio (virtual)
            $radioIds = CuentaPorCobrar::whereNotNull('reserva_radio_id')->pluck('reserva_radio_id')->toArray();
            $radioSinCuenta = ReservaRadio::whereNotIn('id', $radioIds)
                ->whereIn('estado', ['reservado', 'confirmado', 'en_progreso'])
                ->get();
            foreach ($radioSinCuenta as $r) {
                $serviciosItems[] = [
                    'id' => $r->id,
                    'tipo' => 'radio',
                    'monto_total' => (float) ($r->precio_total ?? 0),
                    'monto_abonado' => 0,
                    'saldo_pendiente' => (float) ($r->precio_total ?? 0),
                    'estado' => 'pendiente',
                    'nombre_servicio' => 'Radio',
                    'persona_nombre' => $r->persona ? trim(($r->persona->nombres ?? '') . ' ' . ($r->persona->apellidos ?? '')) : ($r->clienteExterno?->nombres ?? ''),
                ];
            }

            // Clases extra
            $clasesExtraIds = CuentaPorCobrar::whereNotNull('clase_extra_id')->pluck('clase_extra_id')->toArray();
            $clasesExtraSinCuenta = DB::table('academic.clases_extras')
                ->whereNotIn('id', $clasesExtraIds)
                ->get();
            foreach ($clasesExtraSinCuenta as $r) {
                $nombrePersona = '';
                if ($r->estudiante_id) {
                    $p = \App\Models\Persona::find($r->estudiante_id);
                    $nombrePersona = $p ? trim(($p->nombres ?? '') . ' ' . ($p->apellidos ?? '')) : '';
                }
                $serviciosItems[] = [
                    'id' => $r->id,
                    'tipo' => 'clase_extra',
                    'monto_total' => (float) ($r->precio ?? 0),
                    'monto_abonado' => 0,
                    'saldo_pendiente' => (float) ($r->precio ?? 0),
                    'estado' => 'pendiente',
                    'nombre_servicio' => 'Clase Extra',
                    'persona_nombre' => $nombrePersona,
                ];
            }

            // Asesor\u00edas
            $asesoriasIds = CuentaPorCobrar::whereNotNull('asesoria_id')->pluck('asesoria_id')->toArray();
            $asesoriasSinCuenta = DB::table('academic.asesorias')
                ->whereNotIn('id', $asesoriasIds)
                ->whereIn('estado', ['reservado', 'confirmado', 'en_progreso'])
                ->get();
            foreach ($asesoriasSinCuenta as $r) {
                $nombrePersona = '';
                if ($r->persona_id) {
                    $p = \App\Models\Persona::find($r->persona_id);
                    $nombrePersona = $p ? trim(($p->nombres ?? '') . ' ' . ($p->apellidos ?? '')) : '';
                } elseif ($r->cliente_externo_id) {
                    $c = \App\Models\ClienteExterno::find($r->cliente_externo_id);
                    $nombrePersona = $c ? trim(($c->nombres ?? '') . ' ' . ($c->apellidos ?? '')) : '';
                }
                $serviciosItems[] = [
                    'id' => $r->id,
                    'tipo' => 'asesoria',
                    'monto_total' => (float) ($r->precio ?? 0),
                    'monto_abonado' => 0,
                    'saldo_pendiente' => (float) ($r->precio ?? 0),
                    'estado' => 'pendiente',
                    'nombre_servicio' => 'Asesor\u00eda',
                    'persona_nombre' => $nombrePersona,
                ];
            }

            $totalServiciosSinCuenta = collect($serviciosItems)->sum('monto_total');

            // Cursos sin cuenta por cobrar (matrículas con solo lineas_pago_modulo)
            $matriculasConCuentaIds = CuentaPorCobrar::whereNotNull('matricula_id')->pluck('matricula_id')->toArray();
            $cursosSinCuentaRaw = DB::table('finance.lineas_pago_modulo as lpm')
                ->join('academic.matriculas as m', 'm.id', '=', 'lpm.matricula_id')
                ->whereNull('m.deleted_at')
                ->when(! empty($matriculasConCuentaIds), fn($q) => $q->whereNotIn('m.id', $matriculasConCuentaIds))
                ->select(
                    'm.id as matricula_id',
                    DB::raw('SUM(lpm.monto_ajustado) as monto_total'),
                    DB::raw('SUM(lpm.monto_abonado) as monto_abonado'),
                    DB::raw("MAX(CASE WHEN lpm.estado = 'pagado' THEN 1 WHEN lpm.estado = 'abonado' THEN 2 ELSE 3 END) as peor_estado_num")
                )
                ->groupBy('m.id')
                ->get();

            $totalCursosSinCuenta = 0;
            $countCursosSinCuenta = 0;
            $cursosSinCuentaItems = [];
            $matriculasIdsCursosSinCuenta = $cursosSinCuentaRaw->pluck('matricula_id')->toArray();

            if (! empty($matriculasIdsCursosSinCuenta)) {
                $matriculasData = Matricula::with([
                        'estudiante', 'solicitudInscripcion.estudiante', 'solicitudInscripcion.participanteExterno', 'cursoAbierto.catalogo', 'cursoAbierto.docente', 'cursoAbierto.ciudad', 'lineasPago.modulo',
                    ])
                    ->whereIn('id', $matriculasIdsCursosSinCuenta)
                    ->get()
                    ->keyBy('id');

                foreach ($cursosSinCuentaRaw as $csc) {
                    $m = $matriculasData->get($csc->matricula_id);
                    $saldo = max(0, (float) $csc->monto_total - (float) $csc->monto_abonado);

                    $lineasDetalle = $m?->lineasPago
                        ? $m->lineasPago->sortBy('orden')->map(fn($lp) => [
                            'id' => $lp->id,
                            'modulo_id' => $lp->modulo_id,
                            'nombre_modulo' => $lp->modulo?->nombre_modulo ?? ('M\u00f3dulo ' . ($lp->orden + 1)),
                            'numero_orden' => $lp->modulo?->numero_orden ?? $lp->orden,
                            'monto_ajustado' => (float) $lp->monto_ajustado,
                            'monto_abonado' => (float) $lp->monto_abonado,
                            'saldo_pendiente' => (float) $lp->saldo_pendiente,
                            'estado' => $lp->estado,
                        ])->values()->toArray()
                        : [];

                    $instructorNombre = $m?->cursoAbierto?->docente
                        ? trim(($m->cursoAbierto->docente->nombres ?? '') . ' ' . ($m->cursoAbierto->docente->apellidos ?? ''))
                        : null;

                    $cursoAbierto = $m?->cursoAbierto;
                    if ($cursoAbierto) {
                        if ($cursoAbierto->nombre_instancia) {
                            $cursoNombre = $cursoAbierto->nombre_instancia;
                        } else {
                            $partes = [$cursoAbierto->catalogo?->nombre];
                            if ($cursoAbierto->ciudad?->nombre) {
                                $partes[] = $cursoAbierto->ciudad->nombre;
                            }
                            if ($cursoAbierto->fecha_inicio) {
                                $meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
                                $partes[] = $meses[$cursoAbierto->fecha_inicio->month - 1] . ' ' . $cursoAbierto->fecha_inicio->year;
                            }
                            if ($cursoAbierto->modalidad) {
                                $partes[] = ucfirst($cursoAbierto->modalidad);
                            }
                            $cursoNombre = implode(' — ', $partes);
                        }
                    } else {
                        $cursoNombre = 'Curso';
                    }

                    $cursosSinCuentaItems[] = [
                        'id' => 'lpm-' . $csc->matricula_id,
                        'matricula_id' => $csc->matricula_id,
                        'monto_total' => (float) $csc->monto_total,
                        'monto_abonado' => (float) $csc->monto_abonado,
                        'saldo_pendiente' => $saldo,
                        'estado' => match ((int) $csc->peor_estado_num) { 1 => 'pagado', 2 => 'abonado', default => 'pendiente' },
                        'modalidad' => $m?->cursoAbierto?->modalidad ?? null,
                        'matricula' => $m,
                        'solicitud_inscripcion' => null,
                        'curso_nombre' => $cursoNombre,
                        'curso_abierto_id' => $m?->cursoAbierto?->id,
                        'persona_nombre' => $m?->estudiante
                            ? trim(($m->estudiante->nombres ?? '') . ' ' . ($m->estudiante->apellidos ?? ''))
                            : ($m?->solicitudInscripcion?->estudiante
                                ? trim(($m->solicitudInscripcion->estudiante->nombres ?? '') . ' ' . ($m->solicitudInscripcion->estudiante->apellidos ?? ''))
                                : ($m?->solicitudInscripcion?->participanteExterno?->nombres
                                    ? trim(($m->solicitudInscripcion->participanteExterno->nombres ?? '') . ' ' . ($m->solicitudInscripcion->participanteExterno->apellidos ?? ''))
                                    : '')),
                        'lineas_pago' => $lineasDetalle,
                        'instructor_nombre' => $instructorNombre,
                    ];

                    $totalCursosSinCuenta += $saldo;
                    if ($saldo > 0) $countCursosSinCuenta++;
                }
            }

            $pendienteCursosSinCuenta = $cursosSinCuentaRaw->sum(function ($c) {
                return max(0, (float) $c->monto_total - (float) $c->monto_abonado);
            });

            return [
                'total_pendiente' => $saldoDeudor(clone $base)
                    + $pendienteCursosSinCuenta
                    + $totalTalleresSinCuenta
                    + $totalServiciosSinCuenta,
                'total_cobrado' => DB::table('finance.transacciones_ingreso')->where('estado_verificacion', 'aprobado')->sum('monto'),
                'pendientes_verificacion' => TransaccionIngreso::where('estado_verificacion', 'pendiente')->count(),
                'cuentas_con_deuda' => CuentaPorCobrar::whereIn('estado', ['pendiente', 'abonado'])->count()
                    + $countCursosSinCuenta
                    + $countTalleresSinCuenta
                    + count($serviciosItems),
                'distribucion' => [
                    'cursos' => $saldoDeudor((clone $base)->where(fn($q) => $q->whereNotNull('matricula_id')->orWhereNotNull('solicitud_inscripcion_id'))) + $pendienteCursosSinCuenta,
                    'talleres' => $saldoDeudor((clone $base)->whereNotNull('inscripcion_taller_id')),
                    'servicios' => $saldoDeudor((clone $base)->where(fn($q) => $q
                        ->whereNotNull('reserva_aula_id')
                        ->orWhereNotNull('reserva_podcast_id')
                        ->orWhereNotNull('alquiler_equipo_id')
                        ->orWhereNotNull('servicio_streaming_id')
                        ->orWhereNotNull('servicio_produccion_id')
                        ->orWhereNotNull('edicion_video_id')
                        ->orWhereNotNull('clase_extra_id')
                        ->orWhereNotNull('asesoria_id')
                        ->orWhereNotNull('reserva_radio_id')
                    )),
                ],
                'sin_cuenta' => [
                    'talleres' => [
                        'total' => $totalTalleresSinCuenta,
                        'count' => $countTalleresSinCuenta,
                        'items' => $talleresItems,
                    ],
                    'servicios' => [
                        'total' => $totalServiciosSinCuenta,
                        'count' => count($serviciosItems),
                        'items' => $serviciosItems,
                    ],
                    'cursos' => [
                        'total' => $totalCursosSinCuenta,
                        'count' => $countCursosSinCuenta,
                        'items' => $cursosSinCuentaItems,
                    ],
                ],
            ];
        });

        return response()->json($stats);
    }

    public function registrarPago(Request $request): JsonResponse
    {
        $request->validate([
            'cuenta_cobrar_id' => [
                'required',
                'uuid',
                Rule::exists('pgsql.finance.cuentas_por_cobrar', 'id'),
            ],
            'monto' => 'required|numeric|min:0.01',
            'metodo_pago' => 'required|string',
            'comprobante_url' => 'nullable|string',
            'fecha_pago' => 'nullable|date',
            'observaciones' => 'nullable|string'
        ]);

        DB::beginTransaction();
        try {
            $cuenta = CuentaPorCobrar::lockForUpdate()->find($request->cuenta_cobrar_id);
            
            $saldo = $cuenta->monto_total - $cuenta->monto_abonado;
            if ($request->monto > ($saldo + 0.01)) { 
                return response()->json(['mensaje' => 'El monto ($' . $request->monto . ') supera el saldo pendiente ($' . $saldo . ')'], 422);
            }

            $personaId = auth()->user()->persona_id ?? null;
            if ($personaId && !\App\Models\Persona::where('id', $personaId)->exists()) {
                $personaId = null;
            }

            $transaccion = TransaccionIngreso::create([
                'cuenta_cobrar_id' => $cuenta->id,
                'monto' => $request->monto,
                'metodo_pago' => $request->metodo_pago,
                'comprobante_url' => $request->comprobante_url,
                'fecha_pago' => $request->fecha_pago ?? now(),
                'registrado_por' => $personaId,
                'observaciones' => $request->observaciones,
                'estado_verificacion' => 'aprobado',
                'verificado_por' => $personaId,
                'fecha_verificacion' => now(),
            ]);

            $cuenta->monto_abonado += $request->monto;
            $nuevoSaldo = $cuenta->monto_total - $cuenta->monto_abonado;
            $cuenta->estado = $nuevoSaldo <= 0 ? 'pagado' : 'abonado';
            $cuenta->save();

            if ($nuevoSaldo <= 0 && $cuenta->reserva_podcast_id) {
                $cuenta->reservaPodcast()->update(['estado' => 'completado']);
            }

            DB::commit();
            Cache::forget('finance.resumen');
            return response()->json([
                'mensaje' => 'Pago registrado correctamente',
                'datos' => $transaccion
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error registrando pago: " . $e->getMessage());
            return response()->json(['mensaje' => 'Error al registrar el pago: ' . $e->getMessage()], 500);
        }
    }

    public function getTransacciones(Request $request): JsonResponse
    {
        $query = TransaccionIngreso::with([
            'cuentaPorCobrar.matricula.estudiante',
            'cuentaPorCobrar.solicitudInscripcion.estudiante',
            'cuentaPorCobrar.solicitudInscripcion.participanteExterno',
            'registrador:id,nombres,apellidos',
            'verificador:id,nombres,apellidos'
        ]);

        if ($request->has('estado_verificacion')) {
            $query->where('estado_verificacion', $request->estado_verificacion);
        }

        $transacciones = $query->orderBy('fecha_pago', 'desc')->paginate($request->get('per_page', 15));
        return response()->json($transacciones);
    }

    public function uploadComprobantePago(Request $request): JsonResponse
    {
        $request->validate(['archivo' => 'required|file|image|max:5120']);
        $file = $request->file('archivo');
        $filename = \Illuminate\Support\Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('comprobantes', $filename, 'public');
        return response()->json(['data' => ['url' => Storage::disk('public')->url($path)]], 201);
    }

    public function registrarPagosIniciales(StorePagoInicialRequest $request): JsonResponse
    {
        $response = DB::transaction(function () use ($request) {
            $resultados = [];

            foreach ($request->pagos as $pago) {
                $linea = LineaPagoModulo::findOrFail($pago['linea_pago_modulo_id']);

                // Ajuste de precio para este estudiante, si se solicitó
                if (! empty($pago['monto_ajustado']) && $pago['monto_ajustado'] != $linea->monto_ajustado) {
                    $linea->update([
                        'monto_ajustado' => $pago['monto_ajustado'],
                        'motivo_ajuste' => $pago['motivo_ajuste'] ?? null,
                        'ajustado_por' => auth()->user()->persona_id ?? auth()->id(),
                        'fecha_ajuste' => now(),
                    ]);
                    $linea->refresh();
                }

                $transaccion = TransaccionIngreso::create([
                    'linea_pago_modulo_id' => $linea->id,
                    'monto' => $pago['monto'],
                    'metodo_pago' => $pago['metodo_pago'],
                    'fecha_pago' => $pago['fecha_pago'] ?? now(),
                    'comprobante_url' => $pago['comprobante_url'] ?? null,
                    'registrado_por' => auth()->user()->persona_id ?? auth()->id(),
                    'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
                ]);

                $linea->refresh();

                $resultados[] = [
                    'linea_pago_modulo_id' => $linea->id,
                    'transaccion_id' => $transaccion->id,
                    'nuevo_estado' => $linea->estado,
                    'monto_abonado' => (float) $linea->monto_abonado,
                    'monto_ajustado' => (float) $linea->monto_ajustado,
                ];
            }

            return response()->json([
                'mensaje' => count($resultados) === 1
                    ? 'Pago registrado correctamente'
                    : count($resultados) . ' pagos registrados correctamente',
                'pagos' => $resultados,
            ], 201);
        });

        Cache::forget('finance.resumen');
        return $response;
    }

    public function verificarTransaccion(Request $request, $id): JsonResponse
    {
        $request->validate([
            'estado' => 'required|in:aprobado,rechazado',
            'motivo_rechazo' => 'required_if:estado,rechazado|nullable|string',
            'observaciones' => 'nullable|string'
        ]);

        $transaccion = TransaccionIngreso::findOrFail($id);
        
        if ($transaccion->estado_verificacion !== 'pendiente') {
            return response()->json(['mensaje' => 'Esta transacción ya ha sido verificada'], 422);
        }

        $personaId = auth()->user()->persona_id ?? null;
        if ($personaId && !\App\Models\Persona::where('id', $personaId)->exists()) {
            $personaId = null;
        }

        $transaccion->update([
            'estado_verificacion' => $request->estado,
            'motivo_rechazo' => $request->motivo_rechazo,
            'observaciones' => $request->observaciones ?? $transaccion->observaciones,
            'verificado_por' => $personaId,
            'fecha_verificacion' => now()
        ]);

        return response()->json([
            'mensaje' => 'Transacción ' . ($request->estado === 'aprobado' ? 'aprobada' : 'rechazada') . ' correctamente',
            'datos' => $transaccion
        ]);
    }

    public function getHistorial(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 50), 100);
        $currentPage = max((int) $request->get('page', 1), 1);

        // ── UNION paginado (solo id + tipo + fecha, liviano) ────────────────
        $ingresoIdsQuery = DB::table('finance.transacciones_ingreso')
            ->select('id', DB::raw("'ingreso' as tipo_movimiento"), 'fecha_pago');
        $egresoIdsQuery = DB::table('finance.transacciones_egreso')
            ->select('id', DB::raw("'egreso' as tipo_movimiento"), 'fecha_pago');

        // ── Filtros compartidos ──────────────────────────────────────────────
        if ($fechaDesde = $request->get('fecha_desde')) {
            $ingresoIdsQuery->where('fecha_pago', '>=', $fechaDesde);
            $egresoIdsQuery->where('fecha_pago', '>=', $fechaDesde);
        }
        if ($fechaHasta = $request->get('fecha_hasta')) {
            $ingresoIdsQuery->where('fecha_pago', '<=', $fechaHasta . ' 23:59:59');
            $egresoIdsQuery->where('fecha_pago', '<=', $fechaHasta . ' 23:59:59');
        }

        if ($search = $request->get('search')) {
            $ingresoIdsQuery->where(function ($q) use ($search) {
                $q->where('monto', 'ilike', "%{$search}%")
                  ->orWhere('metodo_pago', 'ilike', "%{$search}%")
                  ->orWhere('estado_verificacion', 'ilike', "%{$search}%")
                  ->orWhere('referencia_pago', 'ilike', "%{$search}%");
            });
            $egresoIdsQuery->where(function ($q) use ($search) {
                $q->where('monto', 'ilike', "%{$search}%")
                  ->orWhere('metodo_pago', 'ilike', "%{$search}%")
                  ->orWhere('descripcion', 'ilike', "%{$search}%")
                  ->orWhere('proveedor_beneficiario', 'ilike', "%{$search}%");
            });
        }

        $total = $ingresoIdsQuery->count() + $egresoIdsQuery->count();

        // ── UNION con LIMIT/OFFSET a nivel BD ──
        $unionIds = $ingresoIdsQuery->unionAll($egresoIdsQuery)
            ->orderBy('fecha_pago', 'desc')
            ->offset(($currentPage - 1) * $perPage)
            ->limit($perPage)
            ->get();

        // ── Cargar modelos completos solo para IDs de esta página ────────────
        $ingresoIdsOnPage = $unionIds->where('tipo_movimiento', 'ingreso')->pluck('id');
        $egresoIdsOnPage = $unionIds->where('tipo_movimiento', 'egreso')->pluck('id');

        $ingresos = collect();
        if ($ingresoIdsOnPage->isNotEmpty()) {
            $ingresos = TransaccionIngreso::with([
                'lineaPagoModulo.modulo',
                'lineaPagoModulo.matricula.estudiante',
                'lineaPagoModulo.matricula.solicitudInscripcion.estudiante',
                'lineaPagoModulo.matricula.solicitudInscripcion.participanteExterno',
                'lineaPagoModulo.matricula.cursoAbierto.catalogo',
                'cuentaPorCobrar.matricula.estudiante',
                'cuentaPorCobrar.matricula.cursoAbierto.catalogo',
                'cuentaPorCobrar.inscripcionTaller.taller',
                'cuentaPorCobrar.reservaPodcast.persona',
                'cuentaPorCobrar.reservaPodcast.clienteExterno',
                'cuentaPorCobrar.reservaPodcast.paquete',
                'cuentaPorCobrar.reservaAula.persona',
                'cuentaPorCobrar.reservaAula.clienteExterno',
                'cuentaPorCobrar.reservaAula.aula',
                'cuentaPorCobrar.alquilerEquipo.persona',
                'cuentaPorCobrar.alquilerEquipo.clienteExterno',
                'cuentaPorCobrar.alquilerEquipo.equipo',
                'cuentaPorCobrar.reservaRadio.persona',
                'cuentaPorCobrar.reservaRadio.clienteExterno',
                'registrador',
            ])->whereIn('id', $ingresoIdsOnPage)
              ->orderBy('fecha_pago', 'desc')
              ->get()
              ->keyBy(fn($i) => "i_{$i->id}");
        }

        $egresos = collect();
        if ($egresoIdsOnPage->isNotEmpty()) {
            $egresos = TransaccionEgreso::with(['categoria', 'registrador'])
                ->whereIn('id', $egresoIdsOnPage)
                ->orderBy('fecha_pago', 'desc')
                ->get()
                ->keyBy(fn($e) => "e_{$e->id}");
        }

        $ek = $egresos;
        $ik = $ingresos;

        $pageKeys = $unionIds->map(fn($item) => $item->tipo_movimiento === 'ingreso' ? "i_{$item->id}" : "e_{$item->id}");

        // ── Map items → response shape ────────────────────────────────────────
        $data = $pageKeys->map(function ($key) use ($ek, $ik) {
            $m = str_starts_with($key, 'e_') ? ($ek[$key] ?? null) : ($ik[$key] ?? null);
            if (!$m) return null;

            if (str_starts_with($key, 'e_')) {
                return [
                    'id' => $m->id,
                    'tipo_movimiento' => 'egreso',
                    'tipo' => 'individual',
                    'referencia_pago' => null,
                    'monto' => (float) $m->monto,
                    'monto_total' => (float) $m->monto,
                    'metodo_pago' => $m->metodo_pago,
                    'fecha_pago' => $m->fecha_pago?->toISOString(),
                    'estado_verificacion' => 'aprobado',
                    'comprobante_url' => $m->comprobante_url,
                    'observaciones' => $m->notas,
                    'estudiante_nombre' => $m->proveedor_beneficiario,
                    'estudiante_cedula' => null,
                    'curso_nombre' => $m->descripcion,
                    'categoria_nombre' => $m->categoria?->nombre,
                    'modulo_nombre' => null,
                    'modulos_count' => 0,
                    'modulos_detalle' => [],
                    'cuenta_por_cobrar' => null,
                ];
            }

            // ── Ingreso (existing mapping) ─────────────────────────────────
            $estudiante = null;
            $cursoNombre = null;
            $moduloNombre = null;

            if ($m->lineaPagoModulo) {
                $mat = $m->lineaPagoModulo->matricula;
                $estudiante = $mat?->estudiante
                    ?? $mat?->solicitudInscripcion?->estudiante
                    ?? $mat?->solicitudInscripcion?->participanteExterno;
                $cursoNombre = $mat?->cursoAbierto?->catalogo?->nombre;
                $moduloNombre = $m->lineaPagoModulo->modulo?->nombre_modulo;
            } elseif ($m->cuentaPorCobrar) {
                $cp = $m->cuentaPorCobrar;
                $estudiante = $cp->matricula?->estudiante
                    ?? $cp->inscripcionTaller
                    ?? $cp->reservaPodcast?->persona
                    ?? $cp->reservaPodcast?->clienteExterno
                    ?? $cp->reservaAula?->persona
                    ?? $cp->reservaAula?->clienteExterno
                    ?? $cp->alquilerEquipo?->persona
                    ?? $cp->alquilerEquipo?->clienteExterno
                    ?? $cp->reservaRadio?->persona
                    ?? $cp->reservaRadio?->clienteExterno;

                $cursoNombre = $cp->matricula?->cursoAbierto?->catalogo?->nombre
                    ?? $cp->inscripcionTaller?->taller?->nombre
                    ?? $cp->reservaPodcast?->titulo
                    ?? $cp->reservaPodcast?->paquete?->nombre
                    ?? $cp->reservaAula?->aula?->nombre
                    ?? $cp->alquilerEquipo?->equipo?->nombre;
            }

            $base = [
                'id' => $m->id,
                'tipo_movimiento' => 'ingreso',
                'referencia_pago' => $m->referencia_pago,
                'monto' => (float) $m->monto,
                'metodo_pago' => $m->metodo_pago,
                'fecha_pago' => $m->fecha_pago?->toISOString(),
                'estado_verificacion' => $m->estado_verificacion,
                'comprobante_url' => $m->comprobante_url,
                'observaciones' => $m->observaciones,
                'estudiante_nombre' => $estudiante ? trim(($estudiante->nombres ?? '') . ' ' . ($estudiante->apellidos ?? '')) : null,
                'estudiante_cedula' => $estudiante?->cedula ?? null,
                'curso_nombre' => $cursoNombre,
                'modulo_nombre' => $moduloNombre,
                'cuenta_por_cobrar' => $m->cuentaPorCobrar,
            ];

            return array_merge($base, [
                'tipo' => $base['modulo_nombre'] ? 'individual' : 'individual',
                'monto_total' => $base['monto'],
                'modulos_count' => $base['modulo_nombre'] ? 1 : 0,
                'modulos_detalle' => $base['modulo_nombre']
                    ? [['id' => $base['id'], 'modulo_nombre' => $base['modulo_nombre'], 'monto' => $base['monto']]]
                    : [],
            ]);
        })->values();

        $purgados = \App\Models\ArchivoEliminado::where(function ($q) use ($pageKeys, $ek, $ik) {
            $egresoIds = $pageKeys->filter(fn($k) => str_starts_with($k, 'e_'))
                ->map(fn($k) => $ek[$k]->id ?? null)->filter();
            $ingresoIds = $pageKeys->filter(fn($k) => str_starts_with($k, 'i_'))
                ->map(fn($k) => $ik[$k]->id ?? null)->filter();

            if ($egresoIds->isNotEmpty()) {
                $q->orWhere(function ($sq) use ($egresoIds) {
                    $sq->where('model_type', \App\Models\TransaccionEgreso::class)
                       ->whereIn('model_id', $egresoIds->values());
                });
            }
            if ($ingresoIds->isNotEmpty()) {
                $q->orWhere(function ($sq) use ($ingresoIds) {
                    $sq->where('model_type', \App\Models\TransaccionIngreso::class)
                       ->whereIn('model_id', $ingresoIds->values());
                });
            }
        })
        ->where('field_name', 'comprobante_url')
        ->get()
        ->groupBy(fn($ae) => $ae->model_id . '|' . $ae->field_name);

        $data = $data->map(function ($item) use ($purgados) {
            if (!empty($item['id']) && !empty($item['comprobante_url'])) {
                $isEgreso = ($item['tipo_movimiento'] ?? '') === 'egreso';
                $key = $item['id'] . '|comprobante_url';
                $entries = $purgados[$key] ?? collect();

                if ($entries->isNotEmpty()) {
                    $last = $entries->sortByDesc('created_at')->first();
                    $item['comprobante_purgado'] = in_array($last->accion, [
                        \App\Models\ArchivoEliminado::ACCION_BORRADO_ARCHIVO,
                        \App\Models\ArchivoEliminado::ACCION_BORRADO_REGISTRO,
                    ]);
                } else {
                    $item['comprobante_purgado'] = false;
                }
            } else {
                $item['comprobante_purgado'] = false;
            }
            return $item;
        });

        return response()->json([
            'data' => $data,
            'current_page' => $currentPage,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) ceil($total / $perPage),
        ]);
    }

    public function getTransaccionDetalle($id): JsonResponse
    {
        if (! \Illuminate\Support\Str::isUuid($id)) {
            return response()->json(['mensaje' => 'ID de transacción inválido'], 404);
        }

        $t = TransaccionIngreso::with([
            'lineaPagoModulo.modulo',
            'lineaPagoModulo.matricula.estudiante',
            'lineaPagoModulo.matricula.solicitudInscripcion.estudiante',
            'lineaPagoModulo.matricula.solicitudInscripcion.participanteExterno',
            'lineaPagoModulo.matricula.cursoAbierto.catalogo',
            'cuentaPorCobrar.matricula.estudiante',
            'cuentaPorCobrar.matricula.cursoAbierto.catalogo',
            'cuentaPorCobrar.inscripcionTaller.taller',
            'cuentaPorCobrar.reservaPodcast.persona',
            'cuentaPorCobrar.reservaPodcast.clienteExterno',
            'cuentaPorCobrar.reservaPodcast.paquete',
            'cuentaPorCobrar.reservaAula.persona',
            'cuentaPorCobrar.reservaAula.clienteExterno',
            'cuentaPorCobrar.reservaAula.aula',
            'cuentaPorCobrar.alquilerEquipo.persona',
            'cuentaPorCobrar.alquilerEquipo.clienteExterno',
            'cuentaPorCobrar.alquilerEquipo.equipo',
            'cuentaPorCobrar.reservaRadio.persona',
            'cuentaPorCobrar.reservaRadio.clienteExterno',
            'registrador',
            'verificador',
        ])->findOrFail($id);

        $estudiante = null;
        $cursoNombre = null;
        $moduloNombre = null;
        $tallerNombre = null;

        if ($t->lineaPagoModulo) {
            $mat = $t->lineaPagoModulo->matricula;
            $estudiante = $mat?->estudiante
                ?? $mat?->solicitudInscripcion?->estudiante
                ?? $mat?->solicitudInscripcion?->participanteExterno;
            $cursoNombre = $mat?->cursoAbierto?->catalogo?->nombre;
            $moduloNombre = $t->lineaPagoModulo->modulo?->nombre_modulo;
        } elseif ($t->cuentaPorCobrar) {
            $cp = $t->cuentaPorCobrar;
            $estudiante = $cp->matricula?->estudiante
                ?? $cp->inscripcionTaller
                ?? $cp->reservaPodcast?->persona
                ?? $cp->reservaPodcast?->clienteExterno
                ?? $cp->reservaAula?->persona
                ?? $cp->reservaAula?->clienteExterno
                ?? $cp->alquilerEquipo?->persona
                ?? $cp->alquilerEquipo?->clienteExterno
                ?? $cp->reservaRadio?->persona
                ?? $cp->reservaRadio?->clienteExterno;
            $cursoNombre = $cp->matricula?->cursoAbierto?->catalogo?->nombre
                ?? $cp->inscripcionTaller?->taller?->nombre
                ?? $cp->reservaPodcast?->titulo
                ?? $cp->reservaPodcast?->paquete?->nombre
                ?? $cp->reservaAula?->aula?->nombre
                ?? $cp->alquilerEquipo?->equipo?->nombre;
            $tallerNombre = $cp->inscripcionTaller?->taller?->nombre;
        }

        $lineaData = null;
        if ($t->lineaPagoModulo) {
            $lm = $t->lineaPagoModulo;
            $mat = $lm->matricula;
            $lineaData = [
                'id' => $lm->id,
                'modulo' => $lm->modulo ? [
                    'nombre_modulo' => $lm->modulo->nombre_modulo,
                    'nombre' => $lm->modulo->nombre ?? null,
                ] : null,
                'matricula' => $mat ? [
                    'id' => $mat->id,
                    'estudiante' => $mat->estudiante ? [
                        'nombres' => $mat->estudiante->nombres,
                        'apellidos' => $mat->estudiante->apellidos,
                    ] : null,
                    'solicitud_inscripcion' => $mat->solicitudInscripcion ? [
                        'estudiante' => $mat->solicitudInscripcion->estudiante ? [
                            'nombres' => $mat->solicitudInscripcion->estudiante->nombres,
                            'apellidos' => $mat->solicitudInscripcion->estudiante->apellidos,
                        ] : null,
                        'participante_externo' => $mat->solicitudInscripcion->participanteExterno ? [
                            'nombres' => $mat->solicitudInscripcion->participanteExterno->nombres,
                            'apellidos' => $mat->solicitudInscripcion->participanteExterno->apellidos,
                        ] : null,
                    ] : null,
                    'curso_abierto' => $mat->cursoAbierto ? [
                        'nombre_instancia' => $mat->cursoAbierto->nombre_instancia,
                        'catalogo' => $mat->cursoAbierto->catalogo ? [
                            'nombre' => $mat->cursoAbierto->catalogo->nombre,
                        ] : null,
                    ] : null,
                ] : null,
            ];
        }

        $comprobantePurgado = false;
        if ($t->comprobante_url) {
            $comprobantePurgado = \App\Models\ArchivoEliminado::archivoFueEliminado(
                \App\Models\TransaccionIngreso::class,
                $t->id,
                'comprobante_url'
            );
        }

        return response()->json([
            'datos' => [
                'id' => $t->id,
                'monto' => (float) $t->monto,
                'metodo_pago' => $t->metodo_pago,
                'fecha_pago' => $t->fecha_pago?->format('Y-m-d H:i'),
                'estado_verificacion' => $t->estado_verificacion,
                'comprobante_url' => $t->comprobante_url,
                'comprobante_purgado' => $comprobantePurgado,
                'observaciones' => $t->observaciones,
                'motivo_rechazo' => $t->motivo_rechazo,
                'fecha_verificacion' => $t->fecha_verificacion?->format('Y-m-d H:i'),
                'estudiante_nombre' => $estudiante ? trim(($estudiante->nombres ?? '') . ' ' . ($estudiante->apellidos ?? '')) : null,
                'curso_nombre' => $cursoNombre,
                'modulo_nombre' => $moduloNombre,
                'taller_nombre' => $tallerNombre,
                'cuenta_por_cobrar' => $t->cuentaPorCobrar,
                'linea_pago_modulo' => $lineaData,
                'registrado_por' => $t->registrador ? trim(($t->registrador->nombres ?? '') . ' ' . ($t->registrador->apellidos ?? '')) : null,
                'verificado_por' => $t->verificador ? trim(($t->verificador->nombres ?? '') . ' ' . ($t->verificador->apellidos ?? '')) : null,
            ]
        ]);
    }

    public function getCursoFinanciero($id): JsonResponse
    {
        $curso = \App\Models\CursoAbierto::with([
            'catalogo', 'docente', 'ciudad', 'horario.diasSemana',
            'modulos' => fn($q) => $q->orderBy('numero_orden'),
        ])->findOrFail($id);

        $matriculas = Matricula::with([
            'estudiante', 'solicitudInscripcion.estudiante', 'solicitudInscripcion.participanteExterno', 'lineasPago.modulo',
        ])->where('curso_abierto_id', $id)->get();

        $modulos = $curso->modulos;
        $estudiantesData = [];

        $totalEsperadoCatalogo = 0;
        $totalRecaudadoReal = 0;

        foreach ($matriculas as $matricula) {
            $est = $matricula->estudiante
                ?? $matricula->solicitudInscripcion?->estudiante
                ?? $matricula->solicitudInscripcion?->participanteExterno;
            $filasModulos = [];
            $totalPagadoEstudiante = 0;
            $totalEsperadoEstudiante = 0;

            foreach ($modulos as $mod) {
                $lp = $matricula->lineasPago->firstWhere('modulo_id', $mod->id);
                $precioFallback = $mod->precio_base ?? $curso->precio_base ?? 0;
                $precio = $lp ? (float) $lp->monto_ajustado : (float) $precioFallback;
                $abonado = $lp ? (float) $lp->monto_abonado : 0;
                $saldo = max(0, $precio - $abonado);

                $filasModulos[$mod->id] = [
                    'modulo_id' => $mod->id,
                    'nombre_modulo' => $mod->nombre_modulo,
                    'numero_orden' => $mod->numero_orden,
                    'precio' => $precio,
                    'abonado' => $abonado,
                    'saldo' => $saldo,
                    'estado' => $lp ? $lp->estado : 'pendiente',
                    'es_ajustado' => $lp ? ($lp->monto_original != $lp->monto_ajustado) : false,
                    'monto_original' => $lp ? (float) $lp->monto_original : (float) ($mod->precio_base ?? $curso->precio_base ?? 0),
                    'motivo_ajuste' => $lp ? $lp->motivo_ajuste : null,
                ];

                $totalPagadoEstudiante += $abonado;
                $totalEsperadoEstudiante += $precio;
                $totalEsperadoCatalogo += $precio;
            }

            $esExterno = $est && $est instanceof \App\Models\ClienteExterno;

            $estudiantesData[] = [
                'matricula_id' => $matricula->id,
                'nombre' => $est
                    ? trim(($est->nombres ?? '') . ' ' . ($est->apellidos ?? ''))
                    : '—',
                'cedula' => $est?->cedula ?? '—',
                'telefono' => $est?->celular ?? $est?->telefono ?? '—',
                'ciudad' => $est?->ciudad?->nombre ?? $est?->ciudad ?? '—',
                'modulos' => $filasModulos,
                'total_pagado' => $totalPagadoEstudiante,
                'total_esperado' => $totalEsperadoEstudiante,
            ];

            $totalRecaudadoReal += $totalPagadoEstudiante;
        }

        $horarioStr = '—';
        if ($curso->horario) {
            $dias = $curso->horario->diasSemana
                ->filter(fn($d) => !empty($d->nombre_dia))
                ->pluck('nombre_dia')
                ->map(fn($d) => ucfirst($d))
                ->join(', ');
            $horaIni = $curso->horario->hora_inicio ? substr($curso->horario->hora_inicio, 0, 5) : '';
            $horaFin = $curso->horario->hora_fin ? substr($curso->horario->hora_fin, 0, 5) : '';
            if ($horaIni) {
                $horarioStr = ($dias ? $dias . ' ' : '') . $horaIni . ($horaFin ? ' - ' . $horaFin : '');
            }
        }

        return response()->json([
            'datos' => [
                'curso' => [
                    'id' => $curso->id,
                    'nombre' => $curso->nombre_instancia ?? $curso->catalogo?->nombre ?? 'Curso',
                    'nombre_instancia' => $curso->nombre_instancia,
                    'instructor' => $curso->docente ? trim(($curso->docente->nombres ?? '') . ' ' . ($curso->docente->apellidos ?? '')) : '—',
                    'fecha_inicio' => $curso->fecha_inicio?->format('Y-m-d'),
                    'fecha_fin' => $curso->fecha_fin?->format('Y-m-d'),
                    'ciudad' => $curso->ciudad?->nombre ?? '—',
                    'horario' => $horarioStr,
                    'modalidad' => $curso->modalidad,
                ],
                'modulos' => $modulos->map(fn($m) => [
                    'id' => $m->id,
                    'nombre' => $m->nombre_modulo,
                    'numero_orden' => $m->numero_orden,
                    'precio_base' => (float) ($m->precio_base ?? $curso->precio_base ?? 0),
                ]),
                'estudiantes' => $estudiantesData,
                'totales' => [
                    'estudiantes' => $matriculas->count(),
                    'modulos' => $modulos->count(),
                    'esperado_catalogo' => $totalEsperadoCatalogo,
                    'recaudado_real' => $totalRecaudadoReal,
                ],
            ]
        ]);
    }

    public function getTallerFinanciero($id): JsonResponse
    {
        $taller = \App\Models\Taller::findOrFail($id);
        $inscripciones = InscripcionTaller::where('taller_id', $id)
            ->whereIn('estado', ['activo', 'completado'])
            ->get();

        $participantes = [];
        $totalRecaudado = 0;
        $totalEsperado = 0;

        foreach ($inscripciones as $ins) {
            $cuenta = CuentaPorCobrar::where('inscripcion_taller_id', $ins->id)->first();
            $montoTotal = $cuenta?->monto_total ?? $taller->precio ?? 0;
            $montoAbonado = $cuenta?->monto_abonado ?? ($ins->monto_pagado ?? $ins->precio_pagado ?? 0);
            $montoPagado = (float) ($ins->monto_pagado ?? $ins->precio_pagado ?? 0);
            $saldoPendiente = (float) $cuenta?->saldo_pendiente ?? max(0, $montoTotal - $montoAbonado);

            $totalEsperado += $montoTotal;
            $totalRecaudado += $montoAbonado;

            $participantes[] = [
                'id' => $ins->id,
                'nombres' => $ins->nombres,
                'apellidos' => $ins->apellidos,
                'estudiante_nombre' => trim(($ins->nombres ?? '') . ' ' . ($ins->apellidos ?? '')),
                'cedula' => $ins->cedula ?? '—',
                'correo' => $ins->correo ?? '—',
                'telefono' => $ins->telefono ?? '—',
                'tipo_pago' => $ins->tipo_pago ?? 'completo',
                'monto_pagado' => $montoPagado,
                'monto_total' => $montoTotal,
                'monto_abonado' => $montoAbonado,
                'saldo_pendiente' => $saldoPendiente,
                'precio_taller' => (float) ($taller->precio ?? 0),
                'pago_verificado' => (bool) $ins->pago_verificado,
                'esta_pagado_completo' => $montoAbonado >= $montoTotal,
                'motivo_ajuste' => $ins->motivo_ajuste,
            ];
        }

        return response()->json([
            'datos' => [
                'taller' => [
                    'id' => $taller->id,
                    'nombre' => $taller->nombre,
                    'instructor' => $taller->instructor?->nombres . ' ' . $taller->instructor?->apellidos,
                    'instructor_nombre' => $taller->instructor?->nombres . ' ' . $taller->instructor?->apellidos,
                    'fecha' => $taller->fecha?->format('Y-m-d'),
                    'precio' => (float) ($taller->precio ?? 0),
                    'modalidad' => $taller->modalidad,
                    'capacidad' => $taller->capacidad_maxima,
                ],
                'participantes' => $participantes,
                'totales' => [
                    'inscritos' => $inscripciones->count(),
                    'esperado' => $totalEsperado,
                    'recaudado' => $totalRecaudado,
                ],
            ]
        ]);
    }

    public function getLineasPagoPorMatricula($matriculaId): JsonResponse
    {
        $lineas = LineaPagoModulo::with('modulo')
            ->where('matricula_id', $matriculaId)
            ->orderBy('orden')
            ->get()
            ->map(fn($lp) => [
                'id' => $lp->id,
                'modulo_id' => $lp->modulo_id,
                'nombre_modulo' => $lp->modulo?->nombre_modulo ?? ('Módulo ' . ($lp->orden + 1)),
                'numero_orden' => $lp->modulo?->numero_orden ?? $lp->orden,
                'monto_original' => (float) $lp->monto_original,
                'monto_ajustado' => (float) $lp->monto_ajustado,
                'monto_abonado' => (float) $lp->monto_abonado,
                'saldo_pendiente' => (float) $lp->saldo_pendiente,
                'estado' => $lp->estado,
            ]);

        return response()->json(['datos' => $lineas]);
    }

    public function getHistorialParticipanteTaller($tallerId, $participanteId): JsonResponse
    {
        $inscripcion = InscripcionTaller::where('taller_id', $tallerId)
            ->where('id', $participanteId)
            ->firstOrFail();

        $taller = \App\Models\Taller::findOrFail($tallerId);
        $cuenta = CuentaPorCobrar::where('inscripcion_taller_id', $participanteId)->first();
        $montoTotal = $cuenta?->monto_total ?? $taller->precio ?? 0;
        $montoAbonado = $cuenta?->monto_abonado ?? ($inscripcion->monto_pagado ?? 0);
        $saldo = (float) ($cuenta?->saldo_pendiente ?? max(0, $montoTotal - $montoAbonado));

        $transacciones = TransaccionIngreso::whereHas('cuentaPorCobrar', fn($q) => $q->where('inscripcion_taller_id', $participanteId))
            ->with(['cuentaPorCobrar', 'registrador'])
            ->orderBy('fecha_pago', 'desc')
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'monto' => (float) $t->monto,
                'metodo_pago' => $t->metodo_pago,
                'fecha_pago' => $t->fecha_pago?->format('Y-m-d H:i'),
                'estado_verificacion' => $t->estado_verificacion,
                'comprobante_url' => $t->comprobante_url,
                'observaciones' => $t->observaciones,
            ]);

        return response()->json([
            'datos' => [
                'participante' => [
                    'id' => $inscripcion->id,
                    'nombres' => $inscripcion->nombres,
                    'apellidos' => $inscripcion->apellidos,
                ],
                'nombre_participante' => trim(($inscripcion->nombres ?? '') . ' ' . ($inscripcion->apellidos ?? '')),
                'taller_nombre' => $taller->nombre,
                'taller' => ['nombre' => $taller->nombre],
                'precio_taller' => (float) ($taller->precio ?? 0),
                'monto_total' => $montoTotal,
                'monto_abonado' => $montoAbonado,
                'total_pagado' => $montoAbonado,
                'saldo' => $saldo,
                'saldo_pendiente' => $saldo,
                'motivo_ajuste' => $inscripcion->motivo_ajuste,
                'cuenta_id' => $cuenta->id,
                'transacciones' => $transacciones,
            ]
        ]);
    }

    public function getEstudianteFinancieroCurso($cursoId, $matriculaId): JsonResponse
    {
        $curso = \App\Models\CursoAbierto::with('catalogo')->findOrFail($cursoId);
        $matricula = Matricula::with([
            'estudiante',
            'solicitudInscripcion.estudiante',
            'solicitudInscripcion.participanteExterno',
            'lineasPago.modulo',
        ])->findOrFail($matriculaId);

        $est = $matricula->estudiante
            ?? $matricula->solicitudInscripcion?->estudiante
            ?? $matricula->solicitudInscripcion?->participanteExterno;

        $modulosData = $matricula->lineasPago
            ->sortBy('orden')
            ->map(fn($lp) => [
                'linea_pago_modulo_id' => $lp->id,
                'modulo_id' => $lp->modulo_id,
                'nombre_modulo' => $lp->modulo?->nombre_modulo ?? ('Módulo ' . ($lp->orden + 1)),
                'numero_orden' => $lp->modulo?->numero_orden ?? $lp->orden,
                'monto_ajustado' => (float) $lp->monto_ajustado,
                'monto_abonado' => (float) $lp->monto_abonado,
                'saldo_pendiente' => (float) $lp->saldo_pendiente,
                'estado' => $lp->estado,
            ])->values();

        $transacciones = TransaccionIngreso::whereHas('lineaPagoModulo', fn($q) => $q->where('matricula_id', $matriculaId))
            ->with(['lineaPagoModulo.modulo', 'registrador'])
            ->orderBy('fecha_pago', 'desc')
            ->get()
            ->map(fn($t) => [
                'id' => $t->id,
                'monto' => (float) $t->monto,
                'metodo_pago' => $t->metodo_pago,
                'fecha_pago' => $t->fecha_pago?->format('Y-m-d H:i'),
                'estado_verificacion' => $t->estado_verificacion,
                'comprobante_url' => $t->comprobante_url,
                'modulo_nombre' => $t->lineaPagoModulo?->modulo?->nombre_modulo ?? null,
                'referencia_pago' => $t->referencia_pago,
            ]);

        return response()->json([
            'datos' => [
                'estudiante' => [
                    'nombre' => $est ? trim(($est->nombres ?? '') . ' ' . ($est->apellidos ?? '')) : '—',
                    'cedula' => $est?->cedula ?? '—',
                ],
                'curso' => [
                    'id' => $curso->id,
                    'nombre' => $curso->catalogo?->nombre ?? 'Curso',
                ],
                'modulos' => $modulosData,
                'historial' => $transacciones,
            ]
        ]);
    }

    public function getServicioFinanciero($tipo, $id): JsonResponse
    {
        $modelos = [
            'podcast' => [\App\Models\Services\ReservaPodcast::class, 'reserva_podcast_id'],
            'aula' => [\App\Models\Services\ReservaAula::class, 'reserva_aula_id'],
            'equipo' => [\App\Models\Services\AlquilerEquipo::class, 'alquiler_equipo_id'],
            'edicion' => [\App\Models\Services\TrabajoEdicion::class, 'edicion_video_id'],
            'radio' => [\App\Models\Services\ReservaRadio::class, 'reserva_radio_id'],
        ];

        if (!isset($modelos[$tipo])) {
            return response()->json(['message' => 'Tipo de servicio no válido'], 404);
        }

        [$modeloClass, $fkColumn] = $modelos[$tipo];

        $servicio = $modeloClass::findOrFail($id);

        $cuenta = CuentaPorCobrar::where($fkColumn, $id)->first();

        $nombreCliente = '—';
        if ($servicio->relationLoaded('persona') || method_exists($servicio, 'persona')) {
            $nombreCliente = $servicio->persona
                ? trim(($servicio->persona->nombres ?? '') . ' ' . ($servicio->persona->apellidos ?? ''))
                : '—';
        }
        if ($nombreCliente === '—' && method_exists($servicio, 'clienteExterno') && $servicio->clienteExterno) {
            $nombreCliente = trim(($servicio->clienteExterno->nombres ?? '') . ' ' . ($servicio->clienteExterno->apellidos ?? ''));
        }

        $montoTotal = (float) ($servicio->precio_total ?? 0);
        $montoAbonado = $cuenta ? (float) ($cuenta->monto_abonado ?? 0) : 0;
        $saldoPendiente = max(0, $montoTotal - $montoAbonado);

        $nombreServicio = 'Servicio';
        if ($tipo === 'podcast') {
            $nombreServicio = $servicio->paquete?->nombre ?? 'Podcast';
        } elseif ($tipo === 'aula') {
            $nombreServicio = $servicio->aula?->nombre ?? 'Aula';
        } elseif ($tipo === 'equipo') {
            $nombreServicio = $servicio->equipo?->nombre ?? 'Equipo';
        } elseif ($tipo === 'edicion') {
            $nombreServicio = 'Edición de Video';
        } elseif ($tipo === 'radio') {
            $nombreServicio = 'Radio';
        }

        return response()->json([
            'datos' => [
                'id' => $servicio->id,
                'tipo' => $tipo,
                'nombre_servicio' => $nombreServicio,
                'nombre_cliente' => $nombreCliente,
                'fecha_reserva' => $servicio->fecha_reserva ?? null,
                'hora_inicio' => $servicio->hora_inicio ?? null,
                'hora_fin' => $servicio->hora_fin ?? null,
                'monto_total' => $montoTotal,
                'monto_abonado' => $montoAbonado,
                'saldo_pendiente' => $saldoPendiente,
                'estado' => $servicio->estado ?? '—',
                'cuenta_cobrar_id' => $cuenta?->id,
                'cuenta_estado' => $cuenta?->estado ?? 'pendiente',
            ],
        ]);
    }

    /**
     * Registrar pago directo de un servicio. Si no tiene CuentaPorCobrar la crea automáticamente.
     */
    public function pagarServicio(Request $request, $tipo, $id): JsonResponse
    {
        $modelos = [
            'podcast' => [\App\Models\Services\ReservaPodcast::class, 'reserva_podcast_id'],
            'aula' => [\App\Models\Services\ReservaAula::class, 'reserva_aula_id'],
            'equipo' => [\App\Models\Services\AlquilerEquipo::class, 'alquiler_equipo_id'],
            'edicion' => [\App\Models\Services\TrabajoEdicion::class, 'edicion_video_id'],
            'radio' => [\App\Models\Services\ReservaRadio::class, 'reserva_radio_id'],
        ];

        if (!isset($modelos[$tipo])) {
            return response()->json(['message' => 'Tipo de servicio no válido'], 404);
        }

        [$modeloClass, $fkColumn] = $modelos[$tipo];
        $servicio = $modeloClass::findOrFail($id);

        $request->validate([
            'monto' => 'required|numeric|min:0.01',
            'metodo_pago' => 'required|string',
            'comprobante_url' => 'nullable|string',
            'fecha_pago' => 'nullable|date',
            'observaciones' => 'nullable|string',
        ]);

        $montoTotal = (float) ($servicio->precio_total ?? 0);

        DB::beginTransaction();
        try {
            $cuenta = CuentaPorCobrar::where($fkColumn, $id)->first();
            if (!$cuenta) {
                $cuenta = CuentaPorCobrar::create([
                    $fkColumn => $id,
                    'monto_total' => $montoTotal,
                    'monto_abonado' => 0,
                    'estado' => 'pendiente',
                    'es_legacy' => false,
                ]);
            }

            $saldo = $cuenta->monto_total - $cuenta->monto_abonado;
            if ($request->monto > ($saldo + 0.01)) {
                DB::rollBack();
                return response()->json(['message' => "El monto (\${$request->monto}) supera el saldo pendiente (\${$saldo})"], 422);
            }

            $personaId = auth()->user()->persona_id ?? null;
            if ($personaId && !\App\Models\Persona::where('id', $personaId)->exists()) {
                $personaId = null;
            }

            $transaccion = TransaccionIngreso::create([
                'cuenta_cobrar_id' => $cuenta->id,
                'monto' => $request->monto,
                'metodo_pago' => $request->metodo_pago,
                'comprobante_url' => $request->comprobante_url,
                'fecha_pago' => $request->fecha_pago ?? now(),
                'registrado_por' => $personaId,
                'observaciones' => $request->observaciones,
                'estado_verificacion' => 'aprobado',
                'verificado_por' => $personaId,
                'fecha_verificacion' => now(),
            ]);

            $cuenta->monto_abonado += $request->monto;
            $nuevoSaldo = $cuenta->monto_total - $cuenta->monto_abonado;
            $cuenta->estado = $nuevoSaldo <= 0 ? 'pagado' : 'abonado';
            $cuenta->save();

            if ($nuevoSaldo <= 0 && $cuenta->reserva_podcast_id) {
                $cuenta->reservaPodcast()->update(['estado' => 'completado']);
            }

            Cache::forget('finance.resumen');
            DB::commit();

            return response()->json([
                'message' => 'Pago registrado exitosamente',
                'cuenta_cobrar_id' => $cuenta->id,
                'transaccion_id' => $transaccion->id,
                'monto_pagado' => (float) $request->monto,
                'saldo_pendiente' => $nuevoSaldo,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al registrar pago: ' . $e->getMessage()], 500);
        }
    }

    public function getIngresos(Request $request): JsonResponse
    {
        $query = TransaccionIngreso::with([
            'cuentaPorCobrar.matricula.estudiante',
            'cuentaPorCobrar.matricula.cursoAbierto.catalogo',
            'cuentaPorCobrar.solicitudInscripcion.estudiante',
            'cuentaPorCobrar.solicitudInscripcion.participanteExterno',
            'cuentaPorCobrar.inscripcionTaller.taller',
            'cuentaPorCobrar.reservaPodcast.persona',
            'cuentaPorCobrar.reservaPodcast.clienteExterno',
            'cuentaPorCobrar.reservaPodcast.paquete',
            'cuentaPorCobrar.reservaAula.persona',
            'cuentaPorCobrar.reservaAula.clienteExterno',
            'cuentaPorCobrar.reservaAula.aula',
            'cuentaPorCobrar.alquilerEquipo.persona',
            'cuentaPorCobrar.alquilerEquipo.clienteExterno',
            'cuentaPorCobrar.alquilerEquipo.equipo',
            'cuentaPorCobrar.reservaRadio.persona',
            'cuentaPorCobrar.reservaRadio.clienteExterno',
            'cuentaPorCobrar.reservaRadio.tarifa',
            'lineaPagoModulo.modulo',
            'lineaPagoModulo.matricula.estudiante',
            'lineaPagoModulo.matricula.cursoAbierto.catalogo',
        ]);

        if ($desde = $request->get('fecha_desde')) {
            $query->where('fecha_pago', '>=', $desde);
        }
        if ($hasta = $request->get('fecha_hasta')) {
            $query->where('fecha_pago', '<=', $hasta . ' 23:59:59');
        }
        if ($metodo = $request->get('metodo_pago')) {
            $query->where('metodo_pago', $metodo);
        }

        $categoriaFilter = $request->get('categoria');
        if ($categoriaFilter) {
            if ($categoriaFilter === 'cursos') {
                $query->whereHas('cuentaPorCobrar', fn($q) => $q->whereNotNull('matricula_id'));
            } elseif ($categoriaFilter === 'talleres') {
                $query->whereHas('cuentaPorCobrar', fn($q) => $q->whereNotNull('inscripcion_taller_id'));
            } else {
                $fkMap = [
                    'aulas' => 'reserva_aula_id', 'podcast' => 'reserva_podcast_id',
                    'radio' => 'reserva_radio_id', 'edicion' => 'edicion_video_id',
                    'equipos' => 'alquiler_equipo_id', 'streaming' => 'servicio_streaming_id',
                    'produccion' => 'servicio_produccion_id', 'asesorias' => 'clase_extra_id',
                ];
                if (isset($fkMap[$categoriaFilter])) {
                    $query->whereHas('cuentaPorCobrar', fn($q) => $q->whereNotNull($fkMap[$categoriaFilter]));
                }
            }
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('cuentaPorCobrar.matricula.estudiante', fn($sq) =>
                    $sq->where('nombres', 'ilike', "%{$search}%")->orWhere('apellidos', 'ilike', "%{$search}%"))
                  ->orWhereHas('cuentaPorCobrar.reservaPodcast.persona', fn($sq) =>
                    $sq->where('nombres', 'ilike', "%{$search}%")->orWhere('apellidos', 'ilike', "%{$search}%"))
                  ->orWhereHas('cuentaPorCobrar.reservaAula.persona', fn($sq) =>
                    $sq->where('nombres', 'ilike', "%{$search}%")->orWhere('apellidos', 'ilike', "%{$search}%"))
                  ->orWhereHas('cuentaPorCobrar.reservaRadio.persona', fn($sq) =>
                    $sq->where('nombres', 'ilike', "%{$search}%")->orWhere('apellidos', 'ilike', "%{$search}%"))
                  ->orWhereHas('cuentaPorCobrar.alquilerEquipo.clienteExterno', fn($sq) =>
                    $sq->where('nombres', 'ilike', "%{$search}%")->orWhere('apellidos', 'ilike', "%{$search}%"));
            });
        }

        $totalQuery = clone $query;
        $totales = [
            'total' => (float) $totalQuery->sum('monto'),
        ];

        $cursosTotal = (float) (clone $query)->where(function ($q) {
            $q->whereHas('cuentaPorCobrar', fn($sq) => $sq->whereNotNull('matricula_id'))
              ->orWhereNotNull('linea_pago_modulo_id');
        })->sum('monto');
        $serviciosTotal = (clone $query)->whereHas('cuentaPorCobrar', fn($q) => $q->where(fn($sq) =>
            $sq->whereNotNull('reserva_aula_id')->orWhereNotNull('reserva_podcast_id')
               ->orWhereNotNull('alquiler_equipo_id')->orWhereNotNull('servicio_streaming_id')
               ->orWhereNotNull('servicio_produccion_id')->orWhereNotNull('edicion_video_id')
               ->orWhereNotNull('reserva_radio_id')))->sum('monto');
        $totales['talleres'] = (float) (clone $query)->whereHas('cuentaPorCobrar', fn($q) => $q->whereNotNull('inscripcion_taller_id'))->sum('monto');
        $totales['cursos'] = (float) $cursosTotal;
        $totales['servicios'] = (float) $serviciosTotal;
        $totales['otros'] = max(0, $totales['total'] - $totales['cursos'] - $totales['talleres'] - $totales['servicios']);

        // ── Egresos totals ──────────────────────────────────────────────────
        $egresoQuery = TransaccionEgreso::with('categoria')
            ->when($desde, fn($q) => $q->where('fecha_pago', '>=', $desde))
            ->when($hasta, fn($q) => $q->where('fecha_pago', '<=', $hasta . ' 23:59:59'))
            ->when($metodo, fn($q) => $q->where('metodo_pago', $metodo));

        if ($search = $request->get('search')) {
            $egresoQuery->where(function ($q) use ($search) {
                $q->where('descripcion', 'ilike', "%{$search}%")
                  ->orWhere('proveedor_beneficiario', 'ilike', "%{$search}%");
            });
        }

        $egresosTotal = (float) (clone $egresoQuery)->sum('monto');
        $totales['egresos'] = $egresosTotal;
        $totales['balance'] = round($totales['total'] - $egresosTotal, 2);

        $grafico = TransaccionIngreso::selectRaw("to_char(fecha_pago, 'YYYY-MM') as mes, SUM(monto) as total")
            ->when($desde, fn($q) => $q->where('fecha_pago', '>=', $desde))
            ->when($hasta, fn($q) => $q->where('fecha_pago', '<=', $hasta . ' 23:59:59'))
            ->groupBy('mes')->orderBy('mes')->limit(12)
            ->get()->map(fn($r) => ['mes' => $r->mes, 'total' => (float) $r->total]);

        $orderBy = $request->get('order_by', 'fecha_pago');
        $orderDir = $request->get('order_dir', 'desc');
        $allowed = ['fecha_pago' => 'fecha_pago', 'monto' => 'monto', 'categoria' => 'fecha_pago'];
        $sortCol = $allowed[$orderBy] ?? 'fecha_pago';
        $sortDir = $orderDir === 'asc' ? 'asc' : 'desc';

        // ── Fetch all ingresos + egresos, merge, sort, paginate ──────────────
        $ingresoModels = $query->orderBy($sortCol, $sortDir)->get();
        $egresoModels = $egresoQuery->orderBy('fecha_pago', $sortDir)->get();

        $ingresoMapped = $ingresoModels->map(function ($t) {
            $cp = $t->cuentaPorCobrar;
            $cat = 'Otros';
            if ($cp) {
                if ($cp->matricula_id) $cat = 'Cursos';
                elseif ($cp->inscripcion_taller_id) $cat = 'Talleres';
                elseif ($cp->reserva_podcast_id) $cat = 'Podcast';
                elseif ($cp->reserva_aula_id) $cat = 'Alquiler de Aulas';
                elseif ($cp->alquiler_equipo_id) $cat = 'Alquiler de Equipos';
                elseif ($cp->reserva_radio_id) $cat = 'Radio';
                elseif ($cp->edicion_video_id) $cat = 'Edición de Video';
                elseif ($cp->servicio_streaming_id) $cat = 'Streaming';
                elseif ($cp->servicio_produccion_id) $cat = 'Producción Audiovisual';
                elseif ($cp->clase_extra_id) $cat = 'Asesorías';
            } elseif ($t->linea_pago_modulo_id) {
                $cat = 'Cursos';
            }

            $estudiante = $cp?->matricula?->estudiante
                ?? $cp?->solicitudInscripcion?->estudiante
                ?? $cp?->solicitudInscripcion?->participanteExterno
                ?? $cp?->inscripcionTaller
                ?? $cp?->reservaPodcast?->persona
                ?? $cp?->reservaPodcast?->clienteExterno
                ?? $cp?->reservaAula?->persona
                ?? $cp?->reservaAula?->clienteExterno
                ?? $cp?->alquilerEquipo?->persona
                ?? $cp?->alquilerEquipo?->clienteExterno
                ?? $cp?->reservaRadio?->persona
                ?? $cp?->reservaRadio?->clienteExterno
                ?? $t->lineaPagoModulo?->matricula?->estudiante;

            $concepto = $cp?->matricula?->cursoAbierto?->catalogo?->nombre
                ?? $cp?->matricula?->cursoAbierto?->nombre_instancia
                ?? $cp?->inscripcionTaller?->taller?->nombre
                ?? $cp?->reservaPodcast?->titulo
                ?? $cp?->reservaPodcast?->paquete?->nombre
                ?? $cp?->reservaAula?->aula?->nombre
                ?? $cp?->alquilerEquipo?->equipo?->nombre
                ?? $cp?->reservaRadio?->tarifa?->nombre
                ?? $t->lineaPagoModulo?->matricula?->cursoAbierto?->catalogo?->nombre;

            return [
                'id' => $t->id,
                'tipo_movimiento' => 'ingreso',
                'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                'concepto' => $concepto,
                'estudiante_nombre' => $estudiante ? trim(($estudiante->nombres ?? '') . ' ' . ($estudiante->apellidos ?? '')) : null,
                'categoria' => $cat,
                'monto' => (float) $t->monto,
                'metodo_pago' => $t->metodo_pago,
                'comprobante_url' => $t->comprobante_url,
                'estado_verificacion' => $t->estado_verificacion,
            ];
        });

        $egresoMapped = $egresoModels->map(function ($e) {
            return [
                'id' => $e->id,
                'tipo_movimiento' => 'egreso',
                'fecha_pago' => $e->fecha_pago?->format('Y-m-d'),
                'concepto' => $e->descripcion,
                'estudiante_nombre' => $e->proveedor_beneficiario,
                'categoria' => $e->categoria?->nombre ?? '—',
                'monto' => (float) $e->monto,
                'metodo_pago' => $e->metodo_pago,
                'comprobante_url' => $e->comprobante_url,
                'estado_verificacion' => 'aprobado',
            ];
        });

        $allItems = $ingresoMapped->concat($egresoMapped)
            ->sortByDesc(fn($i) => $i['fecha_pago'] ?? '')
            ->values();

        $perPage = (int) $request->get('per_page', 25);
        $page = (int) $request->get('page', 1);
        $offset = ($page - 1) * $perPage;
        $data = $allItems->slice($offset, $perPage);
        $totalAll = $allItems->count();
        $lastPage = (int) ceil($totalAll / $perPage);

        $graficoCategorias = (clone $query)->get()->groupBy(function ($t) {
            $cp = $t->cuentaPorCobrar;
            if ($cp?->matricula_id || $t->linea_pago_modulo_id) return 'Cursos';
            if ($cp?->inscripcion_taller_id) return 'Talleres';
            if ($cp?->reserva_podcast_id) return 'Podcast';
            if ($cp?->reserva_aula_id) return 'Aulas';
            if ($cp?->reserva_radio_id) return 'Radio';
            if ($cp?->edicion_video_id) return 'Edición';
            if ($cp?->alquiler_equipo_id) return 'Equipos';
            if ($cp?->servicio_streaming_id) return 'Streaming';
            if ($cp?->servicio_produccion_id) return 'Producción';
            return 'Otros';
        })->map(fn($g, $k) => ['name' => $k, 'value' => (float) $g->sum('monto')])
          ->sortByDesc('value')->take(8)->values();

        $previoInicio = date('Y-m-d', strtotime(($desde ?: now()->startOfMonth()->format('Y-m-d')) . ' -1 month'));
        $previoFin = date('Y-m-d', strtotime(($hasta ?: now()->endOfMonth()->format('Y-m-d')) . ' -1 month'));
        $previoTotal = (float) TransaccionIngreso::whereBetween('fecha_pago', [$previoInicio, $previoFin . ' 23:59:59'])->sum('monto');
        $previoCursos = (float) TransaccionIngreso::whereBetween('fecha_pago', [$previoInicio, $previoFin . ' 23:59:59'])
            ->where(function ($q) {
                $q->whereHas('cuentaPorCobrar', fn($sq) => $sq->whereNotNull('matricula_id'))
                  ->orWhereNotNull('linea_pago_modulo_id');
            })->sum('monto');
        $previoServicios = (float) TransaccionIngreso::whereBetween('fecha_pago', [$previoInicio, $previoFin . ' 23:59:59'])
            ->whereHas('cuentaPorCobrar', fn($q) => $q->whereNotNull('reserva_podcast_id')
                ->orWhereNotNull('reserva_aula_id')->orWhereNotNull('alquiler_equipo_id')
                ->orWhereNotNull('reserva_radio_id')->orWhereNotNull('edicion_video_id'))
            ->sum('monto');
        $totales['previo_total'] = round($previoTotal, 2);
        $totales['previo_cursos'] = round($previoCursos, 2);
        $totales['previo_servicios'] = round($previoServicios, 2);

        $graficoMetodo = TransaccionIngreso::selectRaw("metodo_pago, SUM(monto) as total")
            ->when($desde, fn($q) => $q->where('fecha_pago', '>=', $desde))
            ->when($hasta, fn($q) => $q->where('fecha_pago', '<=', $hasta . ' 23:59:59'))
            ->groupBy('metodo_pago')->get()
            ->map(fn($r) => ['name' => $r->metodo_pago, 'value' => (float) $r->total])->values();

        $estTop = TransaccionIngreso::whereHas('cuentaPorCobrar')
            ->when($desde, fn($q) => $q->where('fecha_pago', '>=', $desde))
            ->when($hasta, fn($q) => $q->where('fecha_pago', '<=', $hasta . ' 23:59:59'))
            ->get()->groupBy(function ($t) {
                $cp = $t->cuentaPorCobrar;
                return $cp?->matricula?->estudiante_id ?? $cp?->reservaPodcast?->persona_id ?? 'otro';
            })->map(fn($g) => [
                'total' => (float) $g->sum('monto'),
                'nombre' => '—',
            ])->sortByDesc('total')->take(5)->values();

        $analytics = [
            'metodo_top' => TransaccionIngreso::selectRaw("metodo_pago, COUNT(*) as cnt")
                ->when($desde, fn($q) => $q->where('fecha_pago', '>=', $desde))
                ->when($hasta, fn($q) => $q->where('fecha_pago', '<=', $hasta . ' 23:59:59'))
                ->groupBy('metodo_pago')->orderByDesc('cnt')->value('metodo_pago'),
        ];

        return response()->json([
            'totales' => $totales,
            'grafico' => $grafico,
            'grafico_metodo' => $graficoMetodo,
            'top_estudiantes' => $estTop,
            'analytics' => $analytics,
            'grafico_categorias' => $graficoCategorias,
            'data' => $data,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $totalAll,
            'last_page' => $lastPage,
        ]);

    }

    public function deleteArchivoIngreso(Request $request, string $id): JsonResponse
    {
        $request->validate(['campo' => 'required|string|in:comprobante_url']);
        $transaccion = TransaccionIngreso::findOrFail($id);
        $service = app(StorageCleanupService::class);
        $eliminadoPor = auth()->id() ?? auth()->user()?->persona_id ?? null;

        $resultado = $service->deleteFile($transaccion, $request->campo, $eliminadoPor);

        if (!$resultado['eliminado']) {
            return response()->json(['message' => $resultado['mensaje']], Response::HTTP_CONFLICT);
        }

        return response()->json(['message' => 'Comprobante eliminado del almacenamiento']);
    }

    public function deleteArchivoEgreso(Request $request, string $id): JsonResponse
    {
        $request->validate(['campo' => 'required|string|in:comprobante_url']);
        $transaccion = TransaccionEgreso::findOrFail($id);
        $service = app(StorageCleanupService::class);
        $eliminadoPor = auth()->id() ?? auth()->user()?->persona_id ?? null;

        $resultado = $service->deleteFile($transaccion, $request->campo, $eliminadoPor);

        if (!$resultado['eliminado']) {
            return response()->json(['message' => $resultado['mensaje']], Response::HTTP_CONFLICT);
        }

        return response()->json(['message' => 'Comprobante eliminado del almacenamiento']);
    }

}