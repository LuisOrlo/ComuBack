<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CambioHorario;
use App\Models\Certificado;
use App\Models\CursoAbierto;
use App\Models\Matricula;
use App\Models\SolicitudInscripcion;
use App\Models\TransaccionIngreso;
use App\Models\Services\AlquilerEquipo;
use App\Models\Services\ReservaAula;
use App\Models\Services\ReservaPodcast;
use App\Models\Services\ReservaRadio;
use App\Models\Services\TrabajoEdicion;
use App\Services\AgendaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class SecretariaDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $pagosPendientesHoy = TransaccionIngreso::whereDate('fecha_pago', now()->timezone('America/Guayaquil')->toDateString())
            ->where('estado_verificacion', 'pendiente')
            ->count();

        $matriculasRecientes = Matricula::with([
            'estudiante:id,nombres,apellidos,cedula',
            'cursoAbierto:id,nombre_instancia,catalogo_curso_id',
            'cursoAbierto.catalogo:id,nombre',
        ])
            ->where('fecha_inscripcion', '>=', now()->subDays(7))
            ->orderBy('fecha_inscripcion', 'desc')
            ->take(10)
            ->get()
            ->map(fn($m) => [
                'id' => $m->id,
                'estudiante_nombre' => $m->estudiante?->nombres . ' ' . $m->estudiante?->apellidos,
                'curso' => $m->cursoAbierto?->catalogo?->nombre ?? $m->cursoAbierto?->nombre,
                'fecha' => $m->fecha_inscripcion?->format('Y-m-d') ?? '',
                'estado' => $m->estado,
            ]);

        $cursosConCupo = CursoAbierto::with([
            'catalogo:id,nombre',
            'horario:id,dia_semana,hora_inicio,hora_fin',
        ])
            ->withCount('matriculas as inscritos')
            ->where('es_activo', true)
            ->whereRaw(
                '(SELECT COUNT(*) FROM academic.matriculas WHERE '
                . 'academic.matriculas.curso_abierto_id = academic.cursos_abiertos.id '
                . 'AND academic.matriculas.deleted_at IS NULL) < academic.cursos_abiertos.capacidad_maxima'
            )
            ->orderBy('fecha_inicio', 'asc')
            ->take(10)
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'nombre' => $c->catalogo?->nombre ?? $c->nombre_instancia,
                'inscritos' => (int) $c->inscritos,
                'capacidad' => (int) $c->capacidad_maxima,
                'disponibles' => $c->capacidad_maxima - (int) $c->inscritos,
                'fecha_inicio' => $c->fecha_inicio?->format('Y-m-d'),
            ]);

        $servicios = [
            'podcast_activas' => ReservaPodcast::whereIn('estado', ['reservado', 'confirmado'])->count(),
            'edicion_pendientes' => TrabajoEdicion::whereIn('estado', ['recibido', 'en_proceso'])->count(),
            'alquileres_activos' => AlquilerEquipo::whereNull('fecha_recepcion')->count(),
        ];

        return response()->json([
            'datos' => [
                'pagos_pendientes_hoy' => $pagosPendientesHoy,
                'matriculas_recientes' => $matriculasRecientes,
                'cursos_con_cupo' => $cursosConCupo,
                'estado_servicios' => $servicios,
            ],
        ]);
    }

    public function agendaHoy(): JsonResponse
    {
        $today = now()->timezone('America/Guayaquil')->toDateString();
        $events = app(AgendaService::class)->getEvents($today, $today);

        $grouped = $events->groupBy('tipo_evento')->map(fn($items, $tipo) => [
            'tipo' => $tipo,
            'tipo_label' => $items->first()['tipo_label'] ?? $tipo,
            'color' => $items->first()['color'] ?? '#6b7280',
            'total' => $items->count(),
            'eventos' => $items->values()->toArray(),
        ])->values();

        return response()->json(['datos' => $grouped]);
    }

    public function resumenEstudiantes(): JsonResponse
    {
        $totalActivos = Matricula::where('estado', 'activo')
            ->distinct('estudiante_id')
            ->count('estudiante_id');

        $asistenciaBaja = DB::connection('pgsql')
            ->table('academic.asistencias as a')
            ->join('academic.matriculas as m', 'a.matricula_id', '=', 'm.id')
            ->join('academic.clases as c', 'a.clase_id', '=', 'c.id')
            ->where('c.fecha_clase', '>=', now()->timezone('America/Guayaquil')->subDays(30)->toDateString())
            ->where('m.estado', 'activo')
            ->select('m.estudiante_id')
            ->selectRaw('COUNT(*) as total_clases')
            ->selectRaw('SUM(CASE WHEN a.asistio THEN 1 ELSE 0 END) as asistencias')
            ->groupBy('m.estudiante_id')
            ->havingRaw('COALESCE(SUM(CASE WHEN a.asistio THEN 1 ELSE 0 END)::float / NULLIF(COUNT(*)::float, 0), 1) < 0.7')
            ->count();

        $proximosCompletar = DB::connection('pgsql')
            ->table('academic.matriculas as m')
            ->join('academic.cursos_abiertos as ca', 'm.curso_abierto_id', '=', 'ca.id')
            ->join('academic.modulos as mod', 'mod.curso_abierto_id', '=', 'ca.id')
            ->leftJoin('academic.notas as n', function ($join) {
                $join->on('n.matricula_id', '=', 'm.id')
                    ->on('n.modulo_id', '=', 'mod.id');
            })
            ->where('m.estado', 'activo')
            ->select('m.id')
            ->selectRaw('COUNT(DISTINCT mod.id) as total_modulos')
            ->selectRaw('COUNT(DISTINCT CASE WHEN n.id IS NOT NULL THEN mod.id END) as modulos_completados')
            ->groupBy('m.id')
            ->havingRaw('COUNT(DISTINCT mod.id) > 0')
            ->get()
            ->filter(function ($row) {
                $ratio = $row->total_modulos > 0 ? $row->modulos_completados / $row->total_modulos : 0;
                return $ratio >= 0.7 && $ratio < 1.0;
            })
            ->count();

        return response()->json([
            'datos' => [
                'total_activos' => $totalActivos,
                'asistencia_baja' => $asistenciaBaja,
                'proximos_completar_modulo' => $proximosCompletar,
            ],
        ]);
    }

    public function reservasProximas(): JsonResponse
    {
        $hoy = now()->timezone('America/Guayaquil')->toDateString();
        $limite = now()->timezone('America/Guayaquil')->addDays(3)->toDateString();

        $aulas = ReservaAula::with(['aula:id,nombre', 'persona:id,nombres,apellidos', 'clienteExterno:id,nombres,apellidos'])
            ->whereBetween('fecha_reserva', [$hoy, $limite])
            ->whereIn('estado', ['reservado', 'confirmado'])
            ->orderBy('fecha_reserva')
            ->orderBy('hora_inicio')
            ->get()
            ->map(fn($r) => [
                'id' => $r->id,
                'tipo' => 'aula',
                'titulo' => $r->aula?->nombre ?? 'Aula',
                'fecha' => $r->fecha_reserva,
                'hora_inicio' => $r->hora_inicio,
                'hora_fin' => $r->hora_fin,
                'cliente_nombre' => $r->persona
                    ? trim("{$r->persona->nombres} {$r->persona->apellidos}")
                    : ($r->clienteExterno
                        ? trim("{$r->clienteExterno->nombres} {$r->clienteExterno->apellidos}")
                        : 'Sin cliente'),
                'estado' => $r->estado,
            ]);

        $podcast = ReservaPodcast::with(['paquete:id,nombre', 'persona:id,nombres,apellidos', 'clienteExterno:id,nombres,apellidos'])
            ->whereBetween('fecha_reserva', [$hoy, $limite])
            ->whereIn('estado', ['reservado', 'confirmado'])
            ->orderBy('fecha_reserva')
            ->orderBy('hora_inicio')
            ->get()
            ->map(fn($r) => [
                'id' => $r->id,
                'tipo' => 'podcast',
                'titulo' => $r->paquete?->nombre ?? 'Podcast',
                'fecha' => $r->fecha_reserva,
                'hora_inicio' => $r->hora_inicio,
                'hora_fin' => $r->hora_fin,
                'cliente_nombre' => $r->persona
                    ? trim("{$r->persona->nombres} {$r->persona->apellidos}")
                    : ($r->clienteExterno
                        ? trim("{$r->clienteExterno->nombres} {$r->clienteExterno->apellidos}")
                        : 'Sin cliente'),
                'estado' => $r->estado,
            ]);

        $radio = ReservaRadio::with(['tarifa:id,nombre', 'persona:id,nombres,apellidos', 'clienteExterno:id,nombres,apellidos'])
            ->whereBetween('fecha_reserva', [$hoy, $limite])
            ->whereIn('estado', ['reservado', 'confirmado'])
            ->orderBy('fecha_reserva')
            ->orderBy('hora_inicio')
            ->get()
            ->map(fn($r) => [
                'id' => $r->id,
                'tipo' => 'radio',
                'titulo' => 'Radio Studio',
                'fecha' => $r->fecha_reserva,
                'hora_inicio' => $r->hora_inicio,
                'hora_fin' => $r->hora_fin,
                'cliente_nombre' => $r->persona
                    ? trim("{$r->persona->nombres} {$r->persona->apellidos}")
                    : ($r->clienteExterno
                        ? trim("{$r->clienteExterno->nombres} {$r->clienteExterno->apellidos}")
                        : 'Sin cliente'),
                'estado' => $r->estado,
            ]);

        return response()->json([
            'datos' => [
                'aulas' => $aulas,
                'podcast' => $podcast,
                'radio' => $radio,
                'total' => $aulas->count() + $podcast->count() + $radio->count(),
            ],
        ]);
    }

    public function tareasPendientes(): JsonResponse
    {
        $hoy = now();
        $limite48h = $hoy->copy()->subHours(48);

        // Certificados por generar: matrículas completadas sin certificado asociado
        $pendientesCertificado = DB::connection('pgsql')
            ->table('academic.matriculas as m')
            ->join('people.personas as p', 'm.estudiante_id', '=', 'p.id')
            ->join('academic.cursos_abiertos as ca', 'm.curso_abierto_id', '=', 'ca.id')
            ->leftJoin('academic.certificados as cert', function ($join) {
                $join->on('cert.estudiante_id', '=', 'm.estudiante_id')
                    ->on('cert.curso_abierto_id', '=', 'm.curso_abierto_id');
            })
            ->where('m.estado', 'completado')
            ->whereNull('cert.id')
            ->select(
                'm.id',
                'm.estudiante_id',
                'm.curso_abierto_id',
                'p.nombres',
                'p.apellidos',
                'ca.fecha_fin'
            )
            ->take(20)
            ->get()
            ->map(function ($row) use ($hoy) {
                $fin = $row->fecha_fin ? now()->parse($row->fecha_fin) : null;
                $dias = $fin ? $fin->diffInDays($hoy) : 0;
                $urgencia = $dias > 30 ? 'critica' : ($dias > 7 ? 'alta' : 'normal');
                return [
                    'id' => 'cert-' . $row->id,
                    'tipo' => 'certificado',
                    'descripcion' => "{$row->nombres} {$row->apellidos}",
                    'fecha' => $row->fecha_fin,
                    'urgencia' => $urgencia,
                ];
            });

        // Solicitudes >48h sin respuesta
        $solicitudesVencidas = SolicitudInscripcion::where('estado', 'pendiente_validacion')
            ->where('created_at', '<', $limite48h)
            ->take(10)
            ->get()
            ->map(function ($s) {
                $horas = now()->diffInHours($s->created_at);
                $urgencia = $horas > 72 ? 'critica' : ($horas > 48 ? 'alta' : 'normal');
                return [
                    'id' => 'sol-' . $s->id,
                    'tipo' => 'solicitud_vencida',
                    'descripcion' => 'Solicitud de inscripción sin respuesta',
                    'fecha' => $s->created_at->toDateString(),
                    'urgencia' => $urgencia,
                ];
            });

        // Cambios de horario pendientes
        $cambiosPendientes = CambioHorario::with(['matriculaOrigen:id,estudiante_id', 'matriculaOrigen.estudiante:id,nombres,apellidos'])
            ->orderBy('fecha_cambio', 'desc')
            ->take(10)
            ->get()
            ->map(function ($c) {
                return [
                    'id' => 'cam-' . $c->id,
                    'tipo' => 'cambio_horario',
                    'descripcion' => 'Cambio de horario: ' . ($c->matriculaOrigen?->estudiante?->nombres ?? '') . ' ' . ($c->matriculaOrigen?->estudiante?->apellidos ?? ''),
                    'fecha' => $c->fecha_cambio ? (new \DateTime($c->fecha_cambio))->format('Y-m-d') : '',
                    'urgencia' => 'alta',
                ];
            });

        $tareas = collect()
            ->concat($pendientesCertificado)
            ->concat($solicitudesVencidas)
            ->concat($cambiosPendientes)
            ->sortByDesc('urgencia')
            ->values();

        return response()->json(['datos' => $tareas]);
    }

    public function solicitudesPendientes(): JsonResponse
    {
        $total = SolicitudInscripcion::where('estado', 'pendiente_validacion')->count();

        $items = SolicitudInscripcion::with([
            'estudiante:id,nombres,apellidos,cedula',
            'participanteExterno:id,nombres,apellidos',
            'cursoAbierto:id,nombre_instancia,catalogo_curso_id',
            'cursoAbierto.catalogo:id,nombre',
        ])
            ->where('estado', 'pendiente_validacion')
            ->orderBy('created_at', 'asc')
            ->take(10)
            ->get()
            ->map(fn($s) => [
                'id' => $s->id,
                'solicitante' => $s->estudiante
                    ? trim("{$s->estudiante->nombres} {$s->estudiante->apellidos}")
                    : ($s->participanteExterno
                        ? trim("{$s->participanteExterno->nombres} {$s->participanteExterno->apellidos}")
                        : 'Desconocido'),
                'curso' => $s->cursoAbierto?->catalogo?->nombre ?? $s->cursoAbierto?->nombre_instancia ?? 'Sin curso',
                'cedula' => $s->estudiante?->cedula ?? '',
                'es_externo' => (bool) $s->es_participante_externo,
                'fecha' => $s->created_at->toDateString(),
                'tiene_comprobante' => !empty($s->archivo_comprobante_url),
                'tiene_cedula' => !empty($s->archivo_cedula_url),
            ]);

        return response()->json([
            'datos' => [
                'total' => $total,
                'items' => $items,
            ],
        ]);
    }

    public function alertas(): JsonResponse
    {
        $alertas = collect();

        // Pagos pendientes de verificación >3 días
        $pagosVencidos = TransaccionIngreso::where('estado_verificacion', 'pendiente')
            ->where('fecha_pago', '<', now()->subDays(3))
            ->count();
        if ($pagosVencidos > 0) {
            $alertas->push([
                'id' => 'pagos-vencidos',
                'tipo' => 'danger',
                'mensaje' => "{$pagosVencidos} pago(s) pendiente(s) de verificación por más de 3 días",
                'accion' => ['label' => 'Revisar pagos', 'path' => '/secretaria/pagos'],
            ]);
        }

        // Trabajos de edición muy atrasados (>7 días sin entrega)
        $edicionAtrasada = TrabajoEdicion::whereIn('estado', ['recibido', 'en_proceso'])
            ->where('created_at', '<', now()->subDays(7))
            ->count();
        if ($edicionAtrasada > 0) {
            $alertas->push([
                'id' => 'edicion-atrasada',
                'tipo' => 'warning',
                'mensaje' => "{$edicionAtrasada} trabajo(s) de edición con más de 7 días sin entregar",
                'accion' => ['label' => 'Ver ediciones', 'path' => '/secretaria/edicion-video'],
            ]);
        }

        // Alquileres sin devolución (más de 7 días en préstamo)
        $alquileresVencidos = AlquilerEquipo::whereNull('fecha_recepcion')
            ->where('fecha_entrega', '<', now()->subDays(7))
            ->count();
        if ($alquileresVencidos > 0) {
            $alertas->push([
                'id' => 'alquileres-vencidos',
                'tipo' => 'warning',
                'mensaje' => "{$alquileresVencidos} equipo(s) en préstamo sin devolver por más de 7 días",
                'accion' => ['label' => 'Ver alquileres', 'path' => '/secretaria/alquileres'],
            ]);
        }

        // Matrículas completadas sin certificado
        $sinCertificado = DB::connection('pgsql')
            ->table('academic.matriculas as m')
            ->leftJoin('academic.certificados as cert', function ($join) {
                $join->on('cert.estudiante_id', '=', 'm.estudiante_id')
                    ->on('cert.curso_abierto_id', '=', 'm.curso_abierto_id');
            })
            ->where('m.estado', 'completado')
            ->whereNull('cert.id')
            ->count();
        if ($sinCertificado > 0) {
            $alertas->push([
                'id' => 'sin-certificado',
                'tipo' => 'info',
                'mensaje' => "{$sinCertificado} estudiante(s) completaron cursos y aún no tienen certificado generado",
                'accion' => ['label' => 'Generar certificados', 'path' => '/secretaria/certificados'],
            ]);
        }

        return response()->json(['datos' => $alertas]);
    }
}
