# Changelog

Все значимые изменения NABILET Core оформляются в этом файле.

Формат — [Keep a Changelog](https://keepachangelog.com/ru-RU/1.1.0/), версионирование — [SemVer](https://semver.org/).

## [1.0.0] - 2026-11-04

Первая выпущенная версия для шаред-хостинга TimeWeb (архив установки
`nabilet-hosting-v1.0.0-20261104.zip`).

### Fixed

- **Вебхуки ЮKassa на шаред-хостинге TimeWeb** (коммит `3af767be`): проверка IP
  allowlist выполнялась через `in_array` (exact-match), хотя задание и лог ошибок
  предполагали CIDR. ЮKassa шлёт уведомления из подсетей, поэтому все они
  возвращались с 403. Добавлен чистый класс `Core\Support\IpRange`
  (IPv4/IPv6, CIDR, IPv4-mapped сети, fail-closed на малформированных записях).
  Нечитаемые записи allowlist теперь дают предупреждение в лог и violation в
  `ProductionSafetyAudit`.
- **Дубль точки приёма уведомлений:** `/api/v1/payments/webhooks/{provider}` вёл в
  `PaymentController@webhook`, а `/api/v1/webhooks/payment/{provider}` — в
  `WebhookController@payment`. Оба маршрута объявлены в модуле Webhooks и ведут в
  единый обработчик `WebhookController@payment` (конверт §66 с `request_id`);
  дублирующий роут и мёртвый контроллер удалены.
- **UI админки:** канонический URL вебхука для провайдера, подсказка, что для
  ЮKassa секрет webhook оставлять пустым (платёжная система не подписывает
  уведомления — работает IP-allowlist).

### Added

- `Core\Support\IpRange` — CIDR-aware проверка списка доверенных IP.
- `tests/Unit/IpRangeTest` — 17 unit-тестов диапазонов.
- Расширенные feature-тесты `tests/Feature/Payments/WebhookAuthenticationTest`:
  allow/deny по CIDR, паритет путей, конверт ошибки, флаг малформированного
  allowlist.

## [1.0.1] - 2026-11-13

Вторая сборка архива установки `nabilet-hosting-v1.0.1-20261113.zip` (~30 МБ).
Внутри: свежий `app/` (генератор QR-конфига чекера на `bacon/bacon-qr-code`,
обновлённые symfony-пакеты 7.4.23/8.1.11 в `vendor/`) и две правки установщика,
найденные полным e2e-прогоном визарда на чистой копии.

### Fixed

- **POST /install и «Проверить подключение» падали с form-encoded формой**
  (найдено e2e-прогоном архива 2026-11-13): `db_port` из HTML-формы приходит
  строкой, `validate('integer')` типы не приводит, а `strict_types=1` в
  `InstallerController` превращал вызов `testDatabaseConnection(int …)` в
  TypeError → `INSTALL_FAILED` / `DB_CONNECTION_FAILED`. Порт приводится к
  `int` явно в обоих местах вызова; регрессионный тест в
  `tests/Feature/Installer/InstallerHardeningTest` гоняет порт строкой.
- **Кнопка «Перейти в админку →» вела на фирменную 404**: витрина и админка —
  один SPA на hash-роутинге, прямой `/admin` не существует (Laravel fallback
  отвечает 404). И ссылка в блейде, и `redirect` в JSON-ответе теперь
  `/#/admin`.

### Changed

- В архив не попадают логи `storage/logs`, кэш `storage/framework` и прочий
  runtime-мусор, которые тащила сборка v1.0.0 (544 файла минус); ZIP-записи
  используют стандартные forward-slash разделители (в v1.0.0 были `\`).
- Проверки CI: 953 колонки, 0 advisories (`composer audit`), suite
  1297 тестов / 5289 assertions зелёный (коммиты `ccebb18e`, `bfe26373`).

## [Unreleased]

- Планы: версионирование имени архива установки (`nabilet-hosting-vX.Y.Z-<дата>.zip`),
  git-теги релизов (`vX.Y.Z`).

[1.0.0]: https://github.com/fegerV/nabilet/releases/tag/v1.0.0
[Unreleased]: https://github.com/fegerV/nabilet/compare/v1.0.0...HEAD
