<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Оболочка витрины для тестов — фикстура вместо артефакта сборки.
     *
     * Страница события отдаётся как собранный Vite `dist/index.html`, в который
     * `EventPageController::renderSpaShell()` вставляет серверный SEO-блок. Путь
     * берётся из `nabilet.storefront.shell_path`, значение по умолчанию —
     * `base_path('dist/index.html')`.
     *
     * `dist/` — артефакт сборки: в git его нет, и PHP-задание CI его не собирает
     * (сборкой занято отдельное задание `frontend`). Поэтому на чистом клоне
     * контроллер отвечал 503 `STORE_FRONT_NOT_BUILT`, и 49 тестов страницы
     * события падали, — а у того, кто хоть раз запускал `npm run build`,
     * проходили. Результат зависел от состояния рабочего каталога, а не от кода:
     * та же негерметичность, что была у `TICKET_QR_SECRET`.
     *
     * Фикстура проверяет то, ради чего эти тесты написаны, — инъекцию блока
     * ВНУТРЬ `#app` и подмену метатегов, — а не форму конкретной сборки. Что
     * НАСТОЯЩИЙ `dist/index.html` сохраняет контракт, на который опирается
     * инъекция (`<div id="app"></div>`, `<title>`, `<meta name="description">`),
     * проверяет задание фронтенда — единственное место, где сборка существует.
     *
     * Тесты, которым нужна ОТСУТСТВУЮЩАЯ оболочка (ветка 503), переопределяют
     * путь сами: здесь задаётся только значение по умолчанию.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['nabilet.storefront.shell_path' => base_path('tests/Fixtures/storefront-shell.html')]);
    }
}