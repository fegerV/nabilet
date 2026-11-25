# Отчёт по миграции `halls` → `venues`

## 1. Исходное состояние

`database/migrations/2026_11_18_000800_replace_halls_with_view.php` удалил таблицу `halls` и создал VIEW:

```sql
CREATE VIEW halls AS
SELECT v.id, v.public_id, v.id AS venue_id,
       COALESCE(v.hall_name, v.name) AS name,
       ... NULL AS capacity, NULL AS width, NULL AS height, ...
FROM venues v
```

У View есть три последствия:
1. `INSERT INTO halls` невозможен;
2. колонка `name` (COALESCE) и NULL-литералы не обновляются;
3. BEFORE DELETE триггер заморозки схем исчез вместе с таблицей.

## 2. Что исправлено в этой ветке

Миграция `2026_11_26_000100_finish_hall_view_write_paths.php`:
- добавляет на `venues` колонки `capacity`, `width`, `height`, `exterior_photo_url`, `interior_photo_url`;
- пересоздаёт VIEW, отображая новые колонки (старые читатели продолжают видеть тот же набор полей `halls`);
- заполняет `hall_schema_versions.venue_id` из `hall_id` там, где поле пустое;
- создаёт `trg_venue_frozen_schema_delete_guard`: удаление площадки с published/archived схемой отклоняется SQLSTATE 45000.

Код:
- `HallRepository::create/update/delete()` пишут в `Venue`, возвращая in-memory `Hall`;
- `HallService::deleteHall()` по-прежнему явно проверяет замороженные версии и удаляет площадку из таблицы;
- `VenueController::destroy()` добавил ту же проверку (409 `VENUE_HAS_FROZEN_SCHEMA_VERSIONS`);
- `EventService` больше не вызывает `Hall::create()` на VIEW — создаёт техническую тень в памяти.

Обновлены реестры ожидаемых расширений в `tools/verify-migrations.php` и `tools/verify-models-schema.php`: обе проверки проходят (16/16 и PASS).

Проверка: `HallViewWriteTest` — UPDATE зала попадает в `venues` и виден через VIEW; удаление площадки с опубликованной схемой отклоняется и приложением, и триггером.

## 3. Совместимость

- `hall_id` и `venue_id` остаются равными; старые сеансы, схемы и билеты продолжают ссылаться на те же идентификаторы.
- `schema_version_id` не менялся.
- VIEW по-прежнему отдаёт те же колонки, поэтому публичные ресурсы не изменили контракт.

## 4. Оставшиеся риски миграции 2026-11-18

1. **Мультизальные площадки.** Если до миграции у одной площадки было несколько залов, `UPDATE hall_schema_versions ... SET hall_id = venue_id` мог нарушить `uq_schema_hall_version` / `uq_schema_one_published_per_hall`, а имена всех залов, кроме одного, потерялись. Миграция 2026_11_26 не восстанавливает такие исторические данные — для этого нужен per-tenant бэкап до 2026-11-18.
2. **Конфликт с триггером неизменяемости.** Триггер `trg_schema_version_lifecycle_guard` запрещает менять `hall_id`; если на реальной БД `hall_id != venue_id`, миграция 000800 прервалась бы до нашего fix-forward. Проверить состояние конкретного instalля нужно вручную (`SELECT COUNT(*) ... WHERE hall_id <> venue_id`).
3. **Спец-бандлы SQL.** `migrations.sql` и `nabilet_core_spec/migrations.sql` всё ещё описывают `halls` как физическую таблицу. CI-гейты сознательно допускают это расхождение; обновление контракта ядра — отдельная задача с пересчётом ожидаемого числа колонок.
4. **Внешние ключи.** FK с `hall_schema_versions` и `events` на `venues` не восстановлены. Целостность обеспечивается приложением и триггерами, но не БД.

## 5. Рекомендации перед продакшеном

- Выполнить `SELECT ... WHERE hall_id <> venue_id` и `SELECT COUNT(*) FROM venues` до/после миграции, сравнить с бэкапом.
- Проверить, что на целевом MySQL ≥8.0 созданы оба триггера (`SHOW TRIGGERS`).
- При наличии исторических мультизальных площадок восстановить имена залов из бэкапа вручную и зафиксировать решение.
