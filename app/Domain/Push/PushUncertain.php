<?php

namespace App\Domain\Push;

use RuntimeException;

/** Teslim edilip edilmedigi bilinmeyen veya kesin olmayan saglayici sonucu. Mesaj sir veya jeton icermez. */
final class PushUncertain extends RuntimeException {}
