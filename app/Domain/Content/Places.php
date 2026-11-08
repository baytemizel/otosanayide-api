<?php

namespace App\Domain\Content;

use App\Domain\Account\AccountFence;
use App\Domain\Auth\Actor;
use App\Exceptions\HttpError;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** /api/businesses/places: Google Places Text Search (New) sozlesmesi. */
final class Places
{
    private const URL = 'https://places.googleapis.com/v1/places:searchText';

    public static function apiKey(): ?string
    {
        $key = config('otosanayide.google_places_api_key');

        return is_string($key) && trim($key) !== '' ? $key : null;
    }

    private static function resolve(Actor $u, array $input): array
    {
        $bid = $input['businessId'];
        $b = Docs::business(Rows::business($bid, true));
        $m = Docs::membership(Rows::member($u->uid, $bid, true));
        if ($b === null || ($b['sample'] ?? null) === true || (! $u->admin && ! (($b['ownership'] ?? null) === 'verified' && ($b['ownerUid'] ?? null) === $u->uid && ($m['userId'] ?? null) === $u->uid && ($m['businessId'] ?? null) === $bid && ($m['role'] ?? null) === 'owner' && ($m['active'] ?? null) === true))) {
            throw new HttpError(403, 'Bu işletme için yetkiniz yok.');
        }
        $subjects = [$u->uid];
        if (($b['ownerUid'] ?? null) !== null) {
            $subjects[] = Z::string()->min(1)->max(128)->regex('/^[A-Za-z0-9_-]+$/')->parse($b['ownerUid']);
        } elseif (($b['ownership'] ?? null) === 'verified') {
            throw new HttpError(409, 'İşlem tamamlanamadı. Daha sonra tekrar deneyin.');
        }
        foreach (array_values(array_unique($subjects)) as $uid) {
            AccountFence::assertWritable($uid);
        }

        return ['subjects' => $subjects, 'sourceDigest' => Digest::of(['ownerUid' => $b['ownerUid'] ?? null, 'ownership' => $b['ownership'] ?? null, 'membership' => $m, 'query' => $input['query']])];
    }

    /** @param array{businessId:string,query:string,locale:string} $input */
    public static function search(Actor $u, array $input): array
    {
        $key = self::apiKey();
        if ($key === null) {
            throw new RuntimeException('places_unconfigured');
        }
        $policy = [
            'kind' => 'places',
            'bindingDigest' => Digest::of(['provider' => 'google_places', 'version' => 'v1', 'method' => 'searchText']),
            'resourceKey' => Digest::of(['kind' => 'places', 'userId' => $u->uid] + $input),
            'resolve' => static fn (): array => self::resolve($u, $input),
        ];
        $result = ExternalCalls::call($policy, static fn (): array => self::google($input['query'], $input['locale'], $key));
        Tx::run($policy['resolve']);

        return $result;
    }

    /** @return array{places:list<array<string,mixed>>,source:string,verification:string} */
    public static function google(string $query, string $locale, string $apiKey): array
    {
        if (trim($apiKey) === '') {
            throw new RuntimeException('places_unconfigured');
        }
        if (\App\Support\Js::length(\App\Support\Js::trim($query)) < 3 || \App\Support\Js::length($query) > 200) {
            throw new RuntimeException('places_query');
        }
        $response = Http::timeout(8)->connectTimeout(8)->withoutRedirecting()
            ->withHeaders(['X-Goog-Api-Key' => $apiKey, 'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress,places.location,places.addressComponents'])
            ->withOptions(['stream' => true])
            ->asJson()
            ->post(self::URL, ['textQuery' => \App\Support\Js::trim($query), 'languageCode' => $locale, 'regionCode' => 'TR', 'pageSize' => 5]);
        if (! $response->successful() || ! str_contains((string) $response->header('Content-Type'), 'application/json')) {
            throw new RuntimeException('places_unavailable');
        }
        $body = $response->toPsrResponse()->getBody();
        $raw = '';
        while (! $body->eof()) {
            $raw .= $body->read(8192);
            if (strlen($raw) > 128000) {
                throw new RuntimeException('İstek çok büyük.');
            }
        }
        $value = self::googleSchema()->parse(json_decode($raw, false, 512, JSON_THROW_ON_ERROR));
        $places = [];
        foreach ($value['places'] as $p) {
            $turkey = false;
            foreach ($p['addressComponents'] as $c) {
                if (in_array('country', $c['types'], true) && ($c['shortText'] ?? null) === 'TR') {
                    $turkey = true;
                }
            }
            if ($turkey && $p['location']['latitude'] >= 35.7 && $p['location']['latitude'] <= 42.3 && $p['location']['longitude'] >= 25.5 && $p['location']['longitude'] <= 45) {
                $places[] = (object) ['id' => $p['id'], 'name' => $p['displayName']['text'] ?? '', 'address' => $p['formattedAddress'], 'latitude' => $p['location']['latitude'], 'longitude' => $p['location']['longitude']];
            }
        }

        return Z::strictObject([
            'places' => Z::array(Z::strictObject([
                'id' => Z::string()->min(1)->max(300),
                'name' => Z::string()->max(300),
                'address' => Z::string()->min(5)->max(500),
                'latitude' => Z::number()->min(35.7)->max(42.3),
                'longitude' => Z::number()->min(25.5)->max(45),
            ]))->max(5),
            'source' => Z::literal('google_places'),
            'verification' => Z::literal('candidate'),
        ])->parse((object) ['places' => $places, 'source' => 'google_places', 'verification' => 'candidate']);
    }

    private static function googleSchema(): Z
    {
        return Z::object([
            'places' => Z::array(Z::object([
                'id' => Z::string()->min(1)->max(300),
                'displayName' => Z::object(['text' => Z::string()->max(300)])->optional(),
                'formattedAddress' => Z::string()->min(5)->max(500),
                'location' => Z::object(['latitude' => Z::number(), 'longitude' => Z::number()]),
                'addressComponents' => Z::array(Z::object(['shortText' => Z::string()->optional(), 'types' => Z::array(Z::string())]))->max(50),
            ]))->max(5)->default([]),
        ]);
    }
}
