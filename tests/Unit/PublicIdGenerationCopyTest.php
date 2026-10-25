<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * `public_id` генерируется у КАЖДОЙ модели, чья таблица его требует.
 *
 * ЧТО БЫЛО. `HasPublicId` появился как замена тридцати двум побайтовым копиям
 * `booted()` с хуком `creating`. Замена прошла не до конца: семь моделей
 * остались вне трейта, при том что их таблицы объявляют
 * `public_id CHAR(26) NOT NULL` без значения по умолчанию. Из семи шесть живых
 * (`Page`, `EventCategory`, `SeatHold`, `ApiKey`, `Ticket`, `TicketScan`) и одна
 * мёртвая (дубликат `Modules\Organizations\Models\Organization`).
 *
 * ЧЕМ ЭТО ПЛОХО. Соединение работает в strict-режиме (`config/database.php`,
 * `'strict' => true`), поэтому `EventCategory::create(['name' => …, 'slug' => …])`
 * падает не «мягко», а запросом:
 *
 *     SQLSTATE[HY000]: General error: 1364
 *     Field 'public_id' doesn't have a default value
 *
 * Проверено на живой базе. Сегодня это не видно только потому, что оба
 * существующих места создания (`SeatHold` в `CartItemService`, `Ticket` в
 * `TicketService`) передают `public_id` руками. То есть инвариант держится не
 * владельцем, а дисциплиной двух вызывающих: третий вызов
 * `TicketScan::create()` или `Page::create()` — и 500.
 *
 * ПОЧЕМУ ПРОВЕРКА ИДЁТ ПО СХЕМЕ, А НЕ ПО `$fillable`. `$fillable` — это про
 * массовое заполнение, а трейт присваивает `$model->public_id` напрямую и в
 * `$fillable` не нуждается вовсе. Модель без `public_id` в `$fillable`, но с
 * колонкой в таблице падала бы точно так же, и проверка по `$fillable` её бы
 * пропустила. Поэтому источник истины — канон схемы
 * (`nabilet_core_spec/migrations.sql`), а связь «модель → таблица» берётся из
 * явного `protected $table`, который объявлен у всех проверяемых моделей.
 *
 * Модель без `protected $table` не проверяется: выводить имя таблицы из класса
 * значило бы завести второй плюрализатор, который разойдётся с ларавелевским.
 * Это записано, чтобы отсутствие проверки не выглядело забывчивостью.
 */
final class PublicIdGenerationCopyTest extends TestCase
{
    private const TRAIT = 'HasPublicId';

    /**
     * Ни одной модели вне трейта, если её таблица требует `public_id`.
     */
    public function testEveryModelWhoseTableNeedsAPublicIdUsesTheTrait(): void
    {
        $tables = $this->tablesRequiringPublicId();

        $this->assertTrue(
            isset($tables['event_categories']),
            'схема не прочиталась: `event_categories.public_id` обязан быть в списке',
        );

        $offenders = [];

        foreach ($this->modelFiles() as $path) {
            $code = $this->code((string) file_get_contents($path));

            if (! preg_match("~protected \\\$table\\s*=\\s*'([a-z0-9_]+)'~", $code, $match)) {
                continue;
            }

            if (! isset($tables[$match[1]])) {
                continue;
            }

            if (preg_match('~\b' . self::TRAIT . '\b~', $code) === 1) {
                continue;
            }

            $offenders[] = $this->relative($path) . ' → ' . $match[1];
        }

        $this->assertSame(
            [],
            $offenders,
            'таблица требует public_id, а модель его не генерирует: добавьте use HasPublicId; '
            . 'иначе create() упадёт 1364 «Field public_id doesn\'t have a default value»',
        );
    }

    /**
     * Владелец на месте: трейт обязан заполнять `public_id` при создании.
     *
     * Без этой половины сторож выше зелен и тогда, когда трейт переименован или
     * из него убран хук: модели «используют трейт», а значение не появляется.
     */
    public function testTheTraitFillsTheIdentifierOnCreate(): void
    {
        $trait = dirname(__DIR__, 2) . '/app/Modules/Core/Models/Concerns/HasPublicId.php';
        $code = $this->code((string) file_get_contents($trait));

        // Имя хука проверяется СЛОВОМ, а не подстрокой: `assertStringContainsString`
        // пропускал `bootHasPublicIdX`, потому что искомая строка входит в него.
        // Ровно на этом первая редакция этого теста была зелена при сломанном
        // трейте — Laravel зовёт `bootHasPublicId()`, и опечатка в имени
        // оставляет `public_id` пустым.
        $this->assertSame(
            1,
            preg_match('~function bootHasPublicId\s*\(\s*\)~', $code),
            'трейт обязан объявлять именно bootHasPublicId(): Laravel зовёт его по имени',
        );
        $this->assertStringContainsString('static::creating', $code);
        $this->assertStringContainsString('PublicId::generate()', $code);

        // Проверка на пустую строку, а не только на null: в `CHAR(26) NOT NULL`
        // `''` выглядит как настоящий идентификатор, но им не является.
        $this->assertStringContainsString("\$model->public_id === ''", $code);
    }

    /**
     * Таблицы канона схемы, у которых есть `public_id`.
     *
     * @return array<string, true>
     */
    private function tablesRequiringPublicId(): array
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/nabilet_core_spec/migrations.sql');

        preg_match_all(
            '~CREATE TABLE IF NOT EXISTS\s+(\w+)\s*\((.*?)\n\)\s*ENGINE~s',
            $sql,
            $matches,
            PREG_SET_ORDER,
        );

        $tables = [];

        foreach ($matches as $match) {
            if (preg_match('~^\s*public_id\s~m', $match[2]) === 1) {
                $tables[$match[1]] = true;
            }
        }

        return $tables;
    }

    /**
     * Файлы моделей: `app/Modules/**\/Models/*.php`, кроме трейтов и концернов.
     *
     * @return list<string>
     */
    private function modelFiles(): array
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2) . '/app/Modules');
        $paths = [];

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($rii as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            if (preg_match('~/Models/[^/]+\.php$~', $path) !== 1) {
                continue;
            }

            $paths[] = $path;
        }

        sort($paths);

        return $paths;
    }

    /** Только код: блочные и строчные комментарии вырезаны. */
    private function code(string $source): string
    {
        return (string) preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~'], '', $source);
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen(dirname(__DIR__, 2)) + 1));
    }
}
