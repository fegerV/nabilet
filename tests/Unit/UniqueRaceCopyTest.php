<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Гонка на уникальном индексе — это `firstOrCreate()` / `createOrFirst()`.
 *
 * ЧТО БЫЛО. Свой `try { create } catch (UniqueConstraintViolationException)`
 * стоял в десяти местах, и в шести из них он дословно переписывал метод
 * Eloquent: переспросить по тем же атрибутам и вернуть найденную строку. Хуже
 * того, версия в `Concerns\RemembersUserEvent` объясняла в докблоке, что
 * `firstOrCreate()` «не подходит, потому что между SELECT и INSERT влезает
 * второй запрос». Для Laravel 11+ это неверно: `firstOrCreate()` зовёт
 * `createOrFirst()`, который ловит нарушение ключа. То есть ложное утверждение
 * в прозе и было причиной копии.
 *
 * ЧЕМ ЭТО ОПАСНО, А НЕ ПРОСТО МНОГОСЛОВНО. Своя версия отличается от
 * фреймворковой в одном невидимом месте: `createOrFirst()` оборачивает вставку
 * в `withSavepointIfNeeded()`. Без savepoint отвергнутая вставка оставляет
 * объемлющую транзакцию в состоянии, из которого её нельзя продолжить на
 * PostgreSQL, а на MySQL разница не видна — то есть копия выглядит работающей
 * ровно до смены СУБД. Плюс каждая копия — свой ответ на вопрос «а если
 * конфликт по ДРУГОМУ индексу», и ответы уже разошлись.
 *
 * ЧТО ДЕРЖИТ ЭТОТ СТОРОЖ. `UniqueConstraintViolationException` в `app/`
 * разрешено упоминать только в трёх файлах, и у каждого причина записана в
 * коде рядом:
 *
 *   1. `Core/Http/Middleware/EnsureIdempotency.php` — query builder, а не
 *      Eloquent: `createOrFirst()` недоступен, а `insertOrIgnore()` шире по
 *      смыслу (MySQL `INSERT IGNORE` гасит и обрезанные данные тоже).
 *   2. `Loyalty/Models/ReferralCode.php` — у таблицы ДВА уникальных индекса;
 *      `catch` здесь про повтор генерации кода, а не про гонку.
 *   3. `Loyalty/Services/RaffleService.php` — тоже два индекса, и ни один
 *      набор атрибутов не покрывает оба: `createOrFirst()` перевыбросил бы
 *      исключение вместо `RAFFLE_ALREADY_DRAWN`.
 *
 * Новый `catch` в любом другом файле — красный. Это и есть цель: не запретить
 * приём, а заставить прийти сюда и объяснить, почему фреймворк не подходит.
 */
final class UniqueRaceCopyTest extends TestCase
{
    /**
     * Файлы, которым разрешено ловить нарушение уникального ключа.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'app/Core/Http/Middleware/EnsureIdempotency.php',
        'app/Modules/Loyalty/Models/ReferralCode.php',
        'app/Modules/Loyalty/Services/RaffleService.php',
    ];

    public function testOnlyTheDocumentedExceptionsCatchAUniqueViolation(): void
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            $code = $this->code((string) file_get_contents($path));

            if (! str_contains($code, 'UniqueConstraintViolationException')) {
                continue;
            }

            $relative = $this->relative($path);

            if (! in_array($relative, self::ALLOWED, true)) {
                $offenders[] = $relative;
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            'нарушение уникального ключа ловят вручную: берите firstOrCreate()/createOrFirst() '
            . '(или добавьте файл в ALLOWED с причиной в коде)',
        );
    }

    /**
     * Вторая половина: белый список не должен зарастать мёртвыми записями.
     *
     * Файл из списка, в котором `catch` больше нет, — это разрешение, которым
     * перестали пользоваться. Оставленный «на всякий случай», он тихо разрешит
     * копию тому, кто придёт следующим.
     */
    public function testTheAllowListHasNoStaleEntries(): void
    {
        $stale = [];

        foreach (self::ALLOWED as $relative) {
            $path = dirname(__DIR__, 2) . '/' . $relative;

            if (! is_file($path)) {
                $stale[] = $relative . ' (файла нет)';

                continue;
            }

            $code = $this->code((string) file_get_contents($path));

            if (! str_contains($code, 'UniqueConstraintViolationException')) {
                $stale[] = $relative . ' (исключение больше не ловится)';
            }
        }

        $this->assertSame([], $stale, 'из ALLOWED пора убрать эти файлы');
    }

    /** @return list<string> */
    private function appFiles(): array
    {
        $root = dirname(__DIR__, 2) . '/app';
        $paths = [];

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($rii as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = str_replace('\\', '/', $file->getPathname());
            }
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
