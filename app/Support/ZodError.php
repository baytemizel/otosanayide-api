<?php

namespace App\Support;

use RuntimeException;

final class ZodError extends RuntimeException
{
    public function __construct(public readonly string $issue, string $message = '')
    {
        parent::__construct($message);
    }
}
