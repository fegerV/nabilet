# Отчёт стабилизации после аудитов

Дата: 2026-11-26. Ветка: `fix/stabilization`, PR #114. Сопутствующие PR: #111, #112, #113.

## 1. Фактические журналы GitHub Actions (до исправлений)

| Run | Ветка |_job_ | Причина |
|---|---|---|---|
| 45771408238 | `fix/yandex-hallplan-import` | Verifiers | `app/Modules/Tickets/Domain/TicketTemplateConfig.php:240 framework helper - config()` |
| 45714507505 | `fix/updater-reliability` | Verifiers | `HmacSignatureCopyTest::testSignedBytesAreSerialisedOnlyByTheirOwner`, Expected array(0), got array(1) |
| 45709401967 | `fix/ticket-builder-audit` | Verifiers | тот же `config()` в Domain |
| 45709401967 | `fix/ticket-builder-audit` | Apply the spec bundle to MySQL 8.4 | `ERROR 1054 (42S22) at line 2800: Unknown column 'qr_payload' in 'order_items'` |

Логи скачаны через GitHub Actions API (`actions/jobs/{id}/logs`), а не выведены из локальных отчётов.

## 2. Исправленные дефекты

| Пункт | Подтверждённый дефект | Исправление | Регрессионный тест | Локальная проверка |
|---|---|---|---|---|
| CI-1 | `config()` в `TicketTemplateConfig` (Domain) | Удалён; принимаются только `/storage/…` и data URI | `verify-purity.php` | 166 файлов, 0 нарушений |
| CI-2 | `AFTER qr_payload` для `order_items` в обоих SQL-бандлах | Использовано `AFTER ticket_status` | `verify-migrations.php`, `verify-contract-schema.php`, `verify-spec-parity.php` | 16/16, 0 дрейфа, 6/6 |
| CI-3 | `UpdateJournal` дублировал флаги подписи | Переход на `SignedPayload::encode()` | `tests/run.php` → `HmacSignatureCopyTest` | 1094 метода, 0 падений |
| #112 | `reversibleEntries()` игнорировал pending | Берутся все операции с дедупликацией; восстановление по диску | `UpdaterReliabilityTest::test_unconfirmed_pending_operation...` | 9 тестов, 35 утверждений |
| #112 | Сбой между rename и journal lost backup | Восстановление по факту наличия backup | там же | OK |
| #112 | autoRollbackStuck обходил проверки | Единый `assertRollbackAllowed()` для ручного/авто/аварийного | `test_rollback_is_blocked_when_migration_was_started` | OK |
| #112 | Частичные миграции не фиксировались | Журнал `started` до Artisan, состояния `not_started/started/partial/completed/uncertain` | `test_migration_states_distinguish...` | OK |
| #112 | Бэкап 24 ч считался свежим | По умолчанию 6 ч (`NABILET_UPDATE_BACKUP_MAX_AGE_HOURS`) | конфиг/preflight | OK |
| #113 | Нумерация считалась 1..seatCount | `buildPlaceSequence()` по известным номерам (чётные/нечётные/разреженные) | `test_even_odd_and_sparse_numbering_is_preserved` | PHPUnit 1488 |
| #113 | Известный и восстановленный номер не различались | `number_source` у каждого места | там же | OK |
| #113 | Публикация неподтверждённой зоны проходила при положительных capacity/price | Требуется `confirmed_by_organizer` при unconfirmed provenance | `test_publication_rejects_unconfirmed_standing_zone` | OK |
| #113 | source_id зависел от порядка входа | Стабильный `source_id` по level/row/place | `test_source_ids_survive_reordering...` | OK |
| #111 | Чистая установка MySQL падала с 1054 | Исправлены оба SQL-бандла | verifiers | PASS |
| #111 | Изображения снимка терялись при удалении медиа | Байты встраиваются в снимок при выпуске; рендерер читает `src` | `test_snapshot_embeds_image_bytes_and_survives_media_deletion` | OK |
| #111 | QR проверялся только своей матрицей | Внешний декодер OpenCV по PNG | `TicketQrDecodeTest` | OK |
| AUD-020 | `isPlatformAdmin()` давал права любому admin при пустом списке | Явный opt-in `NABILET_SINGLE_TENANT_ADMIN`; по умолчанию false | `StabilizationIsolationTest`, обновлён `AuditHardeningWave2Test` | OK |
| AUD-021 | Инвентарь чужого сеанса доступен по `session_id` | Проверка видимости сеанса (гость — опубликованные события, staff — своя организация) | `StabilizationIsolationTest` | OK |
| AUD-022 | Любой авторизованный перечислял организации | Список/детали организаций ограничены членством | `StabilizationIsolationTest` | OK |

## 3. Проверки после исправлений

| Проверка | Результат |
|---|---|
| PHPUnit (fix/stabilization) | **1479 тестов / 6439 утверждений**, 0 падений |
| PHPUnit (fix/ticket-builder-audit) | 1465 тестов, 0 падений |
| PHPUnit (fix/yandex-hallplan-import) | 1488 тестов, 0 падений |
| PHPUnit (fix/updater-reliability) | 1462 теста, 0 падений |
| `tests/run.php` | 1094 метода, 0 падений |
| PHPStan | `No errors` |
| `verify-purity.php`, `verify-error-envelope.php` | 0 нарушений |
| `verify-migrations.php`, `verify-contract-schema.php`, `verify-spec-parity.php`, `verify-models-schema.php` | PASS |

Ссылки на фактические CI-прогоны после push: см. `https://github.com/fegerV/nabilet/actions` и коммиты PR #111–#114. Если в момент чтения отчёта CI ещё выполняется, статус ниже не считается подтверждённым.

## 4. Оставшиеся ограничения

1. **AUD-023** (байты медиа по статическому `/storage/`) не исправлен: требует смены схемы выдачи файлов на подписанные URL TTL и проверки прав; это публичный контракт витрины.
2. **Playwright E2E и визуальные регрессии не внедрены** — план подготовлен в `docs/PLAYWRIGHT-E2E-PLAN.md`, но зависимость и CI-job не добавлены.
3. **Декодирование QR из PDF** не автоматизировано: в тесте декодируется PNG, сгенерированный тем же рендерером; для PDF нужен rasterizer в CI.
4. **Интеграционный прогон на Timeweb-подобном хостинге и с предыдущим релизом** не выполнялся: локально доступен только MySQL 8.4; hosting-архив в эмуляции shared hosting не разворачивался.
5. **AUD-024/025/026/027/028** остаются в статусах из `docs/audit/REMAINING-ISSUES.md` (открытые/отложенные/не измеренные).
6. **Fault-injection после каждой операции копирования/переименования/записи журнала/миграции** добавлен только для ключевых точек (pending-операции, миграции, откат); полной матрицы нет.

Ни один пункт не помечен завершённым без локальной проверки.
