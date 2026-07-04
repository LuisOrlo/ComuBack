<?php

namespace App\Http\Controllers\Estudiantes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Estudiantes\EstudianteStoreRequest;
use App\Http\Requests\Estudiantes\EstudianteUpdateRequest;
use App\Http\Resources\Estudiantes\EstudianteResource;
use App\Models\Persona;
use App\Models\PerfilEstudiante;
use App\Models\ClienteExterno;
use App\Models\Ciudad;
use App\Models\Matricula;
use App\Models\Clase;
use App\Models\Asistencia;
use App\Models\Nota;
use App\Models\CuentaPorCobrar;
use App\Models\TransaccionIngreso;
use App\Models\EstudianteSegmento;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;
use PhpOffice\PhpSpreadsheet\IOFactory;

class EstudianteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $internos = Persona::query()
            ->estudiantes()
            ->with(['ciudad', 'perfilEstudiante', 'matriculas.cuentaPorCobrar', 'matriculas.lineasPago'])
            ->whereNull('deleted_at')
            ->orderBy('nombres')
            ->get()
            ->map(function ($p) {
                $totalMatriculas = $p->matriculas->count();
                $cuentas = $p->matriculas->map(fn($m) => $m->cuentaPorCobrar)->filter();
                $estadosCuentas = $cuentas->pluck('estado')->unique();
                $estadosLineas = $p->matriculas->flatMap(fn($m) => $m->lineasPago->pluck('estado'))->unique();

                $estadoPago = 'ninguno';
                if ($totalMatriculas > 0) {
                    $tienePendiente = $estadosCuentas->contains('pendiente') || $estadosLineas->contains('pendiente');
                    $tieneAbonado = $estadosCuentas->contains('abonado') || $estadosLineas->contains('abonado');
                    $tienePagado = ($cuentas->count() > 0 && $estadosCuentas->every(fn($e) => $e === 'pagado'))
                        || ($cuentas->isEmpty() && $estadosLineas->isNotEmpty() && $estadosLineas->every(fn($e) => $e === 'pagado'));

                    if ($cuentas->count() < $totalMatriculas && $estadosLineas->isEmpty()) {
                        $estadoPago = 'deudor';
                    } elseif ($tienePendiente) {
                        $estadoPago = 'deudor';
                    } elseif ($tieneAbonado) {
                        $estadoPago = 'abonado';
                    } elseif ($tienePagado) {
                        $estadoPago = 'al_dia';
                    } else {
                        $estadoPago = 'deudor';
                    }
                }

                return [
                    'id' => $p->id,
                    'tipo' => 'estudiante',
                    'nombres' => $p->nombres,
                    'apellidos' => $p->apellidos,
                    'cedula' => $p->cedula,
                    'correo' => $p->correo,
                    'celular' => $p->celular,
                    'ciudad' => $p->ciudad ? (is_string($p->ciudad) ? ['nombre' => $p->ciudad] : ['nombre' => $p->ciudad->nombre]) : null,
                    'es_activo' => $p->es_activo,
                    'total_cursos' => $p->matriculas->count(),
                    'estado_pago' => $estadoPago,
                    'saldo_pendiente' => $p->matriculas->sum(function($m) {
                        if ($m->cuentaPorCobrar) {
                            return $m->cuentaPorCobrar->monto_total - $m->cuentaPorCobrar->monto_abonado;
                        }
                        return $m->cursoAbierto->precio_base ?? 0;
                    }),
                    'perfil_estudiante' => $p->perfilEstudiante ? [
                        'fecha_nacimiento' => $p->perfilEstudiante->fecha_nacimiento?->format('Y-m-d'),
                        'notas_internas' => $p->perfilEstudiante->notas_internas,
                        'primera_matricula' => $p->perfilEstudiante->primera_matricula?->format('Y-m-d') ?? ($totalMatriculas > 0 ? $p->matriculas->pluck('fecha_inscripcion')->filter()->sort()->first()?->format('Y-m-d') : null),
                        'ultima_matricula' => $p->perfilEstudiante->ultima_matricula?->format('Y-m-d') ?? ($totalMatriculas > 0 ? $p->matriculas->pluck('fecha_inscripcion')->filter()->sort()->last()?->format('Y-m-d') : null),
                        'total_cursos' => $p->perfilEstudiante->total_cursos ?? $totalMatriculas,
                        'ocupacion' => $p->perfilEstudiante->ocupacion,
                        'direccion' => $p->perfilEstudiante->direccion,
                        'estado_civil' => $p->perfilEstudiante->estado_civil,
                        'edad' => $p->perfilEstudiante->edad,
                    ] : null,
                ];
            });

        $clientesData = ClienteExterno::query()
            ->whereHas('solicitudesInscripcion', fn ($q) =>
                $q->whereIn('estado', ['aprobado', 'matricula_creada'])
            )
            ->with([
                'solicitudesInscripcion' => fn ($q) =>
                    $q->whereIn('estado', ['aprobado', 'matricula_creada'])
                      ->with(['cursoAbierto.catalogo', 'cuentasPorCobrar', 'matricula']),
                'ciudad',
            ])
            ->orderBy('nombres')
            ->get()
            ->map(function ($c) {
                $solicitudes = $c->solicitudesInscripcion;
                $tieneRegular = $solicitudes->contains(fn ($s) =>
                    $s->cursoAbierto?->catalogo?->categoria === 'regular'
                );

                $totalSolicitudes = $solicitudes->count();
                $fechasSolicitudes = $solicitudes
                    ->pluck('matricula.fecha_inscripcion')
                    ->filter()
                    ->map(fn($d) => $d instanceof Carbon ? $d : Carbon::parse($d))
                    ->sort();
                $fechaNacimiento = $c->fecha_nacimiento;
                if ($fechaNacimiento) {
                    $fechaNacimiento = $fechaNacimiento instanceof Carbon
                        ? $fechaNacimiento->format('Y-m-d')
                        : Carbon::parse($fechaNacimiento)->format('Y-m-d');
                }
                $cuentas = $solicitudes->flatMap(fn($s) => $s->cuentasPorCobrar)->filter();
                $estados = $cuentas->pluck('estado')->unique();

                $estadoPago = 'ninguno';
                if ($totalSolicitudes > 0) {
                    if ($cuentas->count() < $totalSolicitudes || $estados->contains('pendiente')) {
                        $estadoPago = 'deudor';
                    } elseif ($estados->contains('abonado')) {
                        $estadoPago = 'abonado';
                    } elseif ($estados->count() > 0 && $estados->every(fn($e) => $e === 'pagado')) {
                        $estadoPago = 'al_dia';
                    } else {
                        $estadoPago = 'deudor';
                    }
                }

                return [
                    'id' => $c->id,
                    'tipo' => 'estudiante',
                    'nombres' => $c->nombres,
                    'apellidos' => $c->apellidos ?? '',
                    'cedula' => $c->cedula,
                    'correo' => $c->correo,
                    'celular' => $c->celular,
                    'ciudad' => $this->resolverCiudad($c),
                    'es_activo' => true,
                    'total_cursos' => $solicitudes->count(),
                    'estado_pago' => $estadoPago,
                    'saldo_pendiente' => $solicitudes->sum(function($s) {
                        $cuenta = $s->cuentasPorCobrar->first();
                        if ($cuenta) {
                            return $cuenta->monto_total - $cuenta->monto_abonado;
                        }
                        return ($s->cursoAbierto->precio_base ?? 0) - ($s->monto_solicitado ?? 0);
                    }),
                    'perfil_estudiante' => [
                        'edad' => $c->edad,
                        'ocupacion' => $c->ocupacion,
                        'direccion' => $c->direccion,
                        'estado_civil' => $c->estado_civil,
                        'fecha_nacimiento' => $fechaNacimiento,
                        'primera_matricula' => $fechasSolicitudes->isNotEmpty() ? $fechasSolicitudes->first()->format('Y-m-d') : null,
                        'ultima_matricula' => $fechasSolicitudes->isNotEmpty() ? $fechasSolicitudes->last()->format('Y-m-d') : null,
                        'total_cursos' => $totalSolicitudes,
                        'notas_internas' => null,
                    ],
                ];
            });

        $todos = $internos->concat($clientesData);

        // Agregar estudiantes de talleres (inscripciones_taller)
        $tallerEstudiantes = collect();
        $inscripcionesTaller = \App\Models\InscripcionTaller::query()
            ->whereNotNull('nombres')
            ->whereIn('estado', ['activo', 'completado'])
            ->with('taller')
            ->orderBy('nombres')
            ->get();

        $agrupadosTaller = $inscripcionesTaller->groupBy(function ($ins) {
            if (!empty($ins->cedula)) return 'cedula:' . $ins->cedula;
            return 'nombre:' . mb_strtolower(trim($ins->nombres . ' ' . $ins->apellidos));
        });

        foreach ($agrupadosTaller as $grupo) {
            $primera = $grupo->first();
            $totalTalleres = $grupo->count();
            $totalPagado = $grupo->sum(fn($ins) => $ins->monto_pagado ?? 0);
            $totalPrecio = $grupo->sum(fn($ins) => $ins->taller->precio ?? 0);
            $saldoPendiente = max(0, $totalPrecio - $totalPagado);
            $todosVerificados = $grupo->every(fn($ins) => $ins->pago_verificado);

            if ($totalPrecio <= 0) {
                $estadoPago = 'ninguno';
            } elseif ($saldoPendiente <= 0 && $todosVerificados) {
                $estadoPago = 'al_dia';
            } elseif ($totalPagado > 0) {
                $estadoPago = 'abonado';
            } else {
                $estadoPago = 'deudor';
            }

            $tallerEstudiantes->push([
                'id' => $primera->id,
                'tipo' => 'estudiante',
                'nombres' => $primera->nombres,
                'apellidos' => $primera->apellidos ?? '',
                'cedula' => $primera->cedula,
                'correo' => $primera->correo,
                'celular' => $primera->telefono,
                'ciudad' => null,
                'es_activo' => true,
                'total_cursos' => $totalTalleres,
                'estado_pago' => $estadoPago,
                'saldo_pendiente' => $saldoPendiente,
                'perfil_estudiante' => [
                    'edad' => $primera->edad,
                    'ocupacion' => $primera->ocupacion,
                    'direccion' => $primera->direccion,
                    'estado_civil' => $primera->estado_civil,
                    'fecha_nacimiento' => $primera->fecha_nacimiento,
                    'notas_internas' => null,
                ],
            ]);
        }

        // Mezclar con estudiantes existentes, evitando duplicados por cédula
        $cedulasExistentes = $todos->pluck('cedula')->filter()->toArray();
        $tallerNuevos = $tallerEstudiantes->reject(function ($te) use ($cedulasExistentes) {
            return !empty($te['cedula']) && in_array($te['cedula'], $cedulasExistentes);
        });
        $todos = $todos->concat($tallerNuevos->values());

        if ($request->filled('buscar')) {
            $buscar = mb_strtolower($request->buscar);
            $todos = $todos->filter(fn ($e) =>
                str_contains(mb_strtolower($e['nombres'] . ' ' . $e['apellidos']), $buscar) ||
                str_contains(mb_strtolower($e['cedula'] ?? ''), $buscar) ||
                str_contains(mb_strtolower($e['correo'] ?? ''), $buscar)
            );
        }

        if ($request->filled('estado_pago')) {
            $estado = $request->estado_pago;
            $todos = $todos->filter(fn ($e) => $e['estado_pago'] === $estado);
        }

        if ($request->filled('con_faltas') && $request->boolean('con_faltas')) {
            $personaIds = $todos->pluck('id')->toArray();
            $estudianteIdsConFaltas = DB::table('academic.asistencias')
                ->join('academic.matriculas', 'asistencias.matricula_id', '=', 'matriculas.id')
                ->whereIn('asistencias.estado', ['ausente', 'tardanza'])
                ->whereIn('matriculas.estudiante_id', $personaIds)
                ->select('matriculas.estudiante_id')
                ->distinct()
                ->pluck('estudiante_id');
            $todos = $todos->filter(fn($e) => $estudianteIdsConFaltas->contains($e['id']));
        }

        if ($request->filled('ordenar_por')) {
            $campo = $request->ordenar_por;
            $direccion = $request->orden ?? 'asc';
            if ($direccion === 'desc') {
                $todos = $todos->sortByDesc($campo, SORT_NATURAL | SORT_FLAG_CASE);
            } else {
                $todos = $todos->sortBy($campo, SORT_NATURAL | SORT_FLAG_CASE);
            }
            $todos = $todos->values();
        }

        $baseParaStats = $request->filled('buscar')
            ? $todos
            : $internos->concat($clientesData);

        $stats = [
            'todos' => $baseParaStats->count(),
            'deudor' => $baseParaStats->where('estado_pago', 'deudor')->count(),
            'abonado' => $baseParaStats->where('estado_pago', 'abonado')->count(),
            'al_dia' => $baseParaStats->where('estado_pago', 'al_dia')->count(),
        ];

        $total = $todos->count();
        $porPagina = (int) ($request->input('por_pagina', 15));
        $pagina = (int) ($request->input('page', 1));
        $paginated = $todos->slice(($pagina - 1) * $porPagina, $porPagina)->values();

        return response()->json([
            'datos' => $paginated,
            'stats' => $stats,
            'meta' => [
                'actual' => $pagina,
                'ultima_pagina' => (int) ceil($total / max($porPagina, 1)),
                'por_pagina' => $porPagina,
                'total' => $total,
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $estudiante = Persona::query()
            ->estudiantes()
            ->with(['ciudad', 'perfilEstudiante', 'matriculas'])
            ->find($id);

        if ($estudiante) {
            return response()->json([
                'datos' => new EstudianteResource($estudiante),
            ]);
        }

        $cliente = ClienteExterno::query()
            ->with([
                'ciudad',
                'solicitudesInscripcion' => fn($q) => $q->whereIn('estado', ['aprobado', 'matricula_creada']),
                'solicitudesInscripcion.matricula',
            ])
            ->find($id);

        if ($cliente) {
            $solicitudes = $cliente->solicitudesInscripcion;
            $totalCursos = $solicitudes->count();

            $fechasMatriculas = $solicitudes
                ->pluck('matricula.fecha_inscripcion')
                ->filter()
                ->map(fn($d) => $d instanceof Carbon ? $d : Carbon::parse($d))
                ->sort();

            $primeraMatricula = $fechasMatriculas->isNotEmpty() ? $fechasMatriculas->first()->format('Y-m-d') : null;
            $ultimaMatricula = $fechasMatriculas->isNotEmpty() ? $fechasMatriculas->last()->format('Y-m-d') : null;

            $fechaNacimiento = $cliente->fecha_nacimiento;
            if ($fechaNacimiento) {
                $fechaNacimiento = $fechaNacimiento instanceof Carbon
                    ? $fechaNacimiento->format('Y-m-d')
                    : Carbon::parse($fechaNacimiento)->format('Y-m-d');
            }

            return response()->json([
                'datos' => [
                    'id' => $cliente->id,
                    'tipo' => 'estudiante',
                    'nombres' => $cliente->nombres,
                    'apellidos' => $cliente->apellidos ?? '',
                    'cedula' => $cliente->cedula,
                    'correo' => $cliente->correo,
                    'celular' => $cliente->celular,
                    'es_activo' => true,
                    'total_cursos' => $totalCursos,
                    'estado_pago' => 'ninguno',
                    'saldo_pendiente' => 0,
                    'ciudad' => $this->resolverCiudadCompleta($cliente),
                    'perfil_estudiante' => [
                        'edad' => $cliente->edad,
                        'ocupacion' => $cliente->ocupacion,
                        'direccion' => $cliente->direccion,
                        'estado_civil' => $cliente->estado_civil,
                        'fecha_nacimiento' => $fechaNacimiento,
                        'primera_matricula' => $primeraMatricula,
                        'ultima_matricula' => $ultimaMatricula,
                        'total_cursos' => $totalCursos,
                        'notas_internas' => null,
                    ],
                    'creado_en' => $cliente->created_at?->toIso8601String(),
                    'actualizado_en' => $cliente->updated_at?->toIso8601String(),
                ],
            ]);
        }

        // Fallback: inscripcion de taller
        $inscripcionTaller = \App\Models\InscripcionTaller::query()
            ->with('taller')
            ->find($id);

        if ($inscripcionTaller) {
            $taller = $inscripcionTaller->taller;
            return response()->json([
                'datos' => [
                    'id' => $inscripcionTaller->id,
                    'tipo' => 'estudiante',
                    'nombres' => $inscripcionTaller->nombres,
                    'apellidos' => $inscripcionTaller->apellidos ?? '',
                    'cedula' => $inscripcionTaller->cedula,
                    'correo' => $inscripcionTaller->correo,
                    'celular' => $inscripcionTaller->telefono,
                    'ciudad' => $inscripcionTaller->ciudad ? ['nombre' => $inscripcionTaller->ciudad] : null,
                    'es_activo' => true,
                    'total_cursos' => 1,
                    'estado_pago' => $inscripcionTaller->pago_verificado ? 'al_dia' : 'deudor',
                    'saldo_pendiente' => max(0, ($taller->precio ?? 0) - ($inscripcionTaller->monto_pagado ?? 0)),
                    'perfil_estudiante' => [
                        'edad' => $inscripcionTaller->edad ?? null,
                        'ocupacion' => $inscripcionTaller->ocupacion ?? null,
                        'direccion' => $inscripcionTaller->direccion ?? null,
                        'estado_civil' => $inscripcionTaller->estado_civil ?? null,
                        'fecha_nacimiento' => $inscripcionTaller->fecha_nacimiento
                            ? (\Carbon\Carbon::parse($inscripcionTaller->fecha_nacimiento)->format('Y-m-d'))
                            : null,
                        'primera_matricula' => $inscripcionTaller->fecha_inscripcion ?? null,
                        'ultima_matricula' => $inscripcionTaller->fecha_inscripcion ?? null,
                        'total_cursos' => 1,
                        'notas_internas' => 'Inscrito al taller: ' . ($taller->nombre ?? 'Sin nombre'),
                    ],
                    'creado_en' => $inscripcionTaller->created_at?->toIso8601String(),
                    'actualizado_en' => $inscripcionTaller->updated_at?->toIso8601String(),
                ],
            ]);
        }

        return response()->json(['mensaje' => 'Estudiante no encontrado'], 404);
    }

    public function academicProfile(string $id): JsonResponse
    {
        $estudiante = Persona::query()
            ->estudiantes()
            ->with([
                'matriculas.cursoAbierto.catalogo',
                'matriculas.cursoAbierto.modulos',
                'matriculas.notas.modulo',
            ])
            ->find($id);

        $datosEstudiante = null;
        $matriculasRaw = collect();

        if ($estudiante) {
            $datosEstudiante = [
                'id' => $estudiante->id,
                'nombre_completo' => $estudiante->nombres . ' ' . $estudiante->apellidos,
                'cedula' => $estudiante->cedula,
                'correo' => $estudiante->correo,
            ];
            $matriculasRaw = $estudiante->matriculas;
        } else {
            $cliente = ClienteExterno::query()
                ->with([
                    'solicitudesInscripcion.matricula.cursoAbierto.catalogo',
                    'solicitudesInscripcion.matricula.cursoAbierto.modulos',
                    'solicitudesInscripcion.matricula.notas.modulo',
                ])
                ->find($id);

            if (!$cliente) {
                $inscripcionTaller = \App\Models\InscripcionTaller::query()->with('taller')->find($id);
                if ($inscripcionTaller) {
                    $t = $inscripcionTaller->taller;
                    return response()->json([
                        'datos' => [
                            'estudiante' => [
                                'id' => $inscripcionTaller->id,
                                'nombre_completo' => $inscripcionTaller->nombres . ' ' . ($inscripcionTaller->apellidos ?? ''),
                                'cedula' => $inscripcionTaller->cedula ?? '—',
                                'correo' => $inscripcionTaller->correo ?? '—',
                            ],
                            'matriculas' => [[
                                'id' => $inscripcionTaller->id,
                                'curso' => 'Taller: ' . ($t->nombre ?? 'Sin nombre'),
                                'estado' => $inscripcionTaller->estado ?? 'activo',
                                'fecha_inscripcion' => $inscripcionTaller->fecha_inscripcion ?? $inscripcionTaller->created_at?->format('Y-m-d'),
                                'porcentaje_asistencia' => 100,
                                'promedio' => null,
                                'notas' => [],
                            ]],
                        ],
                    ]);
                }
                return response()->json(['mensaje' => 'Estudiante no encontrado'], 404);
            }

            $datosEstudiante = [
                'id' => $cliente->id,
                'nombre_completo' => $cliente->nombres . ' ' . ($cliente->apellidos ?? ''),
                'cedula' => $cliente->cedula,
                'correo' => $cliente->correo,
            ];

            $matriculasRaw = $cliente->solicitudesInscripcion
                ->map(fn($s) => $s->matricula)
                ->filter()
                ->values();
        }

        $matriculasAcademica = $matriculasRaw->map(function ($matricula) {
            if (!$matricula->cursoAbierto) {
                return [
                    'id' => $matricula->id,
                    'curso' => 'Curso no encontrado',
                    'estado' => $matricula->estado,
                    'fecha_inscripcion' => $matricula->fecha_inscripcion ?? $matricula->created_at,
                    'porcentaje_asistencia' => 0,
                    'notas' => [],
                    'promedio' => null,
                ];
            }

            $totalClases = Clase::whereHas('modulo', function($q) use ($matricula) {
                $q->where('curso_abierto_id', $matricula->curso_abierto_id);
            })->count();

            $asistidas = Asistencia::where('matricula_id', $matricula->id)
                ->where('asistio', true)
                ->count();

            $porcentajeAsistencia = $totalClases > 0 ? round(($asistidas / $totalClases) * 100, 2) : 100;

            return [
                'id' => $matricula->id,
                'curso' => ($matricula->cursoAbierto->catalogo->nombre ?? 'Sin nombre') . " (" . $matricula->cursoAbierto->nombre_instancia . ")",
                'estado' => $matricula->estado,
                'fecha_inscripcion' => $matricula->fecha_inscripcion ?? $matricula->created_at,
                'porcentaje_asistencia' => $porcentajeAsistencia,
                'notas' => $matricula->notas->map(function ($nota) {
                    return [
                        'modulo' => $nota->modulo->nombre_modulo ?? 'Sin modulo',
                        'calificacion' => $nota->calificacion,
                        'aprobado' => $nota->estaAprobada(),
                    ];
                }),
                'promedio' => $matricula->calcularPromedio(),
            ];
        });

        return response()->json([
            'datos' => [
                'estudiante' => $datosEstudiante,
                'matriculas' => $matriculasAcademica
            ]
        ]);
    }

    public function detalleAsistencias(string $id): JsonResponse
    {
        $estudiante = Persona::query()->estudiantes()->find($id);
        $matriculasRaw = collect();

        if ($estudiante) {
            $matriculasRaw = $estudiante->matriculas;
        } else {
            $cliente = ClienteExterno::query()
                ->with(['solicitudesInscripcion.matricula'])
                ->find($id);

            if (!$cliente) {
                return response()->json(['datos' => ['estudiante_id' => $id, 'matriculas' => []]]);
            }

            $matriculasRaw = $cliente->solicitudesInscripcion
                ->map(fn($s) => $s->matricula)
                ->filter()
                ->values();
        }

        $matriculasData = $matriculasRaw->map(function ($matricula) {
            $asistencias = Asistencia::where('matricula_id', $matricula->id)
                ->with(['clase:id,fecha_clase,hora_inicio,hora_fin,modulo_id',
                        'clase.modulo:id,nombre_modulo'])
                ->get();

            if ($asistencias->isEmpty()) {
                return null;
            }

            $porModulo = $asistencias->groupBy(fn($a) => $a->clase->modulo_id);

            return [
                'matricula_id' => $matricula->id,
                'curso' => $matricula->cursoAbierto
                    ? ($matricula->cursoAbierto->catalogo->nombre ?? 'Sin nombre') . ' (' . $matricula->cursoAbierto->nombre_instancia . ')'
                    : 'Curso no encontrado',
                'total_ausencias' => $asistencias->where('estado', 'ausente')->count(),
                'total_tardanzas' => $asistencias->where('estado', 'tardanza')->count(),
                'total_justificados' => $asistencias->where('estado', 'justificado')->count(),
                'modulos' => $porModulo->map(fn($items, $moduloId) => [
                    'modulo_id' => $moduloId,
                    'modulo_nombre' => $items->first()->clase->modulo->nombre_modulo,
                    'registros' => $items->map(fn($a) => [
                        'fecha' => $a->clase->fecha_clase?->format('Y-m-d'),
                        'hora_inicio' => $a->clase->hora_inicio,
                        'hora_fin' => $a->clase->hora_fin,
                        'estado' => $a->estado,
                        'asistio' => $a->asistio,
                        'observaciones' => $a->observaciones,
                    ]),
                    'total_ausencias' => $items->where('estado', 'ausente')->count(),
                    'total_tardanzas' => $items->where('estado', 'tardanza')->count(),
                    'total_justificados' => $items->where('estado', 'justificado')->count(),
                ])->values(),
            ];
        })->filter()->values();

        return response()->json([
            'datos' => [
                'estudiante_id' => $id,
                'matriculas' => $matriculasData,
            ],
        ]);
    }

    public function store(EstudianteStoreRequest $request): JsonResponse
    {
        $datos = $request->validated();

        $estudiante = DB::transaction(function () use ($datos) {
            $persona = Persona::create([
                'tipo' => 'estudiante',
                'cedula' => $datos['cedula'] ?? null,
                'nombres' => $datos['nombres'],
                'apellidos' => $datos['apellidos'],
                'correo' => $datos['correo'] ?? null,
                'celular' => $datos['celular'] ?? null,
                'ciudad_id' => $datos['ciudad_id'] ?? null,
            ]);

            PerfilEstudiante::create([
                'persona_id' => $persona->id,
                'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
                'notas_internas' => $datos['notas_internas'] ?? null,
                'ocupacion' => $datos['ocupacion'] ?? null,
                'direccion' => $datos['direccion'] ?? null,
                'estado_civil' => $datos['estado_civil'] ?? null,
                'edad' => $datos['edad'] ?? null,
            ]);

            return $persona->load(['ciudad', 'perfilEstudiante']);
        });

        return response()->json([
            'mensaje' => 'Estudiante registrado exitosamente.',
            'datos' => new EstudianteResource($estudiante),
        ], 201);
    }

    public function update(EstudianteUpdateRequest $request, string $id): JsonResponse
    {
        $estudiante = Persona::query()
            ->estudiantes()
            ->with(['ciudad', 'perfilEstudiante'])
            ->findOrFail($id);

        $datos = $request->validated();

        DB::transaction(function () use ($estudiante, $datos) {
            $datosPerfil = array_intersect_key($datos, array_flip([
                'fecha_nacimiento',
                'notas_internas',
                'ocupacion',
                'direccion',
                'estado_civil',
                'edad',
            ]));

            $datosPersona = array_diff_key($datos, $datosPerfil);

            if (!empty($datosPersona)) {
                $estudiante->update($datosPersona);
            }

            if (!empty($datosPerfil)) {
                $estudiante->perfilEstudiante()->updateOrCreate(
                    ['persona_id' => $estudiante->id],
                    $datosPerfil
                );
            }
        });

        $estudiante->refresh();
        $estudiante->load(['ciudad', 'perfilEstudiante']);

        return response()->json([
            'mensaje' => 'Estudiante actualizado exitosamente.',
            'datos' => new EstudianteResource($estudiante),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $estudiante = Persona::query()
            ->estudiantes()
            ->find($id);

        if ($estudiante) {
            $this->eliminarEstudianteInterno($estudiante);
            return response()->json(['mensaje' => 'Estudiante eliminado exitosamente.']);
        }

        $cliente = ClienteExterno::query()->find($id);

        if ($cliente) {
            $this->eliminarEstudianteExterno($cliente);
            return response()->json(['mensaje' => 'Estudiante externo eliminado exitosamente.']);
        }

        // Participante de taller
        $inscripcion = \App\Models\InscripcionTaller::find($id);
        if ($inscripcion) {
            $inscripcion->delete();
            return response()->json(['mensaje' => 'Participante de taller eliminado exitosamente.']);
        }

        return response()->json(['mensaje' => 'Estudiante no encontrado'], 404);
    }

    private function eliminarEstudianteInterno(Persona $estudiante): void
    {
        DB::transaction(function () use ($estudiante) {
            $matriculaIds = Matricula::where('estudiante_id', $estudiante->id)->pluck('id');

            if ($matriculaIds->isNotEmpty()) {
                // Eliminar transacciones legacy (cuenta_cobrar)
                TransaccionIngreso::whereIn('cuenta_cobrar_id', function ($q) use ($matriculaIds) {
                    $q->select('id')->from('finance.cuentas_por_cobrar')->whereIn('matricula_id', $matriculaIds);
                })->delete();

                // Eliminar transacciones nuevas (linea_pago_modulo)
                TransaccionIngreso::whereIn('linea_pago_modulo_id', function ($q) use ($matriculaIds) {
                    $q->select('id')->from('finance.lineas_pago_modulo')->whereIn('matricula_id', $matriculaIds);
                })->delete();

                // Eliminar líneas de pago por módulo
                \App\Models\Finance\LineaPagoModulo::whereIn('matricula_id', $matriculaIds)->delete();

                CuentaPorCobrar::whereIn('matricula_id', $matriculaIds)->delete();

                Nota::whereIn('matricula_id', $matriculaIds)->delete();

                Asistencia::whereIn('matricula_id', $matriculaIds)->delete();

                Matricula::whereIn('id', $matriculaIds)->delete();
            }

            PerfilEstudiante::where('persona_id', $estudiante->id)->delete();

            $this->eliminarServiciosYFinanzasVinculados('persona_id', $estudiante->id);

            $estudiante->delete();
        });
    }

    private function eliminarEstudianteExterno(ClienteExterno $cliente): void
    {
        DB::transaction(function () use ($cliente) {
            $solicitudes = $cliente->solicitudesInscripcion()
                ->whereIn('estado', ['aprobado', 'matricula_creada'])
                ->get();

            foreach ($solicitudes as $solicitud) {
                if ($solicitud->matricula) {
                    $matricula = $solicitud->matricula;

                    TransaccionIngreso::whereIn('cuenta_cobrar_id', function ($q) use ($matricula) {
                        $q->select('id')->from('finance.cuentas_por_cobrar')->where('matricula_id', $matricula->id);
                    })->delete();

                    CuentaPorCobrar::where('matricula_id', $matricula->id)->delete();
                    Nota::where('matricula_id', $matricula->id)->delete();
                    Asistencia::where('matricula_id', $matricula->id)->delete();
                    $matricula->delete();
                }

                TransaccionIngreso::whereIn('cuenta_cobrar_id', function ($q) use ($solicitud) {
                    $q->select('id')->from('finance.cuentas_por_cobrar')->where('solicitud_inscripcion_id', $solicitud->id);
                })->delete();
                CuentaPorCobrar::where('solicitud_inscripcion_id', $solicitud->id)->delete();
                $solicitud->delete();
            }

            $cliente->solicitudesInscripcion()->delete();

            $this->eliminarServiciosYFinanzasVinculados('cliente_externo_id', $cliente->id);

            $cliente->delete();
        });
    }

    private function eliminarServiciosYFinanzasVinculados(string $columna, string $id): void
    {
        $podcastIds = DB::table('services.reservas_podcast')->where($columna, $id)->pluck('id')->toArray();
        $streamingIds = DB::table('services.servicios_streaming')->where($columna, $id)->pluck('id')->toArray();
        $produccionIds = DB::table('services.servicios_produccion')->where($columna, $id)->pluck('id')->toArray();
        $edicionIds = DB::table('services.edicion_videos')->where($columna, $id)->pluck('id')->toArray();
        
        if (!empty($podcastIds) || !empty($streamingIds) || !empty($produccionIds) || !empty($edicionIds)) {
            DB::table('services.asignaciones_personal')
                ->where(function ($query) use ($podcastIds, $streamingIds, $produccionIds, $edicionIds) {
                    if (!empty($podcastIds)) $query->orWhereIn('reserva_podcast_id', $podcastIds);
                    if (!empty($streamingIds)) $query->orWhereIn('servicio_streaming_id', $streamingIds);
                    if (!empty($produccionIds)) $query->orWhereIn('servicio_produccion_id', $produccionIds);
                    if (!empty($edicionIds)) $query->orWhereIn('edicion_video_id', $edicionIds);
                })
                ->delete();
        }

        $aulaIds = DB::table('services.reservas_aulas')->where($columna, $id)->pluck('id')->toArray();
        $alquilerIds = DB::table('services.alquiler_equipos')->where($columna, $id)->pluck('id')->toArray();
        $asesoriaIds = DB::table('academic.asesorias')->where($columna, $id)->pluck('id')->toArray();

        $tieneCuentas = !empty($podcastIds) || !empty($streamingIds) || !empty($produccionIds) || !empty($edicionIds) || !empty($aulaIds) || !empty($alquilerIds) || !empty($asesoriaIds);

        if ($tieneCuentas) {
            $cuentasCobrarIds = DB::table('finance.cuentas_por_cobrar')
                ->where(function ($query) use ($podcastIds, $streamingIds, $produccionIds, $edicionIds, $aulaIds, $alquilerIds, $asesoriaIds) {
                    if (!empty($podcastIds)) $query->orWhereIn('reserva_podcast_id', $podcastIds);
                    if (!empty($streamingIds)) $query->orWhereIn('servicio_streaming_id', $streamingIds);
                    if (!empty($produccionIds)) $query->orWhereIn('servicio_produccion_id', $produccionIds);
                    if (!empty($edicionIds)) $query->orWhereIn('edicion_video_id', $edicionIds);
                    if (!empty($aulaIds)) $query->orWhereIn('reserva_aula_id', $aulaIds);
                    if (!empty($alquilerIds)) $query->orWhereIn('alquiler_equipo_id', $alquilerIds);
                    if (!empty($asesoriaIds)) $query->orWhereIn('asesoria_id', $asesoriaIds);
                })
                ->pluck('id')
                ->toArray();

            if (!empty($cuentasCobrarIds)) {
                TransaccionIngreso::whereIn('cuenta_cobrar_id', $cuentasCobrarIds)->delete();
                CuentaPorCobrar::whereIn('id', $cuentasCobrarIds)->delete();
            }
        }

        DB::table('academic.asesorias')->where($columna, $id)->delete();
        DB::table('services.reservas_aulas')->where($columna, $id)->delete();
        DB::table('services.reservas_podcast')->where($columna, $id)->delete();
        DB::table('services.servicios_streaming')->where($columna, $id)->delete();
        DB::table('services.servicios_produccion')->where($columna, $id)->delete();
        DB::table('services.edicion_videos')->where($columna, $id)->delete();
        DB::table('services.alquiler_equipos')->where($columna, $id)->delete();
    }

    /**
     * Perfil financiero completo de un estudiante
     */
    public function financialProfile(string $id): JsonResponse
    {
        $estudiante = Persona::query()
            ->estudiantes()
            ->with([
                'matriculas.cursoAbierto.catalogo',
                'matriculas.cursoAbierto.modulos',
                'matriculas.lineasPago.modulo',
                'matriculas.lineasPago.transacciones',
                'matriculas.cuentaPorCobrar.transacciones',
                'matriculas.solicitudInscripcion.cuentasPorCobrar.transacciones',
                'ciudad',
            ])
            ->find($id);

        $datosEstudiante = null;
        $cuentas = collect();
        $transacciones = collect();
        $matriculasConLineas = collect();

        if ($estudiante) {
            try {
                $datosEstudiante = [
                'id' => $estudiante->id,
                'nombre_completo' => $estudiante->nombres . ' ' . $estudiante->apellidos,
                'cedula' => $estudiante->cedula,
                'correo' => $estudiante->correo,
                'celular' => $estudiante->celular,
            ];

            foreach ($estudiante->matriculas as $matricula) {
                $matriculasConLineas->push([
                    'id' => $matricula->id,
                    'curso' => [
                        'nombre' => ($matricula->cursoAbierto->catalogo->nombre ?? 'Curso'),
                        'instancia' => $matricula->cursoAbierto->nombre_instancia ?? '',
                    ],
                    'lineas_pago' => $matricula->lineasPago->map(function ($lp) {
                        return [
                            'id' => $lp->id,
                            'modulo' => [
                                'id' => $lp->modulo?->id ?? null,
                                'nombre' => $lp->modulo?->nombre_modulo ?? $lp->modulo?->nombre ?? 'Módulo',
                                'numero_orden' => $lp->modulo?->numero_orden ?? $lp->orden,
                            ],
                            'monto_original' => (float) $lp->monto_original,
                            'monto_ajustado' => (float) $lp->monto_ajustado,
                            'monto_abonado' => (float) $lp->monto_abonado,
                            'saldo_pendiente' => (float) $lp->saldo_pendiente,
                            'estado' => $lp->estado,
                            'orden' => $lp->orden,
                        ];
                    }),
                ]);

                $concepto = ($matricula->cursoAbierto->catalogo->nombre ?? 'Curso') . ' - ' . ($matricula->cursoAbierto->nombre_instancia ?? '');
                $cuenta = $matricula->cuentaPorCobrar;

                if ($cuenta) {
                    $cuentas->push([
                        'id' => $cuenta->id,
                        'origen' => 'matricula',
                        'origen_id' => $matricula->id,
                        'concepto' => $concepto,
                        'monto_total' => (float) $cuenta->monto_total,
                        'monto_abonado' => (float) $cuenta->monto_abonado,
                        'saldo_pendiente' => (float) $cuenta->obtenerSaldoPendiente(),
                        'estado' => $cuenta->estado,
                        'fecha_creacion' => $cuenta->created_at?->format('Y-m-d'),
                        'transacciones' => $cuenta->transacciones->map(fn($t) => [
                            'id' => $t->id,
                            'monto' => (float) $t->monto,
                            'metodo_pago' => $t->metodo_pago,
                            'comprobante_url' => $t->comprobante_url,
                            'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                            'estado_verificacion' => $t->estado_verificacion,
                            'observaciones' => $t->observaciones,
                        ]),
                    ]);
                    $transacciones = $transacciones->concat($cuenta->transacciones->map(fn($t) => [
                        'id' => $t->id,
                        'cuenta_id' => $cuenta->id,
                        'concepto' => $concepto,
                        'monto' => (float) $t->monto,
                        'metodo_pago' => $t->metodo_pago,
                        'comprobante_url' => $t->comprobante_url,
                        'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                        'estado_verificacion' => $t->estado_verificacion,
                        'observaciones' => $t->observaciones,
                    ]));
                } elseif ($matricula->solicitudInscripcion && $matricula->solicitudInscripcion->cuentasPorCobrar->isNotEmpty()) {
                    foreach ($matricula->solicitudInscripcion->cuentasPorCobrar as $cuentaSolicitud) {
                        $cuentas->push([
                            'id' => $cuentaSolicitud->id,
                            'origen' => 'solicitud_inscripcion',
                            'origen_id' => $matricula->solicitudInscripcion->id,
                            'concepto' => $concepto,
                            'monto_total' => (float) $cuentaSolicitud->monto_total,
                            'monto_abonado' => (float) $cuentaSolicitud->monto_abonado,
                            'saldo_pendiente' => (float) $cuentaSolicitud->obtenerSaldoPendiente(),
                            'estado' => $cuentaSolicitud->estado,
                            'fecha_creacion' => $cuentaSolicitud->created_at?->format('Y-m-d'),
                            'transacciones' => $cuentaSolicitud->transacciones->map(fn($t) => [
                                'id' => $t->id,
                                'monto' => (float) $t->monto,
                                'metodo_pago' => $t->metodo_pago,
                                'comprobante_url' => $t->comprobante_url,
                                'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                                'estado_verificacion' => $t->estado_verificacion,
                                'observaciones' => $t->observaciones,
                            ]),
                        ]);
                        $transacciones = $transacciones->concat($cuentaSolicitud->transacciones->map(fn($t) => [
                            'id' => $t->id,
                            'cuenta_id' => $cuentaSolicitud->id,
                            'concepto' => $concepto,
                            'monto' => (float) $t->monto,
                            'metodo_pago' => $t->metodo_pago,
                            'comprobante_url' => $t->comprobante_url,
                            'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                            'estado_verificacion' => $t->estado_verificacion,
                            'observaciones' => $t->observaciones,
                        ]));
                    }
                } else {
                    foreach ($matricula->lineasPago as $lp) {
                        $moduloNombre = $lp->modulo?->nombre_modulo ?? $lp->modulo?->nombre ?? 'Módulo';
                        $lpConcepto = $concepto . ' - ' . $moduloNombre;
                        $lpSaldo = (float) $lp->saldo_pendiente;
                        $lpAbonado = (float) $lp->monto_abonado;
                        $cuentas->push([
                            'id' => $lp->id,
                            'origen' => 'matricula',
                            'origen_id' => $matricula->id,
                            'concepto' => $lpConcepto,
                            'monto_total' => (float) $lp->monto_ajustado,
                            'monto_abonado' => $lpAbonado,
                            'saldo_pendiente' => max(0, $lpSaldo),
                            'estado' => $lp->estado,
                            'fecha_creacion' => $matricula->fecha_inscripcion
                                ? $matricula->fecha_inscripcion->format('Y-m-d')
                                : ($matricula->created_at?->format('Y-m-d') ?? null),
                            'transacciones' => $lp->transacciones->map(fn($t) => [
                                'id' => $t->id,
                                'monto' => (float) $t->monto,
                                'metodo_pago' => $t->metodo_pago,
                                'comprobante_url' => $t->comprobante_url,
                                'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                                'estado_verificacion' => $t->estado_verificacion,
                                'observaciones' => $t->observaciones,
                            ])->values(),
                        ]);
                        $transacciones = $transacciones->concat($lp->transacciones->map(fn($t) => [
                            'id' => $t->id,
                            'cuenta_id' => $lp->id,
                            'concepto' => $lpConcepto,
                            'monto' => (float) $t->monto,
                            'metodo_pago' => $t->metodo_pago,
                            'comprobante_url' => $t->comprobante_url,
                            'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                            'estado_verificacion' => $t->estado_verificacion,
                            'observaciones' => $t->observaciones,
                        ]));
                    }
                }
            }

            // Cuentas por cobrar de servicios (Podcast, Aula, Equipo, Radio)
            $clienteExternoMatch = null;
            if ($estudiante->cedula) {
                $clienteExternoMatch = \App\Models\ClienteExterno::where('cedula', $estudiante->cedula)->first();
            }

            $cuentasServicios = \App\Models\CuentaPorCobrar::query()
                ->with(['transacciones'])
                ->where(function ($q) use ($estudiante, $clienteExternoMatch) {
                    $q->whereHas('reservaPodcast', fn($sq) => $sq->where('persona_id', $estudiante->id));
                    $q->orWhereHas('reservaAula', fn($sq) => $sq->where('persona_id', $estudiante->id));
                    $q->orWhereHas('alquilerEquipo', fn($sq) => $sq->where('persona_id', $estudiante->id));
                    $q->orWhereHas('reservaRadio', fn($sq) => $sq->where('persona_id', $estudiante->id));
                    if ($clienteExternoMatch) {
                        $q->orWhereHas('reservaPodcast', fn($sq) => $sq->where('cliente_externo_id', $clienteExternoMatch->id));
                        $q->orWhereHas('reservaAula', fn($sq) => $sq->where('cliente_externo_id', $clienteExternoMatch->id));
                        $q->orWhereHas('alquilerEquipo', fn($sq) => $sq->where('cliente_externo_id', $clienteExternoMatch->id));
                        $q->orWhereHas('reservaRadio', fn($sq) => $sq->where('cliente_externo_id', $clienteExternoMatch->id));
                    }
                })
                ->get();

            $servicioLabels = [
                'reserva_podcast_id' => 'Podcast',
                'reserva_aula_id' => 'Aula',
                'alquiler_equipo_id' => 'Equipo',
                'reserva_radio_id' => 'Radio',
                'edicion_video_id' => 'Edición de Video',
            ];

            foreach ($cuentasServicios as $cs) {
                $tipoServicio = '';
                foreach ($servicioLabels as $col => $label) {
                    if ($cs->$col) { $tipoServicio = $label; break; }
                }

                $cuentas->push([
                    'id' => $cs->id,
                    'origen' => 'servicio',
                    'origen_id' => $cs->id,
                    'concepto' => $tipoServicio ?: 'Servicio',
                    'monto_total' => (float) $cs->monto_total,
                    'monto_abonado' => (float) $cs->monto_abonado,
                    'saldo_pendiente' => (float) $cs->obtenerSaldoPendiente(),
                    'estado' => $cs->estado,
                    'fecha_creacion' => $cs->created_at?->format('Y-m-d'),
                    'transacciones' => $cs->transacciones->map(fn($t) => [
                        'id' => $t->id,
                        'monto' => (float) $t->monto,
                        'metodo_pago' => $t->metodo_pago,
                        'comprobante_url' => $t->comprobante_url,
                        'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                        'estado_verificacion' => $t->estado_verificacion,
                        'observaciones' => $t->observaciones,
                    ])->values(),
                ]);
                $transacciones = $transacciones->concat($cs->transacciones->map(fn($t) => [
                    'id' => $t->id,
                    'cuenta_id' => $cs->id,
                    'concepto' => $tipoServicio ?: 'Servicio',
                    'monto' => (float) $t->monto,
                    'metodo_pago' => $t->metodo_pago,
                    'comprobante_url' => $t->comprobante_url,
                    'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                    'estado_verificacion' => $t->estado_verificacion,
                    'observaciones' => $t->observaciones,
                ]));
            }

            if ($matriculasConLineas->isEmpty()) {
                $solicitudes = \App\Models\SolicitudInscripcion::where('persona_id', $estudiante->id)
                    ->whereIn('estado', ['aprobado', 'matricula_creada'])
                    ->with([
                        'matricula.lineasPago.modulo',
                        'matricula.lineasPago.transacciones',
                        'matricula.cuentaPorCobrar.transacciones',
                        'cursoAbierto.catalogo',
                    ])->get();

                if ($solicitudes->isEmpty() && $estudiante->cedula) {
                    $clienteExterno = \App\Models\ClienteExterno::where('cedula', $estudiante->cedula)->first();
                    if ($clienteExterno) {
                        $solicitudes = \App\Models\SolicitudInscripcion::where('participante_externo_id', $clienteExterno->id)
                            ->whereIn('estado', ['aprobado', 'matricula_creada'])
                            ->with([
                                'matricula.lineasPago.modulo',
                                'matricula.lineasPago.transacciones',
                                'matricula.cuentaPorCobrar.transacciones',
                                'cursoAbierto.catalogo',
                            ])->get();
                    }
                }

                foreach ($solicitudes as $sol) {
                    $matricula = $sol->matricula;
                    if (!$matricula) continue;

                    $matriculasConLineas->push([
                        'id' => $matricula->id,
                        'curso' => [
                            'nombre' => ($sol->cursoAbierto->catalogo->nombre ?? 'Curso'),
                            'instancia' => $sol->cursoAbierto->nombre_instancia ?? '',
                        ],
                        'lineas_pago' => $matricula->lineasPago->map(function ($lp) {
                            return [
                                'id' => $lp->id,
                                'modulo' => [
                                    'id' => $lp->modulo?->id ?? null,
                                    'nombre' => $lp->modulo?->nombre_modulo ?? $lp->modulo?->nombre ?? 'Módulo',
                                    'numero_orden' => $lp->modulo?->numero_orden ?? $lp->orden,
                                ],
                                'monto_original' => (float) $lp->monto_original,
                                'monto_ajustado' => (float) $lp->monto_ajustado,
                                'monto_abonado' => (float) $lp->monto_abonado,
                                'saldo_pendiente' => (float) $lp->saldo_pendiente,
                                'estado' => $lp->estado,
                                'orden' => $lp->orden,
                            ];
                        }),
                    ]);

                    $concepto = ($sol->cursoAbierto->catalogo->nombre ?? 'Curso') . ' - ' . ($sol->cursoAbierto->nombre_instancia ?? '');
                    $cuenta = $matricula->cuentaPorCobrar;

                    if ($cuenta) {
                        $cuentas->push([
                            'id' => $cuenta->id,
                            'origen' => 'matricula',
                            'origen_id' => $matricula->id,
                            'concepto' => $concepto,
                            'monto_total' => (float) $cuenta->monto_total,
                            'monto_abonado' => (float) $cuenta->monto_abonado,
                            'saldo_pendiente' => (float) $cuenta->obtenerSaldoPendiente(),
                            'estado' => $cuenta->estado,
                            'fecha_creacion' => $cuenta->created_at?->format('Y-m-d'),
                            'transacciones' => $cuenta->transacciones->map(fn($t) => [
                                'id' => $t->id,
                                'monto' => (float) $t->monto,
                                'metodo_pago' => $t->metodo_pago,
                                'comprobante_url' => $t->comprobante_url,
                                'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                                'estado_verificacion' => $t->estado_verificacion,
                                'observaciones' => $t->observaciones,
                            ]),
                        ]);
                        $transacciones = $transacciones->concat($cuenta->transacciones->map(fn($t) => [
                            'id' => $t->id,
                            'cuenta_id' => $cuenta->id,
                            'concepto' => $concepto,
                            'monto' => (float) $t->monto,
                            'metodo_pago' => $t->metodo_pago,
                            'comprobante_url' => $t->comprobante_url,
                            'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                            'estado_verificacion' => $t->estado_verificacion,
                            'observaciones' => $t->observaciones,
                        ]));
                    } else {
                        foreach ($matricula->lineasPago as $lp) {
                            $moduloNombre = $lp->modulo?->nombre_modulo ?? $lp->modulo?->nombre ?? 'Módulo';
                            $lpConcepto = $concepto . ' - ' . $moduloNombre;
                            $lpSaldo = (float) $lp->saldo_pendiente;
                            $lpAbonado = (float) $lp->monto_abonado;
                            $cuentas->push([
                                'id' => $lp->id,
                                'origen' => 'matricula',
                                'origen_id' => $matricula->id,
                                'concepto' => $lpConcepto,
                                'monto_total' => (float) $lp->monto_ajustado,
                                'monto_abonado' => $lpAbonado,
                                'saldo_pendiente' => max(0, $lpSaldo),
                                'estado' => $lp->estado,
                                'fecha_creacion' => $matricula->fecha_inscripcion
                                    ? $matricula->fecha_inscripcion->format('Y-m-d')
                                    : ($matricula->created_at?->format('Y-m-d') ?? null),
                                'transacciones' => $lp->transacciones->map(fn($t) => [
                                    'id' => $t->id,
                                    'monto' => (float) $t->monto,
                                    'metodo_pago' => $t->metodo_pago,
                                    'comprobante_url' => $t->comprobante_url,
                                    'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                                    'estado_verificacion' => $t->estado_verificacion,
                                    'observaciones' => $t->observaciones,
                                ])->values(),
                            ]);
                            $transacciones = $transacciones->concat($lp->transacciones->map(fn($t) => [
                                'id' => $t->id,
                                'cuenta_id' => $lp->id,
                                'concepto' => $lpConcepto,
                                'monto' => (float) $t->monto,
                                'metodo_pago' => $t->metodo_pago,
                                'comprobante_url' => $t->comprobante_url,
                                'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                                'estado_verificacion' => $t->estado_verificacion,
                                'observaciones' => $t->observaciones,
                            ]));
                        }
                    }
                }
            }
            } catch (\Exception $e) {
                \Log::error('Error en financialProfile interno: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            }
        } else {
            $cliente = ClienteExterno::query()
                ->with([
                    'solicitudesInscripcion' => fn($q) => $q->whereIn('estado', ['aprobado', 'matricula_creada']),
                    'solicitudesInscripcion.cursoAbierto.catalogo',
                    'solicitudesInscripcion.cuentasPorCobrar.transacciones',
                    'solicitudesInscripcion.matricula.lineasPago.modulo',
                    'solicitudesInscripcion.matricula.lineasPago.transacciones',
                    'solicitudesInscripcion.matricula.cuentaPorCobrar.transacciones',
                    'ciudad',
                ])
                 ->find($id);

            if (!$cliente) {
                $inscripcionTaller = \App\Models\InscripcionTaller::query()->with('taller')->find($id);
                if ($inscripcionTaller) {
                    $t = $inscripcionTaller->taller;
                    $precio = (float) ($t->precio ?? 0);
                    $pagado = (float) ($inscripcionTaller->monto_pagado ?? 0);
                    return response()->json([
                        'datos' => [
                            'estudiante' => [
                                'id' => $inscripcionTaller->id,
                                'nombre_completo' => $inscripcionTaller->nombres . ' ' . ($inscripcionTaller->apellidos ?? ''),
                                'cedula' => $inscripcionTaller->cedula ?? '—',
                                'correo' => $inscripcionTaller->correo ?? '—',
                                'celular' => $inscripcionTaller->telefono ?? '—',
                            ],
                            'cuentas' => [[
                                'id' => $inscripcionTaller->id,
                                'origen' => 'taller',
                                'origen_id' => $inscripcionTaller->id,
                                'concepto' => 'Taller: ' . ($t->nombre ?? 'Sin nombre'),
                                'monto_total' => $precio,
                                'monto_abonado' => $pagado,
                                'saldo_pendiente' => max(0, $precio - $pagado),
                                'estado' => $inscripcionTaller->pago_verificado ? 'pagado' : ($pagado > 0 ? 'abonado' : 'pendiente'),
                                'fecha_creacion' => $inscripcionTaller->fecha_inscripcion ?? $inscripcionTaller->created_at?->format('Y-m-d'),
                                'transacciones' => [],
                            ]],
                            'transacciones' => [],
                            'resumen' => [
                                'total_adeudado' => max(0, $precio - $pagado),
                                'total_pagado' => $pagado,
                                'total_general' => $precio,
                                'porcentaje_pagado' => $precio > 0 ? round(($pagado / $precio) * 100) : 0,
                                'cuentas_pendientes' => $inscripcionTaller->pago_verificado ? 0 : 1,
                                'cuentas_abonadas' => ($pagado > 0 && !$inscripcionTaller->pago_verificado) ? 1 : 0,
                                'cuentas_pagadas' => $inscripcionTaller->pago_verificado ? 1 : 0,
                            ],
                        ],
                    ]);
                }
                return response()->json(['mensaje' => 'Estudiante no encontrado'], 404);
            }

            $datosEstudiante = [
                'id' => $cliente->id,
                'nombre_completo' => $cliente->nombres . ' ' . ($cliente->apellidos ?? ''),
                'cedula' => $cliente->cedula,
                'correo' => $cliente->correo,
                'celular' => $cliente->celular,
            ];

            foreach ($cliente->solicitudesInscripcion as $solicitud) {
                $concepto = ($solicitud->cursoAbierto->catalogo->nombre ?? 'Curso') . ' - ' . ($solicitud->cursoAbierto->nombre_instancia ?? '');
                $matricula = $solicitud->matricula;

                if ($matricula) {
                    $matriculasConLineas->push([
                        'id' => $matricula->id,
                        'curso' => [
                            'nombre' => ($solicitud->cursoAbierto->catalogo->nombre ?? 'Curso'),
                            'instancia' => $solicitud->cursoAbierto->nombre_instancia ?? '',
                        ],
                        'lineas_pago' => $matricula->lineasPago->map(function ($lp) {
                            return [
                                'id' => $lp->id,
                                'modulo' => [
                                    'id' => $lp->modulo?->id ?? null,
                                    'nombre' => $lp->modulo?->nombre_modulo ?? $lp->modulo?->nombre ?? 'Módulo',
                                    'numero_orden' => $lp->modulo?->numero_orden ?? $lp->orden,
                                ],
                                'monto_original' => (float) $lp->monto_original,
                                'monto_ajustado' => (float) $lp->monto_ajustado,
                                'monto_abonado' => (float) $lp->monto_abonado,
                                'saldo_pendiente' => (float) $lp->saldo_pendiente,
                                'estado' => $lp->estado,
                                'orden' => $lp->orden,
                            ];
                        }),
                    ]);
                }

                $tieneCuentaCobrar = $solicitud->cuentasPorCobrar->isNotEmpty();
                $tieneCuentaMatricula = $matricula && $matricula->cuentaPorCobrar;

                if ($tieneCuentaMatricula) {
                    $cuenta = $matricula->cuentaPorCobrar;
                    $cuentas->push([
                        'id' => $cuenta->id,
                        'origen' => 'matricula',
                        'origen_id' => $matricula->id,
                        'concepto' => $concepto,
                        'monto_total' => (float) $cuenta->monto_total,
                        'monto_abonado' => (float) $cuenta->monto_abonado,
                        'saldo_pendiente' => (float) $cuenta->obtenerSaldoPendiente(),
                        'estado' => $cuenta->estado,
                        'fecha_creacion' => $cuenta->created_at?->format('Y-m-d'),
                        'transacciones' => $cuenta->transacciones->map(fn($t) => [
                            'id' => $t->id,
                            'monto' => (float) $t->monto,
                            'metodo_pago' => $t->metodo_pago,
                            'comprobante_url' => $t->comprobante_url,
                            'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                            'estado_verificacion' => $t->estado_verificacion,
                            'observaciones' => $t->observaciones,
                        ]),
                    ]);
                    $transacciones = $transacciones->concat($cuenta->transacciones->map(fn($t) => [
                        'id' => $t->id,
                        'cuenta_id' => $cuenta->id,
                        'concepto' => $concepto,
                        'monto' => (float) $t->monto,
                        'metodo_pago' => $t->metodo_pago,
                        'comprobante_url' => $t->comprobante_url,
                        'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                        'estado_verificacion' => $t->estado_verificacion,
                        'observaciones' => $t->observaciones,
                    ]));
                } elseif ($tieneCuentaCobrar) {
                    foreach ($solicitud->cuentasPorCobrar as $cuenta) {
                        $cuentas->push([
                            'id' => $cuenta->id,
                            'origen' => 'solicitud_inscripcion',
                            'origen_id' => $solicitud->id,
                            'concepto' => $concepto,
                            'monto_total' => (float) $cuenta->monto_total,
                            'monto_abonado' => (float) $cuenta->monto_abonado,
                            'saldo_pendiente' => (float) $cuenta->obtenerSaldoPendiente(),
                            'estado' => $cuenta->estado,
                            'fecha_creacion' => $cuenta->created_at?->format('Y-m-d'),
                            'transacciones' => $cuenta->transacciones->map(fn($t) => [
                                'id' => $t->id,
                                'monto' => (float) $t->monto,
                                'metodo_pago' => $t->metodo_pago,
                                'comprobante_url' => $t->comprobante_url,
                                'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                                'estado_verificacion' => $t->estado_verificacion,
                                'observaciones' => $t->observaciones,
                            ]),
                        ]);
                        $transacciones = $transacciones->concat($cuenta->transacciones->map(fn($t) => [
                            'id' => $t->id,
                            'cuenta_id' => $cuenta->id,
                            'concepto' => $concepto,
                            'monto' => (float) $t->monto,
                            'metodo_pago' => $t->metodo_pago,
                            'comprobante_url' => $t->comprobante_url,
                            'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                            'estado_verificacion' => $t->estado_verificacion,
                            'observaciones' => $t->observaciones,
                        ]));
                    }
                } elseif ($matricula && $matricula->lineasPago->isNotEmpty()) {
                    foreach ($matricula->lineasPago as $lp) {
                        $moduloNombre = $lp->modulo?->nombre_modulo ?? $lp->modulo?->nombre ?? 'Módulo';
                        $lpConcepto = $concepto . ' - ' . $moduloNombre;
                        $lpSaldo = (float) $lp->saldo_pendiente;
                        $lpAbonado = (float) $lp->monto_abonado;
                        $cuentas->push([
                            'id' => $lp->id,
                            'origen' => 'matricula',
                            'origen_id' => $matricula->id,
                            'concepto' => $lpConcepto,
                            'monto_total' => (float) $lp->monto_ajustado,
                            'monto_abonado' => $lpAbonado,
                            'saldo_pendiente' => max(0, $lpSaldo),
                            'estado' => $lp->estado,
                            'fecha_creacion' => $matricula->fecha_inscripcion
                                ? $matricula->fecha_inscripcion->format('Y-m-d')
                                : ($matricula->created_at?->format('Y-m-d') ?? null),
                            'transacciones' => $lp->transacciones->map(fn($t) => [
                                'id' => $t->id,
                                'monto' => (float) $t->monto,
                                'metodo_pago' => $t->metodo_pago,
                                'comprobante_url' => $t->comprobante_url,
                                'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                                'estado_verificacion' => $t->estado_verificacion,
                                'observaciones' => $t->observaciones,
                            ])->values(),
                        ]);
                        $transacciones = $transacciones->concat($lp->transacciones->map(fn($t) => [
                            'id' => $t->id,
                            'cuenta_id' => $lp->id,
                            'concepto' => $lpConcepto,
                            'monto' => (float) $t->monto,
                            'metodo_pago' => $t->metodo_pago,
                            'comprobante_url' => $t->comprobante_url,
                            'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                            'estado_verificacion' => $t->estado_verificacion,
                            'observaciones' => $t->observaciones,
                        ]));
                    }
                }
            }

            // Cuentas por cobrar de servicios para cliente externo
            $cuentasServiciosCliente = \App\Models\CuentaPorCobrar::query()
                ->with(['transacciones'])
                ->where(function ($q) use ($cliente) {
                    $q->whereHas('reservaPodcast', fn($sq) => $sq->where('cliente_externo_id', $cliente->id));
                    $q->orWhereHas('reservaAula', fn($sq) => $sq->where('cliente_externo_id', $cliente->id));
                    $q->orWhereHas('alquilerEquipo', fn($sq) => $sq->where('cliente_externo_id', $cliente->id));
                    $q->orWhereHas('reservaRadio', fn($sq) => $sq->where('cliente_externo_id', $cliente->id));
                })
                ->get();

            foreach ($cuentasServiciosCliente as $cs) {
                $tipoServicio = '';
                if ($cs->reserva_podcast_id) $tipoServicio = 'Podcast';
                elseif ($cs->reserva_aula_id) $tipoServicio = 'Aula';
                elseif ($cs->alquiler_equipo_id) $tipoServicio = 'Equipo';
                elseif ($cs->reserva_radio_id) $tipoServicio = 'Radio';
                elseif ($cs->edicion_video_id) $tipoServicio = 'Edición de Video';

                $cuentas->push([
                    'id' => $cs->id,
                    'origen' => 'servicio',
                    'origen_id' => $cs->id,
                    'concepto' => $tipoServicio ?: 'Servicio',
                    'monto_total' => (float) $cs->monto_total,
                    'monto_abonado' => (float) $cs->monto_abonado,
                    'saldo_pendiente' => (float) $cs->obtenerSaldoPendiente(),
                    'estado' => $cs->estado,
                    'fecha_creacion' => $cs->created_at?->format('Y-m-d'),
                    'transacciones' => $cs->transacciones->map(fn($t) => [
                        'id' => $t->id,
                        'monto' => (float) $t->monto,
                        'metodo_pago' => $t->metodo_pago,
                        'comprobante_url' => $t->comprobante_url,
                        'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                        'estado_verificacion' => $t->estado_verificacion,
                        'observaciones' => $t->observaciones,
                    ])->values(),
                ]);
                $transacciones = $transacciones->concat($cs->transacciones->map(fn($t) => [
                    'id' => $t->id,
                    'cuenta_id' => $cs->id,
                    'concepto' => $tipoServicio ?: 'Servicio',
                    'monto' => (float) $t->monto,
                    'metodo_pago' => $t->metodo_pago,
                    'comprobante_url' => $t->comprobante_url,
                    'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                    'estado_verificacion' => $t->estado_verificacion,
                    'observaciones' => $t->observaciones,
                ]));
            }
        }

        $totalAdeudado = $cuentas->sum('saldo_pendiente');
        $totalPagado = $cuentas->sum('monto_abonado');
        $totalGeneral = $cuentas->sum('monto_total');

        return response()->json([
            'datos' => [
                'estudiante' => $datosEstudiante,
                'matriculas' => $matriculasConLineas->values(),
                'cuentas' => $cuentas->values(),
                'transacciones' => $transacciones->sortByDesc('fecha_pago')->values(),
                'resumen' => [
                    'total_adeudado' => $totalAdeudado,
                    'total_pagado' => $totalPagado,
                    'total_general' => $totalGeneral,
                    'porcentaje_pagado' => $totalGeneral > 0 ? round(($totalPagado / $totalGeneral) * 100, 2) : 0,
                    'cuentas_pendientes' => $cuentas->where('estado', 'pendiente')->count(),
                    'cuentas_abonadas' => $cuentas->where('estado', 'abonado')->count(),
                    'cuentas_pagadas' => $cuentas->where('estado', 'pagado')->count(),
                ],
            ],
        ]);
    }

    /**
     * Estadisticas generales de estudiantes
     */
    public function stats(Request $request): JsonResponse
    {
        $internosCount = Persona::query()
            ->estudiantes()
            ->whereNull('deleted_at')
            ->count();

        $externosCount = ClienteExterno::query()
            ->whereHas('solicitudesInscripcion', fn($q) => $q->whereIn('estado', ['aprobado', 'matricula_creada']))
            ->count();

        $porCiudad = Persona::query()
            ->estudiantes()
            ->whereNull('deleted_at')
            ->whereNotNull('ciudad_id')
            ->selectRaw('ciudad_id, count(*) as total')
            ->groupBy('ciudad_id')
            ->with('ciudad')
            ->get()
            ->map(fn($p) => [
                'ciudad' => is_string($p->ciudad) ? $p->ciudad : ($p->ciudad->nombre ?? 'Sin ciudad'),
                'total' => $p->total,
            ]);

        $matriculasStats = Matricula::query()
            ->selectRaw("estado, count(*) as total")
            ->groupBy('estado')
            ->get()
            ->pluck('total', 'estado');

        $promedioGeneral = Matricula::query()
            ->whereNotNull('estudiante_id')
            ->get()
            ->avg(fn($m) => $m->calcularPromedio()) ?? 0;

        $tasaAprobacion = Matricula::query()
            ->whereNotNull('estudiante_id')
            ->count();

        $completadas = $matriculasStats->get('completado', 0);
        $tasaCompletacion = $tasaAprobacion > 0 ? round(($completadas / $tasaAprobacion) * 100, 2) : 0;

        return response()->json([
        'datos' => [
            'total_estudiantes' => $internosCount + $externosCount,
            'por_ciudad' => $porCiudad,
                'matriculas_por_estado' => $matriculasStats,
                'promedio_general' => round((float) $promedioGeneral, 2),
                'tasa_completacion' => $tasaCompletacion,
            ],
        ]);
    }

    /**
     * Gestion de segmentos
     */
    public function segments(Request $request): JsonResponse
    {
        $segmentos = EstudianteSegmento::query()
            ->orderBy('nombre')
            ->get()
            ->map(function ($seg) {
                return [
                    'id' => $seg->id,
                    'nombre' => $seg->nombre,
                    'descripcion' => $seg->descripcion,
                    'criterios' => $seg->criterios,
                    'created_at' => $seg->created_at?->format('Y-m-d H:i'),
                ];
            });

        return response()->json(['datos' => $segmentos]);
    }

    public function storeSegment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:1000',
            'criterios' => 'required|array',
        ]);

        $segmento = EstudianteSegmento::create($validated);

        return response()->json([
            'mensaje' => 'Segmento creado exitosamente.',
            'datos' => [
                'id' => $segmento->id,
                'nombre' => $segmento->nombre,
                'descripcion' => $segmento->descripcion,
                'criterios' => $segmento->criterios,
                'created_at' => $segmento->created_at?->format('Y-m-d H:i'),
            ],
        ], 201);
    }

    public function destroySegment(string $id): JsonResponse
    {
        $segmento = EstudianteSegmento::findOrFail($id);
        $segmento->delete();

        return response()->json(['mensaje' => 'Segmento eliminado exitosamente.']);
    }

    /**
     * Obtener estudiantes que pertenecen a un segmento aplicando sus criterios
     */
    public function segmentStudents(Request $request, string $id): JsonResponse
    {
        $segmento = EstudianteSegmento::findOrFail($id);
        $criterios = $segmento->criterios;

        $internos = Persona::query()
            ->estudiantes()
            ->with(['ciudad', 'perfilEstudiante', 'matriculas.cuentaPorCobrar'])
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($p) use ($criterios) {
                $totalMatriculas = $p->matriculas->count();
                $cuentas = $p->matriculas->map(fn($m) => $m->cuentaPorCobrar)->filter();
                $estados = $cuentas->pluck('estado')->unique();

                $estadoPago = 'ninguno';
                if ($totalMatriculas > 0) {
                    if ($cuentas->count() < $totalMatriculas || $estados->contains('pendiente')) {
                        $estadoPago = 'deudor';
                    } elseif ($estados->contains('abonado')) {
                        $estadoPago = 'abonado';
                    } elseif ($estados->count() > 0 && $estados->every(fn($e) => $e === 'pagado')) {
                        $estadoPago = 'al_dia';
                    } else {
                        $estadoPago = 'deudor';
                    }
                }

                $promedio = $p->matriculas->avg(fn($m) => $m->calcularPromedio()) ?? 0;

                return [
                    'id' => $p->id,
                    'nombres' => $p->nombres,
                    'apellidos' => $p->apellidos,
                    'cedula' => $p->cedula,
                    'correo' => $p->correo,
                    'total_cursos' => $totalMatriculas,
                    'estado_pago' => $estadoPago,
                    'saldo_pendiente' => $p->matriculas->sum(function($m) {
                        if ($m->cuentaPorCobrar) {
                            return $m->cuentaPorCobrar->monto_total - $m->cuentaPorCobrar->monto_abonado;
                        }
                        return $m->cursoAbierto->precio_base ?? 0;
                    }),
                    'promedio' => round((float) $promedio, 2),
                ];
            });

        $asistenciaStats = [];
        if (isset($criterios['ausencias_min']) || isset($criterios['tardanzas_min'])) {
            $estIds = $internos->pluck('id')->filter();
            $matriculas = Matricula::whereIn('estudiante_id', $estIds)
                ->get(['id', 'estudiante_id']);
            $matIdToEstId = $matriculas->pluck('estudiante_id', 'id');

            $rows = DB::table('academic.asistencias')
                ->whereIn('matricula_id', $matIdToEstId->keys())
                ->whereIn('estado', ['ausente', 'tardanza'])
                ->select('matricula_id', 'estado')
                ->get();

            foreach ($rows as $row) {
                $estId = $matIdToEstId[$row->matricula_id] ?? null;
                if (!$estId) continue;
                if (!isset($asistenciaStats[$estId])) {
                    $asistenciaStats[$estId] = ['ausencias' => 0, 'tardanzas' => 0];
                }
                if ($row->estado === 'ausente') $asistenciaStats[$estId]['ausencias']++;
                if ($row->estado === 'tardanza') $asistenciaStats[$estId]['tardanzas']++;
            }
        }

        $resultado = $internos->filter(function ($e) use ($criterios, $asistenciaStats) {
            if (isset($criterios['estado_pago']) && $criterios['estado_pago'] !== 'todos') {
                if ($e['estado_pago'] !== $criterios['estado_pago']) return false;
            }
            if (isset($criterios['cursos_min']) && $e['total_cursos'] < $criterios['cursos_min']) return false;
            if (isset($criterios['cursos_max']) && $e['total_cursos'] > $criterios['cursos_max']) return false;
            if (isset($criterios['promedio_min']) && $e['promedio'] < $criterios['promedio_min']) return false;
            if (isset($criterios['ausencias_min']) || isset($criterios['tardanzas_min'])) {
                $stats = $asistenciaStats[$e['id']] ?? ['ausencias' => 0, 'tardanzas' => 0];
                if (isset($criterios['ausencias_min']) && $stats['ausencias'] < $criterios['ausencias_min']) return false;
                if (isset($criterios['tardanzas_min']) && $stats['tardanzas'] < $criterios['tardanzas_min']) return false;
            }
            return true;
        })->values();

        return response()->json([
            'datos' => [
                'segmento' => [
                    'id' => $segmento->id,
                    'nombre' => $segmento->nombre,
                    'criterios' => $criterios,
                ],
                'estudiantes' => $resultado,
                'total' => $resultado->count(),
            ],
        ]);
    }

    /**
     * Importacion masiva de estudiantes desde CSV o Excel
     */
    public function importStudents(Request $request): JsonResponse
    {
        $request->validate([
            'archivo' => 'required|file|mimes:csv,xlsx,xls|max:10240',
        ]);

        $file = $request->file('archivo');
        $extension = $file->getClientOriginalExtension();
        $rows = [];

        try {
            if ($extension === 'csv') {
                $csv = Reader::createFromPath($file->getRealPath(), 'r');
                $csv->setHeaderOffset(0);
                $rows = iterator_to_array($csv->getRecords());
            } elseif (in_array($extension, ['xlsx', 'xls'])) {
                $spreadsheet = IOFactory::load($file->getRealPath());
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
                $headers = array_shift($rows);
                $rows = array_map(fn($row) => array_combine($headers, $row), $rows);
            }
        } catch (\Exception $e) {
            return response()->json([
                'mensaje' => 'Error al procesar el archivo: ' . $e->getMessage(),
            ], 422);
        }

        $errores = [];
        $creados = 0;

        DB::beginTransaction();
        try {
            foreach ($rows as $index => $row) {
                $linea = $index + 2;
                $nombres = $this->sanitizeCsvValue($row['nombres'] ?? $row['Nombres'] ?? $row['NOMBRES'] ?? '');
                $apellidos = $this->sanitizeCsvValue($row['apellidos'] ?? $row['Apellidos'] ?? $row['APELLIDOS'] ?? '');

                if (empty($nombres) || empty($apellidos)) {
                    $errores[] = "Linea {$linea}: nombres y apellidos son obligatorios";
                    continue;
                }

                Persona::create([
                    'tipo' => 'estudiante',
                    'cedula' => $this->sanitizeCsvValue(trim($row['cedula'] ?? $row['Cedula'] ?? $row['CEDULA'] ?? '')) ?: null,
                    'nombres' => $nombres,
                    'apellidos' => $apellidos,
                    'correo' => $this->sanitizeCsvValue(trim($row['correo'] ?? $row['Correo'] ?? $row['CORREO'] ?? '')) ?: null,
                    'celular' => $this->sanitizeCsvValue(trim($row['celular'] ?? $row['Celular'] ?? $row['CELULAR'] ?? '')) ?: null,
                ]);

                $creados++;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'mensaje' => 'Error en la importacion: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'mensaje' => "Importacion completada: {$creados} estudiantes creados.",
            'datos' => [
                'creados' => $creados,
                'errores' => $errores,
                'total_procesados' => count($rows),
            ],
        ]);
    }

    /**
     * Validar archivo de importacion sin procesar (vista previa)
     */
    public function validateImport(Request $request): JsonResponse
    {
        $request->validate([
            'archivo' => 'required|file|mimes:csv,xlsx,xls|max:10240',
        ]);

        $file = $request->file('archivo');
        $extension = $file->getClientOriginalExtension();
        $rows = [];

        try {
            if ($extension === 'csv') {
                $csv = Reader::createFromPath($file->getRealPath(), 'r');
                $csv->setHeaderOffset(0);
                $rows = iterator_to_array($csv->getRecords());
            } elseif (in_array($extension, ['xlsx', 'xls'])) {
                $spreadsheet = IOFactory::load($file->getRealPath());
                $worksheet = $spreadsheet->getActiveSheet();
                $rows = $worksheet->toArray();
                $headers = array_shift($rows);
                $rows = array_map(fn($row) => array_combine($headers, $row), $rows);
            }
        } catch (\Exception $e) {
            return response()->json([
                'mensaje' => 'Error al leer el archivo: ' . $e->getMessage(),
            ], 422);
        }

        $vistaPrevia = [];
        $errores = [];

        foreach ($rows as $index => $row) {
            $linea = $index + 2;
            $nombres = trim($row['nombres'] ?? $row['Nombres'] ?? $row['NOMBRES'] ?? '');
            $apellidos = trim($row['apellidos'] ?? $row['Apellidos'] ?? $row['APELLIDOS'] ?? '');

            $estado = 'valido';
            $errorMsg = [];

            if (empty($nombres)) $errorMsg[] = 'Falta nombres';
            if (empty($apellidos)) $errorMsg[] = 'Falta apellidos';

            if (!empty($errorMsg)) {
                $estado = 'error';
                $errores[] = "Linea {$linea}: " . implode(', ', $errorMsg);
            }

            if (count($vistaPrevia) < 20) {
                $vistaPrevia[] = [
                    'linea' => $linea,
                    'nombres' => $nombres,
                    'apellidos' => $apellidos,
                    'cedula' => trim($row['cedula'] ?? $row['Cedula'] ?? $row['CEDULA'] ?? ''),
                    'correo' => trim($row['correo'] ?? $row['Correo'] ?? $row['CORREO'] ?? ''),
                    'celular' => trim($row['celular'] ?? $row['Celular'] ?? $row['CELULAR'] ?? ''),
                    'estado_validacion' => $estado,
                ];
            }
        }

        return response()->json([
            'datos' => [
                'total_registros' => count($rows),
                'registros_validos' => count($rows) - count($errores),
                'registros_con_error' => count($errores),
                'errores' => $errores,
                'vista_previa' => $vistaPrevia,
            ],
        ]);
    }

    /**
     * Exportar estudiantes con formato y campos personalizables
     */
    public function exportStudents(Request $request): \Illuminate\Http\Response|\Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $request->validate([
            'formato' => 'required|string|in:csv,pdf,excel',
            'campos' => 'nullable|array',
            'ids' => 'nullable|array',
            'buscar' => 'nullable|string',
            'estado_pago' => 'nullable|string',
        ]);

        $formato = $request->input('formato', 'csv');
        $camposSeleccionados = $request->input('campos', [
            'nombres', 'apellidos', 'cedula', 'correo', 'celular', 'edad', 'direccion', 'ocupacion', 'estado_civil', 'total_cursos', 'estado_pago', 'saldo_pendiente'
        ]);
        $ids = $request->input('ids');

        $internos = Persona::query()
            ->estudiantes()
            ->with(['ciudad', 'perfilEstudiante', 'matriculas.cuentaPorCobrar'])
            ->whereNull('deleted_at')
            ->orderBy('nombres');

        if ($ids && count($ids) > 0) {
            $internos->whereIn('id', $ids);
        }

        if ($request->filled('buscar')) {
            $buscar = mb_strtolower($request->buscar);
            $internos->where(function ($q) use ($buscar) {
                $q->whereRaw('LOWER(nombres) LIKE ?', ["%{$buscar}%"])
                  ->orWhereRaw('LOWER(apellidos) LIKE ?', ["%{$buscar}%"])
                  ->orWhere('cedula', 'like', "%{$buscar}%")
                  ->orWhere('correo', 'like', "%{$buscar}%");
            });
        }

        $estudiantes = $internos->get()->map(function ($p) {
            $totalMatriculas = $p->matriculas->count();
            $cuentas = $p->matriculas->map(fn($m) => $m->cuentaPorCobrar)->filter();
            $estados = $cuentas->pluck('estado')->unique();

            $estadoPago = 'ninguno';
            if ($totalMatriculas > 0) {
                if ($cuentas->count() < $totalMatriculas || $estados->contains('pendiente')) {
                    $estadoPago = 'deudor';
                } elseif ($estados->contains('abonado')) {
                    $estadoPago = 'abonado';
                } elseif ($estados->count() > 0 && $estados->every(fn($e) => $e === 'pagado')) {
                    $estadoPago = 'al_dia';
                } else {
                    $estadoPago = 'deudor';
                }
            }

            return [
                'id' => $p->id,
                'nombres' => $p->nombres,
                'apellidos' => $p->apellidos,
                'cedula' => $p->cedula,
                'correo' => $p->correo,
                'celular' => $p->celular,
                'total_cursos' => $totalMatriculas,
                'estado_pago' => $estadoPago === 'ninguno' ? 'Sin cursos' : $estadoPago,
                    'saldo_pendiente' => $p->matriculas->sum(function($m) {
                        if ($m->cuentaPorCobrar) {
                            return $m->cuentaPorCobrar->monto_total - $m->cuentaPorCobrar->monto_abonado;
                        }
                        $lineasSum = $m->lineasPago->sum('saldo_pendiente');
                        if ($lineasSum > 0) return $lineasSum;
                        return $m->cursoAbierto->precio_base ?? 0;
                    }),
            ];
        });

        $clientesQuery = ClienteExterno::query()
            ->whereHas('solicitudesInscripcion', fn ($q) =>
                $q->whereIn('estado', ['aprobado', 'matricula_creada'])
            )
            ->with([
                'solicitudesInscripcion' => fn ($q) =>
                    $q->whereIn('estado', ['aprobado', 'matricula_creada'])
                      ->with(['cursoAbierto.catalogo', 'cuentasPorCobrar']),
                'ciudad',
            ])
            ->orderBy('nombres');

        if ($ids && count($ids) > 0) {
            $clientesQuery->whereIn('id', $ids);
        }

        if ($request->filled('buscar')) {
            $buscar = mb_strtolower($request->buscar);
            $clientesQuery->where(function ($q) use ($buscar) {
                $q->whereRaw('LOWER(nombres) LIKE ?', ["%{$buscar}%"])
                  ->orWhere('cedula', 'like', "%{$buscar}%")
                  ->orWhere('correo', 'like', "%{$buscar}%");
            });
        }

        $clientesData = $clientesQuery->get()->map(function ($c) {
            $solicitudes = $c->solicitudesInscripcion;
            $totalSolicitudes = $solicitudes->count();
            $cuentas = $solicitudes->flatMap(fn($s) => $s->cuentasPorCobrar)->filter();
            $estados = $cuentas->pluck('estado')->unique();

            $estadoPago = 'ninguno';
            if ($totalSolicitudes > 0) {
                if ($cuentas->count() < $totalSolicitudes || $estados->contains('pendiente')) {
                    $estadoPago = 'deudor';
                } elseif ($estados->contains('abonado')) {
                    $estadoPago = 'abonado';
                } elseif ($estados->count() > 0 && $estados->every(fn($e) => $e === 'pagado')) {
                    $estadoPago = 'al_dia';
                } else {
                    $estadoPago = 'deudor';
                }
            }

            return [
                'id' => $c->id,
                'nombres' => $c->nombres,
                'apellidos' => $c->apellidos ?? '',
                'cedula' => $c->cedula,
                'correo' => $c->correo,
                'celular' => $c->celular,
                'total_cursos' => $totalSolicitudes,
                'estado_pago' => $estadoPago === 'ninguno' ? 'Sin cursos' : $estadoPago,
                'saldo_pendiente' => $solicitudes->sum(function($s) {
                    $cuenta = $s->cuentasPorCobrar->first();
                    if ($cuenta) {
                        return $cuenta->monto_total - $cuenta->monto_abonado;
                    }
                    return ($s->cursoAbierto->precio_base ?? 0) - ($s->monto_solicitado ?? 0);
                }),
            ];
        });

        $estudiantes = $estudiantes->concat($clientesData);

        // Agregar estudiantes de talleres al export
        $tallerExp = \App\Models\InscripcionTaller::query()
            ->whereNotNull('nombres')
            ->whereIn('estado', ['activo', 'completado'])
            ->with('taller')
            ->get()
            ->groupBy(function ($ins) {
                if (!empty($ins->cedula)) return 'cedula:' . $ins->cedula;
                return 'nombre:' . mb_strtolower(trim($ins->nombres . ' ' . $ins->apellidos));
            })
            ->map(function ($grupo) {
                $p = $grupo->first();
                $totalPagado = $grupo->sum(fn($ins) => $ins->monto_pagado ?? 0);
                $totalPrecio = $grupo->sum(fn($ins) => $ins->taller->precio ?? 0);
                $saldo = max(0, $totalPrecio - $totalPagado);
                $todosVer = $grupo->every(fn($ins) => $ins->pago_verificado);
                $estadoPago = 'ninguno';
                if ($totalPrecio > 0) {
                    if ($saldo <= 0 && $todosVer) $estadoPago = 'al_dia';
                    elseif ($totalPagado > 0) $estadoPago = 'abonado';
                    else $estadoPago = 'deudor';
                }
                return [
                    'id' => $p->id,
                    'nombres' => $p->nombres,
                    'apellidos' => $p->apellidos ?? '',
                    'cedula' => $p->cedula,
                    'correo' => $p->correo,
                    'celular' => $p->telefono,
                    'total_cursos' => $grupo->count(),
                    'estado_pago' => $estadoPago,
                    'saldo_pendiente' => $saldo,
                    'edad' => $p->edad,
                    'direccion' => $p->direccion,
                    'ocupacion' => $p->ocupacion,
                    'estado_civil' => $p->estado_civil,
                ];
            });

        $cedulasExp = $estudiantes->pluck('cedula')->filter()->toArray();
        $tallerNuevos = $tallerExp->reject(function ($te) use ($cedulasExp) {
            return !empty($te['cedula']) && in_array($te['cedula'], $cedulasExp);
        });
        $estudiantes = $estudiantes->concat($tallerNuevos->values());

        if ($request->filled('estado_pago') && $request->estado_pago !== 'todos') {
            $estado = $request->estado_pago;
            $estudiantes = $estudiantes->filter(fn($e) => $e['estado_pago'] === $estado);
        }

        $estudiantes = $estudiantes->sortBy('nombres', SORT_NATURAL | SORT_FLAG_CASE)->values();

        $rows = $estudiantes->map(function ($e) use ($camposSeleccionados) {
            $fila = [];
            foreach ($camposSeleccionados as $campo) {
                $fila[$campo] = $e[$campo] ?? '';
            }
            return $fila;
        });

        try {
            if ($formato === 'csv' || $formato === 'pdf') {
                if ($formato === 'csv') {
                    $writer = Writer::createFromString();
                    $headers = array_map(fn($c) => ucfirst(str_replace('_', ' ', $c)), $camposSeleccionados);
                    $writer->insertOne($headers);
                    $writer->insertAll($rows->map(fn($r) => array_values($r))->toArray());
                    return response($writer->toString(), 200, [
                        'Content-Type' => 'text/csv',
                        'Content-Disposition' => 'attachment; filename="estudiantes.csv"',
                    ]);
                }

                $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.estudiantes', [
                    'estudiantes' => $rows,
                    'campos' => $camposSeleccionados,
                    'fecha' => now()->format('d/m/Y'),
                ]);
                return $pdf->download('estudiantes.pdf');
            }

            if ($formato === 'excel') {
                $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();

                $col = 'A';
                foreach ($camposSeleccionados as $campo) {
                    $sheet->setCellValue($col . '1', ucfirst(str_replace('_', ' ', $campo)));
                    $col++;
                }

                $fila = 2;
                foreach ($rows as $row) {
                    $col = 'A';
                    foreach ($camposSeleccionados as $campo) {
                        $sheet->setCellValue($col . $fila, $row[$campo] ?? '');
                        $col++;
                    }
                    $fila++;
                }

                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
                $tempFile = tempnam(sys_get_temp_dir(), 'export_') . '.xlsx';
                $writer->save($tempFile);

                return response()->download($tempFile, 'estudiantes.xlsx', [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])->deleteFileAfterSend(true);
            }
        } catch (\Exception $e) {
            return response()->json([
                'mensaje' => 'Error al generar exportacion: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json(['mensaje' => 'Formato no soportado'], 400);
    }

    private function sanitizeCsvValue(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return $value;
        }
        $first = $value[0];
        if (in_array($first, ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Resolver el nombre de ciudad de un ClienteExterno.
     * Maneja el conflicto de naming entre la columna 'ciudad' (string)
     * y la relacion 'ciudad()' (BelongsTo).
     */
    private function resolverCiudad(ClienteExterno $c): ?array
    {
        if ($c->ciudad_id) {
            $ciudadModel = Ciudad::find($c->ciudad_id);
            if ($ciudadModel) {
                return ['nombre' => $ciudadModel->nombre];
            }
        }
        $ciudadStr = $c->getAttribute('ciudad');
        if ($ciudadStr) {
            return ['nombre' => $ciudadStr];
        }
        return null;
    }

    /**
     * Resolver ciudad completa (id, nombre, pais) para ClienteExterno.
     */
    private function resolverCiudadCompleta(ClienteExterno $c): ?array
    {
        if ($c->ciudad_id) {
            $ciudadModel = Ciudad::find($c->ciudad_id);
            if ($ciudadModel) {
                return [
                    'id' => $ciudadModel->id,
                    'nombre' => $ciudadModel->nombre,
                    'pais' => $ciudadModel->pais ?? null,
                ];
            }
        }
        $ciudadStr = $c->getAttribute('ciudad');
        if ($ciudadStr) {
            return ['nombre' => $ciudadStr];
        }
        return null;
    }

    /**
     * Buscar estudiantes existentes por cédula, nombre o correo.
     * Usado para evitar duplicados al crear matrículas manuales.
     */
    public function buscar(Request $request): JsonResponse
    {
        $request->validate([
            'cedula' => 'nullable|string|max:20',
            'nombre' => 'nullable|string|max:255',
            'correo' => 'nullable|email|max:255',
        ]);

        $query = Persona::where('tipo', 'estudiante')->activos();

        if ($request->filled('cedula')) {
            $query->where('cedula', $request->cedula);
        }

        if ($request->filled('nombre')) {
            $search = '%' . $request->nombre . '%';
            $query->where(function ($q) use ($search) {
                $q->where('nombres', 'ilike', $search)
                  ->orWhere('apellidos', 'ilike', $search);
            });
        }

        if ($request->filled('correo')) {
            $query->where('correo', $request->correo);
        }

        $resultados = $query->with('perfilEstudiante', 'ciudad')
            ->limit(20)
            ->get();

        return response()->json([
            'datos' => $resultados->map(fn ($p) => [
                'id' => $p->id,
                'nombres' => $p->nombres,
                'apellidos' => $p->apellidos,
                'cedula' => $p->cedula,
                'correo' => $p->correo,
                'celular' => $p->celular,
                'ciudad' => $p->ciudad?->nombre,
            ]),
            'total' => $resultados->count(),
        ]);
    }
}
