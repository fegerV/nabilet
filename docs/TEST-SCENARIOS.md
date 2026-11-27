# Сценарии тестирования: что проверено, а что нет

## Автоматически (один коммит, 2026-11-27)

| Сценарий | Где | Результат |
|---|---|---|
| Синтаксис, неявно nullable, домен без Laravel | `tools/lint.php`, `verify-purity.php` | PASS |
| Ядро без vendor | `tests/run.php` | 1094 метода |
| Feature/Unit с БД | `vendor/bin/phpunit` | 1485 тестов / 6521 утверждений |
| Статика | PHPStan level 5 | 0 ошибок |
| Схема = спека, CHECK, триггеры | `verify-migrations`, `verify-state-machines`, CI `schema` (MySQL 8.4) | PASS |
| Контракт ≠ дрейф | OpenAPI ×2, spec-parity, contract-schema ×2, models-schema | PASS |
| Автозагрузка/маршруты/модули | verify-autoload / route-ownership / module-structure | PASS |
| SPA | typecheck, Vitest (599), production build | PASS |
| Чистая установка из распакованного ZIP | `scripts/smoke/smoke-install.php`, MariaDB 11.4 | 45/45 |
| MCP read-only диагностика | `AgentDiagnosticsToolsTest` | 19 тестов / 150 утверждений |

## Сценарии продаж (покрытие в PHPUnit/Feature)

- Два покупателя, одно место: `ConcurrentBookingTest` — ровно один холд.
- Истечение резерва, late-QR после истечения: `LateSucceededExpiredHoldsTest`.
- Повтор запроса/гонка: `IdempotencyRaceTest`, `PaymentIdempotencyTest`.
- Повтор webhook: `WebhookAuthenticationTest`, `OutboundWebhookDeliveryTest`.
- Возврат полный/частичный и повтор: `PaymentSettlementTest`, `BonusRefundPolicyTest`.
- Отмена мероприятия: `EventCancellationCascadeTest`.
- Ошибки билета/письма: `TicketIssuanceTest`, `TicketEmailTemplateTest`.
- Изоляция организаций: `StabilizationIsolationTest`, `TenantScoped*`, `AuditHardeningWave2Test`.

## Не покрыто браузером / требует стенда

| Сценарий | Почему не закрыт |
|---|---|
| Конструктор залов: undo/redo, мультивыделение, конфликт редакторов, автосохранение, большие залы | есть план `PLAYWRIGHT-E2E-PLAN.md` и Vitest, но CI-задача Playwright с подготовленным стендом не включена |
| Две организации / два администратора / сотрудники в браузере | данные частично подготовлены в PHPUnit; отдельного E2E-набора нет |
| Реальный CRM/платёж/SMTP/Webhook провайдер | нужны ключи и домен |
| Обновление предыдущего релиза | нужен предыдущий архив и отдельный стенд |
| Android Checker и киоск как оборудование | проверялся API, не само оборудование |
| MySQL 5.7 на хостинге | локально MariaDB 11.4 и MySQL 8.4 в CI |
| Восстановление бэкапа | код есть, фактического восстановления не проводилось |

`tests/e2e/purchase-flow.spec.ts` и `upgrade-recovery.spec.ts` пропускаются
без `E2E_BASE_URL` / `E2E_PREVIOUS_RELEASE`: это сознательная защита от прогона
по рабочей базе, а не «зелёный» тест.
