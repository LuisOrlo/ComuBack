<?php

namespace App\Services;

use App\Models\SolicitudInscripcion;
use App\Models\Matricula;
use App\Models\CuentaPorCobrar;
use App\Models\Finance\LineaPagoModulo;
use App\Models\Persona;
use App\Models\TransaccionIngreso;
use Exception;
use Illuminate\Support\Facades\Cache;
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
    public function approve(
        SolicitudInscripcion $solicitud,
        $validadorId = null,
        ?string $observaciones = null,
        array $pagos = [],
        string $metodoPago = 'efectivo',
        ?float $precioInscripcion = null,
        float $inscripcionCubierta = 0
    ): array
    {
        try {
            if ($solicitud->estado !== SolicitudInscripcion::ESTADO_PENDIENTE_VALIDACION) {
                return [
                    'exito' => false,
                    'mensaje' => "Solo se pueden aprobar solicitudes en estado pendiente de validación",
                    'matricula_id' => null,
                    'cuenta_cobrar_id' => null,
                ];
            }

            return DB::transaction(function () use ($solicitud, $validadorId, $observaciones, $pagos, $metodoPago, $precioInscripcion, $inscripcionCubierta) {
                $solicitud->estado = SolicitudInscripcion::ESTADO_APROBADO;
                $solicitud->validado_por = $validadorId ?? null;
                $solicitud->observaciones_validacion = $observaciones;
                $solicitud->fecha_validacion = now();
                $solicitud->save();

                $matricula = $this->crearMatricula($solicitud);
                if (! $matricula) {
                    throw new Exception('No se pudo crear la matrícula');
                }

                $lineasPago = $this->crearLineasPagoModulo($solicitud, $matricula);
                $lineasPagoIds = collect($lineasPago)->pluck('id')->toArray();

                $inscripcionLinea = null;

                if ($precioInscripcion && $precioInscripcion > 0) {
                    $inscripcionCubierta = max(0, $inscripcionCubierta);
                    $estado = $inscripcionCubierta >= $precioInscripcion ? 'pagado' : ($inscripcionCubierta > 0 ? 'abonado' : 'pendiente');
                    $inscripcionLinea = LineaPagoModulo::create([
                        'matricula_id' => $matricula->id,
                        'modulo_id' => null,
                        'tipo' => 'inscripcion',
                        'monto_original' => $precioInscripcion,
                        'monto_ajustado' => $precioInscripcion,
                        'monto_abonado' => $inscripcionCubierta,
                        'estado' => $estado,
                        'orden' => 999,
                    ]);
                    $lineasPagoIds[] = $inscripcionLinea->id;
                }

                CuentaPorCobrar::where('matricula_id', $matricula->id)
                    ->update(['es_legacy' => true]);

                $todosLineasPago = collect($lineasPago);
                if ($inscripcionLinea) {
                    $todosLineasPago->push($inscripcionLinea);
                }
                $montoTotal = $todosLineasPago->sum('monto_ajustado');
                $cuentaCobrar = CuentaPorCobrar::create([
                    'matricula_id' => $matricula->id,
                    'monto_total' => $montoTotal,
                    'monto_abonado' => 0,
                    'estado' => $montoTotal > 0 ? CuentaPorCobrar::ESTADO_PENDIENTE : CuentaPorCobrar::ESTADO_PAGADO,
                ]);

                $solicitud->estado = SolicitudInscripcion::ESTADO_MATRICULA_CREADA;
                $solicitud->save();

                $referencia = 'mat-' . $matricula->id . '-' . now()->timestamp;

                if (! empty($pagos)) {
                    $modulos = $solicitud->cursoAbierto->modulos()->orderBy('numero_orden')->get()->keyBy('id');
                    $lineasPorModulo = collect($lineasPago)->keyBy('modulo_id');

                    foreach ($pagos as $pago) {
                        if (empty($pago['monto']) || (float) $pago['monto'] <= 0) {
                            continue;
                        }

                        $linea = $lineasPorModulo->get($pago['modulo_id']);
                        if (! $linea) continue;

                        if (! empty($pago['monto_ajustado']) && (float) $pago['monto_ajustado'] != $linea->monto_ajustado) {
                            $linea->update([
                                'monto_ajustado' => (float) $pago['monto_ajustado'],
                                'motivo_ajuste' => $pago['motivo_ajuste'] ?? null,
                                'ajustado_por' => $validadorId ?? auth()->user()->persona_id ?? null,
                                'fecha_ajuste' => now(),
                            ]);
                            $linea->refresh();
                        }

                        TransaccionIngreso::create([
                            'linea_pago_modulo_id' => $linea->id,
                            'monto' => (float) $pago['monto'],
                            'metodo_pago' => $metodoPago,
                            'comprobante_url' => $solicitud->archivo_comprobante_url,
                            'fecha_pago' => now(),
                            'registrado_por' => $validadorId ?? auth()->user()->persona_id ?? null,
                            'verificado_por' => $validadorId ?? auth()->user()->persona_id ?? null,
                            'fecha_verificacion' => now(),
                            'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
                            'referencia_pago' => $referencia,
                        ]);
                    }

                    if ($inscripcionLinea && $inscripcionCubierta > 0) {
                        TransaccionIngreso::create([
                            'linea_pago_modulo_id' => $inscripcionLinea->id,
                            'monto' => $inscripcionCubierta,
                            'metodo_pago' => $metodoPago,
                            'comprobante_url' => $solicitud->archivo_comprobante_url,
                            'fecha_pago' => now(),
                            'registrado_por' => $validadorId ?? auth()->user()->persona_id ?? null,
                            'verificado_por' => $validadorId ?? auth()->user()->persona_id ?? null,
                            'fecha_verificacion' => now(),
                            'estado_verificacion' => TransaccionIngreso::VERIFICACION_APROBADO,
                            'referencia_pago' => $referencia . '-insc',
                        ]);
                    }
                }

                if (! empty($pagos)) {
                    $totalPagado = collect($pagos)->sum('monto');
                    $totalEsperado = $todosLineasPago->sum('monto_ajustado');
                    $solicitud->update([
                        'monto_solicitado' => $totalPagado,
                        'tipo_pago' => $totalPagado >= $totalEsperado ? 'completo' : 'abono',
                    ]);
                }

                Cache::forget('finance.resumen');

                $montosActuales = $todosLineasPago->map(fn($l) => $l->refresh());
                $montoTotalFinal = $montosActuales->sum('monto_ajustado');
                $montoAbonadoFinal = $montosActuales->sum('monto_abonado');
                $cuentaCobrar->update([
                    'monto_total' => $montoTotalFinal,
                    'monto_abonado' => $montoAbonadoFinal,
                    'estado' => $montoAbonadoFinal >= $montoTotalFinal
                        ? CuentaPorCobrar::ESTADO_PAGADO
                        : ($montoAbonadoFinal > 0 ? CuentaPorCobrar::ESTADO_ABONADO : CuentaPorCobrar::ESTADO_PENDIENTE),
                ]);

                return [
                    'exito' => true,
                    'mensaje' => 'Solicitud aprobada y pago registrado correctamente',
                    'matricula_id' => $matricula->id,
                    'cuenta_cobrar_id' => $cuentaCobrar->id,
                    'lineas_pago_ids' => $lineasPagoIds,
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

            $estudianteId = $solicitud->persona_id;

            if (!$estudianteId && $solicitud->participante_externo_id) {
                $externo = $solicitud->participanteExterno;
                if ($externo) {
                    $persona = \App\Models\Persona::firstOrCreate(
                        ['cedula' => $externo->cedula ?: null],
                        [
                            'tipo' => 'estudiante',
                            'nombres' => $externo->nombres,
                            'apellidos' => $externo->apellidos ?? '',
                            'correo' => $externo->correo,
                            'celular' => $externo->celular,
                            'ciudad' => $externo->ciudad,
                        ]
                    );
                    $persona->update([
                        'correo' => $persona->correo ?? $externo->correo,
                        'celular' => $persona->celular ?? $externo->celular,
                        'ciudad' => $persona->ciudad ?? $externo->ciudad,
                    ]);
                    \App\Models\PerfilEstudiante::firstOrCreate(
                        ['persona_id' => $persona->id],
                        [
                            'direccion' => $externo->direccion,
                            'ocupacion' => $externo->ocupacion,
                            'estado_civil' => $externo->estado_civil,
                            'edad' => $externo->edad,
                            'fecha_nacimiento' => $externo->fecha_nacimiento,
                            'ciudad' => $externo->ciudad,
                        ]
                    );
                    $estudianteId = $persona->id;
                    $solicitud->update([
                        'persona_id' => $persona->id,
                        'participante_externo_id' => null,
                        'es_participante_externo' => false,
                    ]);
                    $externo->update(['es_cliente' => false]);
                }
            }

            $matricula = Matricula::create([
                'estudiante_id' => $estudianteId,
                'curso_abierto_id' => $solicitud->curso_abierto_id,
                'tipo_pago' => $solicitud->tipo_pago,
                'voucher_url' => $solicitud->archivo_comprobante_url,
                'estado' => 'activo',
                'precio_total_legacy' => 0,
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
