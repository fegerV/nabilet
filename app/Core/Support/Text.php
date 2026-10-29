<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

/**
 * Примитивы работы с текстом, которые в проекте иначе копируют «руками».
 *
 * Единственный владелец класса управляющих символов C0 + DEL. Раньше этот
 * класс был вписан в две точки (StorefrontService::text и MatrixMessage::sanitize)
 * и разошёлся по флагу `u`: у Matrix он намеренно убран, потому что байтовый
 * класс безопасен для потенциально невалидной UTF-8 из БД (с флагом `u`
 * preg_replace вернул бы null на всём выражении и обнулил текст). Флаг теперь
 * передаётся параметром — расхождение видно в одном месте, а не спрятано в
 * двух копиях регулярки.
 */
final class Text
{
    /** Управляющие символы C0 (кроме табуляции/перевода строки/возврата каретки) и DEL. */
    public const CONTROL_CHARS = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    /**
     * Убрать управляющие символы из текста, оставив читабельное содержимое.
     *
     * @param  bool  $unicode  true → добавить флаг `u` (строгая UTF-8, для уже
     *                         проверенного текста). false → байтовый класс, не
     *                         ломается на невалидной UTF-8.
     */
    public static function stripControlChars(string $text, bool $unicode): string
    {
        $pattern = $unicode ? self::CONTROL_CHARS . 'u' : self::CONTROL_CHARS;

        return preg_replace($pattern, '', $text) ?? '';
    }
}
