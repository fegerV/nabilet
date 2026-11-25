# Аварийное восстановление после неудачного обновления

Этот документ описывает действия, когда Laravel, vendor или БД недоступны после self-update.

## 1. Что сделать в первую очередь

1. **Не удалять** `storage/app/updates/{id}/` — там находятся архив, backup и журнал операций.
2. Не запускать второе обновление и не нажимать «Откатить» из админки, если сайт в режиме обслуживания.
3. Сохранить копию `storage/app/updates/{id}/operations.jsonl` и логов.

## 2. Быстрая проверка журнала

```bash
php -r '$e=file("storage/app/updates/ID/operations.jsonl", FILE_IGNORE_NEW_LINES);
foreach($e as $l){$j=json_decode($l,true); echo ($j["seq"]??"?")." ".($j["op"]??"?")." ".($j["status"]??"?")." ".($j["path"]??"").PHP_EOL;}'
```

- `swap_dir`/`swap_file`/`assets` со статусом `done` — шаг реально применён и обратим.
- `backup_*` со статусом `pending` без последующего `done` — замена не произошла.
- `rollback_gap` — оригинал был, но копия отсутствует; файл оставлен на месте намеренно.
- `migrate` со статусом `done` — **БД уже изменена**, простой откат файлов запрещён.

## 3. Откат кода без Laravel

```bash
php storage/framework/updater/recover.php <update_id>
```

Скрипт:
- работает без vendor, artisan и БД;
- использует тот же журнал, что и обычный откат;
- восстанавливает только подтверждённые изменённые пути;
- не удаляет файлы без доказательства, что они созданы обновлением;
- снимает `storage/framework/down` и пишет маркер `recovered-<id>.json`.

Если скрипт отсутствует или устарел, он будет заменён при следующем запуске обновления. Для ручной установки скопируйте `scripts/updater-recover.php` в `storage/framework/updater/recover.php`.

## 4. Откат БД

Миграции MySQL DDL не транзакционны. Если в журнале есть `migrate: done`:

1. Определите момент до обновления (`from_version`, `started_at` в `state.json`).
2. Выберите проверенную резервную копию (ZIP с `.sql`, либо `.enc` при доступном `NABILET_BACKUP_ENCRYPTION_KEY`).
3. Восстановите БД **в отдельную базу** и сверьте число билетов/заказов перед срезом продаж.
4. Только после подтверждения ответственного восстановите основную БД и снимите режим обслуживания.

Автоматически старую БД восстанавливать нельзя: после возобновления продаж это уничтожило бы новые билеты, оплаты и вебхуки. См. `docs/UPDATER-COMPATIBILITY.md`.

## 5. После восстановления

```bash
php artisan migrate --force
php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan cache:clear
php artisan nabilet:update:tick
curl -fsS https://<host>/api/v1/health
```

Проверьте витрину, кабинет, один ранее выпущенный QR и наличие frontend-ассетов. Снимать режим обслуживания следует только после этих проверок.
