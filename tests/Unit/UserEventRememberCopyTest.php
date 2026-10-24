<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Идемпотентная запись «пользователь ↔ событие» — один владелец.
 *
 * Метод стоял в двух параллельных моделях (`EventFavorite::remember()` и
 * `EventFollow::subscribe()`) побайтово одинаково, и совпадение держалось на
 * предложении в докблоке: «та же защита от гонки, что у `EventFavorite`».
 * Третья модель (`BonusAccount`) ссылалась на них же — три реализации, два
 * перекрёстных обещания.
 *
 * Сторож ловит форму, в которой копия существовала: `try` + `self::create` +
 * `catch` + `firstOrFail` по паре `user_id`/`event_id`. Остальные ловцы
 * `UniqueConstraintViolationException` в модуле (BonusAccount, ReferralCode,
 * Referral, FollowNotification, RaffleEntry) под шаблон не попадают намеренно:
 * у них другая форма — свой ключ, своя ветка конфликта, у RaffleEntry ещё и
 * `null` вместо строки. Их объединение — отдельное решение, и оно потребует
 * общего владельца с параметром «что делать при конфликте».
 *
 * Проверка ищет КОД, а не упоминания: докблоки вырезаются, иначе объяснение
 * «почему не здесь» само считалось бы нарушением.
 */
final class UserEventRememberCopyTest extends TestCase
{
    private const OWNER = '/Loyalty/Models/Concerns/RemembersUserEvent.php';

    public function testTheRaceSafeUserEventInsertLivesOnlyInItsOwner(): void
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            if (str_ends_with($path, self::OWNER)) {
                continue;
            }

            $code = $this->code((string) file_get_contents($path));

            // Обе половины правила в одном файле: ловля нарушения уникального
            // ключа И выбор существующей строки по паре пользователь/событие.
            // Имя класса в `catch` ищем без ведущего `\` и без скобок: копия
            // может написать его и полностью квалифицированным
            // (`catch (\Illuminate\...\UniqueConstraintViolationException)`),
            // и правило от этого не перестаёт быть копией.
            if (str_contains($code, 'catch (')
                && str_contains($code, 'UniqueConstraintViolationException')
                && str_contains($code, "->where('user_id'")
                && str_contains($code, "->where('event_id'")
                && str_contains($code, 'firstOrFail()')) {
                $offenders[] = $this->relative($path);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'вторая копия идемпотентной записи «пользователь ↔ событие»',
        );
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
