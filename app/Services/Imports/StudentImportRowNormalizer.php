<?php

namespace App\Services\Imports;

final class StudentImportRowNormalizer
{
    public function normalize(array $row, array $mapping, int $rowNumber): array
    {
        $mapped = [];
        foreach ($mapping as $header => $target) {
            if ($target !== ImportColumnMapper::IGNORE) {
                $mapped[$target] = $row[$header] ?? null;
            }
        }

        $student = [
            'full_name' => $this->text($mapped['student.full_name'] ?? null),
            'nombres' => $this->text($mapped['student.nombres'] ?? null),
            'apellidos' => $this->text($mapped['student.apellidos'] ?? null),
            'cedula' => $this->cedula($mapped['student.cedula'] ?? null),
            'correo' => $this->email($mapped['student.correo'] ?? null),
            'celular' => $this->phone($mapped['student.celular'] ?? null),
            'ciudad' => $this->text($mapped['student.ciudad'] ?? null),
        ];

        $nameInference = false;
        $warnings = [];
        if (!$student['nombres'] && $student['full_name']) {
            [$apellidos, $nombres] = $this->proposeNames($student['full_name']);
            $student['nombres'] = $nombres;
            $student['apellidos'] = $apellidos;
            $nameInference = true;
            $warnings[] = [
                'code' => 'NAME_INFERRED',
                'message' => 'Nombres y apellidos fueron inferidos desde el nombre completo.',
            ];
        }

        return [
            'row_number' => $rowNumber,
            'original_data' => $row,
            'mapped_data' => $mapped,
            'normalized_data' => $student,
            'student' => $student + ['name_inference' => $nameInference],
            'warnings' => $warnings,
        ];
    }

    public function applyCorrections(array $row, array $corrections): array
    {
        $student = $row['student'];
        foreach (['nombres', 'apellidos', 'cedula', 'correo', 'celular', 'ciudad'] as $field) {
            if (array_key_exists($field, $corrections)) {
                $student[$field] = match ($field) {
                    'cedula' => $this->cedula($corrections[$field]),
                    'correo' => $this->email($corrections[$field]),
                    'celular' => $this->phone($corrections[$field]),
                    default => $this->text($corrections[$field]),
                };
            }
        }
        $row['student'] = $student;
        $row['normalized_data'] = $student;
        return $row;
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) return null;
        $value = preg_replace('/\s+/', ' ', trim((string) $value)) ?? trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function cedula(mixed $value): ?string
    {
        $value = $this->text($value);
        return $value === null ? null : preg_replace('/[\s-]+/', '', $value);
    }

    private function phone(mixed $value): ?string
    {
        $value = $this->text($value);
        return $value === null ? null : preg_replace('/[\s.()\-]+/', '', $value);
    }

    private function email(mixed $value): ?string
    {
        $value = $this->text($value);
        return $value === null ? null : mb_strtolower($value);
    }

    private function proposeNames(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        if (count($parts) <= 2) {
            return [$parts[0] ?? '', $parts[1] ?? ''];
        }
        return [implode(' ', array_slice($parts, 0, 2)), implode(' ', array_slice($parts, 2))];
    }
}
