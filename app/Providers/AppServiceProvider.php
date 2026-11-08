<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\AccessToken;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(AccessToken::class);
        Relation::enforceMorphMap(['account' => Account::class]);

        // Kimlikler buyuk/kucuk harf duyarli; Firestore belge kimligi bicimleri korunur.
        Blueprint::macro('cid', function (string $column, int $length = 128): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->string($column, $length)->charset('utf8mb4')->collation('utf8mb4_bin');
        });
        Blueprint::macro('ms', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->dateTime($column, 3);
        });
        Blueprint::macro('sha256', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->char($column, 64)->charset('ascii')->collation('ascii_bin');
        });
    }
}
