<?php

namespace App\Exceptions;

use RuntimeException;

/** Kaynaktaki `HttpError(status, message)`: mesaj Turkce anahtardir, yanitta cevrilir. */
final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
