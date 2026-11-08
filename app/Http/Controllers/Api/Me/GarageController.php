<?php

namespace App\Http\Controllers\Api\Me;

use App\Domain\Garage\GarageRecycle;
use App\Domain\Garage\GarageRepository;
use App\Domain\Garage\GarageSchemas;
use App\Exceptions\HttpError;
use App\Http\Api;
use App\Support\Body;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Throwable;

final class GarageController
{
    use Guards;

    public function __construct(private readonly GarageRepository $garage) {}

    private function backupEnabled(): void
    {
        if (! config('otosanayide.flags.mobile_garage_backup')) {
            throw new HttpError(503, 'Garaj yedekleme henüz etkin değil.');
        }
    }

    public function show(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $u = $this->verified($request);
        $this->backupEnabled();

        return Api::json(['backup' => $this->garage->readBackup($u->uid)]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->noQuery($request);
        $u = $this->verified($request);
        $this->backupEnabled();
        $this->jsonPrefix($request);
        $value = Body::json($request, GarageSchemas::TRANSFER_BYTES + 512);
        $input = GarageSchemas::backupWrite()->parse($value);
        unset($input);
        try {
            $backup = $this->garage->writeBackup($u->uid, $value, config('otosanayide.flags.shared_garage'));
        } catch (Throwable $e) {
            if ($e instanceof RuntimeException && $e->getMessage() === 'shared_disabled') {
                throw new HttpError(503, 'Ortak garaj geçişi henüz etkin değil.');
            }
            throw new HttpError(409, 'Garaj yedeği değişti veya doğrulanamadı. Yeniden yükle.');
        }

        return Api::json(['backup' => $backup]);
    }

    public function garageOptions(Request $request): Response
    {
        return $this->preflight($request, 'GET, PUT, OPTIONS');
    }

    private function lifecycle(Request $request, bool $write): JsonResponse
    {
        $this->noQuery($request);
        $u = $this->verified($request);
        $this->backupEnabled();
        $input = null;
        if ($write) {
            $this->jsonPrefix($request);
            try {
                $bytes = Body::bytes($request, 512);
            } catch (HttpError) {
                throw new HttpError(413, 'İstek çok büyük.');
            }
            try {
                $input = GarageRecycle::action()->parse(Body::decode($bytes));
            } catch (Throwable) {
                throw new HttpError(400, 'Geçersiz istek.');
            }
        }
        try {
            $status = $this->garage->manageRecycle($u->uid, $input);
        } catch (Throwable) {
            throw new HttpError(409, 'Yedek durumu değişti veya bu işlem için uygun değil. Yeniden kontrol et.');
        }

        return Api::json(['status' => $status]);
    }

    public function lifecycleShow(Request $request): JsonResponse
    {
        return $this->lifecycle($request, false);
    }

    public function lifecycleStore(Request $request): JsonResponse
    {
        return $this->lifecycle($request, true);
    }

    public function lifecycleOptions(Request $request): Response
    {
        return $this->preflight($request, 'GET, POST, OPTIONS');
    }

    private function migrate(Request $request, bool $write): JsonResponse
    {
        $this->noQuery($request);
        $u = $this->verified($request);
        if (! config('otosanayide.flags.shared_garage') || ! config('otosanayide.flags.mobile_garage_backup')) {
            throw new HttpError(503, 'Ortak garaj geçişi henüz etkin değil.');
        }
        $conflict = 'Garaj kayıtları değişti veya birleştirilemiyor. Önizlemeyi yeniden yükle.';
        if ($write) {
            try {
                $this->garage->activate($u->uid, Body::json($request));
            } catch (Throwable) {
                throw new HttpError(409, $conflict);
            }
        }
        try {
            return Api::json($this->garage->preview($u->uid));
        } catch (Throwable) {
            throw new HttpError(409, $conflict);
        }
    }

    public function migrateShow(Request $request): JsonResponse
    {
        return $this->migrate($request, false);
    }

    public function migrateStore(Request $request): JsonResponse
    {
        return $this->migrate($request, true);
    }
}
