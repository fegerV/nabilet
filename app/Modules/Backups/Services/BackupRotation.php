<?php

declare(strict_types=1);

namespace Nabilet\Modules\Backups\Services;

/**
 * Политика ротации резервных копий: какие файлы считать старыми.
 *
 * ВЫНЕСЕНО ОТДЕЛЬНО И СДЕЛАНО ЧИСТЫМ НАМЕРЕННО. Решение «что удалять» —
 * единственное по-настоящему опасное место во всей очистке: ошибка здесь
 * стирает последнюю копию, когда она нужнее всего. Поэтому:
 *
 *   - класс не трогает файловую систему и сеть — его можно проверить в
 *     dependency-free наборе (`php tests/run.php`);
 *   - удаляются ТОЛЬКО файлы, чьё имя точно соответствует формату, который
 *     создаёт `YandexDiskBackupService`:
 *       `db_<база>_YYYY-MM-DD_HH-MM-SS.sql[.enc]`
 *       `full_YYYY-MM-DD_HH-MM-SS.zip[.enc]`
 *     Всё остальное (`.gitignore`, чужой файл, директория `full_*`, дамп с
 *     нестандартным именем) остаётся на месте — политика fail-closed;
 *   - «не удалять» сильнее «удалить»: имя из `$protect` (только что созданная
 *     копия) не попадает в результат, даже если оно самое старое;
 *   - полные и БД-копии считаются РАЗНЫМИ очередями: семь последних полных
 *     бэкапов и семь последних дампов БД — это не «семь файлов всего».
 *
 * Сортировка — по имени, а не по mtime: в имени зашита дата `Y-m-d_H-i-s`,
 * лексикографический порядок которой совпадает с хронологическим. Это важно,
 * потому что mtime меняется при копировании/распаковке и не отражает момент
 * снятия дампа.
 */
final class BackupRotation
{
    /**
     * Формат имени, который создаёт сервис бэкапов. Группы: база, дата, тип.
     */
    private const NAME_PATTERN = '/^(?:db_(?P<db>.+)_|full_)(?P<date>\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.(?:sql|zip)(?:\.enc)?$/';

    /**
     * Имена, которые нужно удалить.
     *
     * @param  list<string>  $names  имена файлов (не пути)
     * @param  int  $keep  сколько последних копий в каждой очереди оставить
     * @param  list<string>  $protect  имена, которые нельзя удалять ни при каких условиях
     * @return list<string> имена к удалению, отсортированные по возрастанию
     */
    public static function selectExpired(array $names, int $keep, array $protect = []): array
    {
        if ($keep < 0) {
            throw new \InvalidArgumentException('Backup retention must be zero or greater.');
        }

        $groups = [];

        foreach ($names as $name) {
            if (in_array($name, $protect, true)) {
                continue;
            }

            if (! self::isBackupName($name)) {
                continue;
            }

            $groups[self::groupOf($name)][] = $name;
        }

        $expired = [];

        foreach ($groups as $group) {
            // Новые — первыми; всё, что за пределами `keep`, — на удаление.
            usort($group, static fn (string $a, string $b): int => strcmp($b, $a));

            foreach (array_slice($group, $keep) as $name) {
                $expired[] = $name;
            }
        }

        sort($expired);

        return array_values($expired);
    }

    /**
     * Имя выглядит как резервная копия, созданная этим приложением.
     */
    public static function isBackupName(string $name): bool
    {
        // basename отсекает `../` и абсолютные пути: политика удаляет только
        // плоские имена внутри каталога бэкапов.
        if ($name !== basename($name)) {
            return false;
        }

        if (preg_match(self::NAME_PATTERN, $name, $matches) !== 1) {
            return false;
        }

        // Формата мало: `2026-13-99_99-99-99` тоже подходит под \d{2}.
        // Несуществующая дата — это НЕ наш файл, и удалять его нельзя.
        $date = \DateTimeImmutable::createFromFormat(
            'Y-m-d_H-i-s',
            $matches['date'],
            new \DateTimeZone('UTC'),
        );

        return $date !== false && $date->format('Y-m-d_H-i-s') === $matches['date'];
    }

    /**
     * Очередь ротации: полные архивы и дампы БД независимы.
     */
    private static function groupOf(string $name): string
    {
        return str_starts_with($name, 'full_') ? 'full' : 'db';
    }
}
