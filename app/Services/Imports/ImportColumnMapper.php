<?php

namespace App\Services\Imports;

final class ImportColumnMapper
{
    public const IGNORE = 'IGNORE';

    public const FIELDS = [
        'student.full_name',
        'student.nombres',
        'student.apellidos',
        'student.cedula',
        'student.correo',
        'student.celular',
        'student.ciudad',
    ];

    private const ALIASES = [
        'student.cedula' => ['cedula', 'cédula', 'identificacion', 'identificación', 'documento', 'dni'],
        'student.celular' => ['telefono', 'teléfono', 'celular', 'movil', 'móvil', 'telefono celular'],
        'student.full_name' => ['nombres y apellidos', 'nombre completo', 'estudiante', 'nombre'],
        'student.nombres' => ['nombres', 'nombre(s)'],
        'student.apellidos' => ['apellidos', 'apellido(s)'],
        'student.correo' => ['correo', 'email', 'e-mail', 'correo electronico', 'correo electrónico'],
        'student.ciudad' => ['ciudad', 'localidad'],
    ];

    public function suggest(array $headers): array
    {
        $suggestions = [];
        $used = [];

        foreach ($headers as $header) {
            $field = null;
            $normalized = $this->normalizeHeader((string) $header);

            foreach (self::ALIASES as $candidate => $aliases) {
                if (in_array($normalized, array_map([$this, 'normalizeHeader'], $aliases), true) && !in_array($candidate, $used, true)) {
                    $field = $candidate;
                    $used[] = $candidate;
                    break;
                }
            }

            $suggestions[(string) $header] = $field ?? self::IGNORE;
        }

        return $suggestions;
    }

    public function validate(array $headers, array $mapping): array
    {
        $errors = [];
        $knownHeaders = array_map('strval', $headers);
        $mappedHeaders = array_keys($mapping);

        foreach ($knownHeaders as $header) {
            if (!array_key_exists($header, $mapping)) {
                $errors[] = "La columna {$header} no tiene una decisión de mapeo.";
            }
        }

        foreach ($mappedHeaders as $header) {
            if (!in_array((string) $header, $knownHeaders, true)) {
                $errors[] = "La columna {$header} no existe en el archivo.";
            }
        }

        $targets = array_values(array_filter($mapping, static fn ($value) => $value !== self::IGNORE));
        foreach ($targets as $target) {
            if (!in_array($target, self::FIELDS, true)) {
                $errors[] = "El campo {$target} no es importable en esta fase.";
            }
        }

        if (count($targets) !== count(array_unique($targets))) {
            $errors[] = 'Cada campo del sistema solo puede recibir una columna Excel.';
        }

        if (!in_array('student.full_name', $targets, true)
            && !in_array('student.nombres', $targets, true)) {
            $errors[] = 'Debe mapear nombres y apellidos, o un nombre completo.';
        }

        if (in_array('student.full_name', $targets, true)
            && (in_array('student.nombres', $targets, true) || in_array('student.apellidos', $targets, true))) {
            $errors[] = 'No combine nombre completo con nombres separados en la misma importación.';
        }

        if (in_array('student.full_name', $targets, true) && !in_array('student.apellidos', $targets, true)) {
            return $errors;
        }

        if (in_array('student.nombres', $targets, true) && !in_array('student.apellidos', $targets, true)) {
            $errors[] = 'Cuando se mapean nombres separados también debe mapear apellidos.';
        }

        return $errors;
    }

    public function normalizeHeader(string $header): string
    {
        $header = trim(mb_strtolower($header));
        $header = strtr($header, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return preg_replace('/\s+/', ' ', $header) ?? $header;
    }

}
