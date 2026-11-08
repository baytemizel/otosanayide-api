<?php

namespace App\Exceptions;

use RuntimeException;

/** Kaynaktaki *WriteConflict / AccountErasingError karsiligi: her zaman 409. */
final class WriteConflict extends RuntimeException {}
