<?php

namespace App\Services;

use App\Models\CursoAbierto;
use App\Models\Matricula;
use App\Models\SolicitudInscripcion;
use Carbon\Carbon;

class RegistrationValidationService
{
    /**
     * Validar solicitud de inscripción
     * 
     * @param string $cursoAbiertoId UUID del curso abierto
     * @param string|null $personaId UUID del estudiante (null si es externo)
     * @param string|null $participanteExternoId UUID del participante externo (null si es estudiante)
     * @param float $montoSolicitado Monto que declara pagar el estudiante
     * @param string $tipoPago 'completo' o 'abono'
     * 
     * @return array ['valido' => bool, 'errores' => [], 'capacidad_disponible' => int|null]
     */
    public function validar(
        string $cursoAbiertoId,
        ?string $personaId = null,
        ?string $participanteExternoId = null,
        float $montoSolicitado = 0,
        string $tipoPago = 'completo'
    ): array {
        $errores = [];
        $capacidadDisponible = null;

        // 1. Validar que existe uno y solo uno de personaId o participanteExternoId
        if (empty($personaId) && empty($participanteExternoId)) {
            $errores[] = 'Debe proporcionar persona_id o participante_externo_id';
        }

        if (!empty($personaId) && !empty($participanteExternoId)) {
            $errores[] = 'No puede proporcionar ambos persona_id y participante_externo_id';
        }

        // Si hay errores de identificación, retornar
        if (!empty($errores)) {
            return [
                'valido' => false,
                'errores' => $errores,
                'capacidad_disponible' => null,
            ];
        }

        // 2. Validar que el curso existe y está disponible
        $curso = CursoAbierto::find($cursoAbiertoId);
        if (!$curso) {
            $errores[] = 'El curso solicitado no existe';
            return [
                'valido' => false,
                'errores' => $errores,
                'capacidad_disponible' => null,
            ];
        }

        // 3. Validar que el curso está en estado válido para inscripción
        if ($curso->estado !== 'pendiente' && $curso->estado !== 'confirmado') {
            $errores[] = 'El curso no está disponible para inscripciones';
        }

        // 4. Validar que la fecha del curso no ha pasado (permite inscribirse hasta el día de inicio)
        if ($curso->fecha_inicio && Carbon::parse($curso->fecha_inicio)->startOfDay()->lt(Carbon::today())) {
            $errores[] = 'El curso ya ha comenzado';
        }

        // 5. Validar capacidad disponible
        $capacidadDisponible = $curso->capacidad_maxima - $curso->estudiantes_inscritos;
        if ($capacidadDisponible <= 0) {
            $errores[] = 'El curso está lleno. No hay capacidad disponible';
        }

        // 6. Validar que el estudiante no está ya inscrito
        if ($personaId) {
            $yaInscrito = Matricula::where('estudiante_id', $personaId)
                ->where('curso_abierto_id', $cursoAbiertoId)
                ->exists();

            if ($yaInscrito) {
                $errores[] = 'Ya está inscrito en este curso';
            }

            // 7. Validar que no tiene solicitud pendiente/aprobada para este curso
            $tieneSolicitud = SolicitudInscripcion::where('persona_id', $personaId)
                ->where('curso_abierto_id', $cursoAbiertoId)
                ->whereIn('estado', [
                    SolicitudInscripcion::ESTADO_REGISTRADO,
                    SolicitudInscripcion::ESTADO_PENDIENTE_VALIDACION,
                    SolicitudInscripcion::ESTADO_APROBADO,
                    SolicitudInscripcion::ESTADO_MATRICULA_CREADA,
                ])
                ->exists();

            if ($tieneSolicitud) {
                $errores[] = 'Ya tiene una solicitud de inscripción en proceso para este curso';
            }
        }

        // 8. Validar monto
        if ($montoSolicitado < 0) {
            $errores[] = 'El monto solicitado no puede ser negativo';
        }

        if ($tipoPago === 'completo' && $montoSolicitado != $curso->precio_base) {
            $errores[] = "Para pago completo, el monto debe ser {$curso->precio_base}";
        }

        if ($tipoPago === 'abono' && ($montoSolicitado <= 0 || $montoSolicitado >= $curso->precio_base)) {
            $errores[] = "Para abono, el monto debe ser mayor a 0 y menor a {$curso->precio_base}";
        }

        return [
            'valido' => empty($errores),
            'errores' => $errores,
            'capacidad_disponible' => $capacidadDisponible,
        ];
    }

    /**
     * Validar que la solicitud puede pasar a "pendiente_validacion"
     * 
     * @param SolicitudInscripcion $solicitud
     * @return array ['valido' => bool, 'errores' => []]
     */
    public function validarParaPendiente(SolicitudInscripcion $solicitud): array
    {
        $errores = [];

        // Validar que tiene comprobante
        if (empty($solicitud->archivo_comprobante_url)) {
            $errores[] = 'Debe adjuntar un comprobante de pago';
        }

        // Validar que tiene tipo de comprobante
        if (empty($solicitud->tipo_comprobante)) {
            $errores[] = 'Debe especificar el tipo de comprobante';
        }

        // Validar que tiene fecha de pago
        if (empty($solicitud->fecha_pago_declarada)) {
            $errores[] = 'Debe proporcionar la fecha en que realizó el pago';
        }

        // Validar que el monto es válido
        if ($solicitud->monto_solicitado <= 0) {
            $errores[] = 'El monto debe ser mayor a 0';
        }

        return [
            'valido' => empty($errores),
            'errores' => $errores,
        ];
    }

    /**
     * Contar inscritos activos en un curso (considerando solicitudes aprobadas)
     * 
     * @param string $cursoAbiertoId
     * @return int
     */
    public function contarInscritosActivos(string $cursoAbiertoId): int
    {
        // Matrículas activas
        $matriculosActivos = Matricula::where('curso_abierto_id', $cursoAbiertoId)
            ->whereIn('estado', ['activo', 'completado'])
            ->count();

        // Solicitudes aprobadas/pendientes de creación de matrícula
        $solicitudesAprobadas = SolicitudInscripcion::where('curso_abierto_id', $cursoAbiertoId)
            ->whereIn('estado', [
                SolicitudInscripcion::ESTADO_APROBADO,
                SolicitudInscripcion::ESTADO_MATRICULA_CREADA,
            ])
            ->count();

        return $matriculosActivos + $solicitudesAprobadas;
    }
}
