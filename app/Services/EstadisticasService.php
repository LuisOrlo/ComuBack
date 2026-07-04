<?php

namespace App\Services;

use App\Models\CatalogoCurso;
use App\Models\Ciudad;
use App\Models\CursoAbierto;
use App\Models\CuentaPorCobrar;
use App\Models\Matricula;
use App\Models\Persona;
use App\Models\Taller;
use App\Models\InscripcionTaller;
use App\Models\TransaccionIngreso;
use App\Models\Services\ReservaAula;
use App\Models\Services\ReservaPodcast;
use App\Models\Services\ReservaRadio;
use App\Models\Services\AlquilerEquipo;
use App\Models\Services\TrabajoEdicion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EstadisticasService
{
    public function __construct(
        private string $desde,
        private string $hasta,
    ) {}

    private function cacheKey(string $section): string
    {
        return "estadisticas.{$section}.{$this->desde}.{$this->hasta}";
    }

    private function rangoHasta(): string
    {
        return $this->hasta . ' 23:59:59';
    }

    // ========================================================================
    // SECCION 1 - Métricas base
    // ========================================================================

    public function metricasBase(): array
    {
        return Cache::remember($this->cacheKey('metricas'), now()->addMinutes(15), function () {
            $ingresosTotales = (float) TransaccionIngreso::where('fecha_pago', '>=', $this->desde)
                ->where('fecha_pago', '<=', $this->rangoHasta())
                ->where('estado_verificacion', 'aprobado')
                ->sum('monto');

            $egresosTotales = (float) DB::table('finance.transacciones_egreso')
                ->where('fecha_pago', '>=', $this->desde)
                ->where('fecha_pago', '<=', $this->rangoHasta())
                ->sum('monto');

            $balance = $ingresosTotales - $egresosTotales;
            $margenNeto = $ingresosTotales > 0 ? round(($balance / $ingresosTotales) * 100, 1) : 0;

            $inicioAnioAnterior = date('Y-m-d', strtotime($this->desde . ' -1 year'));
            $finAnioAnterior = date('Y-m-d', strtotime($this->hasta . ' -1 year'));
            $ingresosAnioAnterior = (float) TransaccionIngreso::where('fecha_pago', '>=', $inicioAnioAnterior)
                ->where('fecha_pago', '<=', $finAnioAnterior . ' 23:59:59')
                ->where('estado_verificacion', 'aprobado')
                ->sum('monto');

            $vsAnioAnterior = $ingresosAnioAnterior > 0
                ? round((($ingresosTotales - $ingresosAnioAnterior) / $ingresosAnioAnterior) * 100, 1)
                : '—';

            return [
                'balance' => round($balance, 2),
                'ingresos' => round($ingresosTotales, 2),
                'egresos' => round($egresosTotales, 2),
                'margen_neto' => (float) $margenNeto,
                'vs_anio_anterior' => $vsAnioAnterior,
            ];
        });
    }

    // ========================================================================
    // SECCION 1b - Métricas de estudiantes (matrículas, retención)
    // ========================================================================

    public function metricasEstudiantes(): array
    {
        return Cache::remember($this->cacheKey('metricas_est'), now()->addMinutes(15), function () {
            $matriculados = Matricula::whereBetween('fecha_inscripcion', [$this->desde, $this->rangoHasta()])
                ->count();

            $totalEstudiantesActivos = (int) Matricula::where('estado', 'activo')
                ->selectRaw('COUNT(DISTINCT estudiante_id) as cnt')
                ->value('cnt');

            $estudiantesConReincidencia = (int) DB::table('academic.matriculas')
                ->selectRaw('COUNT(DISTINCT estudiante_id) as cnt')
                ->where('estado', 'activo')
                ->whereIn('estudiante_id', function ($sub) {
                    $sub->select('estudiante_id')
                        ->from('academic.matriculas')
                        ->where('estado', 'activo')
                        ->groupBy('estudiante_id')
                        ->havingRaw('COUNT(*) > 1');
                })
                ->value('cnt');

            $tasaRetencion = $totalEstudiantesActivos > 0
                ? round(($estudiantesConReincidencia / $totalEstudiantesActivos) * 100, 1)
                : 0;

            $tasaAbandono = round(100 - $tasaRetencion, 1);

            return [
                'estudiantes_matriculados' => $matriculados,
                'tasa_retencion' => $tasaRetencion,
                'tasa_abandono' => $tasaAbandono,
            ];
        });
    }


    // ========================================================================
    // SECCION 2 - Flujo financiero 12m
    // ========================================================================

    public function flujoFinanciero(): array
    {
        return Cache::remember($this->cacheKey('flujo'), now()->addMinutes(15), function () {
            $ingresosMensuales = TransaccionIngreso::selectRaw("to_char(fecha_pago, 'YYYY-MM') as mes, SUM(monto) as total")
                ->where('fecha_pago', '>=', now()->subMonths(12)->startOfMonth()->format('Y-m-d'))
                ->where('fecha_pago', '<=', $this->rangoHasta())
                ->where('estado_verificacion', 'aprobado')
                ->groupBy(DB::raw("to_char(fecha_pago, 'YYYY-MM')"))
                ->orderBy(DB::raw("to_char(fecha_pago, 'YYYY-MM')"))
                ->get()->keyBy('mes');

            $egresosMensuales = DB::table('finance.transacciones_egreso')
                ->selectRaw("to_char(fecha_pago, 'YYYY-MM') as mes, SUM(monto) as total")
                ->where('fecha_pago', '>=', now()->subMonths(12)->startOfMonth()->format('Y-m-d'))
                ->where('fecha_pago', '<=', $this->rangoHasta())
                ->groupBy(DB::raw("to_char(fecha_pago, 'YYYY-MM')"))
                ->orderBy(DB::raw("to_char(fecha_pago, 'YYYY-MM')"))
                ->get()->keyBy('mes');

            $mesesKeys = $ingresosMensuales->keys()->merge($egresosMensuales->keys())->unique()->sort()->values();

            return $mesesKeys->map(fn($m) => [
                'mes' => $m,
                'ingresos' => round((float) ($ingresosMensuales[$m]->total ?? 0), 2),
                'egresos' => round((float) ($egresosMensuales[$m]->total ?? 0), 2),
            ])->values()->toArray();
        });
    }

    // ========================================================================
    // SECCION 3 - Composición de ingresos (categorías)
    // ========================================================================

    public function distribucionCategorias(): array
    {
        return Cache::remember($this->cacheKey('dist_cat'), now()->addMinutes(15), function () {
            $ingresosTotales = (float) TransaccionIngreso::where('fecha_pago', '>=', $this->desde)
                ->where('fecha_pago', '<=', $this->rangoHasta())
                ->where('estado_verificacion', 'aprobado')
                ->sum('monto');

            return TransaccionIngreso::where('fecha_pago', '>=', $this->desde)
                ->where('fecha_pago', '<=', $this->rangoHasta())
                ->where('estado_verificacion', 'aprobado')
                ->whereHas('cuentaPorCobrar')
                ->get()
                ->groupBy(function ($t) {
                    $cp = $t->cuentaPorCobrar;
                    if ($cp?->matricula_id || $t->linea_pago_modulo_id) return 'Cursos';
                    if ($cp?->inscripcion_taller_id) return 'Talleres';
                    if ($cp?->reserva_podcast_id) return 'Podcast';
                    if ($cp?->reserva_aula_id) return 'Aulas';
                    if ($cp?->reserva_radio_id) return 'Radio';
                    if ($cp?->alquiler_equipo_id) return 'Equipos';
                    if ($cp?->edicion_video_id) return 'Edición';
                    return 'Otros';
                })->map(function ($g, $k) use ($ingresosTotales) {
                    $total = (float) $g->sum('monto');
                    return [
                        'name' => $k,
                        'value' => $total,
                        'porcentaje' => $ingresosTotales > 0 ? round($total / $ingresosTotales * 100, 1) : 0,
                    ];
                })->sortByDesc('value')->values()->toArray();
        });
    }

    // ========================================================================
    // SECCION 3b - Método de pago
    // ========================================================================

    public function metodoPago(): array
    {
        return Cache::remember($this->cacheKey('metodo_pago'), now()->addMinutes(15), function () {
            return TransaccionIngreso::where('fecha_pago', '>=', $this->desde)
                ->where('fecha_pago', '<=', $this->rangoHasta())
                ->where('estado_verificacion', 'aprobado')
                ->selectRaw("metodo_pago, SUM(monto) as total")
                ->groupBy('metodo_pago')
                ->get()
                ->map(fn($r) => ['name' => $r->metodo_pago, 'value' => (float) $r->total])
                ->values()
                ->toArray();
        });
    }

    // ========================================================================
    // SECCION 3c - Días de la semana
    // ========================================================================

    public function diasSemana(): array
    {
        return Cache::remember($this->cacheKey('dias_sem'), now()->addMinutes(15), function () {
            return TransaccionIngreso::where('fecha_pago', '>=', $this->desde)
                ->where('fecha_pago', '<=', $this->rangoHasta())
                ->where('estado_verificacion', 'aprobado')
                ->selectRaw("EXTRACT(DOW FROM fecha_pago) as dia, SUM(monto) as total")
                ->groupBy(DB::raw("EXTRACT(DOW FROM fecha_pago)"))
                ->get()
                ->map(function ($r) {
                    $dias = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
                    return ['dia' => $dias[(int) $r->dia] ?? $r->dia, 'value' => (float) $r->total];
                })->values()
                ->toArray();
        });
    }

    // ========================================================================
    // SECCION 4 - Rendimiento por catálogo
    // ========================================================================

    public function catalogosTop(): array
    {
        return Cache::remember($this->cacheKey('cat_top'), now()->addMinutes(15), function () {
            return CatalogoCurso::withCount(['cursosAbiertos as ofertas_count' => function ($q) {
                $q->whereBetween('fecha_inicio', [$this->desde, $this->rangoHasta()]);
            }])
                ->with(['cursosAbiertos' => function ($q) {
                    $q->whereBetween('fecha_inicio', [$this->desde, $this->rangoHasta()])
                      ->withCount('matriculas')
                      ->with(['matriculas' => function ($mq) {
                          $mq->select('id', 'curso_abierto_id', 'calificacion_final', 'estado');
                      }]);
                }])
                ->get()
                ->map(function ($c) {
                    $ofertas = $c->ofertas_count ?? $c->cursosAbiertos->count();
                    $estudiantes = $c->cursosAbiertos->sum('matriculas_count');
                    $capacidadTotal = $c->cursosAbiertos->sum('capacidad_maxima');
                    $ocupacion = $capacidadTotal > 0 ? round(($estudiantes / $capacidadTotal) * 100, 1) : 0;

                    $matriculas = $c->cursosAbiertos->pluck('matriculas')->flatten();
                    $completadas = $matriculas->where('estado', 'completado')->where('calificacion_final', '>=', 10)->count();
                    $totalMatriculas = $matriculas->count();
                    $aprobacion = $totalMatriculas > 0 ? round(($completadas / $totalMatriculas) * 100, 1) : 0;

                    $cursoIds = $c->cursosAbiertos->pluck('id')->toArray();
                    $ingreso = !empty($cursoIds)
                        ? (float) TransaccionIngreso::whereHas('cuentaPorCobrar', function ($q) use ($cursoIds) {
                            $q->whereHas('matricula', fn($mq) => $mq->whereIn('curso_abierto_id', $cursoIds));
                        })->where('fecha_pago', '>=', $this->desde)
                          ->where('fecha_pago', '<=', $this->rangoHasta())
                          ->where('estado_verificacion', 'aprobado')
                          ->sum('monto')
                        : 0;

                    return [
                        'id' => $c->id,
                        'nombre' => $c->nombre,
                        'ofertas' => $ofertas,
                        'estudiantes' => $estudiantes,
                        'ocupacion_pct' => $ocupacion,
                        'aprobacion_pct' => $aprobacion,
                        'ingreso' => round($ingreso, 2),
                    ];
                })
                ->filter(fn($c) => $c['estudiantes'] > 0 || $c['ingreso'] > 0)
                ->sortByDesc('ingreso')
                ->values()
                ->toArray();
        });
    }

    // ========================================================================
    // SECCION 5 - Distribución geográfica
    // ========================================================================

    public function ciudadesTop(): array
    {
        return Cache::remember($this->cacheKey('ciu_top'), now()->addMinutes(15), function () {
            return Ciudad::whereHas('cursosAbiertos', function ($q) {
                $q->whereBetween('fecha_inicio', [$this->desde, $this->rangoHasta()]);
            })
                ->withCount(['cursosAbiertos as estudiantes_count' => function ($q) {
                    $q->whereBetween('fecha_inicio', [$this->desde, $this->rangoHasta()])
                      ->join('academic.matriculas', 'academic.cursos_abiertos.id', '=', 'academic.matriculas.curso_abierto_id');
                }])
                ->get()
                ->map(function ($ciudad) {
                    $cursoIds = $ciudad->cursosAbiertos()
                        ->whereBetween('fecha_inicio', [$this->desde, $this->rangoHasta()])
                        ->pluck('id')
                        ->toArray();

                    $ingresos = !empty($cursoIds)
                        ? (float) TransaccionIngreso::whereHas('cuentaPorCobrar', function ($q) use ($cursoIds) {
                            $q->whereHas('matricula', fn($mq) => $mq->whereIn('curso_abierto_id', $cursoIds));
                        })->where('fecha_pago', '>=', $this->desde)
                          ->where('fecha_pago', '<=', $this->rangoHasta())
                          ->where('estado_verificacion', 'aprobado')
                          ->sum('monto')
                        : 0;

                    return [
                        'id' => $ciudad->id,
                        'nombre' => $ciudad->nombre,
                        'ingresos' => round($ingresos, 2),
                        'estudiantes' => $ciudad->estudiantes_count ?? 0,
                    ];
                })
                ->filter(fn($c) => $c['ingresos'] > 0 || $c['estudiantes'] > 0)
                ->sortByDesc('ingresos')
                ->values()
                ->toArray();
        });
    }

    // ========================================================================
    // SECCION 6 - Comparativa por modalidad
    // ========================================================================

    public function modalidadComparativa(): array
    {
        return Cache::remember($this->cacheKey('mod'), now()->addMinutes(15), function () {
            $cursos = CursoAbierto::withCount(['matriculas' => function ($q) {
                $q->whereBetween('fecha_inscripcion', [$this->desde, $this->rangoHasta()]);
            }])->get()->groupBy('modalidad');

            $presencial = $cursos->get('presencial', collect());
            $virtual = $cursos->get('virtual', collect());

            $presencialCursoIds = $presencial->pluck('id')->toArray();
            $virtualCursoIds = $virtual->pluck('id')->toArray();

            $ingresosPresencial = !empty($presencialCursoIds)
                ? (float) TransaccionIngreso::where(function ($q) use ($presencialCursoIds) {
                    $q->whereHas('cuentaPorCobrar', fn($cq) => $cq->whereHas('matricula', fn($mq) => $mq->whereIn('curso_abierto_id', $presencialCursoIds)))
                      ->orWhereHas('lineaPagoModulo.matricula', fn($mq) => $mq->whereIn('curso_abierto_id', $presencialCursoIds));
                })->where('fecha_pago', '>=', $this->desde)
                  ->where('fecha_pago', '<=', $this->rangoHasta())
                  ->where('estado_verificacion', 'aprobado')
                  ->sum('monto')
                : 0;

            $ingresosVirtual = !empty($virtualCursoIds)
                ? (float) TransaccionIngreso::where(function ($q) use ($virtualCursoIds) {
                    $q->whereHas('cuentaPorCobrar', fn($cq) => $cq->whereHas('matricula', fn($mq) => $mq->whereIn('curso_abierto_id', $virtualCursoIds)))
                      ->orWhereHas('lineaPagoModulo.matricula', fn($mq) => $mq->whereIn('curso_abierto_id', $virtualCursoIds));
                })->where('fecha_pago', '>=', $this->desde)
                  ->where('fecha_pago', '<=', $this->rangoHasta())
                  ->where('estado_verificacion', 'aprobado')
                  ->sum('monto')
                : 0;

            $totalEgresosPeriodo = (float) DB::table('finance.transacciones_egreso')
                ->where('fecha_pago', '>=', $this->desde)
                ->where('fecha_pago', '<=', $this->rangoHasta())
                ->sum('monto');

            $totalIngresos = $ingresosPresencial + $ingresosVirtual;
            $egresosPresencial = $totalIngresos > 0
                ? round($totalEgresosPeriodo * ($ingresosPresencial / $totalIngresos), 2)
                : 0;
            $egresosVirtual = $totalIngresos > 0
                ? round($totalEgresosPeriodo * ($ingresosVirtual / $totalIngresos), 2)
                : 0;

            return [
                'presencial' => [
                    'ingresos' => round($ingresosPresencial, 2),
                    'egresos' => $egresosPresencial,
                    'estudiantes' => $presencial->sum('matriculas_count'),
                    'cursos' => $presencial->count(),
                ],
                'virtual' => [
                    'ingresos' => round($ingresosVirtual, 2),
                    'egresos' => $egresosVirtual,
                    'estudiantes' => $virtual->sum('matriculas_count'),
                    'cursos' => $virtual->count(),
                ],
            ];
        });
    }

    // ========================================================================
    // SECCION 7 - Top estudiantes (retención)
    // ========================================================================

    public function topEstudiantes(): array
    {
        return Cache::remember($this->cacheKey('top_est'), now()->addMinutes(15), function () {
            $top = TransaccionIngreso::where('fecha_pago', '>=', $this->desde)
                ->where('fecha_pago', '<=', $this->rangoHasta())
                ->where('estado_verificacion', 'aprobado')
                ->whereHas('cuentaPorCobrar')
                ->get()
                ->groupBy(function ($t) {
                    $cp = $t->cuentaPorCobrar;
                    return $cp?->matricula?->estudiante_id
                        ?? $cp?->solicitudInscripcion?->persona_id
                        ?? 'otro';
                })
                ->map(function ($g) {
                    return [
                        'id' => $g->first()->cuentaPorCobrar?->matricula?->estudiante_id ?? '',
                        'total' => round((float) $g->sum('monto'), 2),
                    ];
                })
                ->filter(fn($e) => !empty($e['id']))
                ->sortByDesc('total')
                ->take(10)
                ->values();

            $ids = $top->pluck('id')->toArray();
            $personas = !empty($ids)
                ? Persona::whereIn('id', $ids)->get()->keyBy('id')
                : collect();

            return $top->map(function ($e) use ($personas) {
                $p = $personas->get($e['id']);
                return [
                    'id' => $e['id'],
                    'nombre' => $p ? trim(($p->nombres ?? '') . ' ' . ($p->apellidos ?? '')) : '—',
                    'total' => $e['total'],
                ];
            })->values()->toArray();
        });
    }

    // ========================================================================
    // SECCION 8 - Estado de cobranza
    // ========================================================================

    public function cobranza(): array
    {
        return Cache::remember($this->cacheKey('cob'), now()->addMinutes(15), function () {
            $cuentas = CuentaPorCobrar::whereIn('estado', ['pendiente', 'abonado'])
                ->whereHas('matricula', function ($q) {
                    $q->whereBetween('fecha_inscripcion', [$this->desde, $this->rangoHasta()]);
                })
                ->with('matricula.cursoAbierto.catalogo')
                ->get();

            $estudiantesConDeuda = $cuentas->pluck('matricula.estudiante_id')->unique()->count();

            $debenTodos = 0;
            $debenAlMenosUno = 0;

            foreach ($cuentas->groupBy('matricula.estudiante_id') as $estudianteId => $grupo) {
                $totalCuentasEstudiante = CuentaPorCobrar::whereHas('matricula', function ($q) use ($estudianteId) {
                    $q->where('estudiante_id', $estudianteId);
                })->count();

                $cuentasPendientes = $grupo->filter(fn($c) => in_array($c->estado, ['pendiente', 'abonado']))->count();

                if ($cuentasPendientes > 0) $debenAlMenosUno++;
                if ($cuentasPendientes === $totalCuentasEstudiante && $totalCuentasEstudiante > 0) $debenTodos++;
            }

            $distribucionPorCatalogo = $cuentas->groupBy(function ($c) {
                return $c->matricula?->cursoAbierto?->catalogo?->nombre ?? 'Otros';
            })->map(function ($grupo) {
                $total = $grupo->count();
                $pagadas = $grupo->where('estado', 'pagado')->count();
                return [
                    'nombre' => $grupo->first()->matricula?->cursoAbierto?->catalogo?->nombre ?? 'Otros',
                    'al_dia' => $pagadas,
                    'deben' => $total - $pagadas,
                ];
            })->values()->toArray();

            return [
                'total_estudiantes' => $estudiantesConDeuda > 0 ? $estudiantesConDeuda : CuentaPorCobrar::whereHas('matricula', function ($q) {
                    $q->whereBetween('fecha_inscripcion', [$this->desde, $this->rangoHasta()]);
                })->distinct()->count('matricula.estudiante_id'),
                'deben_al_menos_un_pago' => $debenAlMenosUno,
                'deben_todos_los_pagos' => $debenTodos,
                'distribucion_por_catalogo' => $distribucionPorCatalogo,
            ];
        });
    }

    // ========================================================================
    // SECCION 9 - Actividad de servicios
    // ========================================================================

    public function actividadServicios(): array
    {
        return Cache::remember($this->cacheKey('servicios'), now()->addMinutes(15), function () {
            $servicios = [];

            $aulas = (float) TransaccionIngreso::whereHas('cuentaPorCobrar', function ($q) {
                $q->whereNotNull('reserva_aula_id');
            })->where('fecha_pago', '>=', $this->desde)
              ->where('fecha_pago', '<=', $this->rangoHasta())
              ->where('estado_verificacion', 'aprobado')
              ->sum('monto');

            $aulasCount = ReservaAula::whereBetween('fecha_reserva', [$this->desde, $this->rangoHasta()])->count();
            if ($aulas > 0 || $aulasCount > 0) {
                $servicios[] = ['tipo' => 'Aulas', 'ingresos' => round($aulas, 2), 'cantidad' => $aulasCount];
            }

            $podcast = (float) TransaccionIngreso::whereHas('cuentaPorCobrar', function ($q) {
                $q->whereNotNull('reserva_podcast_id');
            })->where('fecha_pago', '>=', $this->desde)
              ->where('fecha_pago', '<=', $this->rangoHasta())
              ->where('estado_verificacion', 'aprobado')
              ->sum('monto');

            $podcastCount = ReservaPodcast::whereBetween('fecha_reserva', [$this->desde, $this->rangoHasta()])->count();
            if ($podcast > 0 || $podcastCount > 0) {
                $servicios[] = ['tipo' => 'Podcast', 'ingresos' => round($podcast, 2), 'cantidad' => $podcastCount];
            }

            $radio = (float) TransaccionIngreso::whereHas('cuentaPorCobrar', function ($q) {
                $q->whereNotNull('reserva_radio_id');
            })->where('fecha_pago', '>=', $this->desde)
              ->where('fecha_pago', '<=', $this->rangoHasta())
              ->where('estado_verificacion', 'aprobado')
              ->sum('monto');

            $radioCount = ReservaRadio::whereBetween('fecha_reserva', [$this->desde, $this->rangoHasta()])->count();
            if ($radio > 0 || $radioCount > 0) {
                $servicios[] = ['tipo' => 'Radio', 'ingresos' => round($radio, 2), 'cantidad' => $radioCount];
            }

            $equipos = (float) TransaccionIngreso::whereHas('cuentaPorCobrar', function ($q) {
                $q->whereNotNull('alquiler_equipo_id');
            })->where('fecha_pago', '>=', $this->desde)
              ->where('fecha_pago', '<=', $this->rangoHasta())
              ->where('estado_verificacion', 'aprobado')
              ->sum('monto');

            $equiposCount = AlquilerEquipo::whereBetween('fecha_entrega', [$this->desde, $this->rangoHasta()])->count();
            if ($equipos > 0 || $equiposCount > 0) {
                $servicios[] = ['tipo' => 'Equipos', 'ingresos' => round($equipos, 2), 'cantidad' => $equiposCount];
            }

            $edicion = (float) TransaccionIngreso::whereHas('cuentaPorCobrar', function ($q) {
                $q->whereNotNull('edicion_video_id');
            })->where('fecha_pago', '>=', $this->desde)
              ->where('fecha_pago', '<=', $this->rangoHasta())
              ->where('estado_verificacion', 'aprobado')
              ->sum('monto');

            $edicionCount = TrabajoEdicion::whereBetween('fecha_recibo', [$this->desde, $this->rangoHasta()])->count();
            if ($edicion > 0 || $edicionCount > 0) {
                $servicios[] = ['tipo' => 'Edición', 'ingresos' => round($edicion, 2), 'cantidad' => $edicionCount];
            }

            usort($servicios, fn($a, $b) => $b['ingresos'] <=> $a['ingresos']);

            return $servicios;
        });
    }

    // ========================================================================
    // SECCION - Insight text dinámico
    // ========================================================================

    public function insightText(array $metricas, array $flujo, array $distribucion): string
    {
        $flujoReales = array_filter($flujo, fn($f) => !($f['estimado'] ?? false));
        if (empty($flujoReales)) {
            return 'No hay suficientes datos para generar un análisis.';
        }

        $mejorMes = collect($flujoReales)->sortByDesc(fn($m) => ($m['ingresos'] ?? 0) - ($m['egresos'] ?? 0))->first();
        if ($mejorMes && !empty($mejorMes['mes'])) {
            $balance = ($mejorMes['ingresos'] ?? 0) - ($mejorMes['egresos'] ?? 0);
            $meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                      'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
            [$y, $m] = explode('-', $mejorMes['mes']);
            $monthName = $meses[(int) $m] ?? $mejorMes['mes'];
            $balanceStr = '$' . number_format(abs($balance), 0, ',', '.');

            if ($balance >= 0) {
                return "{$monthName} de {$y} fue tu mejor mes, con {$balanceStr} de balance neto.";
            }
            return "El balance general muestra una tendencia. {$monthName} de {$y} tuvo el menor déficit.";
        }

        $ingresos = $metricas['ingresos'] ?? 0;
        $egresos = $metricas['egresos'] ?? 0;
        if ($ingresos > $egresos) {
            return "Los ingresos superan a los egresos en el período actual por $" . number_format($ingresos - $egresos, 0, ',', '.') . ".";
        }

        return "Continúa monitoreando el flujo financiero para identificar oportunidades de mejora.";
    }

    // ========================================================================
    // SECCION - Detalle de catálogo (para subpágina)
    // ========================================================================

    public function catalogoDetalle(string $catalogoId): array
    {
        $catalogo = CatalogoCurso::findOrFail($catalogoId);

        $cursos = CursoAbierto::where('catalogo_curso_id', $catalogoId)
            ->whereBetween('fecha_inicio', [$this->desde, $this->rangoHasta()])
            ->withCount('matriculas')
            ->get();

        $cursoIds = $cursos->pluck('id')->toArray();

        $ofertas = [];
        foreach ($cursos as $curso) {
            $ingresoCurso = !empty($cursoIds)
                ? (float) TransaccionIngreso::whereHas('cuentaPorCobrar', function ($q) use ($curso) {
                    $q->whereHas('matricula', fn($mq) => $mq->where('curso_abierto_id', $curso->id));
                })->where('fecha_pago', '>=', $this->desde)
                  ->where('fecha_pago', '<=', $this->rangoHasta())
                  ->where('estado_verificacion', 'aprobado')
                  ->sum('monto')
                : 0;

            $completadas = $curso->matriculas()
                ->where('estado', 'completado')
                ->where('calificacion_final', '>=', 10)
                ->count();
            $totalMat = $curso->matriculas_count;
            $aprobacion = $totalMat > 0 ? round(($completadas / $totalMat) * 100, 1) : 0;
            $ocupacion = $curso->capacidad_maxima > 0 ? round(($curso->matriculas_count / $curso->capacidad_maxima) * 100, 1) : 0;

            $ofertas[] = [
                'id' => $curso->id,
                'nombre_instancia' => $curso->nombre_instancia ?? $catalogo->nombre,
                'semestre' => $curso->semestre ?? '',
                'estudiantes' => $curso->matriculas_count,
                'ocupacion_pct' => $ocupacion,
                'aprobacion_pct' => $aprobacion,
                'ingreso' => round($ingresoCurso, 2),
            ];
        }

        $estudiantes = $cursos->sum('matriculas_count');
        $capacidadTotal = $cursos->sum('capacidad_maxima');
        $ocupacionPct = $capacidadTotal > 0 ? round(($estudiantes / $capacidadTotal) * 100, 1) : 0;

        $totalIngreso = array_sum(array_column($ofertas, 'ingreso'));
        $aprobacionPromedio = !empty($ofertas) ? round(array_sum(array_column($ofertas, 'aprobacion_pct')) / count($ofertas), 1) : 0;

        $evolucion = $this->evolucionMensualCatalogo($cursoIds);

        $retencion = $this->calcularRetencionCatalogo($cursos);

        return [
            'catalogo' => [
                'id' => $catalogo->id,
                'nombre' => $catalogo->nombre,
                'ofertas' => $cursos->count(),
                'estudiantes' => $estudiantes,
                'ocupacion_pct' => $ocupacionPct,
                'aprobacion_pct' => $aprobacionPromedio,
                'ingreso' => round($totalIngreso, 2),
            ],
            'periodo' => ['desde' => $this->desde, 'hasta' => $this->hasta],
            'evolucion_mensual' => $evolucion,
            'ofertas' => $ofertas,
            'retencion' => $retencion,
        ];
    }

    private function evolucionMensualCatalogo(array $cursoIds): array
    {
        if (empty($cursoIds)) return [];

        $result = [];
        for ($i = 11; $i >= 0; $i--) {
            $inicio = date('Y-m-01', strtotime("-{$i} months"));
            $fin = date('Y-m-t', strtotime("-{$i} months"));

            $ingresos = (float) TransaccionIngreso::whereHas('cuentaPorCobrar', function ($q) use ($cursoIds) {
                $q->whereHas('matricula', fn($mq) => $mq->whereIn('curso_abierto_id', $cursoIds));
            })->where('fecha_pago', '>=', $inicio)
              ->where('fecha_pago', '<=', $fin . ' 23:59:59')
              ->where('estado_verificacion', 'aprobado')
              ->sum('monto');

            $result[] = [
                'mes' => date('Y-m', strtotime($inicio)),
                'ingresos' => round($ingresos, 2),
                'egresos' => 0,
            ];
        }

        return $result;
    }

    private function calcularRetencionCatalogo($cursos): float
    {
        $estudianteIds = collect();
        foreach ($cursos as $curso) {
            $ids = $curso->matriculas()->pluck('estudiante_id');
            $estudianteIds = $estudianteIds->merge($ids);
        }
        $estudianteIds = $estudianteIds->unique()->values();

        if ($estudianteIds->count() < 2) return 0;

        $recurrentes = Matricula::whereIn('estudiante_id', $estudianteIds->toArray())
            ->whereNotIn('curso_abierto_id', $cursos->pluck('id')->toArray())
            ->distinct('estudiante_id')
            ->count('estudiante_id');

        return round(($recurrentes / $estudianteIds->count()) * 100, 1);
    }

    // ========================================================================
    // SECCION - Detalle de estudiante (para subpágina)
    // ========================================================================

    public function estudianteDetalle(string $estudianteId): array
    {
        $persona = Persona::findOrFail($estudianteId);

        $historialCursos = Matricula::where('estudiante_id', $estudianteId)
            ->with(['cursoAbierto.catalogo', 'cuentaPorCobrar.transacciones'])
            ->whereBetween('fecha_inscripcion', [$this->desde, $this->rangoHasta()])
            ->get()
            ->map(function ($m) {
                $montoTotal = $m->cuentaPorCobrar?->monto_total ?? $m->precio_total_legacy ?? 0;
                $montoPagado = $m->cuentaPorCobrar
                    ? (float) $m->cuentaPorCobrar->transacciones
                        ->where('estado_verificacion', 'aprobado')
                        ->sum('monto')
                    : 0;

                return [
                    'id' => $m->id,
                    'curso' => $m->cursoAbierto?->catalogo?->nombre ?? $m->cursoAbierto?->nombre_instancia ?? '—',
                    'fecha_inscripcion' => $m->fecha_inscripcion?->format('Y-m-d') ?? '',
                    'estado' => $m->estado,
                    'monto_pagado' => round((float) $montoPagado, 2),
                    'monto_total' => round((float) $montoTotal, 2),
                ];
            })->values()->toArray();

        $historialPagos = TransaccionIngreso::whereHas('cuentaPorCobrar', function ($q) use ($estudianteId) {
            $q->whereHas('matricula', fn($mq) => $mq->where('estudiante_id', $estudianteId));
        })->where('fecha_pago', '>=', $this->desde)
          ->where('fecha_pago', '<=', $this->rangoHasta())
          ->where('estado_verificacion', 'aprobado')
          ->get()
          ->map(fn($t) => [
              'fecha' => $t->fecha_pago?->format('Y-m-d') ?? '',
              'monto' => round((float) $t->monto, 2),
              'metodo' => $t->metodo_pago ?? '',
              'referencia' => $t->referencia_pago ?? '',
          ])->values()->toArray();

        $totalIngresos = array_sum(array_column($historialPagos, 'monto'));
        $totalCursos = count($historialCursos);
        $promedio = $totalCursos > 0 ? round($totalIngresos / $totalCursos, 2) : 0;

        return [
            'estudiante' => [
                'id' => $persona->id,
                'nombre' => trim(($persona->nombres ?? '') . ' ' . ($persona->apellidos ?? '')),
                'cedula' => $persona->cedula ?? '',
            ],
            'periodo' => ['desde' => $this->desde, 'hasta' => $this->hasta],
            'resumen' => [
                'total_ingresos' => round($totalIngresos, 2),
                'total_cursos' => $totalCursos,
                'promedio_por_curso' => $promedio,
            ],
            'historial_cursos' => $historialCursos,
            'historial_pagos' => $historialPagos,
        ];
    }
}
