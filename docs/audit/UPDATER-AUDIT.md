# Аудит системы самообновления NABILET

Дата: 2026-11-26. Ветка: `fix/updater-reliability`.

## 1. Подтверждённые дефекты и исправления

| ID | Приор. | Дефект | Исправление | Тест |
|---|---|---|---|---|
| U-01 | P0 | `UpdateApplier::rollback()` удалял целевой файл, если копии в backup не было | legacy-откат больше не удаляет файлы без подтверждённой копии; журнал хранит `original_present` | `UpdaterReliabilityTest::test_rollback_never_deletes_an_original_when_its_backup_is_missing` |
| U-02 | P0 | Аварийный `updater-recover.php` содержал тот же `unlink` | скрипт переписан: читает общий журнал и восстанавливает только подтверждённые шаги; легасный путь консервативен | `scripts/updater-recover.php`, `storage/framework/updater/recover.php` |
| U-03 | P0 | Откат не учитывал применённые миграции; MySQL DDL не транзакционен | журнал отдельно фиксирует `migrate` и `complete`; `requestRollback` блокирует откат, если миграции применены (`UPDATE_ROLLBACK_REQUIRES_DB_RESTORE`) | `test_journal_records_migration_and_completion_separately` |
| U-04 | P0 | Процесс, убитый во время APPLYING, навсегда оставлял статус APPLYING | `recoverInterrupted()` переводит просроченные APPLYING/MIGRATING/VERIFYING в `FAILED_AFTER_SWAP`, после чего включается автооткат | код оркестратора |
| U-05 | P0 | Проверка активного обновления и его создание не были атомарны | `UpdateStorage::withGlobalLock()` (flock + process guard) вокруг `start()`, `cancel()`, `requestRollback()` и всего тика | `test_global_lock_is_exclusive_and_non_blocking` |
| U-06 | P0 | Блокировка шага истекала через 5 минут посреди длинной операции | таймаут вынесен в `updates.lock_stale_minutes` (по умолчанию 15) | конфиг + код |
| U-07 | P0 | Резервная копия до 26 часов считалась свежей; ненулевой файл = «успех» | `updates.backup_max_age_hours` (по умолчанию 24), проверка ZIP (открытие, ZIP Slip, наличие `.sql`) и HMAC для `.enc` | `LocalBackupTest` (фикстура теперь настоящий ZIP) |
| U-08 | P0 | Старый аварийный скрипт оставался в `storage/framework/updater` навсегда | `ensureRecoveryScript()` сравнивает SHA-256 и атомарно заменяет устаревшую копию | `test_recovery_script_is_replaced_when_a_stale_copy_exists` |
| U-09 | P0 | Можно было откатить старый релиз поверх более нового | `requestRollback` разрешает только последний DONE/FAILED_AFTER_SWAP (`UPDATE_STALE_ROLLBACK`) | код оркестратора |
| U-10 | P1 | Журнал состояний в БД не переживал потерю соединения | добавлен append-only `operations.jsonl` с `flock`, independent от Laravel/БД/vendor | `test_apply_writes_a_durable_journal...` |

## 2. Журнал операций

Файл: `storage/app/updates/{id}/operations.jsonl`. Каждая строка — событие:

```
update_id, seq, op, status, path, source, target, backup,
original_present, checksum_before, checksum_after, from_version, to_version, time
```

Записи пишутся ДО шага (`pending`) и ПОСЛЕ (`done`). Откат использует только `done` в обратном порядке. Если копия отсутствует при `original_present=true`, шаг помечается `rollback_gap` и файл остаётся на месте — потеря исключена ценой расхождения, которое видно в журнале.

## 3. Оставшиеся ограничения

1. Откат БД **не автоматизирован** сознательно: после возобновления продаж восстановление старой БД уничтожило бы новые билеты/платежи. Требуется ручной выбор из проверенной копии по `docs/UPDATER-EMERGENCY-RECOVERY.md`.
2. Smoke-тесты ограничены проверкой версии, здоровья приложения (`HealthProbe`) и наличия `vendor/autoload.php` + `public_html/index.php`. Отдельные проверки QR/Android-контролёра, вебхуков и payment-провайдеров в CI не выполнялись.
3. Проверка подписи релиза остаётся в `ManifestVerifier`; journal не дублирует криптографию. SHA-256 по-прежнему не является доказательством подлинности для ленты (требуется подпись).
4. Тесты fault-injection после **каждой** операции установки и миграции не добавлены: покрыты ключевые точки (откат без копии, журнал, блокировка, скрипт). Интеграционные прогоны на hosting-архиве в среде Timeweb не проводились.
5. Атомарное переключение релизов через symlink не внедрено: текущий хостинг-контракт (`public_html` + `dist`) не гарантирует поддерживаемый atomic swap.

Ни один пункт не помечен как решённый без теста.
