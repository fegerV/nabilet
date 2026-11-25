# Реестр ошибок аудита

Обозначения: **Confirmed** — подтверждено чтением кода и/или тестом; **Risk** — потенциальный риск без воспроизведения в этом проходе; **Debt** — архитектурный долг; **Won't fix here** — требует решения по бизнес-правилу.

| ID | Приор. | Статус | Кратко | Исправление / коммит |
|---|---|---|---|---|
| AUD-001 | P0 | Confirmed, fixed | Сеанс коммитился до генерации инвентаря; ошибка оставляла сеанс без мест | транзакция + лок, `SessionController`, `096ceaae`; тест `SessionInventoryAtomicityTest::test_generation_failure_rolls_back_the_created_session` |
| AUD-002 | P0 | Confirmed, fixed | При смене схемы UPDATE сеанса коммитился до пересборки; при ошибке схема и инвентарь расходились |LOCK сеанса + одна транзакция, `096ceaae`; тест `test_failed_rebuild_keeps_the_old_schema_and_inventory` |
| AUD-003 | P0 | Confirmed, fixed | Любая строка `seat_holds` (даже снятая) блокировала пересборку навсегда | inactive/expired холды чистятся в транзакции, живые — 409, `096ceaae`; тест `test_stale_hold_does_not_block_rebuild_but_an_active_hold_does` |
| AUD-004 | P1 | Confirmed, fixed | Параллельные сеансы из одной версии схемы дважды материализовали геометрию | лок версии схемы в `generateFromSchema`, `096ceaae` |
| AUD-005 | P0 | Confirmed, fixed | Admin org A читал черновики/архив org B через публичные version endpoints | фильтр published + проверка владельца, `58601bf6`; `TenantIsolationHallSchemaTest` |
| AUD-006 | P0 | Confirmed, fixed | Публичный `GET /halls` отдавал все залы всех организаций | роут под auth+admin, scoped запрос, `58601bf6`; тест `test_the_all_halls_catalog_is_no_longer_public` |
| AUD-007 | P0 | Confirmed, fixed | Список/карточка сеансов администратора не фильтровались по организации | scope + `assertCanAccess`, `58601bf6`; `test_staff_session_list_and_detail_are_scoped_to_the_organization` |
| AUD-008 | P1 | Confirmed, fixed | Каталог площадок отдавал `organization.settings_json` всем | выборка безопасных колонок, `58601bf6` |
| AUD-009 | P1 | Confirmed, fixed | `PUT /halls/{id}` писал в VIEW с COALESCE-колонкой → 500; поля вместимости/размеров не имели колонок | поля перенесены на `venues`, VIEW пересоздана, репозиторий пишет в таблицу, `b7c785f2`; `HallViewWriteTest` |
| AUD-010 | P1 | Confirmed, fixed | Удаление площадки с опубликованной схемой обходило заморозку (триггер исчез с таблицей) | проверка контроллера + BEFORE DELETE триггер, `b7c785f2`; тест с прямым DELETE |
| AUD-011 | P1 | Confirmed, fixed | `EventService` вызывал `Hall::create()` — INSERT в VIEW | in-memory shadow Hall, `b7c785f2` |
| AUD-012 | P1 | Confirmed, fixed | Две standing-зоны с одинаковой подписью сливались; геометрия терялась | дедупликация по id + дескриптор геометрии, `390a9d7c`; `test_two_standing_zones_with_the_same_label_but_different_ids_are_kept` |
| AUD-013 | P1 | Confirmed, fixed | Вместимость standing-зоны раздувалась в `array_fill()` — OOM при 2e9 | одна сущность + `standing_capacity`, лимит 10000, `390a9d7c`; тест в `HallSchemaLifecycleTest` |
| AUD-014 | P1 | Confirmed, fixed | Витрина прибивала все standing-зоны к нижней полосе | нормализация координат + metadata + `danceZoneBoxAt`, `3eb67931`; Vitest-тесты раскладки |
| AUD-015 | P1 | Confirmed, fixed | Валидация не проверяла версию формата, ид объектов, лимиты и подложки | расширенный валидатор, код `SCHEMA_VALIDATION_FAILED`, контекст `objects`, `390a9d7c` |
| AUD-016 | P1 | Confirmed, fixed | `base_revision` был опционален при публикации | `required` в обоих API, обновлены вызывающие, `390a9d7c`; тест 422 |
| AUD-017 | P1 | Confirmed, fixed | Запоздавший ответ autosave после переключения площадки менял revision/id редактора | `useEditorRequestToken`, `390a9d7c`; Vitest-тест |
| AUD-018 | P1 | Confirmed, fixed | В репозитории были закоммичены токены диагностики и старые сборки | `git rm --cached` + `.gitignore`, `79045e3b`; **токены нужно ротировать** |
| AUD-019 | P1 | Risk | MySQL 5.7 не исполняет CHECK/trigger; хостинг может быть на 5.7 | не исправлялось — инфраструктурное требование MySQL 8.0+ |
| AUD-020 | P1 | Risk | `isPlatformAdmin()` при пустом конфиге делает любого арендаторского админа платформенным | не менялось: публичный контракт прав, решение владельца |
| AUD-021 | P1 | Risk | Публичные inventory endpoints принимают чужой `session_id` без проверки статуса события | не менялось: потребовало бы изменения контракта витрины |
| AUD-022 | P1 | Risk | `GET /organizations` доступен любому авторизованному и отдаёт settings | не менялось: публичный контракт |
| AUD-023 | P1 | Risk | Байты медиа отдаются статикой без проверки прав; защита — только URL | не менялось: требуется решение по storage |
| AUD-024 | P1 | Debt | `HallEditorPage.vue` ~4130 строк, ответственность не разделена | частично: выделен `useEditorRequestToken`; полный рефакторинг — отдельная задача |
| AUD-025 | P1 | Debt | Konva-сцена пересоздаётся целиком на изменение состояния | не исправлялось; замеры не проводились |
| AUD-026 | P1 | Debt | Нет Playwright E2E в CI | не добавлялась зависимость; сценарии описаны в `HALL-EDITOR-TEST-MATRIX.md` |
| AUD-027 | P2 | Risk | Миграция 2026_11_18_000800 могла прерваться на базе с несколькими залами площадки или конфликтовать с триггером неизменяемости | fix-forward добавлен, но восстановление исторических данных не выполнялось — см. `MIGRATION-REPORT.md` |
| AUD-028 | P2 | Risk | Спец-бандлы SQL (`migrations.sql`) всё ещё описывают `halls` как физическую таблицу | гейты это допускают; обновление контракта — отдельная задача |
