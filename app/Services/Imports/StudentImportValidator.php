<?php

namespace App\Services\Imports;

final class StudentImportValidator
{
    public function validate(array $student): array
    {
        $errors = [];

        $this->requiredText($student['nombres'] ?? null, 'nombres', $errors);
        $this->requiredText($student['apellidos'] ?? null, 'apellidos', $errors);

        foreach (['nombres', 'apellidos'] as $field) {
            $value = $student[$field] ?? null;
            if ($value !== null && mb_strlen($value) > 100) {
                $errors[] = ['code' => 'MAX_LENGTH', 'field' => $field, 'message' => "{$field} no puede superar 100 caracteres."];
            } elseif ($value !== null && !preg_match("/^[\\pL]+(?:[ \'-][\\pL]+)*$/u", $value)) {
                $errors[] = ['code' => 'INVALID_NAME', 'field' => $field, 'message' => "{$field} contiene caracteres no permitidos."];
            }
        }

        if (($student['cedula'] ?? null) !== null && !preg_match('/^\d{10}$/', $student['cedula'])) {
            $errors[] = ['code' => 'INVALID_CEDULA', 'field' => 'cedula', 'message' => 'La cédula debe contener exactamente 10 dígitos.'];
        }
        if (($student['correo'] ?? null) !== null && (mb_strlen($student['correo']) > 150 || !filter_var($student['correo'], FILTER_VALIDATE_EMAIL))) {
            $errors[] = ['code' => 'INVALID_EMAIL', 'field' => 'correo', 'message' => 'El correo no es válido.'];
        }
        if (($student['celular'] ?? null) !== null && !preg_match('/^\d{10}$/', $student['celular'])) {
            $errors[] = ['code' => 'INVALID_PHONE', 'field' => 'celular', 'message' => 'El celular debe contener exactamente 10 dígitos.'];
        }
        if (($student['ciudad'] ?? null) !== null && mb_strlen($student['ciudad']) > 100) {
            $errors[] = ['code' => 'MAX_LENGTH', 'field' => 'ciudad', 'message' => 'La ciudad no puede superar 100 caracteres.'];
        }

        return $errors;
    }

    private function requiredText(?string $value, string $field, array &$errors): void
    {
        if (!$value) {
            $errors[] = ['code' => 'REQUIRED', 'field' => $field, 'message' => "{$field} es obligatorio."];
        }
    }
}
