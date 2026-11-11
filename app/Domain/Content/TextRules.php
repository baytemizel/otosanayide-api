<?php

namespace App\Domain\Content;

/** 2. tur serbest metinleri: baglanti, e-posta ve telefon numarasi kabul edilmez; yerel moderasyon isaretlerse ret. */
final class TextRules
{
    public static function acceptable(string $text): bool
    {
        if ($text === '') {
            return true;
        }
        if (preg_match('/https?:\/\/|www\.|\b[\w.+-]+@[\w-]+\.[\w.]+\b/iu', $text) === 1) {
            return false;
        }
        if (preg_match('/(?:\d[\s().-]*){10,}/u', $text) === 1) {
            return false;
        }

        return ! Moderation::local($text)['flagged'];
    }
}
