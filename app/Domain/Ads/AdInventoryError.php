<?php

namespace App\Domain\Ads;

use RuntimeException;

/** Kaynaktaki AdInventoryError: istemciye 409 (access: 403) olarak doner. */
final class AdInventoryError extends RuntimeException {}
