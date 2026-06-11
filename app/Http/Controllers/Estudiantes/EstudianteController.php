<?php

namespace App\Http\Controllers\Estudiantes;

use App\Http\Controllers\Controller;
use App\Http\Requests\Estudiantes\EstudianteStoreRequest;
use App\Http\Requests\Estudiantes\EstudianteUpdateRequest;
use App\Http\Resources\Estudiantes\EstudianteResource;
use App\Models\Persona;
use App\Models\PerfilEstudiante;
use App\Models\ClienteExterno;
use App\Models\Matricula;
use App\Models\Clase;
use App\Models\Asistencia;
use App\Models\Nota;
use App\Models\CuentaPorCobrar;
use App\Models\TransaccionIngreso;
use App\Models\EstudianteSegmento;
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
            ->with(['ciudad', 'perfilEstudiante', 'matriculas.cuentaPorCobrar'])
            ->whereNull('deleted_at')
            ->orderBy('nombres')
            ->get()
            ->map(function ($p) {
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
                    'tipo' => 'estudiante',
                    'tipo_estudiante' => 'interno',
                    'nombres' => $p->nombres,
                    'apellidos' => $p->apellidos,
                    'cedula' => $p->cedula,
                    'correo' => $p->correo,
                    'celular' => $p->celular,
                    'ciudad' => $p->ciudad ? ['nombre' => $p->ciudad->nombre] : null,
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
                      ->with(['cursoAbierto.catalogo', 'cuentasPorCobrar']),
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
                    'tipo_estudiante' => $tieneRegular ? 'interno' : 'externo',
                    'nombres' => $c->nombres,
                    'apellidos' => $c->apellidos ?? '',
                    'cedula' => $c->cedula,
                    'correo' => $c->correo,
                    'celular' => $c->celular,
                    'ciudad' => $c->ciudad ? ['nombre' => $c->ciudad->nombre] : null,
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
                    'perfil_estudiante' => null,
                ];
            });

        $todos = $internos->concat($clientesData);

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

        if ($request->filled('tipo_estudiante')) {
            $tipo = $request->tipo_estudiante;
            $todos = $todos->filter(fn ($e) => $e['tipo_estudiante'] === $tipo);
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
            ->with(['ciudad', 'perfilEstudiante'])
            ->find($id);

        if ($estudiante) {
            return response()->json([
                'datos' => new EstudianteResource($estudiante),
            ]);
        }

        $cliente = ClienteExterno::query()
            ->with(['ciudad'])
            ->find($id);

        if ($cliente) {
            try {
                $totalCursos = $cliente->solicitudesInscripcion()
                    ->whereIn('estado', ['aprobado', 'matricula_creada'])
                    ->count();
            } catch (\Exception $e) {
                $totalCursos = 0;
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
                    'tipo_estudiante' => 'externo',
                    'total_cursos' => $totalCursos,
                    'estado_pago' => 'ninguno',
                    'saldo_pendiente' => 0,
                    'ciudad' => $cliente->ciudad ? [
                        'id' => $cliente->ciudad->id,
                        'nombre' => $cliente->ciudad->nombre,
                        'pais' => $cliente->ciudad->pais ?? null,
                    ] : null,
                    'perfil_estudiante' => null,
                    'creado_en' => null,
                    'actualizado_en' => null,
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

        return response()->json(['mensaje' => 'Estudiante no encontrado'], 404);
    }

    private function eliminarEstudianteInterno(Persona $estudiante): void
    {
        DB::transaction(function () use ($estudiante) {
            $matriculaIds = Matricula::where('estudiante_id', $estudiante->id)->pluck('id');

            if ($matriculaIds->isNotEmpty()) {
                TransaccionIngreso::whereIn('cuenta_cobrar_id', function ($q) use ($matriculaIds) {
                    $q->select('id')->from('finance.cuentas_por_cobrar')->whereIn('matricula_id', $matriculaIds);
                })->delete();

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
                'matriculas.cuentaPorCobrar.transacciones',
                'ciudad',
            ])
            ->find($id);

        $datosEstudiante = null;
        $cuentas = collect();
        $transacciones = collect();

        if ($estudiante) {
            $datosEstudiante = [
                'id' => $estudiante->id,
                'nombre_completo' => $estudiante->nombres . ' ' . $estudiante->apellidos,
                'cedula' => $estudiante->cedula,
                'correo' => $estudiante->correo,
                'celular' => $estudiante->celular,
                'tipo_estudiante' => 'interno',
            ];

            foreach ($estudiante->matriculas as $matricula) {
                $cuenta = $matricula->cuentaPorCobrar;
                if ($cuenta) {
                    $cuentas->push([
                        'id' => $cuenta->id,
                        'origen' => 'matricula',
                        'origen_id' => $matricula->id,
                        'concepto' => ($matricula->cursoAbierto->catalogo->nombre ?? 'Curso') . ' - ' . ($matricula->cursoAbierto->nombre_instancia ?? ''),
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
                        'concepto' => ($matricula->cursoAbierto->catalogo->nombre ?? 'Curso') . ' - ' . ($matricula->cursoAbierto->nombre_instancia ?? ''),
                        'monto' => (float) $t->monto,
                        'metodo_pago' => $t->metodo_pago,
                        'comprobante_url' => $t->comprobante_url,
                        'fecha_pago' => $t->fecha_pago?->format('Y-m-d'),
                        'estado_verificacion' => $t->estado_verificacion,
                        'observaciones' => $t->observaciones,
                    ]));
                }
            }
        } else {
            $cliente = ClienteExterno::query()
                ->with([
                    'solicitudesInscripcion' => fn($q) => $q->whereIn('estado', ['aprobado', 'matricula_creada']),
                    'solicitudesInscripcion.cursoAbierto.catalogo',
                    'solicitudesInscripcion.cuentasPorCobrar.transacciones',
                    'ciudad',
                ])
                ->find($id);

            if (!$cliente) {
                return response()->json(['mensaje' => 'Estudiante no encontrado'], 404);
            }

            $datosEstudiante = [
                'id' => $cliente->id,
                'nombre_completo' => $cliente->nombres . ' ' . ($cliente->apellidos ?? ''),
                'cedula' => $cliente->cedula,
                'correo' => $cliente->correo,
                'celular' => $cliente->celular,
                'tipo_estudiante' => 'externo',
            ];

            foreach ($cliente->solicitudesInscripcion as $solicitud) {
                foreach ($solicitud->cuentasPorCobrar as $cuenta) {
                    $cuentas->push([
                        'id' => $cuenta->id,
                        'origen' => 'solicitud_inscripcion',
                        'origen_id' => $solicitud->id,
                        'concepto' => ($solicitud->cursoAbierto->catalogo->nombre ?? 'Curso') . ' - ' . ($solicitud->cursoAbierto->nombre_instancia ?? ''),
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
                }
            }
        }

        $totalAdeudado = $cuentas->sum('saldo_pendiente');
        $totalPagado = $cuentas->sum('monto_abonado');
        $totalGeneral = $cuentas->sum('monto_total');

        return response()->json([
            'datos' => [
                'estudiante' => $datosEstudiante,
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
                'ciudad' => $p->ciudad->nombre ?? 'Sin ciudad',
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
                'internos' => $internosCount,
                'externos' => $externosCount,
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
                    'tipo_estudiante' => 'interno',
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

        $resultado = $internos->filter(function ($e) use ($criterios) {
            if (isset($criterios['estado_pago']) && $criterios['estado_pago'] !== 'todos') {
                if ($e['estado_pago'] !== $criterios['estado_pago']) return false;
            }
            if (isset($criterios['tipo_estudiante']) && $criterios['tipo_estudiante'] !== 'todos') {
                if ($e['tipo_estudiante'] !== $criterios['tipo_estudiante']) return false;
            }
            if (isset($criterios['cursos_min']) && $e['total_cursos'] < $criterios['cursos_min']) return false;
            if (isset($criterios['cursos_max']) && $e['total_cursos'] > $criterios['cursos_max']) return false;
            if (isset($criterios['promedio_min']) && $e['promedio'] < $criterios['promedio_min']) return false;
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
            'tipo_estudiante' => 'nullable|string',
        ]);

        $formato = $request->input('formato', 'csv');
        $camposSeleccionados = $request->input('campos', [
            'nombres', 'apellidos', 'cedula', 'correo', 'celular', 'total_cursos', 'estado_pago', 'saldo_pendiente'
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
                'tipo_estudiante' => 'interno',
                'total_cursos' => $totalMatriculas,
                'estado_pago' => $estadoPago === 'ninguno' ? 'Sin cursos' : $estadoPago,
                'saldo_pendiente' => $p->matriculas->sum(function($m) {
                    if ($m->cuentaPorCobrar) {
                        return $m->cuentaPorCobrar->monto_total - $m->cuentaPorCobrar->monto_abonado;
                    }
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
                'tipo_estudiante' => 'externo',
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
}
