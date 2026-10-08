<?php

declare(strict_types=1);

namespace Nabilet\Core\Support;

/**
 * Абсолютные URL статических файлов — единственный источник обоих корней.
 *
 * В проекте два РАЗНЫХ корня, и путать их нельзя:
 *  - загруженные файлы лежат в `storage/app/public` и отдаются символической
 *    ссылкой `public/storage` → URL всегда `/storage/{path}`;
 *  - файлы самого приложения (заглушки, favicon) лежат в `public/` и отдаются
 *    из корня → URL `/{path}`.
 *
 * Зачем отдельный класс. Раньше эти две функции были приватными методами
 * `EventPageController`, и любой второй потребитель (письмо с билетом, где
 * нужна афиша) либо дублировал их, либо писал одну ветку «по памяти». Ошибка
 * стоит дорого и незаметна: `og:image` для события без постера получал
 * `/storage/images/og-default.svg` — 404, превью в мессенджере не строилось,
 * а робот соцсети кэшировал ошибку на сутки. Ровно так это и случилось.
 *
 * Поэтому методы живут здесь, а потребители обязаны вызывать их, а не
 * склеивать путь сами. Оба метода идемпотентны: уже абсолютный URL
 * возвращается как есть, поэтому в БД можно хранить и путь, и полный адрес.
 */
final class AssetUrl
{
    /**
     * Абсолютный URL файла из `storage/app/public` (префикс `/storage/`).
     */
    public static function storage(string $path): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }

        return self::base() . '/storage/' . ltrim($path, '/');
    }

    /**
     * Абсолютный URL статического файла из `public/` (без префикса).
     */
    public static function public(string $path): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }

        return self::base() . '/' . ltrim($path, '/');
    }

    /**
     * `null` для пустой строки — чтобы вызывающий код не проверял её сам.
     *
     * Пустое значение встречается чаще, чем кажется: `events.poster` —
     * nullable, и у только что созданного мероприятия его нет.
     */
    public static function storageOrNull(?string $path): ?string
    {
        $path = trim((string) $path);

        return $path === '' ? null : self::storage($path);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, 'http://')
            || str_starts_with($path, 'https://')
            // Протокол-относительный адрес (`//cdn.example.com/a.png`) тоже
            // абсолютен по смыслу: дописав к нему хост приложения, получим
            // «https://tickets.example.com//cdn.example.com/a.png».
            || str_starts_with($path, '//');
    }

    /**
     * База приложения без завершающего слэша.
     *
     * `rtrim` обязателен: `APP_URL=https://host/` (со слэшем) дал бы
     * `https://host//storage/...` — двойной слэш, который часть CDN и
     * прокси трактует как другой путь.
     *
     * ПОЧЕМУ ЗДЕСЬ `function_exists`, А НЕ ПРОСТО `config()`
     *
     * `app/Core/Support` обязан не только загружаться, но и работать без
     * `vendor/` — это единственное, что делает доменные классы проверяемыми в
     * песочнице без Composer (см. `tools/verify-purity.php`). Прямой вызов
     * `config()` делает класс «чистым» только на вид: он загрузится (хелпер
     * вызывается в рантайме, а не при объявлении), но упадёт при первом же
     * обращении там, где фреймворка нет. Проверка `function_exists` — это
     * ровно тот случай, который страж считает не зависимостью, а
     * необязательной интеграцией: фреймворк есть — берём его конфиг, нет —
     * читаем окружение.
     *
     * В боевом запросе и в тестах рабочая ветка — `config()`: именно она
     * учитывает `.env`, кэш конфига и переопределения в `phpunit.xml`.
     * Запасная нужна для `tools/` и `tests/run.php`, где контейнера нет;
     * относительный URL в этом случае — приемлемая деградация, потому что
     * оттуда ничего не отправляется наружу.
     */
    private static function base(): string
    {
        $base = function_exists('config')
            ? (string) config('app.url')
            : (string) getenv('APP_URL');

        return rtrim($base, '/');
    }
}
