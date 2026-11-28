# NABILET — итоговый аудит готовности (2026-11-28)

Версия: 1.16.3. База: `main` (включает `fix/stabilization` и перенесённые коммиты
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
| Full PHPUnit (отдельная БД) | **1559 тестов / 6935 утверждений**, 0 падений (1 skip по среде) |
| `npm run typecheck` | exit 0 |
| Vitest | 56 файлов / 600 тестов, 0 падений |
| `npm run build` | dist/index.html + assets, 559 модулей |
| Version gate | config / package / CHANGELOG согласованы (1.16.3) |
| Clean install из ZIP (распакован, отдельная БД `install_smoke`) | **52/52**, health 1.16.3, `/install` → 404/405 |

Сборка архива: `scripts/build-hosting-zip.php` (портативная, без robocopy),
9304 записи, production `vendor` по `composer.lock`, пустое `storage/`,
`.env` отсутствует. Manifest содержит версию, `commit_sha`, дату, SHA-256.

Итоговый артефакт 1.16.3 публикуется **только после** успешного прогона полного
workflow на финальной ревизии: реквизиты (имя ZIP, SHA-256, commit SHA, ссылка на
run) вносятся сюда и в `releases/manifest.json` из фактического артефакта CI.
Ранее подписанный архив 1.16.2 (`...-20261128-142812.zip`) снят с публикации.
Приватный ключ находится только в секрете GitHub
`NABILET_UPDATE_PRIVATE_KEY_PEM`; для автоматических обновлений владелец задаёт
`NABILET_UPDATE_PUBLIC_KEY` на сайте (мастер 1.16.3 умеет записать его сам).

## 2. Матрица готовности модулей

| Модуль / область | Статус | Основание |
|---|---|---|
| Ядро, tenancy, ошибки §66, идемпотентность | VERIFIED | kernel + PHPUnit, `verify-error-envelope`, `StabilizationIsolationTest` |
| Каталог, площадки, залы, схемы, версии | VERIFIED | PHPUnit + `verify-state-machines`, smoke (96+ таблиц) |
| Конструктор залов (Vue) | IMPLEMENTED | Vitest + PHP контракт; браузерные сценарии редактора в CI пока не запускаются |
| Импорт Яндекс Афиши | IMPLEMENTED | конвертер и fixtures покрыты; живой источник (сеть/капча) не проверен |
| Конструктор билетов / рендеринг | VERIFIED | PHPUnit + внешний декодер QR по PNG и по напечатанному PDF (pymupdf + OpenCV, job `pdf-qr`) |
| Продажи, резервы, платежи, возвраты | VERIFIED* | PHPUnit/Feature гонки и идемпотентность; *живой ЮKassa не проверялся → HOST_DEPENDENT |
| Уведомления, SMTP | IMPLEMENTED | шаблоны и очередь покрыты; реальная доставка HOST_DEPENDENT |
| Лояльность, бонусы, рефералы, розыгрыши | VERIFIED | Feature-тесты начислений/возвратов/анти-накрутки |
| Админка (CRUD, состояния) | IMPLEMENTED | маршруты и права проверены; сплошной UI-аудит не проводился |
| Диагностический центр | IMPLEMENTED | `SystemStatusController`, 16 MCP-срезов, экспорт обезличенного JSON в админке |
| Установщик | VERIFIED | 52/52 чистая установка из архива; 1.16.3: platform-admin только владельцу, регион, atomic key, идемпотентный повтор, честные ошибки, финальная диагностика |
| Обновления (Updater) | IMPLEMENTED | конечный автомат, подпись в `ManifestVerifier`, ZIP Slip guards; переход 1.16.1 → 1.16.2 проверен локально, 1.16.3 на хостинге не проверялся |
| Cron / очередь на shared hosting | IMPLEMENTED | `schedule:run`, `--max-time=50`, heartbeat; на Timeweb не проверялось → HOST_DEPENDENT |
| MCP / ИИ-агент | VERIFIED (ядро) + IMPLEMENTED (расширение) | HTTP 401/403/200, 43 инструмента (9+16+13+5), двухфазные write; `agent:system:read` только у platform-admin, универсальные `*` токены отклоняются |
| SEO / аналитика | IMPLEMENTED | серверная выдача метаданных, sitemap/canonical; живые проверки поисковика не проводились |
| Android Checker / киоски | IMPLEMENTED (API) | API и политика покрыты; оборудование не проверялось |
| Резервное копирование / восстановление | IMPLEMENTED | логический дамп восстановлен и сверен по 99 таблицам; `backup:run` не прогонялся (нет `mysqldump`) |
| CI и сборка релиза | VERIFIED | gates + release-hosting workflow, version gate, smoke при сборке |
| A/B, heatmaps, мультиязычные URL | PARTIAL | модули объявлены, часть отключена по дизайну (см. KNOWN-LIMITATIONS) |
| AUD-023 (байты медиа по статическому `/storage/`) | PARTIAL | публичный контракт витрины; смена на подписанные URL требует решения |
| MySQL 5.7 CHECK/триггеры | HOST_DEPENDENT | degraded-режим; для продаж нужен MySQL 8.0+ |

## 3. Исправления этого этапа

| Проблема | Причина | Файлы | Результат | Тесты | Ограничение |
|---|---|---|---|---|---|
| `config()` в Domain (`TicketTemplateConfig.php:240`) ломал CI 45705478509 | доменный слой читал framework-хелпер | `c6d596cc` (уже в ветке) | purity 166/166, ci green | `verify-purity.php` | — |
| Сборка ZIP зависела от Windows + robocopy, вторая реализация манифеста | локальный скрипт был единственным путём сборки | `scripts/build-hosting-zip.php`, `scripts/build-hosting-zip.ps1`, `.github/workflows/release-hosting.yml`, `.github/workflows/ci.yml` | один портативный сборщик для CI и Windows; гейты через `workflow_call`; манифест с commit SHA/датой | полный набор гейтов + 48/48 smoke | подпись требует секрет `NABILET_UPDATE_PRIVATE_KEY_PEM` |
| Версии config/package/CHANGELOG могли расходиться | нет единого гейта | `tools/lib/release-version.php`, `tools/verify-release-version.php` | strict + `--allow-unreleased` режимы | `verify-release-version` | — |
| MCP не давал диагностики системы | 9 инструментов только по данным/записи | `AgentDiagnostics.php`, `DiagnosticsTool.php`, 16 tool-классов, `NabiletAgentServer.php`, `AgentDiagnosticsToolsTest.php` | 16 read-only срезов без секретов | 19 тестов / 150 утверждений | 13 read-only контентных + 5 подготовок добавлены; публикация/медиа остаются в админке |
| ARCH-verifier мог пропустить новые классы | — | `verify-autoload`, `verify-module-structure`, `verify-route-ownership` | PASS | полный прогон | — |
| Исправления PR #111–#113 оставались в отдельных ветках | ветки были старше `main`, merge целиком стёр бы новые файлы | cherry-pick 13 коммитов в `fix/integrate-pending-branches` | заморозка билета/QR, валидация импорта, журнал обновлений; SQL-бандлы 955 колонок, `EXPECT_COLUMNS=955` | PHPUnit 1559 / 6935, Vitest 600, PHPStan 0, все verifiers | обновление 1.16.3 на хостинге не прогонялось |
| 1.16.3: установка давала права системного администратора всем админам | `NABILET_PLATFORM_ADMINS` задаётся из e-mail владельца мастера; обычные админы организаций права не наследуют | `InstallerController::buildEnvContent`, `InstallerFinalizationTest` | только владелец в списке | feature + smoke `.env` | настройка прав проверяется только после чистой установки |
| 1.16.3: гонка на удалении файла блокировки | файл удалялся, пока `flock` ещё удерживался | `InstallerController::clearInstallState`, `InstallerKeySecurityTest` | файл переиспользуется, новый inode не создаётся | feature-тест с удерживаемым `flock` | — |
| 1.16.3: выбранный часовой пояс не доходил до мероприятия | `events.timezone` оставался NULL, сеанс падал на UTC из DEFAULT | `EventService::create`, `EventTimezoneDefaultTest` | дефолт `app.timezone` (из мастера), явное значение не перетирается | feature-тест | сеансы без даты по-прежнему не синхронизируются |
| 1.16.3: частичная установка создавала дубликаты и маскировала ошибки | fail-soft при создании организации/сидеров | `bootstrapOrganization`, `createAdminUser`, `finalDiagnostics` | повтор идемпотентен; обязательные шаги падают; симлинк остаётся опциональным | 6 новых тестов | `backup:run` по-прежнему непроверен |
| 1.16.3: универсальный токен и чужая роль давали системную диагностику | Sanctum `tokenCan('*') == true`; админка выдавала `system:read` без проверки | `AgentCapabilities::has`, `AgentAdminController::guardSystemRead`, `IssueAgentTokenCommand`, `AgentPermissionSplitTest`, `AgentTokenCommandTest` | `*` → 403 на `/mcp`; `system:read` только для platform-admin | 6 новых тестов + живой хендшейк `@modelcontextprotocol/sdk` 1.30.1 на чистой установке | совместимость с конкретным клиентом (Codex/ChatGPT) не проверялась |

## 4. Блокирующие факторы для продакшена

1. Ручная приёмка на конкретном аккаунте Timeweb (PHP/MySQL версии, docroot,
   HTTPS, cron, `.user.ini`) — `docs/TIMEWEB-ACCEPTANCE.md`.
2. Живой платёж + webhook + возврат с тестовыми ключами провайдера.
3. Реальный SMTP и доставка писем.
4. Проверенное восстановление бэкапа на отдельной БД (RTO/RPO).
5. Ключ подписи релиза: пара сгенерирована, архив подписан, подпись проверена.
   Приватный ключ **уже** лежит в секрете CI `NABILET_UPDATE_PRIVATE_KEY_PEM`.
   На целевом сайте нужно задать публичный `NABILET_UPDATE_PUBLIC_KEY` (мастер
   1.16.3 умеет сделать это сам); без него лента отвергнет манифест.
6. Полный набор браузерных E2E из раздела 20: в CI включён только сценарий
   чистого стенда; остальные требуют подготовленного стенда. Локальный прогон
   браузера в этой среде блокируется песочницей (loopback недоступен).
7. Обновление с неизменного оригинального архива 1.16.1 → 1.16.2 выполнено
   локально **без ручной подмены файлов**: `preparing → ready → migrating →
   verifying → done`, health целевой версии, данные покупателя сохранены.
   Прерванное
   обновление переводится в `failed_after_swap`; откат кода блокируется после
   начатых миграций, пока БД не восстановлена из копии. Логический дамп
   восстановлен на отдельной БД и сверен по 99 базовым таблицам. Команда
   `backup:run` не прогонялась (нет `mysqldump`), поэтому работоспособность
   штатного механизма бэкапа на Timeweb остаётся непроверенной.

## 5. Проверка PR #111/#112/#113

PR #111 (freeze ticket design), #112 (reliable updater), #113 (hallplan import)
были проверены по фактическим коммитам: изменения перенесены в `main` через
`cherry-pick`, покрыты регрессионными тестами и вошли в 1.16.2/1.16.3. Устаревшие
ветки НЕ сливались автоматически; дублирующие PR закрыты с пояснением.

Ни один из пунктов не скрыт и не заменён заглушкой.
