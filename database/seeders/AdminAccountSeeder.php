<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ilk yonetici: API'de yonetici atama ucu yoktur (kaynakta Firebase claim'leri elle veriliyordu).
 * Kimlik yalniz ortamdan gelir, repoya girmez:
 *   SEED_ADMIN_EMAIL=... SEED_ADMIN_PASSWORD=... php artisan db:seed --class=AdminAccountSeeder --force
 * Var olan hesapta sifre degismez, yalniz yetki verilir. MFA kurulumu giristen sonra kullaniciya aittir.
 */
final class AdminAccountSeeder extends Seeder
{
    public function run(): void
    {
        $email = getenv('SEED_ADMIN_EMAIL') ?: null;
        $password = getenv('SEED_ADMIN_PASSWORD') ?: null;
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SEED_ADMIN_EMAIL gerekli.');
        }
        $email = (string) normalizer_normalize(mb_strtolower(trim($email), 'UTF-8'), \Normalizer::FORM_C);
        $now = gmdate('Y-m-d H:i:s').'.000';
        $claims = ['is_admin' => true, 'is_brand_finance' => filter_var(getenv('SEED_ADMIN_FINANCE') ?: 'false', FILTER_VALIDATE_BOOLEAN), 'is_account_erasure_operator' => false];
        DB::transaction(function () use ($email, $password, $now, $claims): void {
            $existing = DB::table('auth_accounts')->where('email', $email)->lockForUpdate()->first();
            if ($existing !== null) {
                DB::table('auth_accounts')->where('id', $existing->id)->update($claims);

                return;
            }
            if (! is_string($password) || strlen($password) < 12) {
                throw new RuntimeException('Yeni hesap icin en az 12 karakterli SEED_ADMIN_PASSWORD gerekli.');
            }
            DB::table('auth_accounts')->insert(['id' => Str::random(28), 'email' => $email, 'email_verified_at' => $now, 'password_hash' => Hash::make($password), 'password_changed_at' => $now, 'tokens_valid_after' => $now] + $claims);
        });
        $this->command?->info('Yonetici hazir.');
    }
}
