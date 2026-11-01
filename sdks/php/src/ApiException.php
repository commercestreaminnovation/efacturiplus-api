<?php

namespace EFacturiPlus;

use RuntimeException;

/** Raspuns de eroare de la API. `errorCode` e stabil, pentru program; `getMessage()` e pentru oameni. */
class ApiException extends RuntimeException
{
    public function __construct(string $message, public int $status, public ?string $errorCode = null, public array $body = [])
    {
        parent::__construct($message, $status);
    }

    /** Erorile pe campuri, la 422. */
    public function errors(): array
    {
        return is_array($this->body['errors'] ?? null) ? $this->body['errors'] : [];
    }
}
