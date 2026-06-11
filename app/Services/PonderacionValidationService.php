<?php

namespace App\Services;

use App\Models\CatalogoCurso;
use App\Models\CursoAbierto;
use App\Models\Modulo;

/**
 * Service para validar ponderación de módulos
 * 
 * Valida que:
 * - La suma de ponderaciones sea 100%
 * - Cada ponderación esté en rango válido
 * - No hay duplicados de ponderación
 */
class PonderacionValidationService
{
    /**
     * Ponderación mínima válida
     */
    private const MIN_PONDERACION = 0.1;

    /**
     * Ponderación máxima válida
     */
    private const MAX_PONDERACION = 100;

    /**
     * Suma esperada (100%)
     */
    private const SUMA_ESPERADA = 100;

    /**
     * Tolerancia de redondeo
     */
    private const TOLERANCIA = 0.01;

    /**
     * Validar que la ponderación sea válida
     */
    public function isValidPonderacion(float $ponderacion): bool
    {
        return $ponderacion >= self::MIN_PONDERACION && $ponderacion <= self::MAX_PONDERACION;
    }

    /**
     * Validar que la suma de ponderaciones sea 100%
     */
    public function sumaEs100(array $ponderaciones): bool
    {
        $suma = array_sum($ponderaciones);
        return abs($suma - self::SUMA_ESPERADA) <= self::TOLERANCIA;
    }

    /**
     * Obtener suma de ponderaciones para un catálogo
     */
    public function getSumaForCatalogo(CatalogoCurso $catalogo): float
    {
        return $catalogo->modulosPredeterminados()
            ->sum('ponderacion');
    }

    /**
     * Obtener suma de ponderaciones para un curso abierto
     */
    public function getSumaForCurso(CursoAbierto $curso): float
    {
        return $curso->modulos()
            ->sum('ponderacion');
    }

    /**
     * Validar módulos de catálogo
     */
    public function validateCatalogo(CatalogoCurso $catalogo): array
    {
        $modulos = $catalogo->modulosPredeterminados()->get();
        return $this->validateModulos($modulos);
    }

    /**
     * Validar módulos de curso abierto
     */
    public function validateCurso(CursoAbierto $curso): array
    {
        $modulos = $curso->modulos()->get();
        return $this->validateModulos($modulos);
    }

    /**
     * Validar lista de módulos
     */
    public function validateModulos($modulos): array
    {
        $errors = [];
        $ponderaciones = [];

        foreach ($modulos as $modulo) {
            // Validar rango
            if (!$this->isValidPonderacion($modulo->ponderacion)) {
                $errors[] = [
                    'modulo_id' => $modulo->id,
                    'error' => "Ponderación {$modulo->ponderacion}% fuera de rango ({self::MIN_PONDERACION}% - {self::MAX_PONDERACION}%)",
                ];
            }

            $ponderaciones[] = $modulo->ponderacion;
        }

        // Validar suma
        if (!empty($ponderaciones) && !$this->sumaEs100($ponderaciones)) {
            $suma = array_sum($ponderaciones);
            $errors[] = [
                'suma' => $suma,
                'error' => "Suma de ponderaciones ({$suma}%) no es igual a 100%",
            ];
        }

        return $errors;
    }

    /**
     * Obtener sugerencias para balancear ponderaciones
     */
    public function getSugerenciasBalance(array $ponderaciones): array
    {
        $suma = array_sum($ponderaciones);
        
        if (abs($suma - self::SUMA_ESPERADA) <= self::TOLERANCIA) {
            return [
                'balanced' => true,
                'message' => 'Las ponderaciones ya están balanceadas',
            ];
        }

        $diferencia = self::SUMA_ESPERADA - $suma;
        $cantidad = count($ponderaciones);

        if ($cantidad === 0) {
            return ['error' => 'No hay módulos'];
        }

        $ajustePromedio = $diferencia / $cantidad;

        return [
            'suma_actual' => $suma,
            'diferencia' => $diferencia,
            'cantidad_modulos' => $cantidad,
            'ajuste_promedio_por_modulo' => round($ajustePromedio, 2),
            'sugerencia' => $diferencia > 0 
                ? "Aumentar {abs(round($ajustePromedio, 2))}% a cada módulo"
                : "Disminuir {abs(round($ajustePromedio, 2))}% a cada módulo",
        ];
    }

    /**
     * Calcular ponderación para balancear
     */
    public function calculateBalancedPonderacion(int $cantidadModulos): float
    {
        return self::SUMA_ESPERADA / $cantidadModulos;
    }
}
