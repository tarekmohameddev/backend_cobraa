<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * DomPDF has no Arabic bidirectional shaping. Convert logical Arabic
 * into presentation-form glyphs that render correctly LTR.
 */
class ArabicPdfText
{
    private static $arabic = null;

    public static function shape(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        if (!preg_match('/\p{Arabic}/u', $text)) {
            return $text;
        }

        return self::engine()->utf8Glyphs($text, 200, false);
    }

    /**
     * @return \ArPHP\I18N\Arabic
     */
    private static function engine()
    {
        if (self::$arabic !== null) {
            return self::$arabic;
        }

        if (!class_exists(\ArPHP\I18N\Arabic::class)) {
            require_once dirname(__DIR__, 2) . '/vendor/khaled.alshamaa/ar-php/src/Arabic.php';
        }

        return self::$arabic = new \ArPHP\I18N\Arabic();
    }
}
