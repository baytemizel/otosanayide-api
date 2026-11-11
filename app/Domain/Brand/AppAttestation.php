<?php

namespace App\Domain\Brand;

/**
 * Halka acik banner sunumu icin uygulama dogrulamasi (kaynakta Firebase App Check).
 * Bu depoda gercek bir uygulama YOKTUR: saglayici secimi (App Attest / Play Integrity / reCAPTCHA vb.)
 * kullanici karari bekler. Baglanmadikca sunum 503 doner.
 */
interface AppAttestation
{
    /** Gecerli jeton icin uygulama kimligi; gecersizse null. */
    public function verify(string $token): ?string;
}
