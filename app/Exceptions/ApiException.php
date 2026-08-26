<?php

namespace App\Exceptions;

use Exception;

/**
 * A business-rule failure that must reach the client as doc 08 §1's error
 * envelope: a stable machine `error` code plus a safe-to-display `message`.
 */
class ApiException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $errors = [],
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }
}
