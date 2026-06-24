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
use App\Models\TransaccionIngreso;
use App\Models\Finance\LineaPagoModulo;
use App\Http\Requests\StorePagoInicialRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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

        // --- Cuentas por cobrar (legacy + servicios + talleres) ---
        $query = CuentaPorCobrar::with([
            'matricula.estudiante',
            'matricula.cursoAbierto.catalogo',
            'solicitudInscripcion.estudiante',
            'solicitudInscripcion.participanteExterno',
            'solicitudInscripcion.cursoAbierto.catalogo',
            'inscripcionTaller.participanteExterno',
            'inscripcionTaller.taller'
        ]);

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

        $cuentas = $query->orderBy('created_at', 'desc')
            ->limit(500)
            ->get();

        // --- Matrículas con lineas_pago_modulo sin cuenta por cobrar ---
        $cursosSinCuenta = collect();
        $debeCursosSinCuenta = ! $origen || $origen === 'curso';

        if ($debeCursosSinCuenta) {
            $idsConCuenta = CuentaPorCobrar::whereNotNull('matricula_id')->pluck('matricula_id')->toArray();

            $lineasAgrupadas = DB::table('finance.lineas_pago_modulo as lpm')
                ->join('academic.matriculas as m', 'm.id', '=', 'lpm.matricula_id')
                ->join('academic.cursos_abiertos as ca', 'ca.id', '=', 'm.curso_abierto_id')
                ->whereNull('m.deleted_at')
                ->whereNull('ca.deleted_at')
                ->when($modalidad && in_array($modalidad, ['presencial', 'virtual']), fn($q) => $q->where('ca.modalidad', $modalidad))
                ->when(! empty($idsConCuenta), fn($q) => $q->whereNotIn('m.id', $idsConCuenta))
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
                $matriculas = Matricula::with(['estudiante', 'cursoAbierto.catalogo'])
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
                    ]);
                }
            }
        }

        // --- Mezclar, ordenar y paginar ---
        $todas = $cuentas->map(fn($c) => (object) [...$c->toArray(), '_origen' => 'cuenta_cobrar'])
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
            'inscripcionTaller.participanteExterno',
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
                $serviciosItems[] = [
                    'id' => $r->id,
                    'tipo' => 'podcast',
                    'monto_total' => (float) ($r->precio_total ?? 0),
                    'monto_abonado' => 0,
                    'saldo_pendiente' => (float) ($r->precio_total ?? 0),
                    'estado' => 'pendiente',
                    'nombre_servicio' => 'Podcast',
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
                        'estudiante', 'cursoAbierto.catalogo', 'lineasPago.modulo',
                    ])
                    ->whereIn('id', $matriculasIdsCursosSinCuenta)
                    ->get()
                    ->keyBy('id');

                foreach ($cursosSinCuentaRaw as $csc) {
                    $m = $matriculasData->get($csc->matricula_id);
                    $saldo = max(0, (float) $csc->monto_total - (float) $csc->monto_abonado);

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
                        'curso_nombre' => $m?->cursoAbierto?->catalogo?->nombre ?? 'Curso',
                        'persona_nombre' => $m?->estudiante ? trim(($m->estudiante->nombres ?? '') . ' ' . ($m->estudiante->apellidos ?? '')) : '',
                    ];

                    $totalCursosSinCuenta += $saldo;
                    if ($saldo > 0) $countCursosSinCuenta++;
                }
            }

            $pendienteCursosSinCuenta = $cursosSinCuentaRaw->sum(function ($c) {
                return max(0, (float) $c->monto_total - (float) $c->monto_abonado);
            });

            return [
                'total_pendiente' => $saldoDeudor(clone $base) + $pendienteCursosSinCuenta,
                'total_cobrado' => DB::table('finance.transacciones_ingreso')->where('estado_verificacion', 'aprobado')->sum('monto'),
                'pendientes_verificacion' => TransaccionIngreso::where('estado_verificacion', 'pendiente')->count(),
                'cuentas_con_deuda' => CuentaPorCobrar::whereIn('estado', ['pendiente', 'abonado'])->count() + $countCursosSinCuenta,
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

        return response()->json(['datos' => $stats]);
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

            $transaccion = TransaccionIngreso::create([
                'cuenta_cobrar_id' => $cuenta->id,
                'monto' => $request->monto,
                'metodo_pago' => $request->metodo_pago,
                'comprobante_url' => $request->comprobante_url,
                'fecha_pago' => $request->fecha_pago ?? now(),
                'registrado_por' => auth()->user()->persona_id ?? null,
                'observaciones' => $request->observaciones,
                'estado_verificacion' => 'pendiente'
            ]);

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

    public function registrarPagosIniciales(StorePagoInicialRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
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

        $transaccion->update([
            'estado_verificacion' => $request->estado,
            'motivo_rechazo' => $request->motivo_rechazo,
            'observaciones' => $request->observaciones ?? $transaccion->observaciones,
            'verificado_por' => auth()->user()->persona_id ?? null,
            'fecha_verificacion' => now()
        ]);

        return response()->json([
            'mensaje' => 'Transacción ' . ($request->estado === 'aprobado' ? 'aprobada' : 'rechazada') . ' correctamente',
            'datos' => $transaccion
        ]);
    }

    public function getHistorial(Request $request): JsonResponse
    {
        $query = TransaccionIngreso::with([
            'lineaPagoModulo.modulo',
            'lineaPagoModulo.matricula.estudiante',
            'lineaPagoModulo.matricula.cursoAbierto.catalogo',
            'cuentaPorCobrar.matricula.estudiante',
            'cuentaPorCobrar.matricula.cursoAbierto.catalogo',
            'registrador',
        ])->orderBy('fecha_pago', 'desc');

        if ($request->has('estado_verificacion')) {
            $query->where('estado_verificacion', $request->estado_verificacion);
        }

        $perPage = (int) $request->get('per_page', 30);
        $transacciones = $query->paginate($perPage);

        $items = collect($transacciones->items())->map(function ($t) {
            $estudiante = null;
            $cursoNombre = null;
            $moduloNombre = null;

            if ($t->lineaPagoModulo) {
                $estudiante = $t->lineaPagoModulo->matricula?->estudiante;
                $cursoNombre = $t->lineaPagoModulo->matricula?->cursoAbierto?->catalogo?->nombre;
                $moduloNombre = $t->lineaPagoModulo->modulo?->nombre_modulo;
            } elseif ($t->cuentaPorCobrar) {
                $estudiante = $t->cuentaPorCobrar->matricula?->estudiante;
                $cursoNombre = $t->cuentaPorCobrar->matricula?->cursoAbierto?->catalogo?->nombre;
            }

            return [
                'id' => $t->id,
                'monto' => (float) $t->monto,
                'metodo_pago' => $t->metodo_pago,
                'fecha_pago' => $t->fecha_pago?->toISOString(),
                'estado_verificacion' => $t->estado_verificacion,
                'comprobante_url' => $t->comprobante_url,
                'observaciones' => $t->observaciones,
                'estudiante_nombre' => $estudiante ? trim(($estudiante->nombres ?? '') . ' ' . ($estudiante->apellidos ?? '')) : null,
                'curso_nombre' => $cursoNombre,
                'modulo_nombre' => $moduloNombre,
            ];
        });

        return response()->json([
            'data' => $items,
            'current_page' => $transacciones->currentPage(),
            'per_page' => $transacciones->perPage(),
            'total' => $transacciones->total(),
            'last_page' => $transacciones->lastPage(),
        ]);
    }

    public function getTransaccionDetalle($id): JsonResponse
    {
        $t = TransaccionIngreso::with([
            'lineaPagoModulo.modulo',
            'lineaPagoModulo.matricula.estudiante',
            'lineaPagoModulo.matricula.cursoAbierto.catalogo',
            'cuentaPorCobrar.matricula.estudiante',
            'cuentaPorCobrar.matricula.cursoAbierto.catalogo',
            'cuentaPorCobrar.inscripcionTaller.taller',
            'registrador',
            'verificador',
        ])->findOrFail($id);

        $estudiante = null;
        $cursoNombre = null;
        $moduloNombre = null;
        $tallerNombre = null;

        if ($t->lineaPagoModulo) {
            $estudiante = $t->lineaPagoModulo->matricula?->estudiante;
            $cursoNombre = $t->lineaPagoModulo->matricula?->cursoAbierto?->catalogo?->nombre;
            $moduloNombre = $t->lineaPagoModulo->modulo?->nombre_modulo;
        } elseif ($t->cuentaPorCobrar) {
            $estudiante = $t->cuentaPorCobrar->matricula?->estudiante
                ?? $t->cuentaPorCobrar->inscripcionTaller?->participanteExterno;
            $cursoNombre = $t->cuentaPorCobrar->matricula?->cursoAbierto?->catalogo?->nombre;
            $tallerNombre = $t->cuentaPorCobrar->inscripcionTaller?->taller?->nombre;
        }

        return response()->json([
            'datos' => [
                'id' => $t->id,
                'monto' => (float) $t->monto,
                'metodo_pago' => $t->metodo_pago,
                'fecha_pago' => $t->fecha_pago?->format('Y-m-d H:i'),
                'estado_verificacion' => $t->estado_verificacion,
                'comprobante_url' => $t->comprobante_url,
                'observaciones' => $t->observaciones,
                'motivo_rechazo' => $t->motivo_rechazo,
                'fecha_verificacion' => $t->fecha_verificacion?->format('Y-m-d H:i'),
                'estudiante_nombre' => $estudiante ? trim(($estudiante->nombres ?? '') . ' ' . ($estudiante->apellidos ?? '')) : null,
                'curso_nombre' => $cursoNombre,
                'modulo_nombre' => $moduloNombre,
                'taller_nombre' => $tallerNombre,
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
            'estudiante', 'lineasPago.modulo',
        ])->where('curso_abierto_id', $id)->get();

        $modulos = $curso->modulos;
        $estudiantesData = [];

        $totalEsperadoCatalogo = 0;
        $totalRecaudadoReal = 0;

        foreach ($matriculas as $matricula) {
            $est = $matricula->estudiante;
            $filasModulos = [];
            $totalPagadoEstudiante = 0;

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
                ];

                $totalPagadoEstudiante += $abonado;
            }

            $estudiantesData[] = [
                'matricula_id' => $matricula->id,
                'nombre' => $est ? trim(($est->nombres ?? '') . ' ' . ($est->apellidos ?? '')) : '—',
                'cedula' => $est->cedula ?? '—',
                'telefono' => $est->celular ?? '—',
                'ciudad' => $est->ciudad?->nombre ?? '—',
                'modulos' => $filasModulos,
                'total_pagado' => $totalPagadoEstudiante,
            ];

            foreach ($modulos as $mod) {
                $totalEsperadoCatalogo += (float) ($mod->precio_base ?? $curso->precio_base ?? 0);
            }
            $totalRecaudadoReal += $totalPagadoEstudiante;
        }

        return response()->json([
            'datos' => [
                'curso' => [
                    'id' => $curso->id,
                    'nombre' => $curso->catalogo?->nombre ?? 'Curso',
                    'nombre_instancia' => $curso->nombre_instancia,
                    'instructor' => $curso->docente ? trim(($curso->docente->nombres ?? '') . ' ' . ($curso->docente->apellidos ?? '')) : '—',
                    'fecha_inicio' => $curso->fecha_inicio?->format('Y-m-d'),
                    'fecha_fin' => $curso->fecha_fin?->format('Y-m-d'),
                    'ciudad' => $curso->ciudad?->nombre ?? '—',
                    'horario' => $curso->horario?->nombre_referencial ?? '—',
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
        $totalEsperado = $taller->precio * $inscripciones->count();

        foreach ($inscripciones as $ins) {
            $montoPagado = (float) ($ins->monto_pagado ?? $ins->precio_pagado ?? 0);
            $totalRecaudado += $montoPagado;

            $participantes[] = [
                'id' => $ins->id,
                'nombre' => trim(($ins->nombres ?? '') . ' ' . ($ins->apellidos ?? '')),
                'cedula' => $ins->cedula ?? '—',
                'correo' => $ins->correo ?? '—',
                'telefono' => $ins->telefono ?? '—',
                'tipo_pago' => $ins->tipo_pago ?? 'completo',
                'monto_pagado' => $montoPagado,
                'precio_taller' => (float) ($taller->precio ?? 0),
                'pago_verificado' => (bool) $ins->pago_verificado,
                'esta_pagado_completo' => $montoPagado >= (float) ($taller->precio ?? 0),
            ];
        }

        return response()->json([
            'datos' => [
                'taller' => [
                    'id' => $taller->id,
                    'nombre' => $taller->nombre,
                    'instructor' => $taller->instructor?->nombres . ' ' . $taller->instructor?->apellidos,
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

    public function getHistorialParticipanteTaller($tallerId, $participanteId): JsonResponse
    {
        $inscripcion = InscripcionTaller::where('taller_id', $tallerId)
            ->where('id', $participanteId)
            ->firstOrFail();

        $taller = \App\Models\Taller::findOrFail($tallerId);

        // Buscar transacciones relacionadas (si existen)
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
                    'nombre' => trim(($inscripcion->nombres ?? '') . ' ' . ($inscripcion->apellidos ?? '')),
                    'taller' => $taller->nombre,
                    'precio_taller' => (float) ($taller->precio ?? 0),
                    'monto_pagado' => (float) ($inscripcion->monto_pagado ?? $inscripcion->precio_pagado ?? 0),
                ],
                'transacciones' => $transacciones,
            ]
        ]);
    }
}
