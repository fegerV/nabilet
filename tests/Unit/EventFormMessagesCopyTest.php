<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Словарь формы мероприятия — один владелец, а не копии в двух Request'ах.
 *
 * Сообщения `messages()` стояли в `StoreEventRequest` и `UpdateEventRequest`
 * побайтово одинаково, и вместе с ними дублем стояло правило `poster_file`
 * (`mimes:jpg,jpeg,png,webp`, `max:5120`). Пара «правило + объяснение» —
 * единое целое: текст обязан называть то число, которое enforce'ит правило.
 *
 * Сторож держит оба конца: и ключ сообщения `slug.unique`, и числовой потолок
 * `max:5120`. Появись вторая копия любого из них — расхождение снова станет
 * возможным.
 *
 * Проверка ищет КОД, а не упоминания: докблоки вырезаются, иначе объяснение
 * «почему не здесь» само считалось бы нарушением.
 */
final class EventFormMessagesCopyTest extends TestCase
{
    private const OWNER = '/Events/Http/Requests/Concerns/EventFormMessages.php';

    public function testTheFormMessagesLiveOnlyInTheirOwner(): void
    {
        $this->assertSame(
            [],
            $this->filesWhere("~'slug\\.unique'~"),
            'вторая копия сообщений формы мероприятия',
        );
    }

    public function testThePosterLimitIsDeclaredOnlyByItsOwner(): void
    {
        $this->assertSame(
            [],
            $this->filesWhere('~max:5120~'),
            'вторая копия потолка афиши: берите EventFormMessages::POSTER_MAX_KB',
        );
    }

    /**
     * Файлы `app/`, в коде которых встречается шаблон, кроме владельца.
     *
     * @return list<string>
     */
    private function filesWhere(string $pattern): array
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            if (str_ends_with($path, self::OWNER)) {
                continue;
            }

            if (preg_match($pattern, $this->code((string) file_get_contents($path))) === 1) {
                $offenders[] = $this->relative($path);
            }
        }

        return $offenders;
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
