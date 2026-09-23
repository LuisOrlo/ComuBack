<?php

namespace App\DTOs\Imports;

final readonly class StudentImportResultDTO
{
    public function __construct(
        public int $rowNumber,
        public string $status,
        public ?string $personaId = null,
        public ?string $perfilEstudianteId = null,
        public ?string $action = null,
        public array $warnings = [],
        public array $errors = [],
        public ?string $matriculaId = null,
        public ?string $enrollmentAction = null,
        public ?string $enrollmentStatus = null,
        public ?string $financeStatus = null,
        public int $financialLinesCreated = 0,
        public int $accountsCreated = 0,
        public int $transactionsCreated = 0,
        public array $financialWarnings = [],
        public array $financialTransactionIds = [],
    ) {}

    public function toArray(): array
    {
        return [
            'row_number' => $this->rowNumber,
            'status' => $this->status,
            'persona_id' => $this->personaId,
            'perfil_estudiante_id' => $this->perfilEstudianteId,
            'action' => $this->action,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'matricula_id' => $this->matriculaId,
            'enrollment_action' => $this->enrollmentAction,
            'enrollment_status' => $this->enrollmentStatus,
            'finance_status' => $this->financeStatus,
            'financial_lines_created' => $this->financialLinesCreated,
            'accounts_created' => $this->accountsCreated,
            'transactions_created' => $this->transactionsCreated,
            'financial_warnings' => $this->financialWarnings,
            'financial_transaction_ids' => $this->financialTransactionIds,
        ];
    }
}
