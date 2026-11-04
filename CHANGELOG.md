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

## [Unreleased]

- Планы: версионирование имени архива установки (`nabilet-hosting-vX.Y.Z-<дата>.zip`),
  git-теги релизов (`vX.Y.Z`).

[1.0.0]: https://github.com/fegerV/nabilet/releases/tag/v1.0.0
[Unreleased]: https://github.com/fegerV/nabilet/compare/v1.0.0...HEAD
