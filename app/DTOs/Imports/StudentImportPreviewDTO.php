<?php

namespace App\DTOs\Imports;

final readonly class StudentImportPreviewDTO
{
    public function __construct(
        public string $previewId,
        public array $headers,
        public array $suggestedMapping,
        public array $rows,
        public string $stage,
        public string $expiresAt,
    ) {}

    public function toArray(): array
    {
        return [
            'preview_id' => $this->previewId,
            'headers' => $this->headers,
            'suggested_mapping' => $this->suggestedMapping,
            'rows' => $this->rows,
            'stage' => $this->stage,
            'expires_at' => $this->expiresAt,
        ];
    }
}
