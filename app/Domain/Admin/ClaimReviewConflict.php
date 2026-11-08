<?php

namespace App\Domain\Admin;

use RuntimeException;

/** claim-review.ts ClaimReviewConflict: her zaman 409, mesaj kullaniciya gider. */
final class ClaimReviewConflict extends RuntimeException {}
