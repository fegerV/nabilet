# NABILET — итоговый аудит готовности (2026-11-27)

Версия: 1.16.1. База: `main` (включает `fix/stabilization` и перенесённые коммиты
из `fix/ticket-builder-audit`, `fix/yandex-hallplan-import`, `fix/updater-reliability`).
Исторические отчёты (`AUDIT-2026-11-19.md`, `PRODUCTION-READINESS.md`, `docs/audit/*`)
использованы как список вопросов, **не** как доказательство. Каждый пункт ниже
проверен на актуальном коде или явно помечен как непроверенный.

> **Вердикт:** система прошла все автоматические гейты и чистую установку из
> архива на отдельной БД. **Production-ready не объявляется**: ручная приёмка на
> Timeweb, живые платежи, реальный SMTP и проверенное восстановление бэкапа не
> выполнялись. До подписания акта продажи не открывать.

## 1. Воспроизводимые проверки (один и тот же коммит)

| Проверка | Результат |
|---|---|
| PHP syntax lint + implicit nullable | 1161 файл, 0 ошибок |
| `php tests/run.php` (dependency-free kernel) | 1094 метода / 2806 утверждений, 0 падений |
| `php tools/verify-purity.php` | 166 файлов, 0 нарушений (domen не знает Laravel) |
| `tools/verify-error-envelope.php`, `modules.php --validate` | 0 нарушений, граф валиден |
| `verify-migrations / state-machines / openapi ×2 / spec-parity / contract-schema ×2 / models-schema / autoload / module-structure / route-ownership` | все PASS |
| `composer validate` | valid |
| PHPStan level 5 | No errors |
| Full PHPUnit (MariaDB 11.4, отдельная БД) | **1520 тестов / 6753 утверждений**, 0 падений |
| `npm run typecheck` | exit 0 |
| Vitest | 56 файлов / 600 тестов, 0 падений |
| `npm run build` | dist/index.html + assets, 559 модулей |
| Version gate | config / package / CHANGELOG согласованы (1.16.1) |
| Clean install из ZIP (распакован, отдельная БД `install_smoke`) | **45/45**, health 1.16.1, `/install` → 404/405 |

Сборка архива: `scripts/build-hosting-zip.php` (портативная, без robocopy),
9257 записей, production `vendor` по `composer.lock`, пустое `storage/`,
`.env` отсутствует. Manifest содержит версию, `commit_sha`, дату, SHA-256.

Итоговый артефакт: `releases/nabilet-hosting-v1.16.1-20261127-205751.zip`,
SHA-256 `a34770dc059fbe947e156c7354442399e29d46607598055b1671101c3bf7459d`,
собран из коммита `25f74f4fd6d519ed2f76632316c93c96921760e5`. Хэш сверен с
`.sha256` и `manifest.json`; распакованный архив прошёл тот же smoke 45/45.
Подпись манифеста пустая: ключ релиза в этой среде не настроен — автоматическое
обновление из ленты невозможно, доступна ручная загрузка ZIP.

## 2. Матрица готовности модулей

| Модуль / область | Статус | Основание |
|---|---|---|
| Ядро, tenancy, ошибки §66, идемпотентность | VERIFIED | kernel + PHPUnit, `verify-error-envelope`, `StabilizationIsolationTest` |
| Каталог, площадки, залы, схемы, версии | VERIFIED | PHPUnit + `verify-state-machines`, smoke (96+ таблиц) |
| Конструктор залов (Vue) | IMPLEMENTED | Vitest + PHP контракт; браузерные сценарии редактора в CI пока не запускаются |
| Импорт Яндекс Афиши | IMPLEMENTED | конвертер и fixtures покрыты; живой источник (сеть/капча) не проверен |
| Конструктор билетов / рендеринг | VERIFIED | PHPUnit + внешний декодер QR по PNG; PDF-rasterizer в CI отсутствует |
| Продажи, резервы, платежи, возвраты | VERIFIED* | PHPUnit/Feature гонки и идемпотентность; *живой ЮKassa не проверялся → HOST_DEPENDENT |
| Уведомления, SMTP | IMPLEMENTED | шаблоны и очередь покрыты; реальная доставка HOST_DEPENDENT |
| Лояльность, бонусы, рефералы, розыгрыши | VERIFIED | Feature-тесты начислений/возвратов/анти-накрутки |
| Админка (CRUD, состояния) | IMPLEMENTED | маршруты и права проверены; сплошной UI-аудит не проводился |
| Диагностический центр | IMPLEMENTED | `SystemStatusController` + 16 MCP-срезов; экспорт обезличенного отчёта через MCP |
| Установщик | VERIFIED | 45/45 чистая установка из архива, закрытие маршрутов, APP_KEY |
| Обновления (Updater) | IMPLEMENTED | конечный автомат, подпись в `ManifestVerifier`, ZIP Slip guards; обновление предыдущего релиза не прогонялось |
| Cron / очередь на shared hosting | IMPLEMENTED | `schedule:run`, `--max-time=50`, heartbeat; на Timeweb не проверялось → HOST_DEPENDENT |
| MCP / ИИ-агент | VERIFIED (ядро) + IMPLEMENTED (расширение) | HTTP 401/403/200, двухфазные write, 38 инструментов (9+16+13), панель подключения, тесты |
| SEO / аналитика | IMPLEMENTED | серверная выдача метаданных, sitemap/canonical; живые проверки поисковика не проводились |
| Android Checker / киоски | IMPLEMENTED (API) | API и политика покрыты; оборудование не проверялось |
| Резервное копирование / восстановление | IMPLEMENTED | код и ротация есть; восстановление на отдельной БД не выполнялось |
| CI и сборка релиза | VERIFIED | gates + release-hosting workflow, version gate, smoke при сборке |
| A/B, heatmaps, мультиязычные URL | PARTIAL | модули объявлены, часть отключена по дизайну (см. KNOWN-LIMITATIONS) |
| AUD-023 (байты медиа по статическому `/storage/`) | PARTIAL | публичный контракт витрины; смена на подписанные URL требует решения |
| MySQL 5.7 CHECK/триггеры | HOST_DEPENDENT | degraded-режим; для продаж нужен MySQL 8.0+ |

## 3. Исправления этого этапа

| Проблема | Причина | Файлы | Результат | Тесты | Ограничение |
|---|---|---|---|---|---|
| `config()` в Domain (`TicketTemplateConfig.php:240`) ломал CI 45705478509 | доменный слой читал framework-хелпер | `c6d596cc` (уже в ветке) | purity 166/166, ci green | `verify-purity.php` | — |
| Сборка ZIP зависела от Windows + robocopy, вторая реализация манифеста | локальный скрипт был единственным путём сборки | `scripts/build-hosting-zip.php`, `scripts/build-hosting-zip.ps1`, `.github/workflows/release-hosting.yml`, `.github/workflows/ci.yml` | один портативный сборщик для CI и Windows; гейты через `workflow_call`; манифест с commit SHA/датой | полный набор гейтов + 45/45 smoke | подпись требует секрет `NABILET_UPDATE_PRIVATE_KEY_PEM` |
| Версии config/package/CHANGELOG могли расходиться | нет единого гейта | `tools/lib/release-version.php`, `tools/verify-release-version.php` | strict + `--allow-unreleased` режимы | `verify-release-version` | — |
| MCP не давал диагностики системы | 9 инструментов только по данным/записи | `AgentDiagnostics.php`, `DiagnosticsTool.php`, 16 tool-классов, `NabiletAgentServer.php`, `AgentDiagnosticsToolsTest.php` | 16 read-only срезов без секретов | 19 тестов / 150 утверждений | content-инструменты (`prepare_*`) ещё не добавлены |
| ARCH-verifier мог пропустить новые классы | — | `verify-autoload`, `verify-module-structure`, `verify-route-ownership` | PASS | полный прогон | — |
| Исправления PR #111–#113 оставались в отдельных ветках | ветки были старше `main`, merge целиком стёр бы новые файлы | cherry-pick 13 коммитов в `fix/integrate-pending-branches` | заморозка билета/QR, валидация импорта, журнал обновлений; SQL-бандлы 955 колонок, `EXPECT_COLUMNS=955` | PHPUnit 1520 / 6753, Vitest 600, PHPStan 0, все verifiers | обновление 1.16.0 → 1.16.1 не прогонялось |

## 4. Блокирующие факторы для продакшена

1. Ручная приёмка на конкретном аккаунте Timeweb (PHP/MySQL версии, docroot,
   HTTPS, cron, `.user.ini`) — `docs/TIMEWEB-ACCEPTANCE.md`.
2. Живой платёж + webhook + возврат с тестовыми ключами провайдера.
3. Реальный SMTP и доставка писем.
4. Проверенное восстановление бэкапа на отдельной БД (RTO/RPO).
5. Подпись релиза (приватный ключ) для автоматических обновлений.
6. Полный набор браузерных E2E из раздела 20: в CI включён только сценарий
   чистого стенда; остальные требуют подготовленного стенда. Локальный прогон
   браузера в этой среде блокируется песочницей (loopback недоступен).
7. Сквозное обновление 1.16.0 → 1.16.1 на отдельном стенде: исправления из
   трёх веток перенесены и SQL-бандлы согласованы (955 колонок), но сам переход
   через `/admin/updates` не проверен на реальном хостинге.

Ни один из этих пунктов не скрыт и не заменён заглушкой.
