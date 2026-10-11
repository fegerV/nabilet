# Инструкция по запуску тестов

В проекте три набора тестов, и у них разные требования:

| Набор | Команда | Что нужно | Объём |
| --- | --- | --- | --- |
| Ядро (без зависимостей) | `php tests/run.php` | только PHP | 700 методов, 1722 утверждения |
| Feature (прикладной слой) | `php vendor/bin/phpunit` | `vendor/` + MySQL 8.4 | 584 теста, 2006 утверждений |
| Фронтенд | `npm run test` | `node_modules/` | 18 файлов, 233 теста |

Первый набор существует потому, что ядро (хуки, модули, ошибки, Money, QR, state
machines, tenancy) обязано работать без Composer: он ловит поломку, которую
`composer install` не смог бы даже заметить.

## Требования

- PHP 8.4 (см. `Dockerfile` и `composer.lock`; Symfony 8 требует `>=8.4.1`)
- MySQL 8.4 — для Feature-суиты
- Node.js 22 — для фронтенда
- Docker — чтобы поднять MySQL, если его нет локально

## Ядро: `php tests/run.php`

Зависимостей нет — ни Composer, ни базы. Тесты лежат в `tests/Unit/*` **плоско**:
имя класса выводится из имени файла, поэтому подкаталоги внутри `tests/Unit/`
(например `tests/Unit/HallEditor/`) этому раннеру недоступны — там лежат
фронтенд-тесты для vitest.

```bash
php tests/run.php
```

В конце раннер сам печатает, сколько файлов он **не** покрывает: это честная
граница его возможностей, а не ошибка.

## Feature: `php vendor/bin/phpunit`

Единственный набор, который реально исполняет приложение. Статические гейты
читают код, но не запускают его — именно поэтому контроллер, вызывающий
несуществующий метод, доходит до продакшена и ловится только здесь.

### Подготовка MySQL

```bash
docker run -d --name nabilet-mysql --security-opt seccomp=unconfined \
  -e MYSQL_ROOT_PASSWORD=rootpass -e MYSQL_DATABASE=nabilet \
  -p 3307:3306 mysql:8.4

docker exec nabilet-mysql mysql -uroot -prootpass \
  -e "CREATE DATABASE nabilet_test_suite CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

`log_bin_trust_function_creators` **обязателен**: миграция триггера иммутабельности
опубликованной версии схемы зала падает с `ERROR 1419` без него, а сама миграция
написана fail-soft — без флага суита прошла бы, молча не проверив триггер.

```bash
docker exec nabilet-mysql mysql -uroot -prootpass \
  -e "SET GLOBAL log_bin_trust_function_creators = 1;"
```

### Запуск

```bash
php vendor/bin/phpunit
```

**Никогда не поднимайте dev-сервер на `nabilet_test_suite`.** `RefreshDatabase`
пересоздаёт все таблицы на каждом прогоне — база расходная по построению.

Не запускайте два набора тестов одновременно: `RefreshDatabase` пересоздаёт ту же
базу, и прогоны внахлёст блокируют друг друга на `DROP`/`CREATE TABLE`. Признак —
прогон «висит» без вывода, при пустом списке транзакций.

### Что задано принудительно (`phpunit.xml`)

`phpunit.xml` фиксирует `DB_*` (127.0.0.1:3307, `nabilet_test_suite`, root/rootpass),
`APP_ENV=testing`, `MAIL_MAILER=array` и `TICKET_QR_SECRET`. Все — с `force="true"`,
поэтому переменная окружения из шелла или CI не может перенаправить тесты.

`TICKET_QR_SECRET` появился здесь не для удобства: `.env.example` оставляет и
`TICKET_QR_SECRET`, и историческое `NABILET_QR_SECRET` пустыми, а CI копирует
именно его. Без этой строки суита зависела от того, заполнил ли разработчик свой
`.env`: на чистом окружении падало 12 тестов платежей с `QR_SECRET_NOT_CONFIGURED`.

### Оболочка витрины — фикстура, а не сборка

Страница события отдаётся как собранный Vite `dist/index.html`, в который
`EventPageController::renderSpaShell()` вставляет серверный SEO-блок. Сборки в
PHP-задании CI нет: `dist/` не хранится в git, а собирает его отдельное задание.

Поэтому `tests/TestCase::setUp()` подставляет `nabilet.storefront.shell_path` =
`tests/Fixtures/storefront-shell.html`. Фикстура проверяет то, ради чего эти тесты
написаны, — инъекцию блока **внутрь** `#app` и подмену метатегов, — а не форму
конкретной сборки. Тесты, которым нужна **отсутствующая** оболочка (ветка 503),
переопределяют путь сами.

Что **настоящий** `dist/index.html` сохраняет контракт инъекции, проверяет задание
фронтенда — единственное место, где сборка существует. Не переносите эту проверку
сюда: здесь нечего проверять.

## Фронтенд: `npm run test`

Конфигурация живёт в `vitest.config.ts` (не в `vite.config.js`), среда — `jsdom`,
и без неё компонентные тесты падают на `document is not defined`.

```bash
npm ci
npm run typecheck   # vue-tsc --noEmit
npm run test        # vitest run
npm run build       # vite build --config vite.config.ts → dist/
```

`vue-tsc` — единственное, что читает `.vue`-шаблоны и типизированный слой
store/api. `vite build` отдельно от него намеренно: файл может проходить проверку
типов и не собираться (битый путь импорта, цикл), а это пустая страница в
продакшене. Сборка заодно доказывает, что динамические импорты роутера разрешаются:
каждая админ-страница — это `() => import(...)`, поэтому переименованный компонент
даёт 404 в рантайме, а не ошибку компиляции.

## Статические гейты

Все без зависимостей (кроме `composer validate`) и все с ненулевым кодом возврата
при расхождении:

```bash
php tools/lint.php
php tools/verify-purity.php
php tools/verify-error-envelope.php
php tools/verify-migrations.php
php tools/verify-state-machines.php
php tools/verify-openapi.php
php tools/verify-openapi.php nabilet_core_spec/openapi.yaml
php tools/verify-contract-schema.php
php tools/verify-contract-schema.php nabilet_core_spec/openapi.yaml
php tools/verify-models-schema.php
php tools/verify-autoload.php
php tools/verify-module-structure.php
php tools/verify-route-ownership.php
php tools/modules.php --validate
```

Списки известных расхождений внутри этих инструментов — **храповик**: он может
только сокращаться, новое расхождение валит сборку.

## CI

`.github/workflows/ci.yml`, пять заданий:

| Задание | Что делает |
| --- | --- |
| `verify` | lint, тесты ядра, все статические гейты, `composer validate`; `vendor/` не нужен |
| `docker` | `docker compose config`, hadolint, сборка образа BuildKit'ом |
| `schema` | накатывает `migrations.sql` и сплит-сет в два разных database на MySQL 8.4, сверяет счётчики, диффит `information_schema.columns`, проверяет триггер в обе стороны |
| `phpunit` | `composer install` по lock, `artisan key:generate`, Feature-суита на MySQL 8.4 |
| `frontend` | `npm ci`, `vue-tsc`, vitest, `vite build`, контракт оболочки витрины |

## Добавление тестов

- Ядро без зависимостей → `tests/Unit/<Имя>Test.php`, класс в пространстве
  `Nabilet\Tests\Unit`, файл **плоско** (без подкаталогов).
- Прикладной слой → `tests/Feature/<Модуль>/<Имя>Test.php`, класс наследует
  `Tests\TestCase`.
- Фронтенд → `tests/Unit/<Область>/<имя>.test.ts`, подхватывается по маске
  `tests/Unit/**/*.{test,spec}.{ts,js}`.

## Диагностика

### `docker ps` отвечает «нет pipe docker_engine»

Docker-движок в этом окружении живёт в Hyper-V VM. Запустите `MobyLinuxVM`, затем
Docker Desktop, затем `docker start nabilet-mysql`.

### `SQLSTATE[42000] ... ERROR 1419`

Не выставлен `log_bin_trust_function_creators` — см. выше.

### PHPUnit вышел с кодом 1, а не 2

Коды разные по смыслу, и это ускоряет разбор: `2` — ошибки исполнения (исключение,
недоступный сервис), `1` — упавшие утверждения, то есть код отработал и вернул не
то. Читайте имена упавших тестов, а не только код.

### `Failed to load PostCSS config`

`postcss.config.js` должен быть ES-модулем (`export default { ... }`).

### Прогон «висит» без вывода

Скорее всего параллельно идёт второй прогон Feature-суиты: `RefreshDatabase`
блокируется на `DROP`/`CREATE TABLE`. См. предупреждение выше.
