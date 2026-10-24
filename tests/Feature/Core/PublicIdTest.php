<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Nabilet\Modules\Core\Support\PublicId;
use Tests\TestCase;

/**
 * `public_id` — один владелец правила и один формат на всю схему.
 *
 * ЗАЧЕМ. Выражение `Str::ulid()->toBase32()` было выписано в 46 файлах. Копии
 * не разошлись, но именно здесь расхождение не падает: колонка объявлена
 * `CHAR(26)`, а `Str::uuid()->toString()` даёт 36 символов, и в нестрогом
 * режиме MySQL молча обрезает значение до 26. Это уже случалось (см.
 * комментарий в `UserRepository`), и найти такой id потом невозможно — он
 * выглядит настоящим, но не совпадает ни с одним сгенерированным.
 *
 * Поэтому проверяются две вещи:
 *   1. формат: 26 символов, алфавит Crockford base32 — ровно `CHAR(26)`;
 *   2. что рукописных генераторов `public_id` в `app/` больше нет.
 */
final class PublicIdTest extends TestCase
{
    public function testGeneratedIdIsTwentySixCharacters(): void
    {
        $id = PublicId::generate();

        $this->assertSame(PublicId::LENGTH, strlen($id), 'длина не совпадает с CHAR(26)');
        $this->assertSame(26, strlen($id));
    }

    public function testAlphabetIsCrockfordBase32(): void
    {
        // ULID в base32 не содержит I, L, O и U — это не «стиль», а часть
        // алфавита Crockford: так буквы не путаются с 1, 0 и между собой.
        // Если однажды генератор сменят на hex или на uuid, тест упадёт здесь,
        // а не через месяц на обрезанном id в проде.
        for ($i = 0; $i < 20; $i++) {
            $id = PublicId::generate();

            $this->assertSame(
                1,
                preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id),
                'не Crockford base32: ' . $id
            );
        }
    }

    public function testIdsAreUnique(): void
    {
        $seen = [];

        for ($i = 0; $i < 200; $i++) {
            $seen[PublicId::generate()] = true;
        }

        $this->assertCount(200, $seen);
    }

    public function testIdsSortByCreationTime(): void
    {
        // ULID, а не UUID: он лексикографически сортируется по времени, поэтому
        // `public_id` годится как tie-breaker в ORDER BY без `created_at`.
        $first = PublicId::generate();
        usleep(2000);
        $second = PublicId::generate();

        $this->assertTrue($first < $second, 'более поздний id обязан быть больше: ' . $first . ' / ' . $second);
    }

    public function testNoFileWritesPublicIdByHand(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app'));
        foreach ($rii as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());
            if (str_ends_with($path, '/PublicId.php')) {
                continue;
            }

            // Только код: в докблоках это выражение цитируется как история.
            $code = (string) preg_replace(
                ['~/\*.*?\*/~s', '~//[^\n]*~'],
                '',
                (string) file_get_contents($path)
            );

            // Рукописный генератор public_id: присваивание ulid/uuid в колонку
            // public_id. Именно так и появлялась 46-я копия.
            if (preg_match("/public_id['\"]?\s*=>[^,\n]*(?:ulid|uuid)\s*\(/i", $code) === 1
                || preg_match('/\$[A-Za-z_]\w*->public_id\s*=\s*[^;]*(?:ulid|uuid)\s*\(/i', $code) === 1) {
                $offenders[] = str_replace('\\', '/', substr($path, strlen($root) + 1));
            }
        }

        $this->assertSame([], $offenders, 'рукописный public_id — вызывать PublicId::generate()');
    }
}
