<?php

namespace App\Jobs;

use App\Support\I18n;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

/**
 * Tek kullanimlik jeton yuku sifreli kuyruk kaydinda tasinir (jobs tablosunda duz metin yok).
 * Alici yoksa is bosa calisir: kayitli olmayan e-posta istekleri zamanlamadan ayirt edilmesin.
 */
final class SendAuthEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly ?string $to,
        public readonly string $kind,
        public readonly ?string $token,
        public readonly string $locale,
    ) {
        $this->afterCommit();
    }

    public function handle(): void
    {
        if ($this->to === null) {
            return;
        }
        $origin = rtrim((string) config('otosanayide.public_web_origin'), '/');
        // Web tarafi bu yollari K171 giris gecisinde saglamalidir.
        [$subject, $body] = match ($this->kind) {
            'verify_email' => ['E-posta adresini doğrula', $origin.'/giris/eposta-dogrula?token='.$this->token],
            'reset_password' => ['Şifre sıfırlama', $origin.'/giris/sifre-sifirla?token='.$this->token],
            default => ['Otosanayide hesabın', I18n::t('Bu e-posta adresiyle zaten bir hesabın var. Şifreni unuttuysan giriş ekranından sıfırlayabilirsin.', [], $this->locale)],
        };
        Mail::raw($body, function ($message) use ($subject): void {
            $message->to($this->to)->subject(I18n::t($subject, [], $this->locale));
        });
    }
}
