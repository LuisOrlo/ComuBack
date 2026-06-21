<?php

namespace App\Observers;

use App\Models\TransaccionIngreso;

class TransaccionIngresoObserver
{
    /**
     * Caso Corrección 4c: transacción creada directamente como aprobada (pago al matricular).
     */
    public function created(TransaccionIngreso $transaccion): void
    {
        if (
            $transaccion->estado_verificacion === TransaccionIngreso::VERIFICACION_APROBADO
            && $transaccion->linea_pago_modulo_id
        ) {
            $transaccion->lineaPagoModulo->aplicarTransaccion($transaccion);
        }
    }

    /**
     * Transición de estado_verificacion: pendiente → aprobado, o aprobado → otro.
     */
    public function updated(TransaccionIngreso $transaccion): void
    {
        if (! $transaccion->wasChanged('estado_verificacion') || ! $transaccion->linea_pago_modulo_id) {
            return;
        }

        $anterior = $transaccion->getOriginal('estado_verificacion');
        $actual = $transaccion->estado_verificacion;
        $linea = $transaccion->lineaPagoModulo;

        if ($anterior !== TransaccionIngreso::VERIFICACION_APROBADO && $actual === TransaccionIngreso::VERIFICACION_APROBADO) {
            $linea->aplicarTransaccion($transaccion);
        }

        if ($anterior === TransaccionIngreso::VERIFICACION_APROBADO && $actual !== TransaccionIngreso::VERIFICACION_APROBADO) {
            $linea->revertirTransaccion($transaccion);
        }
    }
}
