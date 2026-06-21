<?php

namespace App\Services;

use App\Models\SolicitudInscripcion;
use App\Models\Matricula;
use App\Models\CuentaPorCobrar;
use App\Models\Finance\LineaPagoModulo;
use App\Models\Persona;
use Exception;
use Illuminate\Support\Facades\DB;

class RegistrationStateService
{
    private RegistrationValidationService $registrationValidator;

    public function __construct(RegistrationValidationService $registrationValidator)
    {
        $this->registrationValidator = $registrationValidator;
    }

    /**
     * Transicionar a "pendiente_validacion"
     * Se llama después de que el estudiante completa el registro
     * 
     * @param SolicitudInscripcion $solicitud
     * @return array ['exito' => bool, 'mensaje' => string, 'solicitud' => SolicitudInscripcion|null]
     */
    public function submit(SolicitudInscripcion $solicitud): array
    {
        try {
            // Validar que puede hacer la transición
            if ($solicitud->estado !== SolicitudInscripcion::ESTADO_REGISTRADO) {
                return [
                    'exito' => false,
                    'mensaje' => "No puede cambiar de estado desde {$solicitud->estado}",
                    'solicitud' => null,
                ];
            }

            // Validar datos requeridos
            $validacion = $this->registrationValidator->validarParaPendiente($solicitud);
            if (!$validacion['valido']) {
                return [
                    'exito' => false,
                    'mensaje' => implode('; ', $validacion['errores']),
                    'solicitud' => null,
                ];
            }

            // Actualizar estado
            $solicitud->estado = SolicitudInscripcion::ESTADO_PENDIENTE_VALIDACION;
            $solicitud->save();

            return [
                'exito' => true,
                'mensaje' => 'Solicitud enviada a validación correctamente',
                'solicitud' => $solicitud->refresh(),
            ];
        } catch (Exception $e) {
            return [
                'exito' => false,
                'mensaje' => "Error al procesar solicitud: {$e->getMessage()}",
                'solicitud' => null,
            ];
        }
    }

    /**
     * Validador aprueba el registro (realiza transición a aprobado)
     * También genera la matrícula y la cuenta por cobrar
     * 
     * @param SolicitudInscripcion $solicitud
     * @param Persona $validador Personal que aprueba
     * @param string|null $observaciones
     * @return array ['exito' => bool, 'mensaje' => string, 'matricula_id' => string|null, 'cuenta_cobrar_id' => string|null]
     */
    public function approve(SolicitudInscripcion $solicitud, $validadorId = null, ?string $observaciones = null): array
    {
        try {
            // Validar estado actual
            if ($solicitud->estado !== SolicitudInscripcion::ESTADO_PENDIENTE_VALIDACION) {
                return [
                    'exito' => false,
                    'mensaje' => "Solo se pueden aprobar solicitudes en estado pendiente de validación",
                    'matricula_id' => null,
                    'cuenta_cobrar_id' => null,
                ];
            }

            return DB::transaction(function () use ($solicitud, $validadorId, $observaciones) {
                // 1. Actualizar estado a aprobado
                $solicitud->estado = SolicitudInscripcion::ESTADO_APROBADO;
                $solicitud->validado_por = $validadorId ?? null;
                $solicitud->observaciones_validacion = $observaciones;
                $solicitud->fecha_validacion = now();
                $solicitud->save();

                // 2. Crear matrícula
                $matricula = $this->crearMatricula($solicitud);
                if (! $matricula) {
                    throw new Exception('No se pudo crear la matrícula');
                }

                // 3. Crear líneas de pago por módulo
                $lineasPago = $this->crearLineasPagoModulo($solicitud, $matricula);

                // 4. Marcar cuentas legacy existentes
                CuentaPorCobrar::where('matricula_id', $matricula->id)
                    ->update(['es_legacy' => true]);

                // 5. Actualizar estado a matricula_creada
                $solicitud->estado = SolicitudInscripcion::ESTADO_MATRICULA_CREADA;
                $solicitud->save();

                return [
                    'exito' => true,
                    'mensaje' => 'Solicitud aprobada correctamente. Matrícula creada.',
                    'matricula_id' => $matricula->id,
                    'lineas_pago_ids' => collect($lineasPago)->pluck('id')->toArray(),
                    'requiere_pago_inicial' => count($lineasPago) > 0,
                ];
            });
        } catch (Exception $e) {
            return [
                'exito' => false,
                'mensaje' => "Error al aprobar solicitud: {$e->getMessage()}",
                'matricula_id' => null,
                'cuenta_cobrar_id' => null,
            ];
        }
    }

    /**
     * Validador rechaza el registro
     * 
     * @param SolicitudInscripcion $solicitud
     * @param Persona $validador Personal que rechaza
     * @param string $motivoRechazo Razón del rechazo
     * @return array ['exito' => bool, 'mensaje' => string]
     */
    public function reject(SolicitudInscripcion $solicitud, $validadorId = null, string $motivoRechazo = ''): array
    {
        try {
            // Validar estado actual
            if ($solicitud->estado !== SolicitudInscripcion::ESTADO_PENDIENTE_VALIDACION) {
                return [
                    'exito' => false,
                    'mensaje' => "Solo se pueden rechazar solicitudes en estado pendiente de validación",
                ];
            }

            if (empty($motivoRechazo)) {
                return [
                    'exito' => false,
                    'mensaje' => "Debe proporcionar un motivo para rechazar la solicitud",
                ];
            }

            // Actualizar solicitud
            $solicitud->estado = SolicitudInscripcion::ESTADO_RECHAZADO;
            $solicitud->validado_por = $validadorId;
            $solicitud->motivo_rechazo = $motivoRechazo;
            $solicitud->fecha_validacion = now();
            $solicitud->save();

            return [
                'exito' => true,
                'mensaje' => 'Solicitud rechazada correctamente',
            ];
        } catch (Exception $e) {
            return [
                'exito' => false,
                'mensaje' => "Error al rechazar solicitud: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Cancelar una solicitud (cualquier estado)
     * 
     * @param SolicitudInscripcion $solicitud
     * @param string|null $motivo
     * @return array ['exito' => bool, 'mensaje' => string]
     */
    public function cancel(SolicitudInscripcion $solicitud, ?string $motivo = null): array
    {
        try {
            // No se pueden cancelar las que ya tienen matrícula creada
            if ($solicitud->estado === SolicitudInscripcion::ESTADO_MATRICULA_CREADA) {
                return [
                    'exito' => false,
                    'mensaje' => "No se puede cancelar una solicitud con matrícula ya creada",
                ];
            }

            $solicitud->estado = SolicitudInscripcion::ESTADO_CANCELADO;
            $solicitud->observaciones_validacion = $motivo ?? $solicitud->observaciones_validacion;
            $solicitud->save();

            return [
                'exito' => true,
                'mensaje' => 'Solicitud cancelada correctamente',
            ];
        } catch (Exception $e) {
            return [
                'exito' => false,
                'mensaje' => "Error al cancelar solicitud: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Crear matrícula desde una solicitud aprobada
     * 
     * @param SolicitudInscripcion $solicitud
     * @return Matricula|null
     */
    private function crearMatricula(SolicitudInscripcion $solicitud): ?Matricula
    {
        try {
            $curso = $solicitud->cursoAbierto;

            $matricula = Matricula::create([
                'estudiante_id' => $solicitud->persona_id ?: null,
                'curso_abierto_id' => $solicitud->curso_abierto_id,
                'tipo_pago' => $solicitud->tipo_pago,
                'voucher_url' => $solicitud->archivo_comprobante_url,
                'estado' => 'activo',
                'solicitud_inscripcion_id' => $solicitud->id,
            ]);

            $curso->estudiantes_inscritos += 1;
            $curso->save();

            return $matricula;
        } catch (Exception $e) {
            \Log::error("Error creando matrícula: {$e->getMessage()}");
            return null;
        }
    }
    /**
     * Crea líneas de pago por módulo para la matrícula.
     * Reemplaza el antiguo crearCuentaPorCobrar con granularidad por módulo.
     */
    private function crearLineasPagoModulo(SolicitudInscripcion $solicitud, Matricula $matricula): array
    {
        $modulos = $solicitud->cursoAbierto->modulos()->orderBy('numero_orden')->get();

        if ($modulos->isEmpty()) {
            return [];
        }

        $lineas = [];

        foreach ($modulos as $i => $modulo) {
            $precioBase = $modulo->precio_base ?? 0;

            $linea = LineaPagoModulo::create([
                'matricula_id' => $matricula->id,
                'modulo_id' => $modulo->id,
                'monto_original' => $precioBase,
                'monto_ajustado' => $precioBase,
                'monto_abonado' => 0,
                'estado' => LineaPagoModulo::ESTADO_PENDIENTE,
                'orden' => $i,
            ]);

            $lineas[] = $linea;
        }

        return $lineas;
    }
}
