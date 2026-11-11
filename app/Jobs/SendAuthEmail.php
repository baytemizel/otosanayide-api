<?php

namespace App\Jobs;

use App\Support\I18n;
use App\Support\Origin;
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
        public readonly ?string $origin = null,
    ) {
        $this->afterCommit();
    }

    public function handle(): void
    {
        if ($this->to === null) {
            return;
        }
        $origin = rtrim($this->linkOrigin(), '/');
        // Web ve portal bu iki yolu saglar (Sozlesme Eki v1 madde 4).
        [$subject, $body] = match ($this->kind) {
            'verify_email' => ['E-posta adresini doğrula', $origin.'/giris/eposta-dogrula?token='.$this->token],
            'reset_password' => ['Şifre sıfırlama', $origin.'/giris/sifre-sifirla?token='.$this->token],
            'email_change_requested' => ['E-posta değişikliği istendi', I18n::t('Otosanayide hesabının e-posta adresini değiştirme isteği alındı. Değişiklik yeni adresteki bağlantı onaylanınca uygulanır. Bu isteği sen yapmadıysan şifreni hemen değiştir: {link}', ['link' => $origin.'/giris'], $this->locale)],
            'email_changed' => ['E-posta adresin değiştirildi', I18n::t('Otosanayide hesabının e-posta adresi değiştirildi ve bütün oturumlar kapatıldı. Bu işlemi sen yapmadıysan bizimle iletişime geç.', [], $this->locale)],
            'team_invite' => ['İşletme ekibine davet', I18n::t('Otosanayide üzerinde bir işletme ekibine çalışan olarak davet edildin. Daveti bu e-posta adresiyle açtığın ve doğruladığın hesapla 7 gün içinde kabul edebilirsin: {link}', ['link' => $origin.'/ekip/davet?token='.$this->token], $this->locale)],
            'password_changed' => ['Şifren değiştirildi', I18n::t('Otosanayide hesabının şifresi değiştirildi. Bu işlemi sen yapmadıysan hemen şifre sıfırlama bağlantısı iste: {link}', ['link' => $origin.'/giris'], $this->locale)],
            default => ['Otosanayide hesabın', I18n::t('Bu e-posta adresiyle zaten bir hesabın var. Şifreni unuttuysan giriş ekranından sıfırlayabilirsin.', [], $this->locale)],
        };
        Mail::raw($body, function ($message) use ($subject): void {
            $message->to($this->to)->subject(I18n::t($subject, [], $this->locale));
        });
    }

    /** Kuyruktaki deger gonderim aninda yeniden denetlenir: yalniz yapilandirilmis web veya portal origin'i. */
    private function linkOrigin(): string
    {
        $web = (string) config('otosanayide.public_web_origin');

        return $this->origin !== null && Origin::isPortal($this->origin) ? $this->origin : $web;
    }
}
