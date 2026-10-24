<?php

declare(strict_types=1);

namespace Nabilet\Tests\Unit;

use Nabilet\Tests\Support\TestCase;

/**
 * Порядок подготовки payload мероприятия — один владелец, а не три копии.
 *
 * Одна и та же последовательность из четырёх шагов была выписана трижды:
 * в `StoreEventRequest::preparePayload()` и ДВАЖДЫ в `UpdateEventRequest` —
 * отдельно в `passedValidation()`, отдельно в `validated()`. Второй экземпляр
 * в Update и был дефектом: «Belt and braces» («если пайп проскочил
 * `passedValidation()`, всё равно подготовить payload при чтении») держится
 * только на том, что оба метода делают одно и то же. Пятый шаг, добавленный
 * в один и забытый в другом, молча отправлял бы сохранение карточки и ответ
 * API разными путями.
 *
 * Владелец — `Concerns/PreparesEventPayload::preparePayload()`.
 *
 * Сторож ловит возврат копии по её форме: если в одном файле встречаются И
 * первый шаг последовательности (`storeUploadedPoster`), И последний
 * (`stripStatusField`) — значит последовательность выписана здесь целиком, а
 * не вызвана у владельца. В владельце оба есть ровно потому, что он её и
 * определяет.
 *
 * Проверка ищет КОД, а не упоминания: докблоки вырезаются, иначе объяснение
 * «почему не здесь» само считалось бы нарушением.
 */
final class EventPayloadOrderCopyTest extends TestCase
{
    private const OWNER = '/Http/Requests/Concerns/PreparesEventPayload.php';

    public function testThePayloadSequenceIsWrittenOnlyByItsOwner(): void
    {
        $this->assertSame(
            [],
            $this->filesWhere('~storeUploadedPoster~', '~stripStatusField~'),
            'последовательность подготовки payload выписана здесь, а не вызвана у PreparesEventPayload::preparePayload()',
        );
    }

    /**
     * Файлы `app/`, в коде которых встречаются оба шаблона, кроме владельца.
     *
     * @return list<string>
     */
    private function filesWhere(string $first, string $last): array
    {
        $offenders = [];

        foreach ($this->appFiles() as $path) {
            if (str_ends_with($path, self::OWNER)) {
                continue;
            }

            $code = $this->code((string) file_get_contents($path));

            if (preg_match($first, $code) === 1 && preg_match($last, $code) === 1) {
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
