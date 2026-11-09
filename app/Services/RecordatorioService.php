<?php

namespace App\Services;

use App\Models\Certificado;
use App\Models\CursoAbierto;
use App\Models\Matricula;
use App\Models\Modulo;
use App\Models\Taller;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RecordatorioService
{
    /**
     * Obtiene la lista de recordatorios y conteos para el usuario actual.
     */
    public function getRecordatorios($user): array
    {
        if (!$user) {
            return [
                'urgentes_count' => 0,
                'proximos_count' => 0,
                'total_count' => 0,
                'items' => [],
            ];
        }

        $now = Carbon::now('America/Guayaquil')->startOfDay();
        $isDocenteOnly = $user->hasRole('Docente') && !$user->hasAnyRole(['Administrador', 'Super Admin', 'Secretaria', 'Coordinador']);
        $isStaff = $user->hasAnyRole(['Administrador', 'Super Admin', 'Secretaria', 'Coordinador']);

        $items = collect();

        // 1. Cursos (Inicio, Fin, Módulos)
        $cursosQuery = CursoAbierto::where('es_activo', true)
            ->with(['catalogo:id,nombre,color', 'modulos' => function ($q) {
                $q->orderBy('numero_orden', 'asc');
            }]);

        if ($isDocenteOnly) {
            $cursosQuery->where('docente_id', $user->persona_id);
        }

        $cursos = $cursosQuery->get();

        foreach ($cursos as $curso) {
            $config = $curso->recordatorios_config ?? [];
            $cursoNombre = $curso->nombre_instancia ?: ($curso->catalogo?->nombre ?: 'Curso');
            if ($curso->catalogo && $curso->nombre_instancia && $curso->catalogo->nombre !== $curso->nombre_instancia) {
                $cursoNombre = "{$curso->catalogo->nombre} · {$curso->nombre_instancia}";
            }
            $cursoUrl = $isDocenteOnly ? "/instructor/cursos/{$curso->id}" : "/cursos/{$curso->id}";

            // A) Curso por Iniciar
            if ($curso->fecha_inicio) {
                $fechaInicio = Carbon::parse($curso->fecha_inicio)->startOfDay();
                // Expiración natural: Solo aplica si fecha_inicio es hoy o posterior
                if ($fechaInicio->gte($now)) {
                    $configInicio = $config['inicio'] ?? ['activo' => true, 'modo' => 'dias_antes', 'dias_antes' => 3];
                    $activo = $configInicio['activo'] ?? true;
                    if ($activo) {
                        $activarAlerta = false;
                        $modo = $configInicio['modo'] ?? 'dias_antes';
                        if ($modo === 'fecha_fija' && !empty($configInicio['fecha'])) {
                            $fechaAlerta = Carbon::parse($configInicio['fecha'])->startOfDay();
                            $activarAlerta = $now->gte($fechaAlerta) && $now->lte($fechaInicio);
                        } else {
                            $diasAntes = (int) ($configInicio['dias_antes'] ?? 3);
                            $fechaAlerta = (clone $fechaInicio)->subDays($diasAntes);
                            $activarAlerta = $now->gte($fechaAlerta) && $now->lte($fechaInicio);
                        }

                        if ($activarAlerta) {
                            $diasRestantes = (int) round($now->diffInDays($fechaInicio, false));
                            $esUrgente = $diasRestantes <= 1;
                            $items->push([
                                'id' => "curso-inicio-{$curso->id}",
                                'entidad_tipo' => 'curso',
                                'entidad_id' => $curso->id,
                                'tipo_evento' => 'inicio',
                                'titulo' => 'Curso por iniciar',
                                'subtitulo' => $cursoNombre,
                                'descripcion' => $diasRestantes <= 0
                                    ? 'Inicia hoy'
                                    : ($diasRestantes === 1 ? 'Inicia mañana' : "Inicia en {$diasRestantes} días ({$fechaInicio->format('d/m/Y')})"),
                                'fecha_evento' => $fechaInicio->toDateString(),
                                'dias_restantes' => $diasRestantes,
                                'urgencia' => $esUrgente ? 'urgente' : 'proximo',
                                'accion_label' => 'Ver Curso',
                                'accion_url' => $cursoUrl,
                            ]);
                        }
                    }
                }
            }

            // B) Próximo Módulo del Curso
            $configModulos = $config['proximo_modulo'] ?? ['activo' => true, 'dias_antes' => 2];
            if (($configModulos['activo'] ?? true) && $curso->modulos && $curso->modulos->count() > 0) {
                $diasAntesMod = (int) ($configModulos['dias_antes'] ?? 2);
                foreach ($curso->modulos as $modulo) {
                    if ($modulo->fecha_inicio) {
                        $fechaInicioMod = Carbon::parse($modulo->fecha_inicio)->startOfDay();
                        // Solo módulos futuros o de hoy
                        if ($fechaInicioMod->gte($now)) {
                            $fechaAlertaMod = (clone $fechaInicioMod)->subDays($diasAntesMod);
                            if ($now->gte($fechaAlertaMod) && $now->lte($fechaInicioMod)) {
                                $diasRestantesMod = (int) round($now->diffInDays($fechaInicioMod, false));
                                $items->push([
                                    'id' => "modulo-inicio-{$modulo->id}",
                                    'entidad_tipo' => 'curso',
                                    'entidad_id' => $curso->id,
                                    'tipo_evento' => 'modulo',
                                    'titulo' => 'Próximo módulo por iniciar',
                                    'subtitulo' => "{$cursoNombre} · {$modulo->nombre_modulo}",
                                    'descripcion' => $diasRestantesMod <= 0
                                        ? 'Inicia hoy'
                                        : ($diasRestantesMod === 1 ? 'Inicia mañana' : "Inicia en {$diasRestantesMod} días ({$fechaInicioMod->format('d/m/Y')})"),
                                    'fecha_evento' => $fechaInicioMod->toDateString(),
                                    'dias_restantes' => $diasRestantesMod,
                                    'urgencia' => $diasRestantesMod <= 1 ? 'urgente' : 'proximo',
                                    'accion_label' => 'Ver Curso',
                                    'accion_url' => $cursoUrl,
                                ]);
                                break; // Solo alertar del módulo más próximo
                            }
                        }
                    }
                }
            }

            // C) Curso por Finalizar
            if ($curso->fecha_fin && $curso->fecha_inicio) {
                $fechaFin = Carbon::parse($curso->fecha_fin)->startOfDay();
                $fechaInicioCurso = Carbon::parse($curso->fecha_inicio)->startOfDay();
                // Solo si el curso ya empezó y aún no ha pasado la fecha fin
                if ($now->gte($fechaInicioCurso) && $fechaFin->gte($now)) {
                    $configFin = $config['fin'] ?? ['activo' => true, 'modo' => 'dias_antes', 'dias_antes' => 3];
                    if ($configFin['activo'] ?? true) {
                        $modoFin = $configFin['modo'] ?? 'dias_antes';
                        $activarFin = false;
                        if ($modoFin === 'fecha_fija' && !empty($configFin['fecha'])) {
                            $fechaAlertaFin = Carbon::parse($configFin['fecha'])->startOfDay();
                            $activarFin = $now->gte($fechaAlertaFin) && $now->lte($fechaFin);
                        } else {
                            $diasAntesFin = (int) ($configFin['dias_antes'] ?? 3);
                            $fechaAlertaFin = (clone $fechaFin)->subDays($diasAntesFin);
                            $activarFin = $now->gte($fechaAlertaFin) && $now->lte($fechaFin);
                        }

                        if ($activarFin) {
                            $diasRestantesFin = (int) round($now->diffInDays($fechaFin, false));
                            $items->push([
                                'id' => "curso-fin-{$curso->id}",
                                'entidad_tipo' => 'curso',
                                'entidad_id' => $curso->id,
                                'tipo_evento' => 'fin',
                                'titulo' => 'Curso por finalizar',
                                'subtitulo' => $cursoNombre,
                                'descripcion' => $diasRestantesFin <= 0
                                    ? 'Finaliza hoy'
                                    : ($diasRestantesFin === 1 ? 'Finaliza mañana' : "Finaliza en {$diasRestantesFin} días ({$fechaFin->format('d/m/Y')})"),
                                'fecha_evento' => $fechaFin->toDateString(),
                                'dias_restantes' => $diasRestantesFin,
                                'urgencia' => $diasRestantesFin <= 1 ? 'urgente' : 'proximo',
                                'accion_label' => 'Ver Curso',
                                'accion_url' => $cursoUrl,
                            ]);
                        }
                    }
                }
            }
        }

        // 2. Talleres por Iniciar
        $talleresQuery = Taller::whereNotIn('estado', ['cancelado', 'completado']);
        if ($isDocenteOnly) {
            $talleresQuery->where('instructor_id', $user->persona_id);
        }

        $talleres = $talleresQuery->get();
        foreach ($talleres as $taller) {
            if ($taller->fecha) {
                $fechaTaller = Carbon::parse($taller->fecha)->startOfDay();
                if ($fechaTaller->gte($now)) {
                    $configTaller = $taller->recordatorios_config ?? ['inicio' => ['activo' => true, 'modo' => 'dias_antes', 'dias_antes' => 3]];
                    $configInicioTaller = $configTaller['inicio'] ?? ['activo' => true, 'modo' => 'dias_antes', 'dias_antes' => 3];
                    if ($configInicioTaller['activo'] ?? true) {
                        $activarTaller = false;
                        $modoTaller = $configInicioTaller['modo'] ?? 'dias_antes';
                        if ($modoTaller === 'fecha_fija' && !empty($configInicioTaller['fecha'])) {
                            $fechaAlertaTaller = Carbon::parse($configInicioTaller['fecha'])->startOfDay();
                            $activarTaller = $now->gte($fechaAlertaTaller) && $now->lte($fechaTaller);
                        } else {
                            $diasAntesTaller = (int) ($configInicioTaller['dias_antes'] ?? 3);
                            $fechaAlertaTaller = (clone $fechaTaller)->subDays($diasAntesTaller);
                            $activarTaller = $now->gte($fechaAlertaTaller) && $now->lte($fechaTaller);
                        }

                        if ($activarTaller) {
                            $diasRestantesTaller = (int) round($now->diffInDays($fechaTaller, false));
                            $items->push([
                                'id' => "taller-inicio-{$taller->id}",
                                'entidad_tipo' => 'taller',
                                'entidad_id' => $taller->id,
                                'tipo_evento' => 'inicio',
                                'titulo' => 'Taller por iniciar',
                                'subtitulo' => $taller->nombre,
                                'descripcion' => $diasRestantesTaller <= 0
                                    ? 'Inicia hoy'
                                    : ($diasRestantesTaller === 1 ? 'Inicia mañana' : "Inicia en {$diasRestantesTaller} días ({$fechaTaller->format('d/m/Y')})"),
                                'fecha_evento' => $fechaTaller->toDateString(),
                                'dias_restantes' => $diasRestantesTaller,
                                'urgencia' => $diasRestantesTaller <= 1 ? 'urgente' : 'proximo',
                                'accion_label' => 'Ver Taller',
                                'accion_url' => $isDocenteOnly ? "/instructor/talleres/{$taller->id}" : "/admin/talleres/{$taller->id}",
                            ]);
                        }
                    }
                }
            }
        }

        // 3. Emisión de Certificados Pendientes (Solo Staff / Admin / Secretaría)
        if ($isStaff) {
            // Considerar cursos que tengan fecha fin definida en los últimos 90 días o próximos a finalizar
            $hace90Dias = (clone $now)->subDays(90);
            $cursosCertificados = CursoAbierto::whereNotNull('fecha_fin')
                ->where('fecha_fin', '>=', $hace90Dias->toDateString())
                ->with(['catalogo:id,nombre'])
                ->get();

            foreach ($cursosCertificados as $cursoFin) {
                $config = $cursoFin->recordatorios_config ?? [];
                $configCert = $config['certificados'] ?? ['activo' => true, 'modo' => 'dias_despues', 'dias' => 0];
                $activo = $configCert['activo'] ?? true;
                if (!$activo) {
                    continue;
                }

                $fechaFin = Carbon::parse($cursoFin->fecha_fin)->startOfDay();
                $modo = $configCert['modo'] ?? 'dias_despues';
                $activarAlerta = false;

                if ($modo === 'fecha_fija' && !empty($configCert['fecha'])) {
                    $fechaAlerta = Carbon::parse($configCert['fecha'])->startOfDay();
                    $activarAlerta = $now->gte($fechaAlerta);
                } elseif ($modo === 'dias_antes') {
                    $dias = (int) ($configCert['dias'] ?? 3);
                    $fechaAlerta = (clone $fechaFin)->subDays($dias);
                    $activarAlerta = $now->gte($fechaAlerta);
                } else {
                    // modo 'dias_despues' (por defecto)
                    $dias = (int) ($configCert['dias'] ?? 0);
                    $fechaAlerta = (clone $fechaFin)->addDays($dias);
                    $activarAlerta = $now->gte($fechaAlerta);
                }

                if ($activarAlerta) {
                    // Cantidad de matrículas completadas sin certificado
                    $sinCertificadoCount = DB::connection('pgsql')
                        ->table('academic.matriculas as m')
                        ->leftJoin('academic.certificados as cert', function ($join) {
                            $join->on('cert.estudiante_id', '=', 'm.estudiante_id')
                                ->on('cert.curso_abierto_id', '=', 'm.curso_abierto_id');
                        })
                        ->where('m.curso_abierto_id', $cursoFin->id)
                        ->where('m.estado', 'completado')
                        ->whereNull('cert.id')
                        ->count();

                    if ($sinCertificadoCount > 0) {
                        $cursoNombre = $cursoFin->catalogo?->nombre ?: ($cursoFin->nombre_instancia ?: 'Curso');
                        $items->push([
                            'id' => "certificados-curso-{$cursoFin->id}",
                            'entidad_tipo' => 'certificado',
                            'entidad_id' => $cursoFin->id,
                            'tipo_evento' => 'certificado',
                            'titulo' => 'Emitir certificados pendientes',
                            'subtitulo' => $cursoNombre,
                            'descripcion' => "{$sinCertificadoCount} estudiante(s) completaron el curso y aún no tienen certificado emitido.",
                            'fecha_evento' => $fechaFin->toDateString(),
                            'dias_restantes' => 0,
                            'urgencia' => 'urgente',
                            'accion_label' => 'Generar Certificados',
                            'accion_url' => '/secretaria/certificados',
                        ]);
                    }
                }
            }
        }

        // Deduplicar por id de alerta y ordenar
        $sorted = $items->unique('id')->sortBy([
            ['urgencia', 'asc'],
            ['dias_restantes', 'asc'],
        ])->sort(function ($a, $b) {
            if ($a['urgencia'] === $b['urgencia']) {
                return $a['dias_restantes'] <=> $b['dias_restantes'];
            }
            return $a['urgencia'] === 'urgente' ? -1 : 1;
        })->values();

        $urgentesCount = $sorted->where('urgencia', 'urgente')->count();
        $proximosCount = $sorted->where('urgencia', 'proximo')->count();

        return [
            'urgentes_count' => $urgentesCount,
            'proximos_count' => $proximosCount,
            'total_count' => $sorted->count(),
            'items' => $sorted->all(),
        ];
    }
}
