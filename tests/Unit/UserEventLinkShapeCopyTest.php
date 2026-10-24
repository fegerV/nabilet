<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Форма строки «пользователь ↔ событие» — один владелец, а не две копии.
 *
 * `EventFavoriteResource` и `EventFollowResource` отдавали побайтово
 * одинаковый `toArray()`, и связывало их только предложение в докблоке:
 * «Форма намеренно совпадает с `EventFavoriteResource`». Правило жило в
 * прозе.
 *
 * Почему это правило, а не совпадение: экран кабинета показывает два списка
 * рядом, и разные формы заставляли бы фронтенд писать два парсера под одну и
 * ту же строку. Поле, добавленное в один ресурс и забытое во втором, разводит
 * контракт молча — и ломается это на клиенте.
 *
 * Владелец — `Concerns/SerializesUserEventLink`.
 *
 * Сторож ловит возврат копии по её самой узнаваемой строке: вложенный
 * `EventSummaryResource` именно в этом ключе. `SessionResource` тоже делает
 * `whenLoaded('event', ...)`, но отдаёт СВОЙ набор полей, а не
 * `EventSummaryResource`, — поэтому под шаблон не попадает, и это не случайность:
 * у сеанса другая форма, и сторож не должен её трогать.
 *
 * Проверка ищет КОД, а не упоминания: докблоки вырезаются, иначе объяснение
 * «почему не здесь» само считалось бы нарушением.
 */
final class UserEventLinkShapeCopyTest extends TestCase
{
    private const OWNER = '/Http/Resources/Concerns/SerializesUserEventLink.php';

    public function testTheUserEventLinkShapeLivesOnlyInItsOwner(): void
    {
        $this->assertSame(
            [],
            $this->filesWhere('~whenLoaded\(\s*[\'"]event[\'"]\s*,\s*fn\s*\(\s*\)\s*=>\s*new\s+EventSummaryResource~'),
            'вторая копия формы строки «пользователь ↔ событие»: берите SerializesUserEventLink',
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
