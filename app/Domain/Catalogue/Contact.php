<?php

namespace App\Domain\Catalogue;

/** packages/core/contact.ts. */
final class Contact
{
    public static function businessEmail(mixed $raw): ?string
    {
        if (! is_string($raw) || preg_match('/[\r\n\x{0000}-\x{001f}\x{007f}]/u', $raw) === 1) {
            return null;
        }
        $address = mb_strtolower(\App\Support\Js::trim($raw), 'UTF-8');
        if (\App\Support\Js::length($address) > 254 || preg_match("/^[a-z0-9.!#$%&'*+\\/=?^_`{|}~-]+@[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\\.[a-z]{2,}$/iD", $address) !== 1) {
            return null;
        }

        return $address;
    }

    /** @return array{e164:?string,mobile:bool,dial?:string,national?:true}|null */
    public static function phone(?string $raw): ?array
    {
        if ($raw === null || $raw === '' || preg_match('/^[+\d\s().-]+$/D', $raw) !== 1) {
            return null;
        }
        $digits = (string) preg_replace('/[\s().-]/', '', $raw);
        if (str_starts_with($digits, '00')) {
            $digits = '+'.substr($digits, 2);
        }
        if (str_starts_with($digits, '+') && ! str_starts_with($digits, '+90')) {
            return preg_match('/^\+[1-9]\d{7,14}$/D', $digits) === 1 ? ['e164' => $digits, 'mobile' => false] : null;
        }
        $digits = (string) preg_replace('/^\+90/', '', $digits);
        if (strlen($digits) === 12 && str_starts_with($digits, '90')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        if (preg_match('/^90444\d{4}$/D', $digits) === 1) {
            $digits = substr($digits, 2);
        }
        if (preg_match('/^444\d{4}$/D', $digits) === 1) {
            return ['e164' => null, 'dial' => $digits, 'mobile' => false, 'national' => true];
        }
        if (preg_match('/^[23458]\d{9}$/D', $digits) !== 1) {
            return null;
        }

        return ['e164' => '+90'.$digits, 'mobile' => $digits[0] === '5'];
    }
}
