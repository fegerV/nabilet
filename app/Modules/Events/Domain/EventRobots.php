<?php

declare(strict_types=1);

namespace Nabilet\Modules\Events\Domain;

/**
 * Значения `<meta name="robots">` для страницы мероприятия (SEO-аудит P-23).
 *
 * ЗАЧЕМ КЛАСС. `events.robots` — свободный `VARCHAR(255)`, и его значение уходит
 * в `<meta name="robots">` как есть. Пока поле никто не мог записать через API
 * (правила для него не было ни в `StoreEventRequest`, ни в `UpdateEventRequest`),
 * это было безобидно. Как только поле становится управляемым, «как есть»
 * превращается в «неконтролируемое управление индексацией»: опечатка
 * (`noindexs`), русское слово, фраза целиком или директивы с параметрами
 * (`max-snippet:160`) — всё это робот либо проигнорирует целиком, либо
 * разберёт иначе, чем подразумевал администратор, и заметить это можно только
 * в Search Console, то есть уже после выпадения страниц из индекса.
 *
 * ПОЧЕМУ ЗДЕСЬ, А НЕ В ПРАВИЛАХ ВАЛИДАЦИИ. Токены нужны ДВУМ сторонам:
 * валидатору запроса (что принимать) и рендеру страницы (что склеивать с
 * автоматическим `noindex`). Две копии списка разошлись бы при первом же
 * добавлении директивы, и разошлись бы молча — валидатор пропускал бы то, что
 * рендер выбрасывает, или наоборот.
 *
 * Класс намеренно БЕЗ зависимостей (purity-гейт требует, чтобы каталог Domain
 * грузился без `vendor/`), поэтому это чистые строковые операции без
 * `config()` и исключений фреймворка.
 */
final class EventRobots
{
    /**
     * Директивы, которые допустимы в поле.
     *
     * Только «простые» токены без параметров. Формы вида `max-snippet:160` и
     * `unavailable_after:…` не включены НАМЕРЕННО: у них есть синтаксис со
     * значением, и «полуподдержка» (принять строку, но не проверить значение)
     * вернула бы ровно ту же проблему, ради которой этот класс и появился.
     * Если они понадобятся, добавлять их нужно вместе с разбором значения.
     *
     * `none` — официальный синоним `noindex, nofollow`, `all` — синоним
     * `index, follow`; они оставлены, потому что их понимают все роботы и люди
     * пишут именно их.
     */
    public const ALLOWED = [
        'all',
        'none',
        'index',
        'noindex',
        'follow',
        'nofollow',
        'noarchive',
        'nosnippet',
        'noimageindex',
        'notranslate',
        'nocache',
    ];

    /**
     * Директивы, отменяющие друг друга. Из пары побеждает ЗАПРЕЩАЮЩАЯ.
     *
     * Спецификация robots.txt/meta требует от робота выбрать более
     * ограничительное значение, но полагаться на это не стоит: смысл поля в
     * том, чтобы запрет был исполнен, а не истолкован.
     */
    private const NEGATIONS = [
        'noindex' => 'index',
        'nofollow' => 'follow',
        'none' => 'all',
    ];

    /**
     * Разобрать значение поля в набор директив.
     *
     * Всё, что не входит в `ALLOWED`, отбрасывается: мусорный токен не должен
     * доезжать до мета-тега. Разделители — запятая и пробелы, регистр не важен
     * (роботы его не различают, а администратор может написать `NoIndex`).
     *
     * @return list<string> директивы в порядке появления, без повторов
     */
    public static function parse(string $value): array
    {
        $tokens = preg_split('/[\s,]+/', mb_strtolower(trim($value))) ?: [];
        $out = [];

        foreach ($tokens as $token) {
            if ($token === '' || ! in_array($token, self::ALLOWED, true)) {
                continue;
            }

            if (! in_array($token, $out, true)) {
                $out[] = $token;
            }
        }

        return $out;
    }

    /**
     * Проверить, что значение состоит ТОЛЬКО из допустимых директив.
     *
     * Пустая строка допустима — это «поле не заполнено», а не ошибка.
     */
    public static function isValid(string $value): bool
    {
        $tokens = preg_split('/[\s,]+/', mb_strtolower(trim($value))) ?: [];

        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }

            if (! in_array($token, self::ALLOWED, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Привести набор директив к каноническому виду или вернуть `null`.
     *
     * Снятие взаимно противоположных директив — не косметика: `index, noindex`
     * в одном теге это неопределённость, которую разные роботы разрешают
     * по-разному.
     *
     * @param  list<string>  $tokens
     */
    public static function normalize(array $tokens): ?string
    {
        $set = [];

        foreach ($tokens as $token) {
            if (in_array($token, self::ALLOWED, true) && ! in_array($token, $set, true)) {
                $set[] = $token;
            }
        }

        foreach (self::NEGATIONS as $forbidding => $permitting) {
            if (in_array($forbidding, $set, true)) {
                $set = array_values(array_filter(
                    $set,
                    static fn (string $token): bool => $token !== $permitting,
                ));
            }
        }

        return $set === [] ? null : implode(', ', $set);
    }
}
