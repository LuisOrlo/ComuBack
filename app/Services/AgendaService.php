<?php

namespace App\Services;

use App\Models\Clase;
use App\Models\CursoAbierto;
use App\Models\Taller;
use App\Models\Services\ReservaAula;
use App\Models\Services\ReservaPodcast;
use App\Models\Services\ReservaRadio;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AgendaService
{
    private const EVENT_TYPES = [
        'CLASE_CURSO'   => ['color' => '#6366f1', 'label' => 'Curso'],
        'TALLER'        => ['color' => '#f59e0b', 'label' => 'Taller'],
        'ALQUILER_AULA' => ['color' => '#10b981', 'label' => 'Aula'],
        'PODCAST'       => ['color' => '#ec4899', 'label' => 'Podcast'],
        'STREAMING'     => ['color' => '#06b6d4', 'label' => 'Streaming'],
        'ASESORIA'      => ['color' => '#8b5cf6', 'label' => 'Asesoría'],
        'ASESORIA'      => ['color' => '#8b5cf6', 'label' => 'Asesoría'],
    ];

    public function getEvents(?string $fechaInicio = null, ?string $fechaFin = null, ?array $tipos = null): Collection
    {
        $fechaInicio = $fechaInicio ?? Carbon::now()->startOfMonth()->toDateString();
        $fechaFin = $fechaFin ?? Carbon::now()->endOfMonth()->toDateString();

        $allEvents = collect();

        if (!$tipos || in_array('CLASE_CURSO', $tipos)) {
            $allEvents = $allEvents->concat($this->getClases($fechaInicio, $fechaFin));
        }

        if (!$tipos || in_array('TALLER', $tipos)) {
            $allEvents = $allEvents->concat($this->getTalleres($fechaInicio, $fechaFin));
        }

        if (!$tipos || in_array('ALQUILER_AULA', $tipos)) {
            $allEvents = $allEvents->concat($this->getReservasAulas($fechaInicio, $fechaFin));
        }

        if (!$tipos || in_array('PODCAST', $tipos)) {
            $allEvents = $allEvents->concat($this->getReservasPodcast($fechaInicio, $fechaFin));
        }

        if (!$tipos || in_array('STREAMING', $tipos)) {
            $allEvents = $allEvents->concat($this->getStreaming($fechaInicio, $fechaFin));
        }

        if (!$tipos || in_array('ASESORIA', $tipos)) {
            $allEvents = $allEvents->concat($this->getAsesorias($fechaInicio, $fechaFin));
        }

        return $allEvents->sortBy([
            ['fecha', 'asc'],
            ['hora_inicio', 'asc'],
        ])->values();
    }

    public function getEventDetail(string $tipoEvento, string $referenciaId): ?array
    {
        $event = null;

        switch ($tipoEvento) {
            case 'CLASE_CURSO':
                $event = $this->getClaseDetail($referenciaId);
                break;
            case 'TALLER':
                $event = $this->getTallerDetail($referenciaId);
                break;
            case 'ALQUILER_AULA':
                $event = $this->getReservaAulaDetail($referenciaId);
                break;
            case 'PODCAST':
                $event = $this->getReservaPodcastDetail($referenciaId);
                break;
            case 'STREAMING':
                $event = $this->getStreamingDetail($referenciaId);
                break;
            case 'ASESORIA':
                $event = $this->getAsesoriaDetail($referenciaId);
                break;
        }

        return $event;
    }

    // ========================================================================
    // CLASES DE CURSO
    // ========================================================================

    private function getClases(string $fechaInicio, string $fechaFin): Collection
    {
        return DB::connection('pgsql')
            ->table('academic.clases as c')
            ->join('academic.modulos as m', 'c.modulo_id', '=', 'm.id')
            ->join('academic.cursos_abiertos as ca', 'm.curso_abierto_id', '=', 'ca.id')
            ->join('academic.catalogo_cursos as cc', 'ca.catalogo_curso_id', '=', 'cc.id')
            ->leftJoin('people.personas as p', 'c.instructor_id', '=', 'p.id')
            ->leftJoin('people.personas as doc', 'ca.docente_id', '=', 'doc.id')
            ->leftJoin('core.ciudades as ciu', 'ca.ciudad_id', '=', 'ciu.id')
            ->whereNull('ca.deleted_at')
            ->whereBetween('c.fecha_clase', [$fechaInicio, $fechaFin])
            ->select(
                'c.id as referencia_id',
                DB::raw("'CLASE_CURSO' as tipo_evento"),
                DB::raw("('Clase: ' || cc.nombre) as titulo"),
                'c.fecha_clase as fecha',
                'c.hora_inicio',
                'c.hora_fin',
                'c.instructor_id',
                DB::raw("COALESCE(p.nombres || ' ' || p.apellidos, doc.nombres || ' ' || doc.apellidos) as instructor_nombre"),
                'cc.nombre as catalogo_nombre',
                'ca.nombre_instancia',
                'ca.estado',
                'ca.modalidad',
                'ciu.nombre as ciudad_nombre',
                DB::raw("'#6366f1' as color"),
                'm.nombre_modulo as modulo_nombre'
            )
            ->get()
            ->map(function ($row) {
                return $this->normalizeEvent((array) $row, 'CLASE_CURSO');
            });
    }

    private function getClaseDetail(string $id): ?array
    {
        $clase = DB::connection('pgsql')
            ->table('academic.clases as c')
            ->join('academic.modulos as m', 'c.modulo_id', '=', 'm.id')
            ->join('academic.cursos_abiertos as ca', 'm.curso_abierto_id', '=', 'ca.id')
            ->join('academic.catalogo_cursos as cc', 'ca.catalogo_curso_id', '=', 'cc.id')
            ->leftJoin('people.personas as p', 'c.instructor_id', '=', 'p.id')
            ->leftJoin('people.personas as doc', 'ca.docente_id', '=', 'doc.id')
            ->leftJoin('core.ciudades as ciu', 'ca.ciudad_id', '=', 'ciu.id')
            ->whereNull('ca.deleted_at')
            ->where('c.id', $id)
            ->select(
                'c.id as referencia_id',
                DB::raw("'CLASE_CURSO' as tipo_evento"),
                DB::raw("('Clase: ' || cc.nombre) as titulo"),
                'c.fecha_clase as fecha',
                'c.hora_inicio',
                'c.hora_fin',
                'c.instructor_id',
                DB::raw("COALESCE(p.nombres || ' ' || p.apellidos, doc.nombres || ' ' || doc.apellidos) as instructor_nombre"),
                'cc.nombre as catalogo_nombre',
                'ca.nombre_instancia',
                'ca.estado',
                'ca.modalidad',
                'ciu.nombre as ciudad_nombre',
                'ca.id as curso_abierto_id',
                DB::raw("'#6366f1' as color"),
                'm.nombre_modulo as modulo_nombre',
                'c.observaciones'
            )
            ->first();

        if (!$clase) {
            return null;
        }

        $clase = (array) $clase;
        $event = $this->normalizeEvent($clase, 'CLASE_CURSO');
        $event['detalle'] = [
            'modulo' => $clase['modulo_nombre'] ?? null,
            'observaciones' => $clase['observaciones'] ?? null,
        ];

        // Contar matriculas
        $cursoAbiertoId = $clase['curso_abierto_id'] ?? null;
        if ($cursoAbiertoId) {
            $cursoAbierto = CursoAbierto::withCount(['matriculas as inscritos_count'])->find($cursoAbiertoId);
            $event['participantes_count'] = $cursoAbierto ? $cursoAbierto->inscritos_count : null;
        } else {
            $event['participantes_count'] = null;
        }

        return $event;
    }

    // ========================================================================
    // TALLERES
    // ========================================================================

    private function getTalleres(string $fechaInicio, string $fechaFin): Collection
    {
        return DB::connection('pgsql')
            ->table('academic.talleres as t')
            ->leftJoin('people.personas as p', 't.instructor_id', '=', 'p.id')
            ->leftJoin('core.ciudades as ciu', 't.ciudad_id', '=', 'ciu.id')
            ->whereBetween('t.fecha', [$fechaInicio, $fechaFin])
            ->select(
                't.id as referencia_id',
                DB::raw("'TALLER' as tipo_evento"),
                DB::raw("('Taller: ' || t.nombre) as titulo"),
                't.fecha',
                't.hora_inicio',
                't.hora_fin',
                't.instructor_id',
                DB::raw("p.nombres || ' ' || p.apellidos as instructor_nombre"),
                't.nombre as taller_nombre',
                't.estado',
                't.modalidad',
                't.capacidad_maxima',
                'ciu.nombre as ciudad_nombre',
                DB::raw("'#f59e0b' as color")
            )
            ->get()
            ->map(function ($row) {
                $row = (array) $row;
                $event = $this->normalizeEvent($row, 'TALLER');

                // Contar inscripciones
                $inscripciones = DB::connection('pgsql')
                    ->table('academic.inscripciones_taller')
                    ->where('taller_id', $row['referencia_id'])
                    ->count();

                $event['participantes_count'] = $inscripciones;
                $event['aula_nombre'] = $this->getTallerAula($row['referencia_id']);

                return $event;
            });
    }

    private function getTallerDetail(string $id): ?array
    {
        $taller = DB::connection('pgsql')
            ->table('academic.talleres as t')
            ->leftJoin('people.personas as p', 't.instructor_id', '=', 'p.id')
            ->leftJoin('core.ciudades as ciu', 't.ciudad_id', '=', 'ciu.id')
            ->where('t.id', $id)
            ->select(
                't.id as referencia_id',
                DB::raw("'TALLER' as tipo_evento"),
                DB::raw("('Taller: ' || t.nombre) as titulo"),
                't.fecha',
                't.hora_inicio',
                't.hora_fin',
                't.instructor_id',
                DB::raw("p.nombres || ' ' || p.apellidos as instructor_nombre"),
                't.nombre as taller_nombre',
                't.descripcion',
                't.estado',
                't.modalidad',
                't.capacidad_maxima',
                't.precio',
                't.abierto_externos',
                'ciu.nombre as ciudad_nombre',
                DB::raw("'#f59e0b' as color")
            )
            ->first();

        if (!$taller) {
            return null;
        }

        $taller = (array) $taller;
        $event = $this->normalizeEvent($taller, 'TALLER');

        $inscripciones = DB::connection('pgsql')
            ->table('academic.inscripciones_taller')
            ->where('taller_id', $id)
            ->count();

        $event['participantes_count'] = $inscripciones;
        $event['aula_nombre'] = $this->getTallerAula($id);
        $event['detalle'] = [
            'descripcion' => $taller['descripcion'] ?? null,
            'precio' => $taller['precio'] ?? null,
            'abierto_externos' => $taller['abierto_externos'] ?? null,
        ];

        return $event;
    }

    private function getTallerAula(string $tallerId): ?string
    {
        $exists = DB::connection('pgsql')
            ->select("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'academic' AND table_name = 'horarios_talleres') as exists");

        if (!$exists[0]->exists) {
            return null;
        }

        $horario = DB::connection('pgsql')
            ->table('academic.horarios_talleres')
            ->where('taller_id', $tallerId)
            ->whereNotNull('aula')
            ->select('aula')
            ->first();

        return $horario ? $horario->aula : null;
    }

    // ========================================================================
    // RESERVAS DE AULAS
    // ========================================================================

    private function getReservasAulas(string $fechaInicio, string $fechaFin): Collection
    {
        return DB::connection('pgsql')
            ->table('services.reservas_aulas as ra')
            ->join('services.aulas as a', 'ra.aula_id', '=', 'a.id')
            ->leftJoin('people.personas as pp', 'ra.persona_id', '=', 'pp.id')
            ->leftJoin('people.clientes_externos as ce', 'ra.cliente_externo_id', '=', 'ce.id')
            ->whereBetween('ra.fecha_reserva', [$fechaInicio, $fechaFin])
            ->select(
                'ra.id as referencia_id',
                DB::raw("'ALQUILER_AULA' as tipo_evento"),
                DB::raw("('Aula: ' || a.nombre) as titulo"),
                'ra.fecha_reserva as fecha',
                'ra.hora_inicio',
                'ra.hora_fin',
                DB::raw('NULL as instructor_id'),
                DB::raw("COALESCE(pp.nombres || ' ' || pp.apellidos, ce.nombres || ' ' || COALESCE(ce.apellidos, '')) as instructor_nombre"),
                'a.nombre as aula_nombre',
                'ra.estado',
                DB::raw("'#10b981' as color")
            )
            ->get()
            ->map(fn($row) => $this->normalizeEvent((array) $row, 'ALQUILER_AULA'));
    }

    private function getReservaAulaDetail(string $id): ?array
    {
        $reserva = DB::connection('pgsql')
            ->table('services.reservas_aulas as ra')
            ->join('services.aulas as a', 'ra.aula_id', '=', 'a.id')
            ->leftJoin('people.personas as pp', 'ra.persona_id', '=', 'pp.id')
            ->leftJoin('people.clientes_externos as ce', 'ra.cliente_externo_id', '=', 'ce.id')
            ->where('ra.id', $id)
            ->select(
                'ra.id as referencia_id',
                DB::raw("'ALQUILER_AULA' as tipo_evento"),
                DB::raw("('Aula: ' || a.nombre) as titulo"),
                'ra.fecha_reserva as fecha',
                'ra.hora_inicio',
                'ra.hora_fin',
                DB::raw('NULL as instructor_id'),
                DB::raw("COALESCE(pp.nombres || ' ' || pp.apellidos, ce.nombres || ' ' || COALESCE(ce.apellidos, '')) as instructor_nombre"),
                'a.nombre as aula_nombre',
                'a.capacidad as aula_capacidad',
                'a.caracteristicas as aula_caracteristicas',
                'ra.precio_total',
                'ra.estado',
                DB::raw("'#10b981' as color")
            )
            ->first();

        if (!$reserva) {
            return null;
        }

        $reserva = (array) $reserva;
        $event = $this->normalizeEvent($reserva, 'ALQUILER_AULA');
        $event['detalle'] = [
            'aula_capacidad' => $reserva['aula_capacidad'] ?? null,
            'aula_caracteristicas' => $reserva['aula_caracteristicas'] ?? null,
            'precio_total' => $reserva['precio_total'] ?? null,
        ];

        return $event;
    }

    // ========================================================================
    // RESERVAS DE PODCAST
    // ========================================================================

    private function getReservasPodcast(string $fechaInicio, string $fechaFin): Collection
    {
        return DB::connection('pgsql')
            ->table('services.reservas_podcast as rp')
            ->join('services.paquetes_podcast as ppq', 'rp.paquete_id', '=', 'ppq.id')
            ->leftJoin('people.personas as pp', 'rp.persona_id', '=', 'pp.id')
            ->leftJoin('people.clientes_externos as ce', 'rp.cliente_externo_id', '=', 'ce.id')
            ->whereBetween('rp.fecha_reserva', [$fechaInicio, $fechaFin])
            ->select(
                'rp.id as referencia_id',
                DB::raw("'PODCAST' as tipo_evento"),
                DB::raw("('Podcast: ' || ppq.nombre) as titulo"),
                'rp.fecha_reserva as fecha',
                'rp.hora_inicio',
                'rp.hora_fin',
                DB::raw('NULL as instructor_id'),
                DB::raw("COALESCE(pp.nombres || ' ' || pp.apellidos, ce.nombres || ' ' || COALESCE(ce.apellidos, '')) as instructor_nombre"),
                'ppq.nombre as podcast_nombre',
                'rp.estado',
                'rp.observaciones',
                DB::raw("'#ec4899' as color")
            )
            ->get()
            ->map(fn($row) => $this->normalizeEvent((array) $row, 'PODCAST'));
    }

    private function getReservaPodcastDetail(string $id): ?array
    {
        $reserva = DB::connection('pgsql')
            ->table('services.reservas_podcast as rp')
            ->join('services.paquetes_podcast as ppq', 'rp.paquete_id', '=', 'ppq.id')
            ->leftJoin('people.personas as pp', 'rp.persona_id', '=', 'pp.id')
            ->leftJoin('people.clientes_externos as ce', 'rp.cliente_externo_id', '=', 'ce.id')
            ->where('rp.id', $id)
            ->select(
                'rp.id as referencia_id',
                DB::raw("'PODCAST' as tipo_evento"),
                DB::raw("('Podcast: ' || ppq.nombre) as titulo"),
                'rp.fecha_reserva as fecha',
                'rp.hora_inicio',
                'rp.hora_fin',
                DB::raw('NULL as instructor_id'),
                DB::raw("COALESCE(pp.nombres || ' ' || pp.apellidos, ce.nombres || ' ' || COALESCE(ce.apellidos, '')) as instructor_nombre"),
                'ppq.nombre as podcast_nombre',
                'ppq.descripcion as podcast_descripcion',
                'rp.precio_total',
                'rp.observaciones',
                'rp.estado',
                DB::raw("'#ec4899' as color")
            )
            ->first();

        if (!$reserva) {
            return null;
        }

        $reserva = (array) $reserva;
        $event = $this->normalizeEvent($reserva, 'PODCAST');
        $event['detalle'] = [
            'paquete' => $reserva['podcast_nombre'] ?? null,
            'precio_total' => $reserva['precio_total'] ?? null,
            'observaciones' => $reserva['observaciones'] ?? null,
        ];

        return $event;
    }

    // ========================================================================
    // SERVICIOS STREAMING
    // ========================================================================

    private function getStreaming(string $fechaInicio, string $fechaFin): Collection
    {
        $exists = DB::connection('pgsql')
            ->select("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'services' AND table_name = 'servicios_streaming') as exists");

        if (!$exists[0]->exists) {
            return collect();
        }

        return DB::connection('pgsql')
            ->table('services.servicios_streaming as ss')
            ->leftJoin('people.personas as pp', 'ss.persona_id', '=', 'pp.id')
            ->leftJoin('people.clientes_externos as ce', 'ss.cliente_externo_id', '=', 'ce.id')
            ->whereBetween('ss.fecha_evento', [$fechaInicio, $fechaFin])
            ->select(
                'ss.id as referencia_id',
                DB::raw("'STREAMING' as tipo_evento"),
                DB::raw("COALESCE('Streaming: ' || ss.descripcion, 'Servicio de streaming') as titulo"),
                'ss.fecha_evento as fecha',
                'ss.hora_inicio',
                'ss.hora_fin',
                DB::raw('NULL as instructor_id'),
                DB::raw("COALESCE(pp.nombres || ' ' || pp.apellidos, ce.nombres || ' ' || COALESCE(ce.apellidos, '')) as instructor_nombre"),
                'ss.lugar as aula_nombre',
                'ss.estado',
                'ss.descripcion',
                DB::raw("'#06b6d4' as color")
            )
            ->get()
            ->map(fn($row) => $this->normalizeEvent((array) $row, 'STREAMING'));
    }

    private function getStreamingDetail(string $id): ?array
    {
        $exists = DB::connection('pgsql')
            ->select("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'services' AND table_name = 'servicios_streaming') as exists");

        if (!$exists[0]->exists) {
            return null;
        }

        $servicio = DB::connection('pgsql')
            ->table('services.servicios_streaming as ss')
            ->leftJoin('people.personas as pp', 'ss.persona_id', '=', 'pp.id')
            ->leftJoin('people.clientes_externos as ce', 'ss.cliente_externo_id', '=', 'ce.id')
            ->where('ss.id', $id)
            ->select(
                'ss.id as referencia_id',
                DB::raw("'STREAMING' as tipo_evento"),
                DB::raw("COALESCE('Streaming: ' || ss.descripcion, 'Servicio de streaming') as titulo"),
                'ss.fecha_evento as fecha',
                'ss.hora_inicio',
                'ss.hora_fin',
                DB::raw('NULL as instructor_id'),
                DB::raw("COALESCE(pp.nombres || ' ' || pp.apellidos, ce.nombres || ' ' || COALESCE(ce.apellidos, '')) as instructor_nombre"),
                'ss.lugar as aula_nombre',
                'ss.precio_total',
                'ss.descripcion',
                'ss.estado',
                DB::raw("'#06b6d4' as color")
            )
            ->first();

        if (!$servicio) {
            return null;
        }

        $servicio = (array) $servicio;
        $event = $this->normalizeEvent($servicio, 'STREAMING');
        $event['detalle'] = [
            'descripcion' => $servicio['descripcion'] ?? null,
            'precio_total' => $servicio['precio_total'] ?? null,
        ];

        return $event;
    }

    // ========================================================================
    // ASESORIAS
    // ========================================================================

    private function getAsesorias(string $fechaInicio, string $fechaFin): Collection
    {
        $exists = DB::connection('pgsql')
            ->select("SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'academic' AND table_name = 'asesorias') as exists");

        if (!$exists[0]->exists) {
            return collect();
        }

        return DB::connection('pgsql')
            ->table('academic.asesorias as a')
            ->join('people.personas as pi', 'a.instructor_id', '=', 'pi.id')
            ->whereBetween('a.fecha', [$fechaInicio, $fechaFin])
            ->select(
                'a.id as referencia_id',
                DB::raw("'ASESORIA' as tipo_evento"),
                DB::raw("('Asesoría: ' || a.titulo) as titulo"),
                'a.fecha',
                'a.hora_inicio',
                'a.hora_fin',
                'a.instructor_id',
                DB::raw("pi.nombres || ' ' || pi.apellidos as instructor_nombre"),
                'a.titulo as asesoria_titulo',
                'a.modalidad',
                'a.estado',
                DB::raw("'#8b5cf6' as color")
            )
            ->get()
            ->map(fn($row) => $this->normalizeEvent((array) $row, 'ASESORIA'));
    }

    private function getAsesoriaDetail(string $id): ?array
    {
        $asesoria = DB::connection('pgsql')
            ->table('academic.asesorias as a')
            ->join('people.personas as pi', 'a.instructor_id', '=', 'pi.id')
            ->leftJoin('people.personas as pp', 'a.persona_id', '=', 'pp.id')
            ->leftJoin('people.clientes_externos as ce', 'a.cliente_externo_id', '=', 'ce.id')
            ->where('a.id', $id)
            ->select(
                'a.id as referencia_id',
                DB::raw("'ASESORIA' as tipo_evento"),
                DB::raw("('Asesoría: ' || a.titulo) as titulo"),
                'a.fecha',
                'a.hora_inicio',
                'a.hora_fin',
                'a.instructor_id',
                DB::raw("pi.nombres || ' ' || pi.apellidos as instructor_nombre"),
                'a.titulo as asesoria_titulo',
                'a.descripcion',
                'a.modalidad',
                'a.estado',
                'a.notas_sesion',
                'a.precio',
                'pp.nombres as cliente_nombres',
                'pp.apellidos as cliente_apellidos',
                DB::raw("'#8b5cf6' as color")
            )
            ->first();

        if (!$asesoria) {
            return null;
        }

        $asesoria = (array) $asesoria;
        $event = $this->normalizeEvent($asesoria, 'ASESORIA');
        $event['detalle'] = [
            'descripcion' => $asesoria['descripcion'] ?? null,
            'notas_sesion' => $asesoria['notas_sesion'] ?? null,
            'precio' => $asesoria['precio'] ?? null,
            'cliente' => trim(($asesoria['cliente_nombres'] ?? '') . ' ' . ($asesoria['cliente_apellidos'] ?? '')),
        ];

        return $event;
    }

    // ========================================================================
    // PDF EXPORT DATA (for the grid layout)
    // ========================================================================

    public function getEventsForPdf(?string $fechaInicio = null, ?string $fechaFin = null, ?array $tipos = null): array
    {
        $events = $this->getEvents($fechaInicio, $fechaFin, $tipos);

        // Group by week for pagination
        $fechaInicio = Carbon::parse($fechaInicio ?? Carbon::now()->startOfMonth());
        $fechaFin = Carbon::parse($fechaFin ?? Carbon::now()->endOfMonth());

        $diasSemana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
        $diasNumeros = [1, 2, 3, 4, 5, 6, 7]; // 1=Lun, 7=Dom

        // Determine time range from events
        $minHour = 7;
        $maxHour = 21;
        if ($events->isNotEmpty()) {
            $minMinutes = $events->min(fn($e) => $this->timeToMinutes($e['hora_inicio']));
            $maxMinutes = $events->max(fn($e) => $this->timeToMinutes($e['hora_fin']));
            $minHour = max(6, floor($minMinutes / 60) - 1);
            $maxHour = min(22, ceil($maxMinutes / 60) + 1);
        }

        $hours = range($minHour, $maxHour);

        // Split into weeks
        $weeks = [];
        $current = $fechaInicio->copy()->startOfWeek(Carbon::MONDAY);
        $end = $fechaFin->copy()->endOfWeek(Carbon::SUNDAY);

        while ($current->lte($end)) {
            $weekStart = $current->copy();
            $weekEnd = $current->copy()->addDays(6);
            $weekEvents = $events->filter(function ($e) use ($weekStart, $weekEnd) {
                $fecha = Carbon::parse($e['fecha']);
                return $fecha->between($weekStart, $weekEnd);
            });

            $days = [];
            for ($d = 0; $d < 7; $d++) {
                $date = $weekStart->copy()->addDays($d);
                $dayEvents = $weekEvents->filter(function ($e) use ($date) {
                    return Carbon::parse($e['fecha'])->toDateString() === $date->toDateString();
                })->values()->toArray();

                $days[] = [
                    'date' => $date->copy(),
                    'label' => $diasSemana[$d] . ' ' . $date->format('d'),
                    'is_today' => $date->isToday(),
                    'events' => $dayEvents,
                ];
            }

            $weeks[] = [
                'start' => $weekStart->copy(),
                'end' => $weekEnd->copy(),
                'days' => $days,
                'has_events' => !empty(array_filter($days, fn($d) => !empty($d['events']))),
            ];

            $current->addWeek();
        }

        return [
            'weeks' => $weeks,
            'hours' => $hours,
            'min_hour' => $minHour,
            'max_hour' => $maxHour,
            'fecha_inicio' => $fechaInicio->format('d/m/Y'),
            'fecha_fin' => $fechaFin->format('d/m/Y'),
            'total_eventos' => $events->count(),
            'tipos_activos' => $events->pluck('tipo_evento')->unique()->values()->toArray(),
            'leyenda' => self::EVENT_TYPES,
        ];
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    private function normalizeEvent(array $row, string $tipoEvento): array
    {
        $info = self::EVENT_TYPES[$tipoEvento] ?? ['color' => '#6b7280', 'label' => $tipoEvento];

        return [
            'id' => $tipoEvento . ':' . ($row['referencia_id'] ?? ''),
            'tipo_evento' => $tipoEvento,
            'referencia_id' => $row['referencia_id'] ?? '',
            'titulo' => $row['titulo'] ?? '',
            'fecha' => $row['fecha'] ?? null,
            'hora_inicio' => $row['hora_inicio'] ?? '',
            'hora_fin' => $row['hora_fin'] ?? '',
            'instructor_id' => $row['instructor_id'] ?? null,
            'instructor_nombre' => $row['instructor_nombre'] ?? null,
            'aula_nombre' => $row['aula_nombre'] ?? null,
            'estado' => $row['estado'] ?? null,
            'modalidad' => $row['modalidad'] ?? null,
            'participantes_count' => $row['participantes_count'] ?? null,
            'capacidad_maxima' => $row['capacidad_maxima'] ?? null,
            'color' => $info['color'],
            'tipo_label' => $info['label'],
            'ciudad_nombre' => $row['ciudad_nombre'] ?? null,
            'catalogo_nombre' => $row['catalogo_nombre'] ?? null,
            'nombre_instancia' => $row['nombre_instancia'] ?? null,
        ];
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return (intval($parts[0]) * 60) + intval($parts[1] ?? 0);
    }
}
