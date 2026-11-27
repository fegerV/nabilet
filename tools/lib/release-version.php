<?php

declare(strict_types=1);

/**
 * Единые правила сверки номера версии релиза.
 *
 * Файл без зависимостей: его используют и CI-гейт, и сборщик архива, и
 * локальный PowerShell-入口. Правило одно — config/nabilet.php является
 * источником истины; package.json и CHANGELOG.md обязаны с ним совпадать.
 */

/** @return string */
function nabilet_release_config_version(string $repo): string
{
    $raw = @file_get_contents($repo . '/config/nabilet.php');
    if ($raw === false || preg_match("/'version'\s*=>\s*'([^']+)'/", $raw, $m) !== 1) {
        throw new RuntimeException('config/nabilet.php: не удалось прочитать версию приложения.');
    }

    return trim($m[1]);
}

function nabilet_release_package_version(string $repo): string
{
    $raw = @file_get_contents($repo . '/package.json');
    if ($raw === false) {
        throw new RuntimeException('package.json: файл не найден.');
    }

    $json = json_decode($raw, true);
    if (! is_array($json) || ! isset($json['version']) || ! is_string($json['version'])) {
        throw new RuntimeException('package.json: поле version отсутствует или имеет неверный тип.');
    }

    return trim($json['version']);
}

function nabilet_release_changelog_has(string $repo, string $version): bool
{
    $raw = @file_get_contents($repo . '/CHANGELOG.md');
    if ($raw === false) {
        throw new RuntimeException('CHANGELOG.md: файл не найден.');
    }

    $pattern = '/^## \[' . preg_quote($version, '/') . '\]\s*-\s*\d{4}-\d{2}-\d{2}/m';

    return preg_match($pattern, $raw) === 1;
}

function nabilet_release_changelog_has_unreleased(string $repo): bool
{
    $raw = @file_get_contents($repo . '/CHANGELOG.md');

    return $raw !== false && preg_match('/^## \[Unreleased\]/m', $raw) === 1;
}

/**
 * Проверяет согласованность версий и возвращает версию релиза.
 *
 * @param  string|null  $expected  ожидаемая версия (из тега или аргумента сборки)
 * @param  bool  $allowUnreleased  допустить запись [Unreleased] вместо готового раздела
 */
function nabilet_release_assert(string $repo, ?string $expected, bool $allowUnreleased): string
{
    $config = nabilet_release_config_version($repo);
    $package = nabilet_release_package_version($repo);

    if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/', $config) !== 1) {
        throw new RuntimeException(sprintf('config/nabilet.php: некорректный SemVer "%s".', $config));
    }

    if ($config !== $package) {
        throw new RuntimeException(sprintf(
            'Версии расходятся: config/nabilet.php = %s, package.json = %s.',
            $config,
            $package,
        ));
    }

    if ($expected !== null && trim($expected) !== '' && trim($expected) !== $config) {
        throw new RuntimeException(sprintf(
            'Запрошенная версия %s не совпадает с версией в коде %s.',
            trim($expected),
            $config,
        ));
    }

    if (! nabilet_release_changelog_has($repo, $config)
        && ! ($allowUnreleased && nabilet_release_changelog_has_unreleased($repo))) {
        throw new RuntimeException(sprintf(
            'CHANGELOG.md: нет раздела "## [%s] - YYYY-MM-DD" для версии %s.',
            $config,
            $config,
        ));
    }

    return $config;
}
