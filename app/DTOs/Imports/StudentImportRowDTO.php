<?php

namespace App\DTOs\Imports;

final readonly class StudentImportRowDTO
{
    public function __construct(
        public int $rowNumber,
        public array $originalData,
        public array $mappedData,
        public array $normalizedData,
        public array $student,
        public array $identityResolution,
        public array $errors = [],
        public array $warnings = [],
        public string $status = 'READY',
    ) {}

    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'original_data' => $this->originalData,
            'mapped_data' => $this->mappedData,
            'normalized_data' => $this->normalizedData,
            'student' => $this->student,
            'identity_resolution' => $this->identityResolution,
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'status' => $this->status,
        ];
    }
}
