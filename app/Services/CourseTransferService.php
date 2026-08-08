<?php

namespace App\Services;

use App\Models\CursoAbierto;
use App\Models\Matricula;
use App\Models\Nota;
use App\Models\CambioHorario;
use App\Models\CuentaPorCobrar;
use App\Models\Finance\LineaPagoModulo;
use Illuminate\Support\Facades\DB;

class CourseTransferService
{
    public function getAlternativos(string $cursoAbiertoId): array
    {
        $cursoActual = CursoAbierto::findOrFail($cursoAbiertoId);

        $alternativos = CursoAbierto::where('catalogo_curso_id', $cursoActual->catalogo_curso_id)
            ->where('id', '!=', $cursoActual->id)
            ->where('es_activo', true)
            ->where('fecha_inicio', '>', now()->subDays(7))
            ->with(['ciudad', 'horario.diasSemana'])
            ->get()
            ->filter(fn($c) => $c->hayEspacios())
            ->values()
            ->map(fn($c) => [
                'id' => $c->id,
                'nombre_instancia' => $c->nombre_instancia,
                'modalidad' => $c->modalidad,
                'precio_base' => (float) $c->precio_base,
                'capacidad_maxima' => $c->capacidad_maxima,
                'espacios_disponibles' => $c->obtenerEspaciosDisponibles(),
                'fecha_inicio' => $c->fecha_inicio,
                'fecha_fin' => $c->fecha_fin,
                'ciudad' => $c->ciudad?->nombre,
                'horario' => $c->horario ? [
                    'nombre_referencial' => $c->horario->nombre_referencial,
                    'hora_inicio' => $c->horario->hora_inicio,
                    'hora_fin' => $c->horario->hora_fin,
                    'dias' => $c->horario->diasSemana->pluck('dia_semana'),
                ] : null,
            ])
            ->toArray();

        return $alternativos;
    }

    public function transferir(string $matriculaId, string $cursoAbiertoNuevoId, ?string $motivo = null, array $lineas = []): array
    {
        return DB::transaction(function () use ($matriculaId, $cursoAbiertoNuevoId, $motivo) {
            $matriculaOrigen = Matricula::findOrFail($matriculaId);
            $cursoNuevo = CursoAbierto::findOrFail($cursoAbiertoNuevoId);

            if ($matriculaOrigen->estado !== Matricula::ESTADO_ACTIVO) {
                throw new \Exception('La matrícula debe estar activa para transferir.');
            }

            if ($matriculaOrigen->curso_abierto_id === $cursoNuevo->id) {
                throw new \Exception('El curso destino debe ser diferente al actual.');
            }

            if (!$cursoNuevo->hayEspacios()) {
                throw new \Exception('El curso destino no tiene cupos disponibles.');
            }

            $cursoViejo = $matriculaOrigen->cursoAbierto;

            CambioHorario::create([
                'matricula_origen_id' => $matriculaOrigen->id,
                'curso_abierto_antiguo_id' => $matriculaOrigen->curso_abierto_id,
                'curso_abierto_nuevo_id' => $cursoNuevo->id,
                'motivo' => $motivo,
                'estado' => CambioHorario::ESTADO_COMPLETADO,
            ]);

            $matriculaOrigen->update(['estado' => Matricula::ESTADO_RETIRADO]);

            $nuevaMatricula = Matricula::create([
                'estudiante_id' => $matriculaOrigen->estudiante_id,
                'curso_abierto_id' => $cursoNuevo->id,
                'horario_id' => $cursoNuevo->horario_id,
                'tipo_pago' => $matriculaOrigen->tipo_pago,
                'estado' => Matricula::ESTADO_ACTIVO,
                'fecha_inicio' => $cursoNuevo->fecha_inicio,
                'fecha_fin' => $cursoNuevo->fecha_fin,
            ]);

            $notasMigradas = 0;
            $modulosNuevos = $cursoNuevo->modulos()->orderBy('numero_orden')->get();

            foreach ($matriculaOrigen->notas as $nota) {
                $moduloViejo = $nota->modulo;
                $moduloNuevo = $modulosNuevos->firstWhere('numero_orden', $moduloViejo->numero_orden);

                if ($moduloNuevo) {
                    Nota::create([
                        'matricula_id' => $nuevaMatricula->id,
                        'modulo_id' => $moduloNuevo->id,
                        'calificacion' => $nota->calificacion,
                        'observaciones' => $nota->observaciones,
                    ]);
                    $notasMigradas++;
                }
            }

            $cuentaActual = CuentaPorCobrar::where('matricula_id', $matriculaOrigen->id)->first();
            if ($cuentaActual) {
                $cuentaActual->update(['es_legacy' => true]);
            }

            // Crear líneas de pago con montos del payload de reconciliación
            foreach ($lineas as $i => $l) {
                LineaPagoModulo::create([
                    'matricula_id' => $nuevaMatricula->id,
                    'modulo_id' => $l['modulo_id'] ?? null,
                    'tipo' => $l['tipo'] ?? 'modulo',
                    'monto_original' => $l['monto_ajustado'],
                    'monto_ajustado' => $l['monto_ajustado'],
                    'monto_abonado' => $l['monto_abonado'],
                    'estado' => match(true) {
                        $l['monto_abonado'] >= $l['monto_ajustado'] => LineaPagoModulo::ESTADO_PAGADO,
                        $l['monto_abonado'] > 0 => LineaPagoModulo::ESTADO_ABONADO,
                        default => LineaPagoModulo::ESTADO_PENDIENTE,
                    },
                    'orden' => $i,
                ]);
            }

            // Crear nueva cuenta por cobrar
            $totalAjustado = array_sum(array_column($lineas, 'monto_ajustado'));
            $totalAbonado = array_sum(array_column($lineas, 'monto_abonado'));
            CuentaPorCobrar::create([
                'matricula_id' => $nuevaMatricula->id,
                'solicitud_inscripcion_id' => $matriculaOrigen->solicitud_inscripcion_id,
                'monto_total' => $totalAjustado,
                'monto_abonado' => $totalAbonado,
                'estado' => match(true) {
                    $totalAbonado >= $totalAjustado => CuentaPorCobrar::ESTADO_PAGADO,
                    $totalAbonado > 0 => CuentaPorCobrar::ESTADO_ABONADO,
                    default => CuentaPorCobrar::ESTADO_PENDIENTE,
                },
            ]);

            $diferenciaPrecio = (float) $cursoNuevo->precio_base - (float) $cursoViejo->precio_base;

            return [
                'success' => true,
                'message' => 'Transferencia completada exitosamente',
                'data' => [
                    'cambio_horario_id' => $cambio->id ?? null,
                    'matricula_nueva_id' => $nuevaMatricula->id,
                    'notas_migradas' => $notasMigradas,
                    'diferencia_precio' => $diferenciaPrecio,
                ],
            ];
        });
    }
}
