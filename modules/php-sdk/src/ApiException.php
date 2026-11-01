<?php

namespace EFacturiPlus;

use RuntimeException;

/** Raspuns de eroare de la API; `errorCode` e stabil, `getMessage()` e pentru oameni. */
class ApiException extends RuntimeException
{
    public function __construct(string $message, public int $status, public ?string $errorCode = null, public array $body = [])
    {
        parent::__construct($message, $status);
    }
}
