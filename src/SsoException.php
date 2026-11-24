<?php

namespace Voope\SsoClient;

use RuntimeException;

class SsoException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $indisponivel = false)
    {
        parent::__construct($message);
    }

    public static function indisponivel(): self
    {
        return new self('O login Voope não respondeu. Tente de novo em instantes.', true);
    }
}
